<?php

namespace App\Controllers;

use App\Models\ClientModel;
use App\Models\UserClientModel;

/**
 * UsersAjax controller
 *
 * Porting CI4 del controller Ajax (CI3, modulo users): gestione AJAX dei
 * clienti assegnati agli utenti (salvataggio, tabella, lista clienti).
 * Gli endpoint restituiscono JSON (o HTML parziale) e il filtro CSRF non li
 * protegge direttamente: le richieste AJAX inviano comunque il token.
 */
class UsersAjax extends BaseController
{
    public function saveUserClient()
    {
        $request  = service('request');
        $userId   = (int) $request->getPost('user_id');
        $clientId = (int) $request->getPost('client_id');

        if (!$clientId) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Cliente non valido',
                'token'   => csrf_hash(),
            ]);
        }

        // findScoped() e non una query grezza: senza perimetro azienda, il
        // modale "Aggiungi cliente" del form Utenti poteva assegnare a un
        // utente guest un cliente di un'altra azienda (stesso baco gia'
        // corretto in UserClients::create(), vedi commento li').
        $client = (new ClientModel())->findScoped($clientId);

        if (!$client) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Cliente non trovato',
                'token'   => csrf_hash(),
            ]);
        }

        if ($userId) {
            // Utente esistente: salva direttamente in ip_user_clients
            $userClientModel = new UserClientModel();
            $existing = $userClientModel->where('user_id', $userId)
                ->where('client_id', $clientId)
                ->first();

            if (!$existing) {
                $userClientModel->saveRecord(null, [
                    'user_id'   => $userId,
                    'client_id' => $clientId,
                ]);
            }
        } else {
            // Utente nuovo: accumula in sessione fino al salvataggio dell'utente
            $userClients = session('user_clients') ?: [];
            $userClients[$clientId] = $clientId;
            session()->set('user_clients', $userClients);
        }

        return $this->response->setJSON([
            'success' => true,
            'token'   => csrf_hash(),
        ]);
    }

    public function loadUserClientTable()
    {
        $request = service('request');
        $userId  = (int) $request->getPost('user_id');

        if ($sessionUserClients = session('user_clients')) {
            // Utente nuovo: clienti dalla sessione
            $db = \Config\Database::connect();

            $userClients = $db->table('ip_clients')
                ->whereIn('client_id', array_values($sessionUserClients))
                ->get()
                ->getResultArray();

            return view('users/modal_user_client', [
                'id'           => null,
                'user_clients' => $userClients,
            ]);
        }

        // Utente esistente: clienti dal DB
        return view('users/modal_user_client', [
            'id'           => $userId,
            'user_clients' => (new UserClientModel())->assignedTo($userId),
        ]);
    }

    public function modalAddUserClient(?int $userId = null)
    {
        $db = \Config\Database::connect();

        $assignedIds = [];

        if ($userId) {
            $rows       = (new UserClientModel())->assignedTo($userId);
            $assignedIds = array_map(static fn (array $row) => $row['client_id'], $rows);
        } elseif (session('user_clients')) {
            $assignedIds = array_values(session('user_clients'));
        }

        // scope_builder_to_company(): stesso baco della select clienti in
        // UserClients::user() se non applicato -- il modale mostrava anche
        // clienti di altre aziende, assegnabili a un utente guest.
        $query = scope_builder_to_company($db->table('ip_clients'), 'ip_clients')
            ->where('client_active', 1);
        if ($assignedIds) {
            $query->whereNotIn('client_id', $assignedIds);
        }

        return $this->response->setJSON([
            'success' => true,
            'clients' => $query->orderBy('client_name', 'ASC')->get()->getResultArray(),
            'token'   => csrf_hash(),
        ]);
    }
}