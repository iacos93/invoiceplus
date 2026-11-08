<?php

namespace App\Controllers;

use App\Models\UserModel;
use App\Models\UserClientModel;
use App\Models\UserCustomModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Users controller
 *
 * Porting CI4 del controller Users (CI3): gestione utenti (lista, form,
 * cambio password, eliminazione) e clienti assegnati.
 */
class Users extends BaseController
{
    protected UserModel $userModel;

    protected $db;

    public function __construct()
    {
        $this->userModel = new UserModel();
        $this->db        = \Config\Database::connect();
    }

    public function index()
    {
        $data = [
            'users'      => $this->userModel->orderBy('user_name', 'ASC')->findAll(),
            'user_types' => $this->userModel->userTypes(),
        ];

        return view('users/index', $data);
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/users');
            }

            $post = $request->getPost();

            $rules    = $id ? $this->userModel->rulesForExisting() : $this->userModel->rulesForCreate();
            $messages = $id ? $this->userModel->messagesForExisting() : $this->userModel->messagesForCreate();

            $validation = service('validation');
            $validation->setRules($rules, $messages);

            if ($validation->run($post)) {
                $dbArray = $id
                    ? $this->userModel->dbArrayForExisting($post)
                    : $this->userModel->dbArrayFromPost($post);

                // Booleano nativo: PostgreSQL rifiuta gli interi su colonne boolean.
                $dbArray['user_active'] = !empty($post['user_active']);

                $wasNew = $id === null;
                $id     = (int) $this->userModel->saveRecord($id, $dbArray);

                // Campi personalizzati (ip_user_custom)
                $custom = $post['custom'] ?? [];
                if ($custom) {
                    (new UserCustomModel())->saveCustom($id, $custom);
                }

                // Clienti accumulati in sessione (utente nuovo) -> DB
                if ($userClients = session('user_clients')) {
                    $userClientModel = new UserClientModel();
                    foreach ($userClients as $clientId) {
                        $userClientModel->saveRecord(null, [
                            'user_id'   => $id,
                            'client_id' => (int) $clientId,
                        ]);
                    }
                }

                // L'utente ha modificato il proprio account: aggiorna la sessione
                if (session('user_id') == $id) {
                    $user = $this->userModel->find($id);

                    if ($user) {
                        session()->set([
                            'user_type'     => $user['user_type'],
                            'user_id'       => $user['user_id'],
                            'user_name'     => $user['user_name'],
                            'user_email'    => $user['user_email'],
                            'user_company'  => $user['user_company'],
                            'user_language' => $user['user_language'] ?? 'system',
                        ]);
                    }
                }

                session()->remove('user_clients');

                session()->setFlashdata('alert_success', $wasNew
                    ? 'Utente creato correttamente'
                    : 'Utente aggiornato correttamente');

                return redirect()->to('/users');
            }

            // Validazione fallita: ripopola i valori dal post per la view
            $this->userModel->formValues = $post;

            $data = $this->formViewData($id);
            $data['errors'] = $validation->getErrors();

            return view('users/form', $data);
        }

        if ($id && !$this->userModel->prepForm($id)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $data = $this->formViewData($id);
        $data['errors'] = [];

        return view('users/form', $data);
    }

    public function changePassword(int $userId)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/users');
            }

            $post = $request->getPost();

            $validation = service('validation');
            $validation->setRules(
                $this->userModel->rulesForChangePassword(),
                $this->userModel->messagesForChangePassword()
            );

            if ($validation->run($post)) {
                $this->userModel->saveChangePassword($userId, (string) $post['user_password']);

                session()->setFlashdata('alert_success', trans('password_changed'));

                return redirect()->to('/users/form/' . $userId);
            }

            return view('users/form_change_password', [
                'user_id' => $userId,
                'errors'  => $validation->getErrors(),
            ]);
        }

        return view('users/form_change_password', [
            'user_id' => $userId,
            'errors'  => [],
        ]);
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        if ($id === 1) {
            session()->setFlashdata('alert_error', 'Non e\' possibile eliminare l\'utente amministratore principale');

            return redirect()->to('/users');
        }

        $this->userModel->delete($id);

        // Elimina anche le assegnazioni e i campi personalizzati orfani
        (new UserClientModel())->where('user_id', $id)->delete();
        (new UserCustomModel())->where('user_id', $id)->delete();

        session()->setFlashdata('alert_success', 'Utente eliminato correttamente');

        return redirect()->to('/users');
    }

    public function deleteUserClient(int $userId, int $userClientId)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        (new UserClientModel())->delete($userClientId);

        session()->setFlashdata('alert_success', 'Cliente rimosso correttamente');

        return redirect()->to('/users/form/' . $userId);
    }

    /**
     * Dati condivisi dalla view users/form.
     */
    private function formViewData(?int $id): array
    {
        // I clienti assegnati non stanno piu' in questa schermata: hanno una
        // pagina propria (user_clients/user/{id}, raggiungibile dal bottone
        // "Clienti assegnati" nella lista utenti) che fa la stessa cosa senza
        // duplicarla dentro il form. Con la card sono uscite anche le due
        // query sui clienti, che ora non alimentavano piu' nulla.
        $mfa = null;

        if ($id && $id !== (int) session('user_id')) {
            $user = $this->userModel->find($id);
            $mfa  = $user ? db_bool($user['user_mfa_enabled']) : false;
        }

        return [
            'id'         => $id,
            'model'      => $this->userModel,
            'user_types' => $this->userModel->userTypes(),
            'languages'  => ['system' => 'System', 'it' => 'Italiano', 'en' => 'English'],
            'mfa_enabled' => $mfa,
            // Proprio account: lo stato di MFA e passkey per la scheda riassuntiva
            // del profilo; la gestione sta nella pagina dedicata /security.
            'security_panel' => $id && $id === (int) session('user_id')
                ? Security::panelData($id)
                : null,
        ];
    }

    /**
     * Reset della verifica in due passaggi di un altro utente (admin).
     *
     * Chi ha perso il telefono e i codici di recupero non può disattivare la
     * propria MFA da solo (il disable richiede un codice valido): serve
     * l'intervento di un amministratore. Le passkey dell'utente restano
     * intatte — sono un fattore separato, valido anche senza TOTP.
     */
    public function resetMfa(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        if ($id === (int) session('user_id')) {
            session()->setFlashdata('alert_error', 'Non puoi resettare la MFA del tuo stesso account da qui: usa la pagina Sicurezza account.');

            return redirect()->to('/users/form/' . $id);
        }

        $user = $this->userModel->find($id);

        if (!$user) {
            session()->setFlashdata('alert_error', trans('loginalert_user_not_found'));

            return redirect()->to('/users');
        }

        $this->userModel->updateMfaFields($id, [
            'user_mfa_secret'       => null,
            'user_mfa_enabled'      => false,
            'user_mfa_backup_codes' => null,
            'user_mfa_last_counter' => null,
        ]);

        session()->setFlashdata('alert_success', 'Verifica in due passaggi resettata. L\'utente può riattivarla dalla sua pagina Sicurezza account.');

        return redirect()->to('/users/form/' . $id);
    }
}