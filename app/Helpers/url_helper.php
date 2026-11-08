<?php


/**
 * Helper centralizzato per la composizione delle URL dell'applicazione.
 *
 * Obiettivo: un solo punto da cui nascono le URL, cosi' che la coerenza
 * (niente index.php, niente slash finale, protocollo unico) non dipenda da
 * come ogni vista concatena le stringhe. Le funzioni qui sotto sono drop-in
 * per gli site_url('gruppo/azione/'. $id) gia' presenti nel codice.
 */

if (! function_exists('ip_url')) {
    /**
     * Compone una URL interna a partire da un percorso relativo.
     * Garantisce l'assenza di uno slash finale (coerente con il rewrite del
     * .htaccess) e delega a site_url() per il prefisso base + indexPage vuoto.
     */
    function ip_url(string $path = ''): string
    {
        $path = trim($path, '/');

        return site_url($path);
    }
}

if (! function_exists('ip_resource_url')) {
    /**
     * Compone l'URL di una risorsa CRUD a partire da gruppo/azione/id.
     *
     * Esempi:
     *   ip_resource_url('invoices')                 -> /invoices
     *   ip_resource_url('invoices', 'view', 12)    -> /invoices/view/12
     *   ip_resource_url('invoices', 'form')         -> /invoices/form
     *   ip_resource_url('invoices', 'delete', 12)   -> /invoices/delete/12
     *
     * $id puo' essere null, un intero o una stringa (es. url key pubbliche).
     * Gli elementi vuoti (azione o id non passati) vengono saltati, evitando
     * barre doppie o slash finale.
     */
    function ip_resource_url(string $group, string $action = '', $id = null): string
    {
        $segments = [trim($group, '/')];

        if ($action !== '' && $action !== null) {
            $segments[] = trim((string) $action, '/');
        }

        if ($id !== null && $id !== '') {
            $segments[] = (string) $id;
        }

        return site_url(implode('/', $segments));
    }
}

if (! function_exists('ip_back_url')) {
    /**
     * URL di ritorno usato dai controller dopo una mutazione CRUD.
     * Se in POST non arriva 'back' (o e' vuoto) si usa l'indice del gruppo.
     *
     * Gestisce sia URL assolute (da current_url()) che relative: estrae il
     * percorso se l'URL è assoluta dello stesso host.
     */
    function ip_back_url(string $group, ?string $back = null): string
    {
        $back = trim((string) $back);

        if ($back !== '') {
            // Se $back è un'URL assoluta, estrai il percorso relativo
            if (str_starts_with($back, 'http://') || str_starts_with($back, 'https://')) {
                $baseUrl = rtrim(config('App')->baseURL, '/');
                if (str_starts_with($back, $baseUrl)) {
                    // Stessa origine: estrai il percorso
                    $back = substr($back, strlen($baseUrl));
                }
            }
            return site_url($back);
        }

        return site_url(trim($group, '/'));
    }
}

if (! function_exists('ip_current_url')) {
    /**
     * URL corrente normalizzata (senza query string), comoda per i form che
     * devono risottomettersi sulla stessa pagina.
     */
    function ip_current_url(): string
    {
        return current_url();
    }
}
