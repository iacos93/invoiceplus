<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Le colonne BOOLEAN vanno lette con db_bool(), non con empty()/!empty() né
 * con un cast.
 *
 * PostgreSQL restituisce i booleani come stringa "t"/"f" tramite
 * pg_fetch_assoc(): "f" è una stringa non vuota, quindi !empty("f") è true e
 * empty("f") è false; (int) "t" vale 0. Su SQLite (la suite unit) e su MySQL
 * il valore è 0/1 e il baco non si vede — si manifesta solo in produzione con
 * il driver Postgre. Per questo la verifica è testuale: nessun test HTTP con
 * SQLite lo coprirebbe.
 *
 * Le colonne sono ricavate dalle migrazioni, non da un elenco a mano: una
 * nuova colonna BOOLEAN entra nel guard da sola.
 */
final class BooleanColumnReadGuardTest extends CIUnitTestCase
{
    /**
     * Variabili che contengono il payload del form (o dei parametri MCP), non
     * una riga letta dal database: lì empty()/!empty() è legittimo.
     */
    private const PAYLOAD_VARS = ['post', 'dbArray', 'fields', 'p', 'params', 'settings'];

    /** Cartelle dell'applicazione in cui si legge una riga dal database. */
    private const SCAN_DIRS = [
        'Views',
        'Controllers',
        'Models',
        'Services',
        'Libraries',
        'Helpers',
        'Commands',
        // Le migrazioni leggono righe vere (backfill, colmi di importazione):
        // un flag letto lì col valore sbagliato scrive dati sbagliati.
        'Database',
    ];

    public function testLeColonneBooleaneSiLeggonoSoloConDbBool(): void
    {
        $columns = $this->booleanColumns();

        // Se il parser delle migrazioni si rompe, il test non deve passare a
        // vuoto: queste tre colonne esistono di sicuro.
        $this->assertContains('bank_account_is_default', $columns);
        $this->assertContains('company_forfettario_startup', $columns);
        $this->assertContains('is_reconciled', $columns);

        $offese = [];

        foreach ($this->phpFiles() as $file) {
            $source = (string) file_get_contents($file);

            foreach ($columns as $column) {
                foreach ($this->badReads($source, $column) as $riga => $testo) {
                    $offese[] = sprintf(
                        '%s:%d — %s (colonna %s)',
                        str_replace(APPPATH, 'app/', $file),
                        $riga,
                        trim($testo),
                        $column
                    );
                }
            }
        }

        $this->assertSame([], $offese, "Colonne BOOLEAN lette senza db_bool():\n" . implode("\n", $offese));
    }

    public function testIlGuardRiconosceUnaLetturaSbagliata(): void
    {
        $colonna = 'bank_account_is_default';

        $this->assertNotSame([], $this->badReads(
            '<?php if (!empty($row[\'bank_account_is_default\'])) { echo 1; }',
            $colonna
        ));
        $this->assertNotSame([], $this->badReads(
            '<?php $x = (int) $row["bank_account_is_default"];',
            $colonna
        ));
        $this->assertNotSame([], $this->badReads(
            '<?php if ($row[\'bank_account_is_default\']) { echo 1; }',
            $colonna
        ));
        $this->assertNotSame([], $this->badReads(
            '<?php $x = (int) $model->formValue(\'bank_account_is_default\');',
            $colonna
        ));
        $this->assertNotSame([], $this->badReads(
            '<?php $x = $v(\'bank_account_is_default\') ? 1 : 0;',
            $colonna
        ));
        // Confronto con la stringa "1": su PostgreSQL il valore è "t", quindi
        // è già il baco, non un caso accettabile.
        $this->assertNotSame([], $this->badReads(
            '<?php $x = $row[\'bank_account_is_default\'] == \'1\';',
            $colonna
        ));
        // Assegnazione nuda: la colonna sparisce dalla riga che la usa, e
        // nessun altro pattern la vedrebbe più.
        $this->assertNotSame([], $this->badReads(
            '<?php $alias = $row[\'bank_account_is_default\'];',
            $colonna
        ));
        // Il percorso giusto: db_bool() in assegnazione non va segnalato.
        $this->assertSame([], $this->badReads(
            '<?php $alias = db_bool($row[\'bank_account_is_default\']);',
            $colonna
        ));
        // Il payload del form non è una lettura dal database: anche assegnato
        // resta legittimo.
        $this->assertSame([], $this->badReads(
            '<?php $x = $dbArray[\'bank_account_is_default\'] ?? false;',
            $colonna
        ));
        $this->assertSame([], $this->badReads(
            '<?php if (db_bool($row[\'bank_account_is_default\'])) { echo 1; }',
            $colonna
        ));
        // Il payload del form non è una lettura dal database.
        $this->assertSame([], $this->badReads(
            '<?php $x = !empty($post[\'bank_account_is_default\']);',
            $colonna
        ));
    }

