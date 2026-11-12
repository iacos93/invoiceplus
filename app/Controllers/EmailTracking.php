<?php

namespace App\Controllers;

use App\Libraries\EmailTracking as Tracker;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Il pixel di tracciamento delle aperture.
 *
 * Sta fuori dall'area autenticata perché lo carica il programma di posta del
 * destinatario, che non ha una sessione. Risponde sempre con l'immagine, anche
 * per un token sconosciuto: un 404 direbbe a chi prova token a caso quali
 * esistono.
 *
 * Non estende BaseController di proposito: quella classe carica impostazioni
 * e lingua a ogni richiesta, e questa rotta viene chiamata da un client di
 * posta, non da una persona. Meno lavoro significa un pixel che non ritarda
 * la lettura del messaggio.
 */
class EmailTracking extends Controller
{
    /** GIF trasparente di 1×1, il formato più piccolo che i client accettano. */
    private const PIXEL_GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(string $token): ResponseInterface
    {
        $token = (string) preg_replace('/\.gif$/i', '', $token);

        // 48 caratteri esadecimali: il token è generato così e non contiene
        // l'id del documento. Un formato diverso non merita una query.
        if (preg_match('/^[a-f0-9]{48}$/', $token)) {
            $request = $this->request;

            (new Tracker())->recordOpen(
                $token,
                (string) $request->getIPAddress()
            );
        }

        return $this->response
            ->setStatusCode(200)
            ->setContentType('image/gif')
            // Un pixel non si cachea: ogni apertura deve arrivare al server,
            // altrimenti il contatore si ferma alla prima.
            ->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->setHeader('Pragma', 'no-cache')
            ->setBody(base64_decode(self::PIXEL_GIF, true) ?: '');
    }
}
