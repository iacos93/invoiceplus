<?php

namespace App\Controllers;

use App\Models\PaymentModel;
use App\Models\UserClientModel;

/**
 * GuestPayments controller
 *
 * Porting CI4 del controller Payments (CI3, modulo guest): elenco dei
 * pagamenti relativi alle fatture dei clienti assegnati all'utente guest.
 */
class GuestPayments extends BaseController
{
    public function index()
    {
        $clientIds = session('user_clients');

        if (!is_array($clientIds) || empty($clientIds)) {
            $userId = (int) session('user_id');
            $clientIds = [];

            if ($userId) {
                $clientIds = array_map(
                    static fn (array $row) => (int) $row['client_id'],
                    (new UserClientModel())->assignedTo($userId)
                );
                session()->set('user_clients', $clientIds);
            }
        } else {
            $clientIds = array_values(array_map('intval', $clientIds));
        }

        $data = [
            'title'    => trans('payments'),
            'payments' => (new PaymentModel())->getForGuestClients($clientIds),
        ];

        return view('guest/payments_index', $data);
    }
}