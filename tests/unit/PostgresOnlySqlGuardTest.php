<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Funzioni SQL che esistono solo su PostgreSQL non devono comparire nel
 * codice dell'applicazione fuori da un ramo che controlla il driver.
 *
 * La suite unit gira su SQLite e la CI su PostgreSQL e MariaDB, ma solo con
 * le pagine coperte dai test: la prima installazione vera su MySQL ha aperto
 * la Bacheca con un 500 (TO_CHAR nelle serie mensili) e il cron delle
 * ricorrenti con un altro (pg_try_advisory_lock). Il controllo è testuale
 * apposta: trova la query anche in un metodo che nessun test esegue.
 *
 * Un file può usarle solo se nomina il driver 'Postgre' nello stesso file,
 * cioè se ha il ramo per l'altro motore accanto.
 */
final class PostgresOnlySqlGuardTest extends CIUnitTestCase
{
    private const SCAN_DIRS = ['Controllers', 'Models', 'Services', 'Libraries', 'Helpers', 'Commands', 'Database'];

    /** Costrutti che MySQL/MariaDB rifiutano. */
    private const PATTERNS = [
        '/\bTO_CHAR\s*\(/i'            => 'TO_CHAR (usa sql_year_month() o un ramo sul driver)',
        // Solo le funzioni SQL, non i nomi dei programmi (pg_dump, pg_restore)
        // che compaiono nei suggerimenti della pagina Stato del sistema.
        '/\bpg_(try_)?advisory_[a-z_]+\s*\(/' => 'advisory lock PostgreSQL',
        '/\bhashtext\s*\(/i'           => 'hashtext()',
        '/::(bigint|int|integer|text|date|numeric)\b/i' => 'cast ::tipo',
        '/\bILIKE\b/'                  => 'ILIKE',
    ];

    public function testNoPostgresOnlySqlOutsideDriverBranches(): void
    {
        $violations = [];

        foreach (self::SCAN_DIRS as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(APPPATH . $dir, \FilesystemIterator::SKIP_DOTS));

            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                if (str_contains($source, "'Postgre'")) {
                    continue;
                }

                foreach (explode("\n", $source) as $n => $line) {
                    $trim = ltrim($line);

                    // I commenti spiegano spesso proprio perché TO_CHAR non va.
                    if (str_starts_with($trim, '//') || str_starts_with($trim, '*') || str_starts_with($trim, '/*')) {
                        continue;
                    }

                    foreach (self::PATTERNS as $pattern => $what) {
                        if (preg_match($pattern, $line) === 1) {
                            $violations[] = str_replace(APPPATH, 'app/', $file->getPathname()) . ':' . ($n + 1) . ' — ' . $what;
                        }
                    }
                }
            }
        }

        $this->assertSame([], $violations, "SQL solo PostgreSQL senza ramo per MySQL:\n" . implode("\n", $violations));
    }
}
