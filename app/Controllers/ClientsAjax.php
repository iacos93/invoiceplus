<?php

namespace App\Controllers;

use App\Models\ClientModel;
use App\Models\ClientNoteModel;

/**
 * ClientsAjax controller
 *
 * Porting CI4 del controller Ajax del modulo clients (CI3):
 * ricerca clienti per nome (name_query), ultimi clienti attivi
 * (get_latest) e salvataggio note cliente (save_client_note).
 */
class ClientsAjax extends BaseController
{
    protected ClientModel $clientModel;

    public function __construct()
    {
        $this->clientModel = new ClientModel();
    }

    /**
     * Ricerca clienti attivi per nome, cognome o nome completo
     * (equivalente di name_query() in CI3, su POST).
     */
    public function nameQuery()
    {
        $query = $this->request->getPost('query');

        $clients = [];
        if (!empty($query)) {
            $db = \Config\Database::connect();

            // db->escape() e non solo escapeLikeString(): quest'ultimo sfugge
            // solo i caratteri jolly della LIKE (% e _), non gli apici. Con
            // 'null, false' (auto-escape disattivato) il valore va quotato a
            // mano qui, altrimenti un apice nella ricerca esce dalla stringa
            // SQL -- era l'unico punto del codebase senza questo wrapper,
            // gli altri sono in likeExpression()/Search.php.
            $pattern = $db->escape('%' . $db->escapeLikeString($query) . '%');

            // scopeToCompany(): la ricerca restituiva l'anagrafica di tutte le
            // aziende. getLatest(), due metodi sotto, lo faceva gia' correttamente.
            $clients = $this->clientModel
                ->scopeToCompany()
                ->select("ip_clients.client_id, ip_clients.client_name, ip_clients.client_surname, CONCAT(ip_clients.client_name, ' ', ip_clients.client_surname) AS client_fullname", false)
                ->where('client_active', 1)
                ->groupStart()
                // LOWER() sui due lati come nelle altre ricerche: su PostgreSQL
                // LIKE distingue le maiuscole, quindi l'autocompletamento non
                // trovava "Rossi" digitando "rossi".
                ->where("LOWER(ip_clients.client_name) LIKE LOWER({$pattern})", null, false)
                ->orWhere("LOWER(ip_clients.client_surname) LIKE LOWER({$pattern})", null, false)
                ->orWhere("LOWER(CONCAT(ip_clients.client_name, ' ', ip_clients.client_surname)) LIKE LOWER({$pattern})", null, false)
                ->groupEnd()
                ->orderBy('ip_clients.client_name', 'ASC')
                ->limit(100)
                ->findAll();
        }

        $response = [];
        foreach ($clients as $client) {
            $response[] = [
                'client_id'      => $client['client_id'],
                'client_fullname' => htmlsc($client['client_fullname']),
                'client_name'    => $client['client_name'],
            ];
        }

        return $this->response->setJSON($response);
    }

    /**
     * Ultimi 5 clienti attivi (equivalente di get_latest() in CI3).
     */
    public function getLatest()
    {
        $response = [];
        foreach ($this->clientModel->getLatest(5) as $client) {
            $response[] = [
                'client_id'       => $client['client_id'],
                'client_fullname' => htmlsc($client['client_fullname']),
                'client_name'     => $client['client_name'],
            ];
        }

        return $this->response->setJSON($response);
    }

    /**
     * Salva una nota cliente (equivalente di save_client_note() in CI3).
     */
    public function saveClientNote()
    {
        $noteModel = new ClientNoteModel();
        $post      = $this->request->getPost();

        $response = [
            'success'   => 0,
            'new_token' => csrf_hash(),
        ];

        // Il client_id arriva da un input hidden, quindi e' modificabile lato
        // client: va verificato contro l'azienda attiva prima di scrivere,
        // altrimenti basta cambiare quel campo per lasciare una nota sulla
        // scheda di un cliente di un'altra azienda.
        if (empty($post['client_id']) || !$this->clientModel->findScoped((int) $post['client_id'])) {
            $response['validation_errors'] = ['client_id' => 'Cliente non valido'];

            return $this->response->setJSON($response);
        }

        if ($noteModel->validate($post)) {
            $noteModel->saveRecord(null, $noteModel->dbArrayFromPost($post));
            $response['success'] = 1;
        } else {
            $response['validation_errors'] = $noteModel->errors();
        }

        return $this->response->setJSON($response);
    }
}