<?php

namespace App\Commands;

use App\Libraries\Queue\RedisQueue;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Worker per la coda Redis — esegue i job accodati con RedisQueue::push().
 *
 * Uso:
 *   php spark queue:work              # coda "default"
 *   php spark queue:work fattura      # coda "fattura"
 *   php spark queue:work --once       # esegue un solo job ed esce
 *
 * Il worker gira in loop con BRPOP (blocking pop), quindi non consuma
 * CPU quando la coda è vuota. Fermarlo con Ctrl+C è sicuro: il job
 * corrente finisce prima dell'uscita.
 */
class QueueWorker extends BaseCommand
{
    protected $group       = 'Queue';
    protected $name        = 'queue:work';
    protected $description = 'Esegue i job dalla coda Redis.';
    protected $usage       = 'queue:work [queue] [--once]';
    protected $arguments   = [
        'queue' => 'Nome della coda (default: "default")',
    ];
    protected $options = [
        '--once' => 'Esegue un solo job ed esce.',
    ];

    /** Il ciclo di attesa continua finché un segnale non lo ferma. */
    private bool $shouldRun = true;

    public function run(array $params)
    {
        $queue = $params[0] ?? 'default';
        $once  = CLI::getOption('once') !== null;

        CLI::write("Worker avviato sulla coda \"{$queue}\"", 'green');

        $redisQueue = RedisQueue::instance();

        if ($once) {
            $executed = $redisQueue->pop($queue);
            CLI::write($executed ? 'Job eseguito.' : 'Nessun job in coda.', $executed ? 'green' : 'yellow');
            return;
        }

        $this->handleSignals();

        while ($this->shouldRun) {
            $redisQueue->pop($queue, 5);
        }

        CLI::write('Worker fermato.', 'yellow');
    }


    private function handleSignals(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        $stop = function () {
            $this->shouldRun = false;
            CLI::write('Segnale ricevuto, il worker si fermerà dopo il job corrente...', 'yellow');
        };

        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGTERM, $stop);
    }
}
