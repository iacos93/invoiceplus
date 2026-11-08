<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Security\Exceptions\SecurityException;
use CodeIgniter\Security\Security;

/**
 * Stesso lavoro del filtro CSRF di CodeIgniter (Config\Filters puntava
 * direttamente a CodeIgniter\Filters\CSRF), con una sola differenza: in
 * caso di token disallineato il messaggio va nella flashdata 'alert_error',
 * non nella generica 'error' che usa il framework. layout/alerts.php (il
 * toast dell'app) legge solo alert_success/alert_info/alert_error — con la
 * chiave del framework il redirect avveniva comunque ma senza che l'utente
 * vedesse alcun messaggio, un fallimento silenzioso ancora piu' confuso
 * dello stack trace che sostituiva (vedi Config/Security::$redirect).
 */
class CsrfFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        /** @var Security $security */
        $security = service('security');

        try {
            $security->verify($request);
        } catch (SecurityException $e) {
            if ($security->shouldRedirect() && ! $request->isAJAX()) {
                return redirect()->back()->with(
                    'alert_error',
                    trans('csrf_expired', '', 'La pagina era rimasta aperta troppo a lungo o è stata inviata due volte: riprova.')
                );
            }

            throw $e;
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
