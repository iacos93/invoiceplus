<?php

namespace App\Libraries;

use Config\Database;
use RuntimeException;

/**
 * Prova che un dump si ripristini davvero.
 *
 * Il backup risponde a una domanda sola ("il file esiste?"), che non è quella
 * che conta. Un dump può essere prodotto ogni giorno e non essere
 * ripristinabile: file troncato, binario di versione sbagliata, tabella che
 * manca. Qui il dump viene caricato in un database temporaneo e i conteggi
 * delle tabelle vengono confrontati con quelli del database di partenza.
 *
 * Il database temporaneo si chiama `<database>_verify_<timestamp>` e viene
 * eliminato sempre, anche quando il ripristino fallisce. Servono permessi di
 * creazione database (CREATEDB su PostgreSQL, CREATE su MySQL): se mancano, il
 * messaggio lo dice invece di lasciare un file a metà.
 */
final class RestoreVerifier
{
    /** Tabelle confrontate, quando esistono in entrambi i database. */
    public const TABLES = [
        'ip_clients',
        'ip_invoices',
        'ip_invoice_items',
        'ip_invoice_amounts',
        'ip_quotes',
        'ip_quote_items',
        'ip_quote_amounts',
        'ip_payments',
        'ip_expenses',
        'ip_projects',
        'ip_users',
        'ip_settings',
    ];

    /**
     * @param array<string, mixed> $db          gruppo di connessione di Config\Database
     * @param string               $file        dump da provare
     * @param int                  $serverMajor versione del server PostgreSQL, per scegliere i binari
     */
    public function __construct(
        private array $db,
        private string $file,
        private int $serverMajor = 0,
    ) {}

    /**
     * Nome del database di prova: solo caratteri validi per entrambi i motori
     * e non oltre il limite di PostgreSQL (63 byte).
     */
    public static function scratchName(string $database, string $timestamp): string
    {
        $base = preg_replace('/[^A-Za-z0-9_]/', '_', $database) ?: 'db';

        if (ctype_digit($base[0])) {
            $base = 'db_' . $base;
        }

        $suffix = '_verify_' . preg_replace('/[^0-9]/', '', $timestamp);
        $base   = substr($base, 0, max(1, 63 - strlen($suffix)));

        return $base . $suffix;
    }

    /**
     * @return array{ok: bool, tables: int, mismatches: list<string>, error: string, scratch: string}
     */
    public function verify(): array
    {
        $engine = DatabaseBackup::engineFor((string) ($this->db['DBDriver'] ?? ''));

        if ($engine === null) {
            return $this->result(false, 0, [], 'Motore non supportato: la verifica esiste per PostgreSQL e MySQL/MariaDB', '');
        }

        if (! is_file($this->file) || filesize($this->file) === 0) {
            return $this->result(false, 0, [], 'Dump assente o vuoto: ' . $this->file, '');
        }

        $scratch = self::scratchName((string) ($this->db['database'] ?? 'db'), date('YmdHis'));
        $creato  = false;
        $drop    = null;

        try {
            $comandi = $this->commands($engine, $scratch);
            $drop    = $comandi['drop'];

            $esito = $this->run($comandi['create']);

            if ($esito['code'] !== 0) {
                return $this->result(false, 0, [], 'Creazione del database temporaneo fallita: ' . $esito['output'], $scratch);
            }

            $creato = true;

            $esito = $this->run($comandi['restore']);

            if ($esito['code'] !== 0) {
                return $this->result(false, 0, [], 'Ripristino fallito: ' . $esito['output'], $scratch);
            }

            [$tabelle, $differenze] = $this->compare($scratch);

            return $this->result($differenze === [] && $tabelle > 0, $tabelle, $differenze, $tabelle === 0 ? 'Nessuna tabella confrontabile nel ripristino' : '', $scratch);
        } catch (\Throwable $e) {
            return $this->result(false, 0, [], $e->getMessage(), $scratch);
        } finally {
            if ($creato && $drop !== null) {
                $this->run($drop);
            }
        }
    }

    /**
     * Comandi di creazione, ripristino ed eliminazione del database di prova.
     *
     * @return array{create: array{name: string, command: string}, restore: array{name: string, command: string}, drop: array{name: string, command: string}}
     */
    public function commands(string $engine, string $scratch): array
    {
        $host = (string) ($this->db['hostname'] ?? 'localhost');
        $port = (string) (int) ($this->db['port'] ?? ($engine === DatabaseBackup::ENGINE_POSTGRES ? 5432 : 3306));
        $user = (string) ($this->db['username'] ?? '');
        $env  = $engine === DatabaseBackup::ENGINE_POSTGRES ? 'PGPASSWORD' : 'MYSQL_PWD';

        if ($engine === DatabaseBackup::ENGINE_POSTGRES) {
            $createdb  = $this->binary('createdb');
            $pgRestore = $this->binary('pg_restore');
            $dropdb    = $this->binary('dropdb');

            $connessione = ['-h', $host, '-p', $port];

            if ($user !== '') {
                $connessione[] = '-U';
                $connessione[] = $user;
            }

            return [
                'create' => [
                    'name'    => $env,
                    'command' => $this->line($createdb, array_merge($connessione, [$scratch])),
                ],
                'restore' => [
                    'name'    => $env,
                    'command' => $this->line($pgRestore, array_merge(
                        ['--no-owner', '--no-acl', '--exit-on-error'],
                        $connessione,
                        ['-d', $scratch, $this->file],
                    )),
                ],
                'drop' => [
                    'name'    => $env,
                    'command' => $this->line($dropdb, array_merge(['--if-exists'], $connessione, [$scratch])),
                ],
            ];
        }

        $mysql = $this->binary('mysql');

        $connessione = ['--host=' . $host, '--port=' . $port];

        if ($user !== '') {
            $connessione[] = '--user=' . $user;
        }

        return [
            'create' => [
                'name'    => $env,
                'command' => $this->line($mysql, array_merge($connessione, [
                    '--execute=CREATE DATABASE `' . $scratch . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                ])),
            ],
            'restore' => [
                'name'    => $env,
                'command' => $this->line($mysql, array_merge($connessione, [$scratch]))
                    . ' < ' . escapeshellarg($this->file),
            ],
            'drop' => [
                'name'    => $env,
                'command' => $this->line($mysql, array_merge($connessione, [
                    '--execute=DROP DATABASE IF EXISTS `' . $scratch . '`',
                ])),
            ],
        ];
    }

