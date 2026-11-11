<?php

namespace App\Controllers;

use App\Models\PaymentModel;
use App\Traits\ResolvesGuestClients;

/**
 * GuestPayments controller
 *
 * Porting CI4 del controller Payments (CI3, modulo guest): elenco dei
 * pagamenti relativi alle fatture dei clienti assegnati all'utente guest.
 */
class GuestPayments extends BaseController
{
    // Stesso elenco clienti delle altre porte del portale: qui ne restava
    // una copia a parte, con la sua cache in sessione.
    use ResolvesGuestClients;

    public function index()
    {
        $clientIds = $this->clientIds();

        $data = [
            'title'    => trans('payments'),
            'payments' => (new PaymentModel())->getForGuestClients($clientIds),
        ];

        return view('guest/payments_index', $data);
    }
}