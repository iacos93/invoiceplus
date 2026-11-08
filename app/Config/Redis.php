<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Configurazione Redis per la coda di lavori asincroni.
 *
 * I parametri di cache e sessione sono nei rispettivi Config (Cache.php,
 * Session.php); qui si configura solo il database dedicato alla queue,
 * tenuto separato per non interferire con cache e sessioni.
 */
class Redis extends BaseConfig
{
    public string $host     = '127.0.0.1';
    public int    $port     = 6379;
    public string $password = '';
    public int    $database = 2;
    public int    $timeout  = 5;

    /**
     * Prefisso per le chiavi della coda — evita collisioni se più app
     * condividono la stessa istanza Redis.
     */
    public string $queuePrefix = 'ip_queue:';
}