    /**
     * Confronta i conteggi fra database di partenza e copia ripristinata.
     *
     * @return array{0: int, 1: list<string>} tabelle confrontate, differenze
     */
    private function compare(string $scratch): array
    {
        $origine = Database::connect($this->db, false);
        $copia   = null;

        try {
            $copia = Database::connect(['database' => $scratch] + $this->db, false);

            $confrontate = 0;
            $differenze  = [];

            foreach (self::TABLES as $tabella) {
                $inOrigine = $origine->tableExists($tabella);
                $inCopia   = $copia->tableExists($tabella);

                if (! $inOrigine && ! $inCopia) {
                    continue;
                }

                if ($inOrigine && ! $inCopia) {
                    $differenze[] = $tabella . ': presente nel database, assente nel ripristino';
                    continue;
                }

                $atteso = (int) $origine->table($tabella)->countAllResults();
                $trovato = (int) $copia->table($tabella)->countAllResults();

                if ($atteso !== $trovato) {
                    $differenze[] = $tabella . ': ' . $atteso . ' righe nel database, ' . $trovato . ' nel ripristino';
                }

                if ($tabella === 'ip_invoice_amounts') {
                    $totaleOrigine = round((float) ($origine->table($tabella)->selectSum('invoice_total')->get()->getRow()->invoice_total ?? 0), 2);
                    $totaleCopia   = round((float) ($copia->table($tabella)->selectSum('invoice_total')->get()->getRow()->invoice_total ?? 0), 2);

                    if ($totaleOrigine !== $totaleCopia) {
                        $differenze[] = $tabella . ': totale fatturato diverso fra database e ripristino';
                    }
                }

                $confrontate++;
            }

            return [$confrontate, $differenze];
        } finally {
            $origine->close();

            if ($copia !== null) {
                $copia->close();
            }
        }
    }

    /**
     * Esegue un comando con la password nell'ambiente del figlio, mai sulla
     * riga di comando.
     *
     * @param array{name: string, command: string} $comando
     *
     * @return array{code: int, output: string}
     */
    private function run(array $comando): array
    {
        putenv($comando['name'] . '=' . (string) ($this->db['password'] ?? ''));

        $output = [];
        exec($comando['command'] . ' 2>&1', $output, $code);

        putenv($comando['name']);

        return ['code' => $code, 'output' => trim(implode("\n", $output))];
    }

    /**
     * @param list<string> $args
     */
    private function line(string $binary, array $args): string
    {
        $quoted = array_map(static fn (string $arg): string => escapeshellarg($arg), $args);

        return escapeshellarg($binary) . ' ' . implode(' ', $quoted);
    }

    /**
     * @throws RuntimeException se il binario non c'è
     */
    private function binary(string $name): string
    {
        if (in_array($name, ['createdb', 'dropdb', 'pg_restore'], true)) {
            $pgDump = (string) (new PgDumpLocator(
                trim((string) env('backup.pgDumpPath', '')) ?: null
            ))->locate($this->serverMajor);

            if ($pgDump !== '') {
                $candidato = dirname($pgDump) . '/' . $name;

                if (is_executable($candidato)) {
                    return $candidato;
                }
            }
        }

        if ($name === 'mysql' || $name === 'mysqldump') {
            $mysqldump = trim((string) env('backup.mysqldumpPath', ''));

            if ($mysqldump !== '' && is_executable(dirname($mysqldump) . '/mysql')) {
                return dirname($mysqldump) . '/mysql';
            }
        }

        $trovato = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));

        if ($trovato === '') {
            throw new RuntimeException($name . ' non disponibile sul server: serve per provare il ripristino');
        }

        return $trovato;
    }

    /**
     * @param list<string> $differenze
     *
     * @return array{ok: bool, tables: int, mismatches: list<string>, error: string, scratch: string}
     */
    private function result(bool $ok, int $tables, array $differenze, string $error, string $scratch): array
    {
        return [
            'ok'         => $ok,
            'tables'     => $tables,
            'mismatches' => $differenze,
            'error'      => mb_substr($error, 0, 300),
            'scratch'    => $scratch,
        ];
    }
}
