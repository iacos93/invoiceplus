<?php

namespace App\Traits;

use App\Models\UserClientModel;

/**
 * Clienti assegnati all'utente del portale guest.
 *
 * La stessa lettura era copiata in Guest, GuestInvoices e GuestQuotes. Qui si
 * decide quali documenti un cliente puo' vedere: tre copie sono tre occasioni
 * di divergere su un controllo di accesso, non solo tre volte lo stesso codice.
 */
trait ResolvesGuestClients
{
    /**
     * Id dei clienti assegnati all'utente guest: dalla sessione
     * 'user_clients' oppure, come fallback, dalla tabella ip_user_clients.
     */
    protected function clientIds(): array
    {
        $clientIds = session('user_clients');

        if (!is_array($clientIds) || empty($clientIds)) {
            $userId = (int) session('user_id');

            if ($userId) {
                $clientIds = array_map(
                    static fn (array $row) => (int) $row['client_id'],
                    (new UserClientModel())->assignedTo($userId)
                );
                session()->set('user_clients', $clientIds);
            }
        }

        return is_array($clientIds) ? array_values(array_map('intval', $clientIds)) : [];
    }
}
