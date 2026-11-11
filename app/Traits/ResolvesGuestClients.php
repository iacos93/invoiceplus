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
    /** @var list<int>|null letti una volta per richiesta */
    private ?array $guestClientIds = null;

    /**
     * Id dei clienti assegnati all'utente guest, letti da ip_user_clients.
     *
     * Prima l'elenco veniva messo in sessione ('user_clients') e riletto da
     * li': una revoca fatta dall'amministratore non aveva effetto finche'
     * l'utente non usciva, e nel frattempo poteva ancora vedere i documenti
     * e decidere sui preventivi. E' un controllo di accesso: vale una query
     * a richiesta. Quella chiave di sessione resta ai soli form utenti
     * dell'area admin, che la usano per altro.
     */
    protected function clientIds(): array
    {
        if ($this->guestClientIds !== null) {
            return $this->guestClientIds;
        }

        $userId = (int) session('user_id');

        return $this->guestClientIds = $userId
            ? (new UserClientModel())->clientIdsFor($userId)
            : [];
    }
}
