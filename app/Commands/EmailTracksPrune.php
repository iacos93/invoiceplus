<?php

namespace App\Commands;

use App\Models\EmailTrackModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Pota il registro delle aperture email.
 *
 * Le righe di tracciamento non servono per sempre: dopo un anno dicono poco e
 * occupano spazio; quelle di una fattura o di un preventivo eliminato non sono
 * più raggiungibili da nessuna pagina. Il cron-runner lancia questo comando
 * una volta a settimana, così la tabella non cresce all'infinito.
 *
 * La soglia è volutamente larga: un documento può essere riaperto dopo mesi,
 * e la storia degli invii resta utile finché il documento esiste.
 */
class EmailTracksPrune extends BaseCommand
{
    protected $group       = 'email';
    protected $name        = 'email:prune';
    protected $description = 'Elimina le righe di tracciamento email scadute o senza documento.';
    protected $usage       = 'email:prune [--days 365]';
    protected $options     = [
        '--days' => 'Giorni di conservazione (default 365)',
    ];

    public function run(array $params)
    {
        $days = (int) (CLI::getOption('days') ?? 365);

        if ($days < 1) {
            CLI::error('--days deve essere un numero maggiore di zero.');

            return EXIT_ERROR;
        }

        $result  = (new EmailTrackModel())->prune($days);
        $message = sprintf(
            'Tracciamento email: rimosse %d righe più vecchie di %d giorni e %d righe di documenti eliminati.',
            $result['expired'],
            $days,
            $result['orphans']
        );

        CLI::write($message, 'green');
        log_message('info', $message);

        return EXIT_SUCCESS;
    }
}