    /**
     * @return list<string>
     */
    private function booleanColumns(): array
    {
        $columns = [];

        foreach (glob(APPPATH . 'Database/Migrations/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);

            // Forma inline: 'colonna' => ['type' => 'BOOLEAN', ...]
            preg_match_all(
                "/'([a-z0-9_]+)'\s*=>\s*\[[^\[\]]*?'type'\s*=>\s*'BOOLEAN'/is",
                $source,
                $inline
            );

            foreach ($inline[1] as $column) {
                $columns[$column] = true;
            }

            // Forma con variabile: $field = [... 'type' => 'BOOLEAN' ...];
            // addColumn('tabella', ['colonna' => $field])
            preg_match_all(
                "/\\\$([a-z_][a-z0-9_]*)\s*=\s*\[[^\[\]]*?'type'\s*=>\s*'BOOLEAN'/is",
                $source,
                $variabili
            );

            foreach (array_unique($variabili[1]) as $variabile) {
                preg_match_all(
                    "/'([a-z0-9_]+)'\s*=>\s*\\\$" . preg_quote($variabile, '/') . '\\b/',
                    $source,
                    $campi
                );

                foreach ($campi[1] as $column) {
                    $columns[$column] = true;
                }
            }
        }

        $columns = array_keys($columns);
        sort($columns);

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $files = [];

        foreach (self::SCAN_DIRS as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(APPPATH . $dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Righe (numero => testo) in cui la colonna viene letta senza db_bool().
     *
     * @return array<int, string>
     */
    private function badReads(string $source, string $column): array
    {
        $col = preg_quote($column, '/');

        $patterns = [
            // empty() / !empty()
            "/(?:empty|!empty)\s*\(\s*\\\$([A-Za-z_][A-Za-z0-9_]*)\s*\[\s*['\"]{$col}['\"]\s*\]/",
            // cast a int/bool/string/float
            "/\(\s*(?:int|bool|string|float)\s*\)\s*\\\$([A-Za-z_][A-Za-z0-9_]*)\s*\[\s*['\"]{$col}['\"]\s*\]/",
            // if ($row['col']) / if (!$row['col'])
            "/\b(?:if|elseif|while)\s*\(\s*!?\s*\\\$([A-Za-z_][A-Za-z0-9_]*)\s*\[\s*['\"]{$col}['\"]\s*\]\s*\)/",
            // $row['col'] ? ... (ma non l'operatore ?? di un db_bool)
            "/\\\$([A-Za-z_][A-Za-z0-9_]*)\s*\[\s*['\"]{$col}['\"]\s*\]\s*\?(?!\?)/",
            // confronto esplicito con true/false/1/0/'t'/'f', compresi gli
            // apici: su PostgreSQL il valore è proprio la stringa "t", quindi
            // $row['col'] == '1' è il confronto sbagliato per costruzione
            "/\\\$([A-Za-z_][A-Za-z0-9_]*)\s*\[\s*['\"]{$col}['\"]\s*\]\s*[!=]==?\s*(?:true|false|(?:['\"])?[01tf](?:['\"])?)/i",
            // assegnazione nuda e diretta: $alias = $row['colonna']; il valore
            // finisce in una variabile e lì l'uso difettoso non è più
            // riconoscibile dal nome della colonna — da qui in poi la riga
            // colpevole non la vedrebbe nessun pattern (es. $mfaEnabled =
            // $user['user_mfa_enabled']). Solo la forma con l'array subito
            // dopo "=": un db_bool(...) in mezzo a un espressione complessa
            // è già il percorso giusto.
            "/\\\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\\\$([A-Za-z_][A-Za-z0-9_]*)\s*\[\s*['\"]{$col}['\"]\s*\]/",
            // in_array()/array_search() su un membro con la colonna
            "/\b(?:in_array|array_search)\s*\(\s*\\\$([A-Za-z_][A-Za-z0-9_]*)\s*\[\s*['\"]{$col}['\"]\s*\]/",
            // empty()/cast su formValue()/old(), il valore grezzo del form
            "/\b(?:empty|!empty)\s*\(\s*(?:\\\$[A-Za-z_][A-Za-z0-9_]*->)?formValue\(\s*['\"]{$col}['\"]/",
            "/\(\s*(?:int|bool|string|float)\s*\)\s*\(?\s*(?:\\\$[A-Za-z_][A-Za-z0-9_]*->)?formValue\(\s*['\"]{$col}['\"]/",
            "/\(\s*(?:int|bool|string|float)\s*\)\s*\(?\s*old\(\s*['\"]{$col}['\"]/",
            // helper locale che restituisce formValue(), es. $v('colonna')
            "/\\\$([A-Za-z_][A-Za-z0-9_]*)\s*\(\s*['\"]{$col}['\"]\s*\)\s*\?(?!\?)/",
            "/\b(?:if|elseif|while)\s*\(\s*!?\s*\\\$([A-Za-z_][A-Za-z0-9_]*)\s*\(\s*['\"]{$col}['\"]\s*\)\s*\)/",
            "/\(\s*(?:int|bool|string|float)\s*\)\s*\\\$([A-Za-z_][A-Za-z0-9_]*)\s*\(\s*['\"]{$col}['\"]\s*\)/",
        ];

        $offese = [];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $index => [$testo, $offset]) {
                // Tutte le variabili catturate dal pattern: con l'assegnazione
                // ($alias = $row['col']) il payload va esentato guardando il
                // gruppo dell'array a destra, non solo quello a sinistra.
                $esentata = false;

                foreach (array_slice($matches, 1) as $gruppo) {
                    $nome = $gruppo[$index][0] ?? '';

                    if ($nome !== '' && in_array($nome, self::PAYLOAD_VARS, true)) {
                        $esentata = true;
                        break;
                    }
                }

                if ($esentata) {
                    continue;
                }

                $riga = substr_count(substr($source, 0, $offset), "\n") + 1;
                $linea = trim((string) strtok(substr($source, $offset), "\n"));

                // Le righe di commento non sono codice.
                if (str_starts_with($linea, '//') || str_starts_with($linea, '*') || str_starts_with($linea, '/*')) {
                    continue;
                }

                $offese[$riga] = $testo;
            }
        }

        ksort($offese);

        return $offese;
    }
}
