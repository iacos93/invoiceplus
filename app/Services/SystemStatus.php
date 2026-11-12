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
            $this->restoreCheck(),
            $this->cronCheck(),
            $this->environmentCheck(),
            $this->diskCheck(),
            $this->logCheck(),
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
            return [
                'key'    => 'restore',
                'label'  => 'Verifica del ripristino',
                'state'  => self::ERROR,
                'detail' => 'Ultima verifica fallita ' . self::age($eta) . ': '
                    . mb_substr((string) ($dati['error'] ?? 'motivo non registrato'), 0, 160),
                'hint'   => 'Il dump non si ripristina: non considerarlo un backup. Guarda l\'esito di `php spark backup:verify` e che pg_restore (o mysql) sia installato.',
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

        $errori = 0;

        foreach (explode("\n", $this->tail($file, 200 * 1024)) as $riga) {
            if (preg_match('/^(ERROR|CRITICAL)\b/', $riga) === 1) {
                $errori++;
            }
        }

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
            'detail' => $errori . ($errori === 1 ? ' voce' : ' voci') . ' ERROR/CRITICAL in ' . basename($file),
            'hint'   => 'Apri writable/logs/' . basename($file) . ': un errore singolo può essere un caso, uno ripetuto è un guasto che sta lavorando in silenzio.',
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
     * Ultimi $bytes di un file, senza caricarlo tutto in memoria: i log
     * possono essere grandi e qui interessa solo la coda.
     */
    private function tail(string $file, int $bytes): string
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            return '';
        }

        $size = (int) @filesize($file);

        if ($size > $bytes) {
            fseek($handle, $size - $bytes);
        }

        $contenuto = (string) stream_get_contents($handle);
        fclose($handle);

        return $contenuto;
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
