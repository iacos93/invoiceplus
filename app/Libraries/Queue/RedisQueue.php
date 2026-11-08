<?php

namespace App\Libraries\Queue;

use Config\Redis as RedisConfig;
use Predis\Client as PredisClient;

/**
 * Coda di lavori asincroni su Redis (LPUSH/BRPOP).
 *
 * Ogni "queue" è una lista Redis. I job vengono serializzati con la
 * classe e lo stato, poi deserializzati dal worker che chiama handle().
 *
 * Usa Predis (userland, nessuna estensione C richiesta). Il database
 * dedicato (default 2) evita collisioni con cache (db 0) e sessioni (db 1).
 */
class RedisQueue
{
    private PredisClient $redis;
    private string $prefix;

    private static ?self $instance = null;

    public function __construct(?RedisConfig $config = null)
    {
        $config       = $config ?? config('Redis');
        $this->prefix = $config->queuePrefix;

        $this->redis = new PredisClient([
            'scheme'   => 'tcp',
            'host'     => $config->host,
            'port'     => $config->port,
            'password' => $config->password ?: null,
            'database' => $config->database,
            'timeout'  => $config->timeout,
        ]);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Accoda un job per l'esecuzione asincrona.
     *
     * @param string $queue Nome logico della coda (es. 'fattura', 'email')
     */
    public function push(JobInterface $job, string $queue = 'default'): void
    {
        $payload = json_encode([
            'class'      => get_class($job),
            'data'       => serialize($job),
            'queued_at'  => date('c'),
            'attempt'    => 0,
        ], JSON_THROW_ON_ERROR);

        $this->redis->lpush($this->key($queue), [$payload]);
    }

    /**
     * Preleva ed esegue il prossimo job dalla coda (blocking).
     *
     * @param int $timeout Secondi di attesa BRPOP (0 = infinito)
     * @return bool true se un job è stato eseguito
     */
    public function pop(string $queue = 'default', int $timeout = 5): bool
    {
        $result = $this->redis->brpop([$this->key($queue)], $timeout);

        if ($result === null) {
            return false;
        }

        $payload = json_decode($result[1], true);
        $job     = unserialize($payload['data']);

        if (!$job instanceof JobInterface) {
            log_message('error', 'RedisQueue: job non valido dalla coda {queue}', ['queue' => $queue]);
            return false;
        }

        try {
            $job->handle();
        } catch (\Throwable $e) {
            $attempt = ($payload['attempt'] ?? 0) + 1;

            if ($attempt < 3) {
                $payload['attempt'] = $attempt;
                $this->redis->lpush($this->key($queue), [json_encode($payload)]);
                log_message('warning', 'RedisQueue: job {class} fallito (tentativo {attempt}/3), riaccodato: {msg}', [
                    'class'   => $payload['class'],
                    'attempt' => $attempt,
                    'msg'     => $e->getMessage(),
                ]);
            } else {
                $this->redis->lpush($this->key($queue . ':failed'), [json_encode($payload + ['error' => $e->getMessage()])]);
                log_message('error', 'RedisQueue: job {class} fallito definitivamente dopo 3 tentativi: {msg}', [
                    'class' => $payload['class'],
                    'msg'   => $e->getMessage(),
                ]);
            }
        }

        return true;
    }

    /**
     * Numero di job in attesa nella coda.
     */
    public function size(string $queue = 'default'): int
    {
        return (int) $this->redis->llen($this->key($queue));
    }

    /**
     * Numero di job nella coda dei falliti.
     */
    public function failedSize(string $queue = 'default'): int
    {
        return (int) $this->redis->llen($this->key($queue . ':failed'));
    }

    /**
     * Svuota una coda (utile per i test).
     */
    public function flush(string $queue = 'default'): void
    {
        $this->redis->del([$this->key($queue), $this->key($queue . ':failed')]);
    }

    private function key(string $queue): string
    {
        return $this->prefix . $queue;
    }
}
