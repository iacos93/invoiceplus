<?php

namespace App\Controllers;

use App\Models\CompanyModel;
use App\Models\EmailSenderModel;

/**
 * EmailSenders — caselle mittente selezionabili all'invio.
 *
 * La pagina indice ospita anche la configurazione email generale
 * (allegato PDF, mittente di sistema) che prima stava nel tab Email
 * delle Impostazioni: cosi' tutto il folklore email vive in un unico
 * posto.
 */
class EmailSenders extends BaseController
{
    protected EmailSenderModel $model;

    public function __construct()
    {
        $this->model = new EmailSenderModel();
    }

    public function index()
    {
        $request = service('request');

        if (strtolower((string) $request->getMethod()) === 'post') {
            $settings = $request->getPost('settings');

            if (is_array($settings)) {
                $settingModel = new \App\Models\SettingModel();

                foreach ($settings as $key => $value) {
                    if ($key === 'company_system_sender_id') {
                        $companyId = active_company_id();

                        if ($companyId) {
                            (new CompanyModel())->update($companyId, [
                                'company_system_sender_id' => (int) $value,
                            ]);
                        }
                    } else {
                        $settingModel->setSetting($key, $value);
                    }
                }

                session()->setFlashdata('alert_success', 'Configurazione email salvata');
            }

            return redirect()->to('/email_senders');
        }

        return view('email_senders/index', [
            'senders' => $this->model->getAllOrdered(list_per_page(), list_page('ip_email_senders')),
            'pager'   => $this->model->pager,
            'group'   => 'ip_email_senders',
        ]);
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            $post = $request->getPost();

            // Perimetro azienda anche in scrittura: la GET controlla già
            // l'appartenenza, ma il POST con lo stesso id la scavalcherebbe
            // (update() filtra solo per chiave primaria).
            if ($id && !$this->model->findScoped($id)) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }

            // Booleano nativo: PostgreSQL rifiuta gli interi su colonne boolean.
            $post['sender_is_default'] = !empty($post['sender_is_default']);

            if ($this->model->validate($post)) {
                $dbArray = $this->model->dbArrayFromPost($post);

                // Password vuota in modifica: si mantiene quella salvata, invece
                // di azzerarla perché il campo è stato lasciato in bianco.
                if ($id && ($dbArray['sender_smtp_password'] ?? '') === '') {
                    unset($dbArray['sender_smtp_password']);
                } elseif (($dbArray['sender_smtp_password'] ?? '') !== '') {
                    // Cifrata con Cryptor (encrypt_secret): mai in chiaro nel DB.
                    // decrypt_secret() alla lettura gestisce anche le righe
                    // salvate prima dell'introduzione di questa cifratura.
                    $dbArray['sender_smtp_password'] = encrypt_secret((string) $dbArray['sender_smtp_password']);
                }

                $this->model->saveSender($id, $dbArray);
                session()->setFlashdata('alert_success', 'Mittente salvato correttamente');

                return redirect()->to('/email_senders');
            }

            $this->model->formValues = $post;

            return view('email_senders/form', [
                'sender_id' => $id, 'errors' => $this->model->errors(), 'model' => $this->model,
            ]);
        }

        // findScoped() prima di prepForm(): il record va cercato dentro il
        // perimetro dell'azienda attiva, altrimenti con l'id nell'URL si apre
        // in modifica la scheda di un altro soggetto giuridico -- e il
        // salvataggio successivo la riscrive senza spostarla, quindi la
        // modifica resta invisibile a chi la subisce.
        if ($id && (!$this->model->findScoped($id) || !$this->model->prepForm($id))) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        return view('email_senders/form', [
            'sender_id' => $id, 'errors' => [], 'model' => $this->model,
        ]);
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Il perimetro azienda si verifica prima di cancellare: con l'id nel
        // POST si distruggeva il record di un altro soggetto giuridico, e una
        // cancellazione non si annulla.
        if (!$this->model->findScoped($id)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $this->model->delete($id);
        session()->setFlashdata('alert_success', 'Mittente eliminato correttamente');

        return redirect()->to('/email_senders');
    }
}
