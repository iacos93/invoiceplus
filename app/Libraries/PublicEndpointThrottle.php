<?php

namespace App\Libraries;

use CodeIgniter\Cache\CacheInterface;

/**
 * Tetto alle richieste sugli endpoint pubblici.
 *
 * `/mcp`, `/cron/*`, `/sdi-coop/*` e `/sdi-ricezione/*` non hanno sessione:
 * chiunque conosca (o indovini) l'URL puo' chiamarli. Il token o la cron key
 * restano la difesa vera, ma senza un limite i tentativi si possono ripetere
 * a volonta' e riempiono log e cache. Il contatore e' per IP e per scope:
 * consumare le richieste MCP non deve bloccare i cron, e un IP non deve
 * ereditare i contatori di un altro.
 *
 * Il limite e' volutamente generoso: SDICoop puo' consegnare lotti ravvicinati
 * e un cron che gira ogni cinque minuti non deve avvicinarsi al tetto. Lo
 * scopo e' fermare un ciclo automatico, non misurare il traffico legittimo.
 *
 * Sta nella cache configurata (di norma Redis, con ripiego su file), non in
 * sessione: cambiare sessione o IP non deve azzerare il conteggio. La chiave
 * e' un hash, cosi' in cache non restano in chiaro ne' lo scope ne' l'IP.
 */
final class PublicEndpointThrottle
{
    public const SCOPE_MCP  = 'mcp';
    public const SCOPE_CRON = 'cron';
    public const SCOPE_SDI  = 'sdi';

    /**
     * Richieste ammesse e durata della finestra, per scope.
     *
     * - MCP: 120 al minuto. Un client agentico puo' fare piu' chiamate di
     *   seguito (initialize, tools/list, piu' tools/call), ma non centinaia.
     * - Cron: 30 al minuto. I cron installati girano al massimo ogni cinque
     *   minuti; 30 lascia spazio a piu' voci e a un runner che ritenta.
     * - SDI: 300 al minuto. SOGEI puo' inviare molti lotti ravvicinati,
     *   soprattutto nella fase di qualificazione; qui il tetto serve solo a
     *   fermare un loop, non a limitare il flusso ufficiale.
     *
     * @var array<string, array{limit: int, window: int}>
     */
    public const LIMITS = [
        'mcp'  => ['limit' => 120, 'window' => 60],
        'cron' => ['limit' => 30, 'window' => 60],
        'sdi'  => ['limit' => 300, 'window' => 60],
    ];

    private ?CacheInterface $cache;

    public function __construct(?CacheInterface $cache = null)
    {
        $this->cache = $cache;
    }

    private function cache(): CacheInterface
    {
        return $this->cache ??= service('cache');
    }

    /**
     * Chiave del contatore: hash di scope e IP, stesso schema del throttle
     * del portale guest. In cache non finisce l'IP in chiaro.
     */
    public static function key(string $scope, string $ip): string
    {
        return 'public_endpoint_' . hash('sha256', $scope . '|' . $ip);
    }

    /**
     * Il throttle e' attivo? Si disattiva con `app.endpointThrottle = false`
     * in `.env` (default attivo). Nei test si spegne da solo: le chiamate di
     * integrazione ripetono gli endpoint e la cache su file sopravvive fra
     * un'esecuzione e l'altra, quindi il contatore non deve accumulare stato.
     * La lettura sta qui, in un punto solo, per non avere comportamenti
     * diversi nei vari endpoint.
     */
    public static function enabled(): bool
    {
        // Il bootstrap dei test imposta $_SERVER['CI_ENVIRONMENT'] a
        // "testing" e definisce ENVIRONMENT di conseguenza. La costante non
        // si legge direttamente perche' a un'analisi statica ha un valore
        // solo e il confronto risulterebbe sempre falso. env() non va bene
        // qui: nei test legge il valore di .env ($_ENV) prima di $_SERVER.
        if ((string) ($_SERVER['CI_ENVIRONMENT'] ?? 'production') === 'testing') {
            return false;
        }

        $value = env('app.endpointThrottle');

        if ($value === null || $value === '') {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /** Richieste ammesse nella finestra dello scope. */
    public static function limit(string $scope): int
    {
        return self::LIMITS[$scope]['limit'];
    }

    /** Durata della finestra dello scope, in secondi. */
    public static function window(string $scope): int
    {
        return self::LIMITS[$scope]['window'];
    }

    /**
     * Controlla e registra una richiesta.
     *
     * Va chiamato PRIMA dell'autenticazione: anche un tentativo con token
     * sbagliato consuma il contatore, altrimenti un attaccante potrebbe
     * provare chiavi all'infinito senza mai essere fermato.
     *
     * @return int|null secondi da indicare in Retry-After se la richiesta va
     *                  rifiutata, null se puo' proseguire
     */
    public static function guard(string $scope, string $ip): ?int
    {
        $throttle = new self();

        if (! self::enabled()) {
            return null;
        }

        if ($throttle->blocked($scope, $ip)) {
            return $throttle->retryAfter($scope);
        }

        $throttle->hit($scope, $ip);

        return null;
    }

    public function attempts(string $scope, string $ip): int
    {
        return (int) ($this->cache()->get(self::key($scope, $ip)) ?? 0);
    }

    public function blocked(string $scope, string $ip): bool
    {
        return $this->attempts($scope, $ip) >= self::limit($scope);
    }

    /**
     * Registra una richiesta nella finestra corrente. Il TTL si rinnova a
     * ogni richiesta: chi insiste resta bloccato finche' non smette per una
     * finestra intera.
     */
    public function hit(string $scope, string $ip): void
    {
        $key = self::key($scope, $ip);

        $this->cache()->save($key, $this->attempts($scope, $ip) + 1, self::window($scope));
    }

    /** Azzera il contatore (usato dai test e da eventuali sblocchi manuali). */
    public function clear(string $scope, string $ip): void
    {
        $this->cache()->delete(self::key($scope, $ip));
    }

    /**
     * Secondi da attendere prima di riprovare. Non si espone il limite esatto
     * nella risposta: il valore e' solo la durata della finestra.
     */
    public function retryAfter(string $scope): int
    {
        return self::window($scope);
    }
}
