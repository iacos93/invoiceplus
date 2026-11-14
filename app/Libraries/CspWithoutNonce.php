<?php

namespace App\Libraries;

use CodeIgniter\HTTP\ContentSecurityPolicy;

/**
 * Servizio CSP che non emette mai un nonce.
 *
 * La policy dell'app ammette gli script in linea con 'unsafe-inline' (vedi
 * Config\ContentSecurityPolicy per il perche'). Ma appena l'header contiene
 * anche un solo nonce, i browser ignorano 'unsafe-inline' e smettono di
 * eseguire gli script in linea della pagina. Il framework ne aggiunge uno
 * senza che nessuno glielo chieda: con CI_DEBUG attivo inizializza Kint con un
 * nonce a ogni richiesta (CodeIgniter::initializeKint), e gli helper
 * csp_script_nonce(), csp_style_nonce(), safe_mailto() e script_tag() lo
 * chiedono a loro volta. In sviluppo la policy aveva quindi sempre un nonce, e
 * in produzione bastava che qualcuno usasse uno di quegli helper per
 * silenziare in blocco gli script di una pagina.
 *
 * Qui le due richieste restituiscono una stringa vuota senza registrare niente
 * nella policy: gli helper producono `nonce=""`, innocuo, e 'unsafe-inline'
 * resta valido. Se un giorno si passera' a una policy a nonce, basta togliere
 * questa classe da Config\Services.
 */
class CspWithoutNonce extends ContentSecurityPolicy
{
    public function getScriptNonce(): string
    {
        return '';
    }

    public function getStyleNonce(): string
    {
        return '';
    }
}
