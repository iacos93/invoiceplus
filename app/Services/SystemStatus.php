<?php

namespace App\Services;

/**
 * Controlli di salute dell'installazione, per la pagina "Stato del sistema".
 *
 * Nasce da un caso concreto: il backup automatico non produceva file da giorni
 * (pg_dump 16 contro un server 17), e non se ne accorgeva nessuno perché il
 * fallimento finiva solo nel log del cron. Le cose che possono rompersi in
 * silenzio — backup vecchi o assenti, automazioni ferme, cartelle non
 * scrivibili — qui diventano un avviso visibile nella pagina.
 *
 * Le soglie sono volutamente larghe: il backup gira una volta al giorno, il
 * cron-runner ogni 15 minuti. Un avviso che compare per un ritardo di pochi
 * minuti viene ignorato, e allora non serve a niente.
 */
final class SystemStatus
{
    public const OK      = 'ok';
    public const WARNING = 'warning';
    public const ERROR   = 'error';

    /** Oltre due giorni senza un dump nuovo, qualcosa non va. */
    public const BACKUP_MAX_AGE = 2 * 86400;

    /** Il runner passa ogni 15 minuti: due ore di silenzio sono un guasto. */
    public const CRON_MAX_AGE = 2 * 3600;

    /** Oltre un mese senza una prova di ripristino, il backup è solo una speranza. */
    public const RESTORE_MAX_AGE = 30 * 86400;

    /**
     * Sotto questi valori il disco è un problema immediato o imminente.
     * La soglia è assoluta: una percentuale bassa su un disco grande non è
     * di per sé un rischio, mentre 800 MB liberi lo sono su qualunque disco.
     */
    public const DISK_ERROR_BYTES   = 256 * 1024 * 1024;
    public const DISK_WARNING_BYTES = 1024 * 1024 * 1024;

    /** PHP minimo richiesto da composer.json. */
    public const PHP_MIN_VERSION_ID = 80400;

    /** Giorni alla scadenza del certificato SDICoop sotto i quali si avvisa. */
    public const CERT_WARNING_DAYS = 45;

    public function __construct(
        private string $writablePath,
        private string $backupDir,
        private string $cronDir,
        private int $now,
        private string $environment = 'production',
    ) {}

    public static function fromApp(): self
    {
        $writable = rtrim(WRITEPATH, '/');

        return new self($writable, $writable . '/backups', $writable . '/cron', time(), ENVIRONMENT);
    }

