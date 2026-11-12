<?php

namespace App\Commands;

use App\Libraries\RestoreVerifier;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Prova il ripristino dell'ultimo backup.
 *
 * Il backup automatico dice che il file è stato prodotto; questo comando dice
 * se il file si ripristina. Carica il dump in un database temporaneo, confronta
 * i conteggi delle tabelle e elimina la copia. L'esito viene scritto in
 * writable/cron/backup_verify.json, dove la pagina "Stato del sistema" lo
 * legge.
 *
 * Serve un utente del database con permesso di creare database (CREATEDB su
 * PostgreSQL, CREATE su MySQL/MariaDB). Se manca, il comando lo dice e non
 * tocca niente.
 */
class BackupVerify extends BaseCommand
{
    protected $group       = 'backup';
    protected $name        = 'backup:verify';
    protected $description = 'Ripristina l\'ultimo dump in un database temporaneo e confronta i conteggi.';
    protected $usage       = 'backup:verify [--file <percorso>] [--group <gruppo>]';
    protected $arguments   = [];
    protected $options     = [
        '--file'  => 'Dump da provare (default: il più recente in writable/backups)',
        '--group' => 'Gruppo di connessione di Config\\Database (default: quello attivo)',
    ];

    public function run(array $params)
    {
        $cfg   = config('Database');
        $group = (string) (CLI::getOption('group') ?? $cfg->defaultGroup);
        $db    = $cfg->{$group} ?? null;

        if (! is_array($db)) {
            CLI::error('Gruppo di database sconosciuto: ' . $group);

            return EXIT_ERROR;
        }

        $file = $this->file(CLI::getOption('file'));

        if ($file === null) {
            CLI::error('Nessun dump da provare in ' . WRITEPATH . 'backups: lancia prima cron/backup o passa --file.');

            return EXIT_ERROR;
        }

        $serverMajor = 0;

        try {
            $serverMajor = (int) \Config\Database::connect($db, false)->getVersion();
        } catch (\Throwable $e) {
            CLI::write('Versione del server non leggibile: ' . $e->getMessage(), 'yellow');
        }

        CLI::write('Provo il ripristino di ' . basename($file) . ' (' . self::size((int) filesize($file)) . ')…');

        $esito = (new RestoreVerifier($db, $file, $serverMajor))->verify();

        $this->writeMarker($file, $esito);

        foreach ($esito['mismatches'] as $differenza) {
            CLI::write('  ! ' . $differenza, 'red');
        }

        if (! $esito['ok']) {
            CLI::error('Verifica fallita: ' . $esito['error']);

            return EXIT_ERROR;
        }

        CLI::write(
            'Ripristino riuscito: ' . $esito['tables'] . ' tabelle confrontate con gli stessi conteggi.',
            'green'
        );

        return EXIT_SUCCESS;
    }

    /**
     * L'ultimo dump prodotto, o quello indicato con --file.
     */
    private function file(?string $indicato): ?string
    {
        if ($indicato !== null && $indicato !== '') {
            return is_file($indicato) ? $indicato : null;
        }

        $files = array_merge(
            glob(WRITEPATH . 'backups/backup_*.dump') ?: [],
            glob(WRITEPATH . 'backups/backup_*.sql') ?: [],
        );

        if ($files === []) {
            return null;
        }

        usort($files, static fn (string $a, string $b): int => (int) filemtime($b) <=> (int) filemtime($a));

        return $files[0];
    }

    /**
     * @param array{ok: bool, tables: int, mismatches: list<string>, error: string, scratch: string} $esito
     */
    private function writeMarker(string $file, array $esito): void
    {
        $dir = WRITEPATH . 'cron';

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $dati = [
            'time'       => time(),
            'file'       => basename($file),
            'tables'     => $esito['tables'],
            'ok'         => $esito['ok'],
            'error'      => $esito['error'],
            'mismatches' => array_slice($esito['mismatches'], 0, 5),
            'scratch'    => $esito['scratch'],
        ];

        @file_put_contents($dir . '/backup_verify.json', json_encode($dati, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private static function size(int $bytes): string
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
