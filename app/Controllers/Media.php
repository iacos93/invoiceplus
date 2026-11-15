<?php

namespace App\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Immagini dell'interfaccia che vivono in writable/uploads.
 *
 * La cartella scrivibile non è servita dal web, e i loghi ci stanno dentro:
 * finora finivano nelle pagine come data URI, cioè ~33 KB di base64 per ogni
 * immagine, rispediti a ogni caricamento e mai in cache del browser (un PNG in
 * base64 si comprime anche male). Il PDF ha bisogno del data URI — dompdf legge
 * dal filesystem e non fa richieste HTTP — ma la pagina no: qui il file esce da
 * una rotta, con ETag e cache lunga, e l'HTML resta leggero.
 *
 * È l'unica parte di writable/uploads raggiungibile da fuori, e solo per i due
 * loghi della schermata di accesso: il nome del file non arriva dall'URL, si
 * legge dalle impostazioni, quindi la rotta non può servire altro.
 */
class Media extends BaseController
{
    /** Le varianti richiedibili, ciascuna con l'impostazione che la contiene. */
    private const LOGOS = [
        'login'      => 'login_logo',
        'login-dark' => 'login_logo_dark',
    ];

    private const MIMES = [
        'gif'  => 'image/gif',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'svg'  => 'image/svg+xml',
    ];

    public function logo(string $variant = 'login'): ResponseInterface
    {
        // Il nome arriva con l'estensione (logo/login.svg): così il file cade
        // nelle regole degli asset del .htaccess — cache lunga e compressione —
        // e l'URL cambia anche se si passa da PNG a SVG.
        $variant = preg_replace('/\.(png|jpe?g|gif|svg)$/i', '', $variant) ?? $variant;

        if (!isset(self::LOGOS[$variant])) {
            throw PageNotFoundException::forPageNotFound();
        }

        $path = logo_path((string) get_setting(self::LOGOS[$variant], ''));

        if ($path === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $data      = @file_get_contents($path);

        if ($data === false) {
            throw PageNotFoundException::forPageNotFound();
        }

        // L'ETag cambia quando cambia il file: chi ha in cache la versione
        // vecchia se ne accorge anche se l'URL non portava il ?v=.
        $etag         = '"' . md5($data) . '"';
        $richiesta    = service('request')->getHeaderLine('If-None-Match');

        if ($richiesta === $etag) {
            return $this->response->setStatusCode(304)->setHeader('ETag', $etag);
        }

        return $this->response
            ->setHeader('Content-Type', self::MIMES[$extension] ?? 'application/octet-stream')
            ->setHeader('ETag', $etag)
            ->setHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->setBody($data);
    }
}