    /**
     * @return list<array{key: string, label: string, state: string, detail: string, hint: string}>
     */
    public function checks(): array
    {
        return [
            $this->phpCheck(),
            $this->writableCheck(),
            $this->backupCheck(),
            $this->filesBackupCheck(),
            $this->restoreCheck(),
            $this->cronCheck(),
            $this->environmentCheck(),
            $this->diskCheck(),
            $this->logCheck(),
            $this->orphanCheck(),
        ];
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    public function phpCheck(): array
    {
        $ok = PHP_VERSION_ID >= self::PHP_MIN_VERSION_ID;

        return [
            'key'    => 'php',
            'label'  => 'Versione di PHP',
            'state'  => $ok ? self::OK : self::ERROR,
            'detail' => 'PHP ' . PHP_VERSION,
            'hint'   => $ok ? '' : 'Serve PHP 8.4 o successivo: aggiorna il runtime prima di usare l\'applicazione in produzione.',
        ];
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    public function writableCheck(): array
    {
        $nonScrivibili = [];

        // Le cartelle che l'applicazione crea da sé non sono un problema se
        // mancano (vengono create al primo uso): conta solo se ci sono e non
        // si possono scrivere.
        foreach (['', '/logs', '/session', '/cache', '/uploads', '/backups', '/cron'] as $sub) {
            $dir = $this->writablePath . $sub;

            if (is_dir($dir) && ! is_writable($dir)) {
                $nonScrivibili[] = 'writable' . $sub;
            }
        }

        if ($nonScrivibili !== []) {
            return [
                'key'    => 'writable',
                'label'  => 'Cartelle scrivibili',
                'state'  => self::ERROR,
                'detail' => 'Non scrivibili: ' . implode(', ', $nonScrivibili),
                'hint'   => 'Senza permessi di scrittura log, sessioni, PDF e backup falliscono con errori che arrivano tardi: dai la proprietà a writable/ all\'utente del processo PHP.',
            ];
        }

        return [
            'key'    => 'writable',
            'label'  => 'Cartelle scrivibili',
            'state'  => self::OK,
            'detail' => 'writable/ e le cartelle usate dall\'applicazione sono scrivibili',
            'hint'   => '',
        ];
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    public function backupCheck(): array
    {
        $files = $this->newestFiles($this->backupDir, 'backup_*');

        if ($files === []) {
            return [
                'key'    => 'backup',
                'label'  => 'Backup del database',
                'state'  => self::ERROR,
                'detail' => 'Nessun backup in writable/backups',
                'hint'   => 'Il job cron/backup non ha mai prodotto un file. Controlla il log del cron-runner e che pg_dump (o mysqldump) sia installato nella versione giusta.',
            ];
        }

        [$file, $mtime] = $files[0];
        $size           = (int) filesize($file);

        if ($size === 0) {
            return [
                'key'    => 'backup',
                'label'  => 'Backup del database',
                'state'  => self::ERROR,
                'detail' => basename($file) . ' è da 0 byte',
                'hint'   => 'Un dump vuoto non è un backup: guarda il log del cron-runner e l\'endpoint cron/backup.',
            ];
        }

        $age  = max(0, $this->now - $mtime);
        $vecchio = $age > self::BACKUP_MAX_AGE;

        return [
            'key'    => 'backup',
            'label'  => 'Backup del database',
            'state'  => $vecchio ? self::WARNING : self::OK,
            'detail' => basename($file) . ', ' . self::formatBytes($size) . ', ' . self::age($age),
            'hint'   => $vecchio
                ? 'L\'ultimo dump è più vecchio di due giorni. Il backup gira una volta al giorno: controlla cron-runner.sh e il suo log.'
                : '',
        ];
    }

    /**
     * L'ultimo dump del database, quello che la pagina offre da scaricare.
     *
     * Il backup vive dentro writable/backups e viene ruotato: portarlo fuori
     * dal server è l'unica cosa che lo rende una copia e non un file sullo
     * stesso disco che si guasta. Il download passa da qui (e non da un
     * percorso scelto nell'URL) proprio perché il nome del file non deve
     * diventare un modo per leggere altro.
     *
     * @return array{path: string, name: string, size: int}|null
     */
    public function latestBackup(): ?array
    {
        foreach ($this->newestFiles($this->backupDir, 'backup_*') as [$file]) {
            // Nella cartella può esserci di tutto: conta solo un dump vero e
            // non vuoto. Un file da 0 byte non è un backup da conservare.
            if (preg_match('/\.(dump|sql)$/', $file) !== 1 || ! is_file($file)) {
                continue;
            }

            $size = (int) filesize($file);

            if ($size <= 0) {
                continue;
            }

            return [
                'path' => $file,
                'name' => basename($file),
                'size' => $size,
            ];
        }

        return null;
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    public function cronCheck(): array
    {
        $files = $this->newestFiles($this->cronDir, '*.last');

        if ($files === []) {
            return [
                'key'    => 'cron',
                'label'  => 'Automazioni',
                'state'  => self::WARNING,
                'detail' => 'Nessuna esecuzione registrata in writable/cron',
                'hint'   => 'Le automazioni (fatture ricorrenti, SdI, riepilogo, backup) partono da tools/cron-runner.sh, da mettere in crontab. Se l\'hai appena installato, il primo giro non è ancora passato.',
            ];
        }

        [$file, $mtime] = $files[0];
        $age     = max(0, $this->now - $mtime);
        $ferme   = $age > self::CRON_MAX_AGE;
        $job     = basename($file, '.last');

        return [
            'key'    => 'cron',
            'label'  => 'Automazioni',
            'state'  => $ferme ? self::WARNING : self::OK,
            'detail' => 'Ultimo job eseguito: ' . $job . ', ' . self::age($age),
            'hint'   => $ferme
                ? 'Il cron-runner non passa da più di due ore: controlla la riga di crontab e il log in writable/cron.'
                : '',
        ];
    }

    /**
     * L'ultima prova di ripristino registrata da `php spark backup:verify`.
     *
     * Un dump che esiste e non si ripristina è peggio di nessun dump: dà
     * l'illusione di essere coperti. La verifica confronta i conteggi del
     * database temporaneo con quelli del database di partenza, quindi dice
     * qualcosa che il solo file non può dire.
     *
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    public function restoreCheck(): array
    {
        $marker = $this->cronDir . '/backup_verify.json';

        if (! is_file($marker)) {
            return [
                'key'    => 'restore',
                'label'  => 'Verifica del ripristino',
                'state'  => self::WARNING,
                'detail' => 'Nessuna verifica registrata',
                'hint'   => 'Esegui `php spark backup:verify`: ripristina l\'ultimo dump in un database temporaneo e confronta i conteggi. Finché non è stato provato, il backup è solo un file.',
            ];
        }

        $dati = json_decode((string) @file_get_contents($marker), true);

        if (! is_array($dati) || ! isset($dati['time'])) {
            return [
                'key'    => 'restore',
                'label'  => 'Verifica del ripristino',
                'state'  => self::ERROR,
                'detail' => 'writable/cron/backup_verify.json illeggibile',
                'hint'   => 'Il file di esito è corrotto: rilancia `php spark backup:verify` per rigenerarlo.',
            ];
        }

        $quando  = (int) $dati['time'];
        $eta     = max(0, $this->now - $quando);
        $file    = (string) ($dati['file'] ?? '');
        $tabelle = (int) ($dati['tables'] ?? 0);

        if (($dati['ok'] ?? false) !== true) {
            $errore = (string) ($dati['error'] ?? 'motivo non registrato');

            // Non poter creare il database di prova non dice niente sul dump:
            // è un permesso mancante, non un backup rotto. Va detto, ma senza
            // far credere che i dati non siano recuperabili.
            $permessi = str_contains($errore, 'Creazione del database temporaneo fallita');

            return [
                'key'    => 'restore',
                'label'  => 'Verifica del ripristino',
                'state'  => $permessi ? self::WARNING : self::ERROR,
                'detail' => ($permessi ? 'Verifica non eseguibile ' : 'Ultima verifica fallita ')
                    . self::age($eta) . ': ' . mb_substr($errore, 0, 160),
                'hint'   => $permessi
                    ? 'L\'utente del database non può creare il database temporaneo: concedi CREATEDB (PostgreSQL) o CREATE (MySQL), oppure esegui `php spark backup:verify --group=<gruppo con un utente abilitato>`. Non è detto che il dump sia rotto.'
                    : 'Il dump non si ripristina: non considerarlo un backup. Guarda l\'esito di `php spark backup:verify` e che pg_restore (o mysql) sia installato.',
            ];
        }

        $backup      = $this->newestFiles($this->backupDir, 'backup_*');
        $piuRecente  = $backup[0][0] ?? '';

        if ($piuRecente !== '' && basename($piuRecente) !== $file) {
            return [
                'key'    => 'restore',
                'label'  => 'Verifica del ripristino',
                'state'  => self::WARNING,
                'detail' => 'C\'è un dump più recente non ancora verificato: ' . basename($piuRecente),
                'hint'   => 'La verifica vale per il dump che è stato provato, non per quelli successivi. Rilancia `php spark backup:verify`.',
            ];
        }

        if ($eta > self::RESTORE_MAX_AGE) {
            return [
                'key'    => 'restore',
                'label'  => 'Verifica del ripristino',
                'state'  => self::WARNING,
                'detail' => 'Ultima verifica ' . self::age($eta) . ': ' . $file,
                'hint'   => 'Una prova al mese è il minimo: il ripristino si rompe anche quando il dump continua a essere prodotto (permessi, versione del binario, disco).',
            ];
        }

        return [
            'key'    => 'restore',
            'label'  => 'Verifica del ripristino',
            'state'  => self::OK,
            'detail' => 'Ultima verifica ' . self::age($eta) . ': ' . $file . ', ' . $tabelle . ' tabelle confrontate',
            'hint'   => '',
        ];
    }

    /**
     * In sviluppo gli errori mostrano stack trace, query e percorsi: con dati
     * veri è un'informazione che non deve uscire.
     *
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    public function environmentCheck(): array
    {
        $produzione = strtolower(trim($this->environment)) === 'production';

        return [
            'key'    => 'environment',
            'label'  => 'Ambiente dell\'applicazione',
            'state'  => $produzione ? self::OK : self::WARNING,
            'detail' => 'CI_ENVIRONMENT = ' . $this->environment,
            'hint'   => $produzione
                ? ''
                : 'L\'applicazione è in ambiente di sviluppo: in caso di errore la pagina mostra stack trace, query e percorsi. Prima di usarla con dati veri imposta CI_ENVIRONMENT = production nel .env e svuota la cache.',
        ];
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    public function diskCheck(): array
    {
        $libero = @disk_free_space($this->writablePath);
        $totale = @disk_total_space($this->writablePath);

        if ($libero === false) {
            return [
                'key'    => 'disk',
                'label'  => 'Spazio su disco',
                'state'  => self::WARNING,
                'detail' => 'Non determinabile per writable/',
                'hint'   => 'Il processo PHP non riesce a leggere lo spazio libero: controlla i permessi o il filesystem.',
            ];
        }

        $libero = (int) $libero;
        $totale = (int) $totale;

        $stato = self::OK;

        if ($libero < self::DISK_ERROR_BYTES) {
            $stato = self::ERROR;
        } elseif ($libero < self::DISK_WARNING_BYTES) {
            $stato = self::WARNING;
        }

        $detail = self::formatBytes($libero) . ' liberi';

        if ($totale > 0) {
            $detail .= ' su ' . self::formatBytes($totale) . ' (' . (int) round(100 * $libero / $totale) . '%)';
        }

        return [
            'key'    => 'disk',
            'label'  => 'Spazio su disco',
            'state'  => $stato,
            'detail' => $detail,
            'hint'   => match ($stato) {
                self::ERROR   => 'Con il disco pieno falliscono PDF, sessioni, log e backup. Libera spazio adesso.',
                self::WARNING => 'Lo spazio si sta esaurendo: i backup occupano la stessa cartella dell\'applicazione e la rotazione ne tiene 14.',
                default       => '',
            },
        ];
    }

    /**
     * Un errore nel log di oggi non è per forza un guasto, ma è il posto in
     * cui si nasconde un guasto che nessuno ha visto.
     *
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    public function logCheck(): array
    {
        $file = $this->writablePath . '/logs/log-' . date('Y-m-d', $this->now) . '.log';

        if (! is_file($file)) {
            return [
                'key'    => 'logs',
                'label'  => 'Errori nel log',
                'state'  => self::OK,
                'detail' => 'Nessun log per oggi: nessun errore registrato',
                'hint'   => '',
            ];
        }

        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return [
                'key'    => 'logs',
                'label'  => 'Errori nel log',
                'state'  => self::WARNING,
                'detail' => 'Log di oggi non leggibile: ' . basename($file),
                'hint'   => 'Il processo PHP non riesce ad aprire il file: controlla i permessi di writable/logs.',
            ];
        }

        $errori = 0;
        $ultimo = '';

        // Si legge tutto il file, una riga per volta. Prima si guardava solo
        // la coda di 200 KB: in una giornata di log lunga un errore scritto
        // la mattina restava invisibile, e la pagina diceva "nessun errore"
        // proprio quando ce n'era uno.
        while (($riga = fgets($handle)) !== false) {
            if (preg_match('/^(ERROR|CRITICAL)\b/', $riga) !== 1) {
                continue;
            }

            $errori++;

            if (preg_match('/^(?:ERROR|CRITICAL) - \d{4}-\d{2}-\d{2} (\d{2}:\d{2}):\d{2}/', $riga, $m) === 1) {
                $ultimo = $m[1];
            }
        }

        fclose($handle);

        if ($errori === 0) {
            return [
                'key'    => 'logs',
                'label'  => 'Errori nel log',
                'state'  => self::OK,
                'detail' => 'Nessun ERROR o CRITICAL in ' . basename($file),
                'hint'   => '',
            ];
        }

        return [
            'key'    => 'logs',
            'label'  => 'Errori nel log',
            'state'  => self::WARNING,
            'detail' => $errori . ($errori === 1 ? ' voce' : ' voci') . ' ERROR/CRITICAL in ' . basename($file)
                . ($ultimo !== '' ? ' (l\'ultima alle ' . $ultimo . ')' : ''),
            'hint'   => 'Apri writable/logs/' . basename($file) . ': un errore singolo può essere un caso, uno ripetuto è un guasto che sta lavorando in silenzio.',
        ];
    }

    /**
     * Righe per-azienda rimaste senza company_id.
     *
     * Da quando l'eccezione `company_id IS NULL` è stata tolta dalle query,
     * una riga senza azienda non compare in nessuna lista: il conteggio deve
     * restare a zero, e un valore diverso da zero dice che qualcosa scrive
     * senza `company_id`. Qui si legge solo il conteggio: il backfill non
     * parte da una pagina di stato. Senza database raggiungibile il controllo
     * avvisa invece di rompere la pagina.
     *
     * @param array{total?: int, tables?: array<string, array{nulls?: int, total?: int}>}|null $conteggi
     *        conteggi già calcolati (test), null per interrogarli ora
     *
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    public function orphanCheck(?array $conteggi = null): array
    {
        $label = 'Dati senza azienda';

        if ($conteggi === null) {
            try {
                $conteggi = (new CompanyOrphanReport())->counts();
            } catch (\Throwable $e) {
                log_message('error', 'Stato del sistema: conteggio delle righe senza azienda fallito: ' . $e->getMessage());

                return [
                    'key'    => 'company_orphans',
                    'label'  => $label,
                    'state'  => self::WARNING,
                    'detail' => 'Conteggio non eseguibile',
                    'hint'   => 'Il database non risponde o lo schema non è leggibile. Quando torna raggiungibile, `php spark companies:orphans` elenca le righe per-azienda senza company_id.',
                ];
            }
        }

        $totale = (int) ($conteggi['total'] ?? 0);

        if ($totale === 0) {
            return [
                'key'    => 'company_orphans',
                'label'  => $label,
                'state'  => self::OK,
                'detail' => 'Nessuna riga per-azienda con company_id NULL',
                'hint'   => '',
            ];
        }

        $tabelle = array_filter(
            $conteggi['tables'] ?? [],
            static fn (array $c): bool => (int) ($c['nulls'] ?? 0) > 0
        );

        return [
            'key'    => 'company_orphans',
            'label'  => $label,
            'state'  => self::WARNING,
            'detail' => sprintf(
                '%d %s in %d %s senza company_id',
                $totale,
                $totale === 1 ? 'riga' : 'righe',
                count($tabelle),
                count($tabelle) === 1 ? 'tabella' : 'tabelle'
            ),
            'hint'   => 'Finché restano, quelle righe sono visibili a tutte le aziende e finiscono nei loro totali. Esegui `php spark companies:orphans` per l\'elenco e, dopo un backup, `php spark companies:orphans --fix` per assegnarle all\'azienda predefinita.',
        ];
    }

    /**
     * Versioni delle migrazioni non ancora applicate.
     *
     * findMigrations() indicizza i risultati per UID
     * ("20261111120000App\Database\Migrations\EnsureIndexNames"), mentre la
     * storia riporta la versione con i trattini ("2026-11-11-120000").
     * Confrontare le chiavi dell'array con le versioni applicate le faceva
     * risultare tutte da applicare: la pagina diceva "59 migrazioni pendenti"
     * su uno schema aggiornato.
     *
     * @param array<string, object> $available risultato di findMigrations()
     * @param list<string>          $applied   versioni presenti nella storia
     *
     * @return list<string>
     */
    public static function pendingMigrations(array $available, array $applied): array
    {
        $pending = [];

        foreach ($available as $migration) {
            $version = (string) ($migration->version ?? '');

            if ($version !== '' && ! in_array($version, $applied, true)) {
                $pending[] = $version;
            }
        }

        return $pending;
    }

    /**
     * Mette in cima i controlli guasti, poi gli avvisi, poi i regolari.
     *
     * La pagina si apre per trovare le cose da sistemare: con dieci controlli
     * tutti allo stesso livello, un guasto in fondo alla lista si legge solo
     * se si scorre tutto. L'ordinamento è stabile (PHP 8), quindi a parità di
     * stato resta l'ordine in cui i controlli sono stati prodotti.
     *
     * @param list<array{key: string, label: string, state: string, detail: string, hint: string}> $checks
     *
     * @return list<array{key: string, label: string, state: string, detail: string, hint: string}>
     */
    public static function bySeverity(array $checks): array
    {
        $gravita = [
            self::ERROR   => 0,
            self::WARNING => 1,
            self::OK      => 2,
        ];

        usort($checks, static fn (array $a, array $b): int => ($gravita[$a['state']] ?? 3) <=> ($gravita[$b['state']] ?? 3));

        return $checks;
    }

    /**
     * Copia dei file caricati (FilesBackup).
     *
     * Il dump del database non contiene gli XML delle fatture ricevute ne' gli
     * allegati: senza questa copia, perdere il server vuol dire perderli. Un
     * avviso e non un guasto, perche' manca anche su un'installazione appena
     * fatta, prima del primo giro notturno.
     *
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    public function filesBackupCheck(): array
    {
        $label = 'Backup dei file';
        $files = $this->newestFiles($this->backupDir, 'files_*.zip');

        if ($files === []) {
            return [
                'key'    => 'files_backup',
                'label'  => $label,
                'state'  => self::WARNING,
                'detail' => 'Nessuna copia dei file caricati in writable/backups',
                'hint'   => 'Il backup notturno salva anche writable/uploads (XML delle fatture ricevute, allegati, F24). Se il database e\' gia\' salvato e i file no, guarda il log di cron/backup: probabilmente manca l\'estensione PHP zip o lo spazio su disco.',
            ];
        }

        [$file, $mtime] = $files[0];
        $size           = (int) filesize($file);
        $age            = max(0, $this->now - $mtime);
        $vecchio        = $age > self::BACKUP_MAX_AGE;

        return [
            'key'    => 'files_backup',
            'label'  => $label,
            'state'  => $vecchio || $size === 0 ? self::WARNING : self::OK,
            'detail' => basename($file) . ', ' . self::formatBytes($size) . ', ' . self::age($age),
            'hint'   => $vecchio || $size === 0
                ? 'L\'ultima copia dei file e\' vecchia o vuota, mentre il dump del database puo\' essere recente: il backup dei file ha smesso di riuscire. Guarda il log di cron/backup.'
                : '',
        ];
    }

    /**
     * Scadenza del certificato client SDICoop.
     *
     * Con SDICoop si e' autenticati con un certificato che scade. Quando scade
     * l'invio e la ricezione si fermano senza un errore evidente: lo SdI
     * chiude l'handshake TLS e nessuna pagina dell'app se ne accorge; nel
     * forum degli sviluppatori italiani (thread sul rinnovo dei certificati
     * SDICoop) i rinnovi mancati compaiono come causa tipica di una ricezione
     * muta. Il controllo parla solo del certificato che l'app puo'
     * leggere (quello client); quello del server web, che l'app non vede, va
     * tenuto d'occhio a parte.
     *
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    public function sdiCertificateCheck(string $channel, string $certPath): array
    {
        $label = 'Certificato SDICoop';

        if ($channel !== 'sdicoop') {
            return ['key' => 'sdi_cert', 'label' => $label, 'state' => self::OK, 'detail' => 'Canale SDICoop non in uso', 'hint' => ''];
        }

        $hint = 'Con il certificato scaduto invio e ricezione si fermano senza errori visibili. Rinnovalo con largo anticipo e tieni d\'occhio anche quello del server web, che l\'app non puo\' leggere.';

        if ($certPath === '') {
            return ['key' => 'sdi_cert', 'label' => $label, 'state' => self::WARNING, 'detail' => 'Percorso del certificato client non impostato', 'hint' => 'Impostalo in Impostazioni > Fattura elettronica > SDICoop.'];
        }

        if (! is_file($certPath) || ! is_readable($certPath)) {
            return ['key' => 'sdi_cert', 'label' => $label, 'state' => self::ERROR, 'detail' => 'Certificato non leggibile: ' . $certPath, 'hint' => 'Controlla il percorso e i permessi del file: senza, l\'app non puo\' autenticarsi presso lo SdI.'];
        }

        $info = function_exists('openssl_x509_parse') ? @openssl_x509_parse((string) file_get_contents($certPath)) : false;

        if (! is_array($info) || empty($info['validTo_time_t'])) {
            return ['key' => 'sdi_cert', 'label' => $label, 'state' => self::WARNING, 'detail' => 'Non riesco a leggere la scadenza di ' . basename($certPath), 'hint' => 'Il file deve essere un certificato in formato PEM (puo\' contenere anche la chiave).'];
        }

        $scadenza = (int) $info['validTo_time_t'];
        $giorni   = (int) floor(($scadenza - $this->now) / 86400);
        $data     = date('d/m/Y', $scadenza);

        if ($scadenza <= $this->now) {
            return ['key' => 'sdi_cert', 'label' => $label, 'state' => self::ERROR, 'detail' => 'Scaduto il ' . $data, 'hint' => $hint];
        }

        if ($giorni < self::CERT_WARNING_DAYS) {
            return ['key' => 'sdi_cert', 'label' => $label, 'state' => self::WARNING, 'detail' => 'Scade tra ' . $giorni . ($giorni === 1 ? ' giorno' : ' giorni') . ' (' . $data . ')', 'hint' => $hint];
        }

        return ['key' => 'sdi_cert', 'label' => $label, 'state' => self::OK, 'detail' => 'Valido fino al ' . $data . ' (' . $giorni . ' giorni)', 'hint' => ''];
    }

    /**
     * Fatture di acquisto consegnate dallo SdI e non ancora diventate spese.
     *
     * L'endpoint di ricezione scrive il file in incoming/ e solo dopo prova a
     * importarlo, e risponde ER01 comunque: per lo SdI la fattura e' consegnata
     * e non la rimanda piu'. Se l'importazione non riesce (nessuna azienda con
     * quella partita IVA, XML illeggibile, errore del database) l'unica traccia
     * e' una riga nel log e il file su disco, e il file non lo aspetta nessuno.
     * Qui lo si rende visibile.
     *
     * Una spesa importata porta il nome del file in expense_file; per un
     * .p7m il nome e' quello interno e per un lotto ha un suffisso "(fattura
     * 1 di 2)", quindi il confronto e' sul nome senza estensione di busta. Le
     * notifiche (decorrenza termini, metadati) stanno nella stessa cartella ma
     * non sono fatture e si escludono dal nome.
     *
     * @param list<string> $nomiImportati valori di ip_expenses.expense_file
     *
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    public function incomingCheck(array $nomiImportati): array
    {
        $dir   = $this->writablePath . '/uploads/expenses/incoming';
        $label = 'Fatture ricevute dallo SdI';

        if (! is_dir($dir)) {
            return ['key' => 'incoming', 'label' => $label, 'state' => self::OK, 'detail' => 'Nessuna fattura ricevuta dallo SdI', 'hint' => ''];
        }

        $inAttesa = [];

        foreach (scandir($dir) ?: [] as $nome) {
            if ($nome === '.' || $nome === '..' || ! is_file($dir . '/' . $nome)) {
                continue;
            }

            // Metadati (_MT_) e notifiche (_DT_, _NS_, ...): non sono fatture.
            if (preg_match('/_(MT|DT|NS|RC|NE|MC|AT|EC)_\d+\.xml$/i', $nome) === 1) {
                continue;
            }

            $radice = (string) preg_replace('/\.p7m$/i', '', $nome);
            $trovata = false;

            foreach ($nomiImportati as $importato) {
                if ($importato !== '' && str_starts_with($importato, $radice)) {
                    $trovata = true;

                    break;
                }
            }

            if (! $trovata) {
                $inAttesa[] = $nome;
            }
        }

        if ($inAttesa === []) {
            return ['key' => 'incoming', 'label' => $label, 'state' => self::OK, 'detail' => 'Tutte le fatture ricevute sono state importate', 'hint' => ''];
        }

        $elenco = implode(', ', array_slice($inAttesa, 0, 3)) . (count($inAttesa) > 3 ? '…' : '');

        return [
            'key'    => 'incoming',
            'label'  => $label,
            'state'  => self::WARNING,
            'detail' => count($inAttesa) === 1
                ? '1 fattura ricevuta e non importata: ' . $elenco
                : count($inAttesa) . ' fatture ricevute e non importate: ' . $elenco,
            'hint'   => 'Lo SdI le considera consegnate e non le rimanda. Importale da Spese > Importa XML, scegliendo il file in writable/uploads/expenses/incoming; la causa piu\' frequente e\' un committente la cui partita IVA non coincide con nessuna azienda (il log ha la riga "SDIRicezione").',
        ];
    }

    /**
     * File più recente di un pattern, con il suo mtime.
     *
     * @return list<array{0: string, 1: int}> dal più recente
     */
    private function newestFiles(string $dir, string $pattern): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];

        foreach (glob($dir . '/' . $pattern) ?: [] as $file) {
            if (is_file($file)) {
                $files[] = [$file, (int) filemtime($file)];
            }
        }

        usort($files, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

        return $files;
    }

    public static function age(int $seconds): string
    {
        if ($seconds < 60) {
            return 'adesso';
        }

        if ($seconds < 3600) {
            $minuti = intdiv($seconds, 60);

            return $minuti === 1 ? '1 minuto fa' : $minuti . ' minuti fa';
        }

        if ($seconds < 86400) {
            $ore = intdiv($seconds, 3600);

            return $ore === 1 ? '1 ora fa' : $ore . ' ore fa';
        }

        $giorni = intdiv($seconds, 86400);

        return $giorni === 1 ? '1 giorno fa' : $giorni . ' giorni fa';
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' byte';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
