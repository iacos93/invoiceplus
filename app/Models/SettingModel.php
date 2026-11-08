<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * SettingModel
 *
 * Porting CI4 di Mdl_settings (CI3): accesso alla tabella ip_settings
 * con doppia cache — Redis cross-request (5 min) + statica per request,
 * come load_settings() + setting() in CI3.
 */
class SettingModel extends Model
{
    protected $table         = 'ip_settings';
    protected $primaryKey    = 'setting_id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['setting_key', 'setting_value'];

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
            $cached = cache()->get(self::REDIS_KEY);

            if (is_array($cached)) {
                self::$settingsCache = $cached;
            } else {
                $rows = $this->findAll();
                self::$settingsCache = [];

                foreach ($rows as $row) {
                    self::$settingsCache[$row['setting_key']] = $row['setting_value'];
                }

                cache()->save(self::REDIS_KEY, self::$settingsCache, self::REDIS_TTL);
            }
        }

        return self::$settingsCache;
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

        cache()->delete(self::REDIS_KEY);
    }

    public static function resetCache(): void
    {
        self::$settingsCache = null;
        cache()->delete(self::REDIS_KEY);
    }
}
