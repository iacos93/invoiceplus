<?php

namespace App\Libraries;

/**
 * Sceglie il pg_dump capace di leggere il server in uso.
 *
 * pg_dump rifiuta di leggere un server con versione maggiore della propria, e
 * il primo binario nel PATH non è sempre quello giusto: su macOS Homebrew
 * lascia `postgresql@16` e `postgresql@17` installati insieme e il collegamento
 * in `/opt/homebrew/bin` può puntare al vecchio. Il risultato non è un errore
 * evidente: il backup semplicemente non viene prodotto, e ce ne si accorge
 * quando serve.
 *
 * Qui i candidati si raccolgono dai percorsi abituali e si sceglie il primo con
 * versione almeno pari a quella del server (il maggiore). Se nessuno basta si
 * restituisce comunque il più recente trovato: fallirà con un messaggio chiaro
 * invece di non trovare nulla.
 */
class PgDumpLocator
{
    /** @var list<string> */
    private array $candidates;

    /**
     * @param string|null       $configured percorso indicato in .env (backup.pgDumpPath)
     * @param list<string>|null $candidates percorsi alternativi; null = quelli di sistema
     */
    public function __construct(?string $configured = null, ?array $candidates = null)
    {
        $this->candidates = self::uniquePaths(array_merge(
            [$configured],
            $candidates ?? self::defaultCandidates(),
        ));
    }

    /**
     * Percorsi in cui le installazioni mettono pg_dump, dal più al meno
     * probabile. I glob coprono le versioni affiancate (postgresql@16,
     * postgresql@17, /usr/lib/postgresql/17/bin) senza sapere in anticipo
     * quale sia installata.
     *
     * @return list<string>
     */
    public static function defaultCandidates(): array
    {
        $paths = [];

        $which = trim((string) shell_exec('command -v pg_dump 2>/dev/null'));
        if ($which !== '') {
            $paths[] = $which;
        }

        $patterns = [
            '/opt/homebrew/opt/postgresql@*/bin/pg_dump',
            '/usr/local/opt/postgresql@*/bin/pg_dump',
            '/usr/lib/postgresql/*/bin/pg_dump',
            '/usr/pgsql-*/bin/pg_dump',
            '/Applications/Postgres.app/Contents/Versions/*/bin/pg_dump',
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Restituisce il binario da usare, o null se non ne esiste nessuno.
     *
     * @param int $serverMajor versione maggiore del server (17 per 17.11)
     */
    public function locate(int $serverMajor): ?string
    {
        $found = [];

        foreach ($this->candidates as $bin) {
            if (! is_file($bin) || ! is_executable($bin)) {
                continue;
            }

            $major = self::parseVersion((string) shell_exec(escapeshellarg($bin) . ' --version 2>/dev/null'));

            if ($major !== null) {
                $found[$bin] = $major;
            }
        }

        return self::pick($found, $serverMajor);
    }

    /**
     * Sceglie fra i binari disponibili quello da usare.
     *
     * Preferisce il primo con versione almeno pari al server; altrimenti
     * restituisce il più recente, che è quello con più probabilità di
     * funzionare e in ogni caso produce un errore comprensibile.
     *
     * @param array<string, int> $found percorso => versione maggiore
     */
    public static function pick(array $found, int $serverMajor): ?string
    {
        $best         = null;
        $bestMajor    = -1;

        foreach ($found as $bin => $major) {
            if ($major >= $serverMajor) {
                return $bin;
            }

            if ($major > $bestMajor) {
                $best      = $bin;
                $bestMajor = $major;
            }
        }

        return $best;
    }

    /**
     * "pg_dump (PostgreSQL) 17.11 (Homebrew)" → 17.
     */
    public static function parseVersion(string $output): ?int
    {
        if (preg_match('/pg_dump\D*(\d+)(?:\.\d+)?/', $output, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * @param list<string|null> $paths
     *
     * @return list<string>
     */
    private static function uniquePaths(array $paths): array
    {
        $result = [];
        $seen   = [];

        foreach ($paths as $path) {
            $path = trim((string) $path);

            if ($path === '' || isset($seen[$path])) {
                continue;
            }

            $seen[$path] = true;
            $result[]    = $path;
        }

        return $result;
    }
}
