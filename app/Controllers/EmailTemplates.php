<?php

namespace App\Controllers;

use App\Models\EmailTemplateModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * EmailTemplates controller
 *
 * Porting CI4 del controller Email_templates (CI3): lista dei modelli
 * email, form di creazione/modifica (con controllo duplicati) e delete.
 */
class EmailTemplates extends BaseController
{
    protected EmailTemplateModel $emailTemplateModel;

    public function __construct()
    {
        $this->emailTemplateModel = new EmailTemplateModel();
    }

    public function index()
    {
        $data = [
            'email_templates' => $this->emailTemplateModel->getAllOrdered(list_per_page(), list_page('ip_email_templates')),
            'pager'           => $this->emailTemplateModel->pager,
            'group'           => 'ip_email_templates',
        ];

        return view('email_templates/index', $data);
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/email_templates');
            }

            $post = $request->getPost();

            // Controllo duplicati solo in creazione (come CI3)
            if ($id === null && !empty($post['email_template_title'])) {
                if ($this->emailTemplateModel->findDuplicateTitle((string) $post['email_template_title'])) {
                    session()->setFlashdata('alert_error', trans('email_template_already_exists'));

                    return redirect()->to('/email_templates/form');
                }
            }

            if ($this->emailTemplateModel->validate($post)) {
                $dbArray = $this->emailTemplateModel->dbArrayFromPost($post);
                $this->emailTemplateModel->saveRecord($id, $dbArray);

                session()->setFlashdata('alert_success', $id
                    ? 'Modello email aggiornato correttamente'
                    : 'Modello email creato correttamente');

                return redirect()->to('/email_templates');
            }

            // Validazione fallita: ripopola i valori dal post per la view
            $this->emailTemplateModel->formValues = $post;

            $data = [
                'email_template_id' => $id,
                'errors'            => $this->emailTemplateModel->errors(),
                'model'             => $this->emailTemplateModel,
                'pdf_templates'     => $this->getPdfTemplates(),
            ];

            return view('email_templates/form', $data);
        }

        if ($id) {
            if (!$this->emailTemplateModel->prepForm($id)) {
                throw PageNotFoundException::forPageNotFound();
            }
        } else {
            // In creazione precompila oggetto e corpo con un modello di default
            // che usa i tag piu' comuni, cosi' l'utente parte da una base valida.
            $this->emailTemplateModel->formValues = [
                'email_template_subject' => 'Fattura {{invoice_number}}',
                'email_template_body'    => "Gentile {{client_name}},\n\n"
                    . "in allegato trovi la fattura {{invoice_number}} del {{invoice_date_created}}.\n\n"
                    . "Importo totale: {{invoice_total}}\n"
                    . "Saldo dovuto: {{invoice_balance}}\n\n"
                    . "Puoi visualizzare e scaricare la fattura al link:\n"
                    . "{{invoice_guest_url}}\n\n"
                    . "Cordiali saluti,\n"
                    . "{{user_name}}\n"
                    . "{{user_company}}",
            ];
        }

        $data = [
            'email_template_id' => $id,
            'errors'            => [],
            'model'             => $this->emailTemplateModel,
            'pdf_templates'     => $this->getPdfTemplates(),
        ];

        return view('email_templates/form', $data);
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->emailTemplateModel->delete($id);
        session()->setFlashdata('alert_success', 'Modello email eliminato correttamente');

        return redirect()->to('/email_templates');
    }

    /**
     * Elenco dei template PDF disponibili: scansiona le cartelle
     * invoice_templates e quote_templates; fallback su lista statica.
     */
    public function getPdfTemplates(): array
    {
        $templates = ['invoices' => [], 'quotes' => []];

        foreach (glob(APPPATH . 'Views/invoice_templates/*.pdf.php') ?: [] as $file) {
            $name = basename($file, '.pdf.php');
            $templates['invoices'][$name] = $name;
        }

        foreach (glob(APPPATH . 'Views/quote_templates/pdf/*.php') ?: [] as $file) {
            $name = basename($file, '.php');
            $templates['quotes'][$name] = $name;
        }

        if (empty($templates['invoices']) && empty($templates['quotes'])) {
            $templates = ['invoices' => ['default' => 'default'], 'quotes' => []];
        }

        return $templates;
    }
}