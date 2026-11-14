<?php

namespace App\Commands;

use App\Services\CompanyOrphanReport;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Diagnostica e riassegna le righe per-azienda rimaste senza company_id.
 *
 * Senza opzioni è un dry-run: elenca le tabelle, quante righe hanno
 * company_id NULL e quante righe hanno in tutto, e dice se c'è qualcosa da
 * sistemare. Con --fix assegna quelle righe all'azienda predefinita.
 *
 * L'output non contiene dati: solo nomi di tabella e conteggi. Le righe orfane
 * oggi sono visibili a tutte le aziende, quindi finché ci sono il comando esce
 * con 1 (utile in cron o in una checklist di aggiornamento).
 *
 * Uso:
 *   php spark companies:orphans
 *   php spark companies:orphans --fix
 */
class CompanyOrphans extends BaseCommand
{
    protected $group       = 'companies';
    protected $name        = 'companies:orphans';
    protected $description = 'Conta le righe per-azienda senza company_id e, con --fix, le assegna all\'azienda predefinita.';
    protected $usage       = 'companies:orphans [--fix]';
    protected $arguments   = [];
    protected $options     = [
        '--fix' => 'Assegna le righe orfane all\'azienda predefinita (default: sola diagnosi, non scrive).',
    ];

    /** Codici di uscita espliciti: EXIT_SUCCESS/EXIT_FAILURE esistono solo in CLI. */
    private const EXIT_OK      = 0;
    private const EXIT_PROBLEM = 1;

    public function run(array $params)
    {
        $servizio = new CompanyOrphanReport();
        $fix      = CLI::getOption('fix') !== null;

        try {
            $conteggi = $servizio->counts();
        } catch (\Throwable $e) {
            CLI::error('Conteggio non eseguibile: il database non risponde o lo schema non è leggibile.');
            log_message('error', 'companies:orphans: ' . $e->getMessage());

            return self::EXIT_PROBLEM;
        }

        $this->stampaConteggi($conteggi);

        if ($conteggi['total'] === 0) {
            CLI::write('A posto: nessuna riga per-azienda senza company_id.', 'green');

            return self::EXIT_OK;
        }

        if (! $fix) {
            CLI::write(
                sprintf(
                    '%d righe da sistemare. Finché restano sono visibili a tutte le aziende. '
                    . 'Esegui `php spark companies:orphans --fix` per assegnarle all\'azienda predefinita.',
                    $conteggi['total']
                ),
                'yellow'
            );

            return self::EXIT_PROBLEM;
        }

        $esito = $servizio->backfill();

        if (! $esito['ok']) {
            CLI::error('Backfill non eseguito: ' . $esito['reason']);

            return self::EXIT_PROBLEM;
        }

        $assegnate = array_filter($esito['tables'], static fn (int $n): bool => $n > 0);

        if ($assegnate === []) {
            CLI::write('Nessuna riga da aggiornare: erano già state assegnate.', 'green');
        } else {
            $righe = [];

            foreach ($assegnate as $tabella => $quante) {
                $righe[] = [$tabella, $quante];
            }

            CLI::write('Righe assegnate all\'azienda predefinita:', 'green');
            CLI::table($righe, ['Tabella', 'Righe assegnate']);
        }

        try {
            $dopo = $servizio->counts();
        } catch (\Throwable $e) {
            CLI::error('Backfill eseguito, ma la verifica finale non è stata possibile.');
            log_message('error', 'companies:orphans: verifica finale fallita: ' . $e->getMessage());

            return self::EXIT_PROBLEM;
        }

        if ($dopo['total'] === 0) {
            CLI::write('Verifica: zero righe senza azienda.', 'green');

            return self::EXIT_OK;
        }

        CLI::error(sprintf('Restano %d righe senza azienda: guarda la tabella sopra e il log.', $dopo['total']));

        return self::EXIT_PROBLEM;
    }

    /**
     * @param array{
     *     tables: array<string, array{nulls: int, total: int}>,
     *     total: int,
     *     rows: int,
     *     skipped: list<string>
     * } $conteggi
     */
    private function stampaConteggi(array $conteggi): void
    {
        if ($conteggi['tables'] === []) {
            CLI::write('Nessuna tabella per-azienda trovata in questa installazione.', 'yellow');
            $this->stampaSaltate($conteggi['skipped']);

            return;
        }

        $righe = [];

        foreach ($conteggi['tables'] as $tabella => $c) {
            $righe[] = [
                $tabella,
                $c['nulls'],
                $c['total'],
                $c['nulls'] === 0 ? 'ok' : 'da sistemare',
            ];
        }

        CLI::table($righe, ['Tabella', 'Senza azienda', 'Righe totali', 'Esito']);

        CLI::write(sprintf(
            'Totale: %d righe senza azienda su %d righe esaminate in %d tabelle.',
            $conteggi['total'],
            $conteggi['rows'],
            count($conteggi['tables'])
        ));

        // I parametri fiscali con NULL sono condivisi per scelta: vanno detti,
        // altrimenti chi legge si aspetta di vederli nella tabella.
        CLI::write('NULL legittimo (condivisi, non contati): ' . implode(', ', CompanyOrphanReport::GLOBAL_TABLES) . '.');

        $this->stampaSaltate($conteggi['skipped']);
    }

    /**
     * @param list<string> $saltate
     */
    private function stampaSaltate(array $saltate): void
    {
        if ($saltate !== []) {
            CLI::write('Tabelle assenti in questa installazione (saltate): ' . implode(', ', $saltate) . '.');
        }
    }
}
