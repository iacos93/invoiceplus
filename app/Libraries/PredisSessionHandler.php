<?php

namespace App\Libraries;

use CodeIgniter\I18n\Time;
use CodeIgniter\Session\Handlers\BaseHandler;
use Config\Session as SessionConfig;
use Predis\Client as PredisClient;

/**
 * Session handler basato su Predis (userland, nessuna estensione C).
 *
 * Il RedisHandler nativo di CI4 richiede l'estensione phpredis; questo
 * handler è un'alternativa drop-in che usa Predis, già installato per
 * il cache handler e la coda.
 *
 * Il savePath si configura come stringa: tcp://host:port?database=N&auth=pass
 */
class PredisSessionHandler extends BaseHandler
{
    private const DEFAULT_PORT = 6379;

    protected ?PredisClient $redis = null;

    protected string $keyPrefix = 'ci_session:';
    protected ?string $lockKey  = null;
    protected bool $keyExists   = false;
    protected int $sessionExpiration;
    protected int $lockRetryInterval;
    protected int $lockMaxRetries;

    /** @var array{}|array{host: string, port: int, password: ?string, database: int, timeout: float} */
    protected array $parsedPath = [];

    public function __construct(SessionConfig $config, string $ipAddress)
    {
        parent::__construct($config, $ipAddress);

        $this->sessionExpiration = ($config->expiration === 0)
            ? (int) ini_get('session.gc_maxlifetime')
            : $config->expiration;

        $this->keyPrefix .= $config->cookieName . ':';

        $this->parseSavePath();

        if ($this->matchIP === true) {
            $this->keyPrefix .= $this->ipAddress . ':';
        }

        $this->lockRetryInterval = $config->lockRetryInterval ?? 100_000;
        $this->lockMaxRetries    = $config->lockMaxRetries ?? 300;
    }

    private function parseSavePath(): void
    {
        $url   = parse_url($this->savePath);
        $query = [];

        if (isset($url['query'])) {
            parse_str($url['query'], $query);
        }

        $this->parsedPath = [
            'host'     => $url['host'] ?? '127.0.0.1',
            'port'     => $url['port'] ?? self::DEFAULT_PORT,
            'password' => $query['auth'] ?? null,
            'database' => isset($query['database']) ? (int) $query['database'] : 0,
            'timeout'  => isset($query['timeout']) ? (float) $query['timeout'] : 5.0,
        ];

        if (isset($query['prefix'])) {
            $this->keyPrefix = $query['prefix'];
        }
    }

    public function open($path, $name): bool
    {
        if (empty($this->parsedPath)) {
            return false;
        }

        try {
            $this->redis = new PredisClient([
                'scheme'   => 'tcp',
                'host'     => $this->parsedPath['host'],
                'port'     => $this->parsedPath['port'],
                'password' => $this->parsedPath['password'],
                'database' => $this->parsedPath['database'],
                'timeout'  => $this->parsedPath['timeout'],
            ]);

            $this->redis->ping();

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Session: impossibile connettersi a Redis (Predis): ' . $e->getMessage());
            return false;
        }
    }

    public function read($id): false|string
    {
        if (isset($this->redis) && $this->lockSession($id)) {
            if (!isset($this->sessionID)) {
                $this->sessionID = $id;
            }

            $data = $this->redis->get($this->keyPrefix . $id);

            if (is_string($data)) {
                $this->keyExists = true;
            } else {
                $data = '';
            }

            $this->fingerprint = md5($data);

            return $data;
        }

        return false;
    }

    public function write($id, $data): bool
    {
        if (!isset($this->redis)) {
            return false;
        }

        if ($this->sessionID !== $id) {
            if (!$this->releaseLock() || !$this->lockSession($id)) {
                return false;
            }

            $this->keyExists = false;
            $this->sessionID = $id;
        }

        if (isset($this->lockKey)) {
            $this->redis->expire($this->lockKey, 300);

            if ($this->fingerprint !== ($fingerprint = md5($data)) || $this->keyExists === false) {
                $this->redis->setex($this->keyPrefix . $id, $this->sessionExpiration, $data);
                $this->fingerprint = $fingerprint;
                $this->keyExists   = true;

                return true;
            }

            return (bool) $this->redis->expire($this->keyPrefix . $id, $this->sessionExpiration);
        }

        return false;
    }

    public function close(): bool
    {
        if (isset($this->redis)) {
            try {
                $this->redis->ping();

                if (isset($this->lockKey) && !$this->releaseLock()) {
                    return false;
                }
            } catch (\Throwable $e) {
                $this->logger->error('Session: RedisException on close(): ' . $e->getMessage());
            }

            return true;
        }

        return true;
    }

    public function destroy($id): bool
    {
        if (isset($this->redis, $this->lockKey)) {
            $this->redis->del([$this->keyPrefix . $id]);

            return $this->destroyCookie();
        }

        return false;
    }

    public function gc($max_lifetime): int
    {
        return 1;
    }

    protected function lockSession(string $sessionID): bool
    {
        $lockKey = $this->keyPrefix . $sessionID . ':lock';

        if ($this->lockKey === $lockKey) {
            return (bool) $this->redis->expire($this->lockKey, 300);
        }

        $attempt = 0;

        do {
            // SET NX EX — atomico: crea solo se non esiste, con scadenza
            $result = $this->redis->set(
                $lockKey,
                (string) Time::now()->getTimestamp(),
                'EX',
                300,
                'NX'
            );

            if ($result === null) {
                usleep($this->lockRetryInterval);
                continue;
            }

            $this->lockKey = $lockKey;
            break;
        } while (++$attempt < $this->lockMaxRetries);

        if ($attempt === $this->lockMaxRetries) {
            $this->logger->error(
                'Session: impossibile acquisire il lock per ' . $this->keyPrefix . $sessionID
                . ' dopo ' . $this->lockMaxRetries . ' tentativi.'
            );

            return false;
        }

        $this->lock = true;

        return true;
    }

    protected function releaseLock(): bool
    {
        if (isset($this->redis, $this->lockKey) && $this->lock) {
            if (!$this->redis->del([$this->lockKey])) {
                $this->logger->error('Session: errore nel rilascio del lock per ' . $this->lockKey);
                return false;
            }

            $this->lockKey = null;
            $this->lock    = false;
        }

        return true;
    }
}
