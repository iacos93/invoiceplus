<?php

namespace App\Filters;

use App\Libraries\SessionUserGuard;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * AuthFilter
 *
 * Equivalente di Admin_Controller (CI3): richiede una sessione
 * con user_type 1, altrimenti redirect alla pagina di login.
 */
class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (session('user_type') != 1) {
            return redirect()->to('/sessions/login');
        }

        // Il tipo in sessione non basta: l'utente può essere stato eliminato
        // o disattivato dopo il login. In quel caso la sessione va distrutta,
        // non solo rimandata al login, perché le altre chiavi (azienda attiva,
        // lingua) non devono sopravvivere al prossimo accesso.
        if (!SessionUserGuard::isValid(session('user_id'), 1)) {
            SessionUserGuard::reject();

            return redirect()->to('/sessions/login');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        // Nessuna modifica alla risposta in uscita.
        return null;
    }
}