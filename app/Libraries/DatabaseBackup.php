<?php

namespace App\Libraries;

use RuntimeException;

/**
 * Dump del database per il backup periodico.
 *
 * Due motori, due binari: su PostgreSQL `pg_dump` in formato custom (compresso,
 * ripristinabile con pg_restore e con restore selettivo), su MySQL/MariaDB
 * `mysqldump` in SQL. Prima di questa classe l'endpoint lanciava sempre
 * pg_dump: su un'installazione MariaDB falliva parlando di PostgreSQL, un
 * errore che non dice niente su cosa fare.
 *
 * La password passa da una variabile d'ambiente (PGPASSWORD / MYSQL_PWD) e non
 * dalla riga di comando: `ps` la mostrerebbe a chiunque sia loggato sul server.
 *
 * Il dump è una copia di lavoro dentro writable/backups: la rotazione tiene gli
 * ultimi 14, ma va portata fuori dal server.
 */
final class DatabaseBackup
{
    public const ENGINE_POSTGRES = 'postgres';
    public const ENGINE_MYSQL    = 'mysql';

    /** Quanti dump restano in writable/backups per motore. */
    public const KEEP = 14;

    /** @var array<string, mixed> */
    private array $db;

    private string $dir;

    /**
     * @param array<string, mixed> $db          gruppo di connessione di Config\Database
     * @param string|null          $dir         cartella dei dump; default writable/backups
     * @param int                  $serverMajor versione del server PostgreSQL (per scegliere pg_dump)
     */
    public function __construct(array $db, ?string $dir = null, private int $serverMajor = 0)
    {
        $this->db  = $db;
        $this->dir = rtrim($dir ?? WRITEPATH . 'backups', '/');
    }

    /**
     * Motore di backup per un DBDriver di CodeIgniter, o null se non c'è un
     * binario sensato per quel motore (SQLite, per esempio).
     */
    public static function engineFor(string $driver): ?string
    {
        return match (strtolower(trim($driver))) {
            'postgre', 'postgres', 'postgresql' => self::ENGINE_POSTGRES,
            'mysqli', 'mysql', 'mariadb'         => self::ENGINE_MYSQL,
            default                              => null,
        };
    }

    /**
     * Esegue il dump e restituisce il file prodotto.
     *
     * @return array{file: string, bytes: int, removed: int}
     *
     * @throws RuntimeException con il messaggio del binario, da mostrare in chiaro
     */
    public function dump(): array
    {
        $driver = (string) ($this->db['DBDriver'] ?? '');
        $engine = self::engineFor($driver);

        if ($engine === null) {
            throw new RuntimeException('Backup non previsto per il motore "' . $driver . '": sono supportati PostgreSQL e MySQL/MariaDB');
        }

        if (! function_exists('exec')) {
            throw new RuntimeException('exec() non disponibile: backup impossibile su questo hosting');
        }

        if (! is_dir($this->dir) && ! mkdir($this->dir, 0775, true) && ! is_dir($this->dir)) {
            throw new RuntimeException('Cartella dei backup non creabile: ' . $this->dir);
        }

        $binary = $this->binary($engine);
        $file   = $this->dir . '/backup_' . date('Ymd_His') . ($engine === self::ENGINE_POSTGRES ? '.dump' : '.sql');
        $env    = $this->command($engine, $binary, $file);

        // La variabile d'ambiente vale solo per questo processo figlio, ma
        // sopravvive a exec(): va tolta subito dopo.
        putenv($env['name'] . '=' . (string) ($this->db['password'] ?? ''));

        $output = [];
        exec($env['command'] . ' 2>&1', $output, $code);

        putenv($env['name']);

        clearstatcache(true, $file);

        if ($code !== 0 || ! is_file($file) || filesize($file) === 0) {
            // Il file vuoto va tolto: la rotazione conta i file, e due
            // settimane di dump falliti spingerebbero fuori quelli buoni
            // lasciando 14 file da 0 byte.
            if (is_file($file)) {
                @unlink($file);
            }

            $messaggio = trim(implode(' ', $output));

            throw new RuntimeException($messaggio !== '' ? mb_substr($messaggio, 0, 300) : 'nessun output dal comando, file non prodotto');
        }

        $removed = self::rotate($this->dir, 'backup_', $engine === self::ENGINE_POSTGRES ? '.dump' : '.sql');

        return ['file' => $file, 'bytes' => (int) filesize($file), 'removed' => $removed];
    }

