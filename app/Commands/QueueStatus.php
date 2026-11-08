<?php

namespace App\Commands;

use App\Libraries\Queue\RedisQueue;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Mostra lo stato delle code Redis.
 *
 * Uso:
 *   php spark queue:status
 *   php spark queue:status fattura email
 */
class QueueStatus extends BaseCommand
{
    protected $group       = 'Queue';
    protected $name        = 'queue:status';
    protected $description = 'Mostra il numero di job in attesa e falliti per ogni coda.';
    protected $usage       = 'queue:status [queue1 queue2 ...]';

    public function run(array $params)
    {
        $queues = !empty($params) ? $params : ['default', 'fattura', 'email', 'sdi'];

        $redisQueue = RedisQueue::instance();

        $headers = ['Coda', 'In attesa', 'Falliti'];
        $rows    = [];

        foreach ($queues as $queue) {
            $rows[] = [
                $queue,
                $redisQueue->size($queue),
                $redisQueue->failedSize($queue),
            ];
        }

        CLI::table($rows, $headers);
    }
}
