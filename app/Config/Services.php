<?php

namespace Config;

use App\Libraries\CspWithoutNonce;
use CodeIgniter\Config\BaseService;

/**
 * Servizi dell'applicazione e sostituzioni dei servizi del framework.
 *
 * Vedi system/Config/Services.php per l'elenco di quelli predefiniti.
 */
class Services extends BaseService
{
    /**
     * CSP che non emette nonce (App\Libraries\CspWithoutNonce).
     *
     * Stessa firma e stessa condivisione dell'originale: cambia solo la classe
     * istanziata.
     *
     * @return CspWithoutNonce
     */
    public static function csp(?ContentSecurityPolicy $config = null, bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('csp', $config);
        }

        $config ??= config(ContentSecurityPolicy::class);

        return new CspWithoutNonce($config);
    }
}