    /**
     * Comando completo (già con gli argomenti quotati) e nome della variabile
     * d'ambiente per la password.
     *
     * Separato da dump() per poterlo controllare senza eseguirlo: gli
     * argomenti sono la parte in cui un errore si vede solo al primo backup
     * vero.
     *
     * @return array{name: string, command: string}
     */
    public function command(string $engine, string $binary, string $file): array
    {
        $host = (string) ($this->db['hostname'] ?? 'localhost');
        $port = (int) ($this->db['port'] ?? ($engine === self::ENGINE_POSTGRES ? 5432 : 3306));
        $user = (string) ($this->db['username'] ?? '');
        $name = (string) ($this->db['database'] ?? '');

        if ($engine === self::ENGINE_POSTGRES) {
            $args = [
                '-Fc', '--no-owner', '--no-acl',
                '-h', $host,
                '-p', (string) $port,
            ];

            // Con username vuoto la connessione usa l'utente di sistema:
            // passare `-U ''` è diverso e su alcune configurazioni non funziona.
            if ($user !== '') {
                $args[] = '-U';
                $args[] = $user;
            }

            $args[] = '-f';
            $args[] = $file;
            $args[] = $name;
            $envName = 'PGPASSWORD';
        } else {
            // --single-transaction: dump coerente su InnoDB senza bloccare le
            // scritture; --quick evita di caricare in memoria una tabella
            // intera; --no-tablespaces evita di chiedere privilegi sul
            // tablespace che l'utente dell'applicazione non ha.
            $args = [
                '--single-transaction', '--quick', '--triggers', '--routines',
                '--no-tablespaces', '--default-character-set=utf8mb4',
                '--host=' . $host,
                '--port=' . $port,
            ];

            if ($user !== '') {
                $args[] = '--user=' . $user;
            }

            $args[] = '--result-file=' . $file;
            $args[] = $name;
            $envName = 'MYSQL_PWD';
        }

        $quoted = array_map(static fn (string $arg): string => escapeshellarg($arg), $args);

        return [
            'name'    => $envName,
            'command' => escapeshellarg($binary) . ' ' . implode(' ', $quoted),
        ];
    }

    /**
     * Tiene gli ultimi $keep dump di quel formato, ordinando per nome: i nomi
     * contengono il timestamp, quindi l'ordine alfabetico è cronologico.
     *
     * L'estensione fa parte del filtro: su un'installazione che ha cambiato
     * motore, i vecchi .dump non vengono contati come dump MySQL né viceversa.
     *
     * @return int numero di file eliminati
     */
    public static function rotate(string $dir, string $prefix = 'backup_', string $extension = '.dump', int $keep = self::KEEP): int
    {
        $files = glob($dir . '/' . $prefix . '*' . $extension) ?: [];
        sort($files, SORT_STRING);

        $removed = 0;

        foreach (array_slice($files, 0, max(0, count($files) - $keep)) as $old) {
            @unlink($old);
            $removed++;
        }

        return $removed;
    }

    /**
     * @throws RuntimeException se il binario non c'è o non è utilizzabile
     */
    private function binary(string $engine): string
    {
        if ($engine === self::ENGINE_POSTGRES) {
            $bin = (string) (new PgDumpLocator(
                trim((string) env('backup.pgDumpPath', '')) ?: null
            ))->locate($this->serverMajor);

            if ($bin === '') {
                throw new RuntimeException('pg_dump non disponibile sul server (serve la versione ' . $this->serverMajor . ' o successiva)');
            }

            return $bin;
        }

        $configured = trim((string) env('backup.mysqldumpPath', ''));

        if ($configured !== '' && is_executable($configured)) {
            return $configured;
        }

        $which = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));

        if ($which === '') {
            throw new RuntimeException('mysqldump non disponibile sul server');
        }

        return $which;
    }
}
