<?php

namespace App\Controllers;

use App\Models\UserModel;
use App\Models\UserClientModel;
use App\Models\ClientModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * UserClients controller
 *
 * Porting CI4 del controller User_Clients (CI3): assegnazione clienti agli
 * utenti (pagina clienti assegnati, creazione associazione, eliminazione).
 */
class UserClients extends BaseController
{
    public function user(int $id)
    {
        $user = (new UserModel())->find($id);

        if (!$user) {
            return redirect()->to('/users');
        }

        $userClientModel = new UserClientModel();
        $userClients     = $userClientModel->assignedTo($id);

        $assignedIds = array_map(static fn (array $uc) => $uc['client_id'], $userClients);

        // Stessa ragione di UserClientModel::getAllClients(): i clienti
        // assegnabili sono solo quelli dell'azienda attiva.
        $query = scope_builder_to_company(
            \Config\Database::connect()->table('ip_clients'),
            'ip_clients'
        )->where('client_active', 1);
        if ($assignedIds) {
            $query->whereNotIn('client_id', $assignedIds);
        }
        $clients = $query->orderBy('client_name', 'ASC')->get()->getResultArray();

        return view('user_clients/field', [
            'id'           => $id,
            'user'         => $user,
            'user_clients' => $userClients,
            'clients'      => $clients,
        ]);
    }

    public function create()
    {
        $request = service('request');

        // GET: pagina per assegnare clienti a un utente
        // (richiede la route GET user_clients/create, da aggiungere a Routes.php)
        if ($request->getMethod() === 'GET') {
            return view('user_clients/new', [
                'users'   => (new UserModel())->orderBy('user_name', 'ASC')->findAll(),
                'clients' => (new UserClientModel())->getAllClients(),
            ]);
        }

        if ($request->getPost('btn_cancel')) {
            return redirect()->to('/users');
        }

        $userId = (int) $request->getPost('user_id');

        if (!$userId) {
            session()->setFlashdata('alert_error', 'Seleziona un utente');

            return redirect()->to('/users');
        }

        $userClientModel = new UserClientModel();

        if ($request->getPost('user_all_clients')) {
            // Associa tutti i clienti all'utente
            $userClientModel->setAllClientsUser($userId);
            (new UserModel())->update($userId, ['user_all_clients' => 1]);

            session()->setFlashdata('alert_success', 'Tutti i clienti sono stati assegnati all\'utente');
        } else {
            $clientId = (int) $request->getPost('client_id');

            if (!$clientId) {
                session()->setFlashdata('alert_error', 'Seleziona un cliente');

                return redirect()->to('/user_clients/user/' . $userId);
            }

            // Anche l'assegnazione singola va verificata: il client_id arriva da
            // una select e senza questo controllo un id digitato a mano collegava
            // all'utente il cliente di un'altra azienda, con lo stesso effetto sul
            // portale descritto in setAllClientsUser().
            if (!(new ClientModel())->findScoped($clientId)) {
                session()->setFlashdata('alert_error', 'Cliente non valido');

                return redirect()->to('/user_clients/user/' . $userId);
            }

            $existing = $userClientModel->where('user_id', $userId)
                ->where('client_id', $clientId)
                ->first();

            if (!$existing) {
                $userClientModel->saveRecord(null, [
                    'user_id'   => $userId,
                    'client_id' => $clientId,
                ]);
            }

            (new UserModel())->update($userId, ['user_all_clients' => 0]);

            session()->setFlashdata('alert_success', 'Cliente assegnato correttamente');
        }

        return redirect()->to('/user_clients/user/' . $userId);
    }

    public function delete(int $userClientId)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $userClientModel = new UserClientModel();
        $userClient      = $userClientModel->find($userClientId);

        if (!$userClient) {
            return redirect()->to('/users');
        }

        $userClientModel->delete($userClientId);

        session()->setFlashdata('alert_success', 'Cliente rimosso correttamente');

        return redirect()->to('/user_clients/user/' . $userClient['user_id']);
    }
}