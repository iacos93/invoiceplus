<?php

namespace App\Libraries;

use CodeIgniter\Cache\CacheInterface;

/**
 * Tetto ai tentativi di sblocco dei documenti pubblici.
 *
 * Il portale guest accetta la password di fattura e preventivo da un form
 * anonimo: senza un limite si poteva provare all'infinito, magari con una
 * lista di password comuni. Il contatore è per IP *e* documento: cinque
 * errori in un quarto d'ora e la risposta diventa "riprova più tardi".
 *
 * Sta nella cache (handler configurato, di norma file) e non in sessione:
 * cambiare IP o saltare la sessione non deve azzerare il conteggio, e non
 * serve Redis né un'altra infrastruttura.
 */
final class GuestAccessThrottle
{
    /**
     * Errori ammessi nella finestra.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Durata della finestra, in secondi (15 minuti). Il TTL si rinnova a ogni
     * errore: chi continua a provare resta bloccato.
     */
    public const WINDOW_SECONDS = 900;

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
     * Chiave del contatore.
     *
     * L'identificatore è la url key (non l'id: è quella che viaggia nel link
     * pubblico) e viene passato da hash() insieme all'IP, così la chiave in
     * cache non contiene né l'uno né l'altro in chiaro.
     *
     * @param string $kind       'invoice' o 'quote'
     * @param string $identifier url key del documento
     * @param string $ip         indirizzo del richiedente
     */
    public static function key(string $kind, string $identifier, string $ip): string
    {
        return 'guest_unlock_' . $kind . '_' . hash('sha256', $identifier . '|' . $ip);
    }

    public function attempts(string $key): int
    {
        return (int) ($this->cache()->get($key) ?? 0);
    }

    public function blocked(string $key): bool
    {
        return $this->attempts($key) >= self::MAX_ATTEMPTS;
    }

    /**
     * Registra un tentativo fallito.
     */
    public function hit(string $key): void
    {
        $this->cache()->save($key, $this->attempts($key) + 1, self::WINDOW_SECONDS);
    }

    /**
     * Sblocco riuscito: il contatore sparisce, non deve pesare sui prossimi
     * accessi legittimi.
     */
    public function clear(string $key): void
    {
        $this->cache()->delete($key);
    }
}
