<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * SettingModel
 *
 * Porting CI4 di Mdl_settings (CI3): accesso alla tabella ip_settings
 * con doppia cache — Redis cross-request (5 min) + statica per request,
 * come load_settings() + setting() in CI3.
 *
 * La cache cross-request è legata al database (ip_settings_all_<database>),
 * non all'installazione: i test girano sulla copia e leggevano le impostazioni
 * del database di lavoro, quindi una pagina provata sulla copia mostrava valori
 * di un altro archivio e una modifica scritta in transazione restava invisibile.
 * Con la chiave legata al database ogni connessione ha la sua, e la copia la
 * sua: nessun test poteva accorgersene finché l'installazione ne aveva una sola.
 */
class SettingModel extends Model
{
    protected $table         = 'ip_settings';
    protected $primaryKey    = 'setting_id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['setting_key', 'setting_value'];

    /** @var array<string, mixed>|null impostazioni per request, null = da caricare */
    private static ?array $settingsCache = null;

    private const REDIS_KEY = 'ip_settings_all';
    private const REDIS_TTL = 300; // 5 minuti

    /**
     * Carica tutte le impostazioni in cache (una volta per request).
     * Se Redis è disponibile, la query DB viene eseguita solo ogni 5 minuti
     * invece che a ogni singola request PHP.
     */
    public function loadSettings(): array
    {
        if (self::$settingsCache === null) {
            $cached = cache()->get($this->cacheKey());

            if (is_array($cached)) {
                self::$settingsCache = $cached;
            } else {
                try {
                    $rows = $this->findAll();
                } catch (\Throwable $e) {
                    // Database irraggiungibile o schema non ancora creato: è
                    // la situazione del primo avvio, e proprio lì la pagina di
                    // verifica (Sessions::setup) deve poter dire che cosa
                    // manca. Si risponde "nessuna impostazione" senza metterla
                    // in cache, così alla richiesta dopo si riprova. L'errore
                    // resta nel log: non si perde.
                    log_message('warning', 'Impostazioni non leggibili: {msg}', ['msg' => $e->getMessage()]);
                    self::$settingsCache = [];

                    return self::$settingsCache;
                }

                self::$settingsCache = [];

                foreach ($rows as $row) {
                    self::$settingsCache[$row['setting_key']] = $row['setting_value'];
                }

                cache()->save($this->cacheKey(), self::$settingsCache, self::REDIS_TTL);
            }
        }

        return self::$settingsCache;
    }

    /**
     * Chiave della cache condivisa: una per database, non una per installazione.
     *
     * Due connessioni diverse (il database di lavoro e la copia su cui girano i
     * test) non devono leggersi le impostazioni a vicenda: era il motivo per cui
     * una pagina provata sulla copia mostrava valori dell'altro archivio.
     */
    private function cacheKey(): string
    {
        return self::cacheKeyFor($this->db->getDatabase());
    }

    /**
     * Chiave di cache per un database, ripulita dai caratteri che i gestori di
     * cache rifiutano: il nome può essere un percorso (`/var/db/x.sqlite`) o
     * `:memory:` nei test, e la chiave di Redis non li accetta.
     */
    private static function cacheKeyFor(string $database): string
    {
        if ($database === '') {
            $database = 'predefinito';
        }

        return self::REDIS_KEY . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $database);
    }

    /**
     * Legge una impostazione (equivalente di setting($key, $default) in CI3).
     */
    public function setting(string $key, $default = '')
    {
        $settings = $this->loadSettings();

        return $settings[$key] ?? $default;
    }

    /**
     * Salva (insert o update) una impostazione.
     */
    public function setSetting(string $key, $value): void
    {
        $this->setSettings([$key => $value]);
    }

    /**
     * Salva più impostazioni in una passata sola.
     *
     * setSetting() per ogni chiave faceva una SELECT di esistenza, l'UPDATE o
     * l'INSERT e la cancellazione della cache Redis: con le 50 e più chiavi del
     * form Impostazioni erano oltre un centinaio di query per un solo
     * salvataggio, tutte a svuotare la stessa chiave di cache. Qui si legge una
     * volta sola quali chiavi esistono, si scrive in blocco e si invalida la
     * cache una volta a fine giro. Il risultato per chi legge è identico.
     *
     * @param array<string, mixed> $values chiave => valore
     */
    public function setSettings(array $values): void
    {
        if ($values === []) {
            return;
        }

        // setting_value è TEXT e i chiamanti passano anche interi e null:
        // normalizzo a stringa qui, così update e insert ricevono lo stesso tipo.
        $values = array_map(static fn ($value): string => (string) $value, $values);

        $existing = [];

        foreach ($this->select('setting_id, setting_key')->whereIn('setting_key', array_keys($values))->findAll() as $row) {
            $existing[$row['setting_key']] = (int) $row['setting_id'];
        }

        $updates = [];
        $inserts = [];

        foreach ($values as $key => $value) {
            if (isset($existing[$key])) {
                $updates[] = ['setting_id' => $existing[$key], 'setting_value' => $value];
            } else {
                $inserts[] = ['setting_key' => $key, 'setting_value' => $value];
            }
        }

        // In transazione: un salvataggio parziale lascerebbe metà delle
        // impostazioni nuove e metà vecchie, stato che nessuna schermata sa
        // leggere (es. formato numerico aggiornato e separatori no).
        $db = $this->db;
        $db->transStart();

        if ($updates !== []) {
            $db->table($this->table)->updateBatch($updates, 'setting_id');
        }

        if ($inserts !== []) {
            $db->table($this->table)->insertBatch($inserts);
        }

        $db->transComplete();

        // Cache statica solo se era già stata caricata: se è null la prossima
        // lettura rilegge dal database, che è comunque aggiornato.
        if (self::$settingsCache !== null) {
            self::$settingsCache = array_merge(self::$settingsCache, $values);
        }

        cache()->delete($this->cacheKey());
    }

    /**
     * Butta via la cache statica e quella condivisa del database in uso.
     *
     * Chiamato dalle migrazioni e dopo modifiche fatte fuori dal modello. La
     * cache condivisa si cancella per la connessione corrente: un test non deve
     * poter svuotare la cache del database di lavoro.
     */
    public static function resetCache(): void
    {
        self::$settingsCache = null;

        try {
            $database = \Config\Database::connect()->getDatabase();
        } catch (\Throwable) {
            return;
        }

        cache()->delete(self::cacheKeyFor($database));
    }
}
