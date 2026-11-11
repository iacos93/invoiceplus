<?php

namespace App\Libraries;

use lbuchs\WebAuthn\WebAuthn;

/**
 * WebAuthn (passkey) con lbuchs/webauthn, configurato dall'installazione.
 *
 * L'RP ID è il dominio dell'app senza porta (per WebAuthn la porta non fa
 * parte dell'RP ID, solo dell'origin); la libreria accetta origin http solo
 * quando l'RP ID è "localhost", cioè esattamente il caso di sviluppo.
 *
 * Con useBase64UrlEncoding=true tutti i blob (challenge, user id, credential
 * id) viaggiano in base64url nel JSON: è il formato che navigator.credentials
 * si aspetta lato browser e che lo script webauthn.js manda indietro senza
 * conversioni.
 */
class WebAuthnService
{
    public static function rpId(): string
    {
        $host = (string) parse_url(base_url(), PHP_URL_HOST);

        return $host !== '' ? $host : 'localhost';
    }

    public static function instance(): WebAuthn
    {
        $rpName = get_setting('custom_title', '', true);
        if ($rpName === '') {
            $rpName = 'Invoice+';
        }

        return new WebAuthn($rpName, self::rpId(), null, true);
    }
}