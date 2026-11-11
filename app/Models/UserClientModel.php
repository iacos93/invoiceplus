<?php

namespace App\Models;

class UserClientModel extends IpBaseModel
{
    protected $table            = 'ip_user_clients';
    protected $primaryKey       = 'user_client_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'user_id',
        'client_id',
    ];

    public function validationRulesLegacy(): array
    {
        return [
            'user_id'   => 'required',
            'client_id' => 'required',
        ];
    }

    /**
     * Clienti assegnati a un utente con dati cliente e utente in join.
     */
    public function assignedTo(int $userId): array
    {
        return $this->select('ip_user_clients.*, ip_users.user_name, ip_clients.client_name, ip_clients.client_surname')
            ->join('ip_users', 'ip_users.user_id = ip_user_clients.user_id')
            ->join('ip_clients', 'ip_clients.client_id = ip_user_clients.client_id')
            ->where('ip_user_clients.user_id', $userId)
            ->orderBy('ip_clients.client_name', 'ASC')
            ->findAll();
    }

    /**
     * Soli id dei clienti assegnati a un utente: e' la lettura del controllo
     * di accesso del portale (ResolvesGuestClients), fatta a ogni richiesta,
     * quindi senza i join di assignedTo().
     *
     * @return list<int>
     */
    public function clientIdsFor(int $userId): array
    {
        $rows = $this->db->table($this->table)
            ->select('client_id')
            ->where('user_id', $userId)
            ->get()
            ->getResultArray();

        return array_values(array_map('intval', array_column($rows, 'client_id')));
    }

    /**
     * Assegna tutti i clienti attivi agli utenti indicati.
     *
     * @param int|array<int> $usersId
     */
    public function setAllClientsUser($usersId): void
    {
        $userIds = (array) $usersId;

        // scopeToCompany(): senza perimetro la casella "tutti i clienti" assegnava
        // all'utente i clienti attivi di TUTTE le aziende. Non e' un difetto
        // teorico: ip_user_clients e' quello che il portale cliente usa per
        // decidere cosa mostrare, e i controller Guest* non rifiltrano per
        // azienda, quindi l'utente guest di un'azienda si ritrovava a vedere
        // fatture, preventivi e pagamenti di un'altra. Basta un click ordinario.
        $clients = (new ClientModel())->scopeToCompany()->where('client_active', 1)->findAll();

        foreach ($userIds as $userId) {
            foreach ($clients as $client) {
                $exists = $this->where('user_id', $userId)
                    ->where('client_id', $client['client_id'])
                    ->first();

                if (!$exists) {
                    $this->insert([
                        'user_id'   => $userId,
                        'client_id' => $client['client_id'],
                    ]);
                }
            }
        }
    }

    /**
     * Tutti i clienti attivi (per la checkbox "tutti i clienti").
     */
    public function getAllClients(): array
    {
        // Builder grezzo: il perimetro passa da scope_builder_to_company(),
        // altrimenti la tendina proponeva i clienti di tutte le aziende.
        return scope_builder_to_company($this->db->table('ip_clients'), 'ip_clients')
            ->where('client_active', 1)
            ->orderBy('client_name', 'ASC')
            ->get()
            ->getResultArray();
    }
}