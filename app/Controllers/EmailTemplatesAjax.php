<?php

namespace App\Controllers;

use App\Models\EmailTemplateModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * EmailTemplatesAjax controller
 *
 * Porting CI4 del controller Ajax del modulo email_templates (CI3):
 * restituisce il contenuto di un modello email in JSON per popolare
 * il form di invio (mailer).
 */
class EmailTemplatesAjax extends BaseController
{
    public function getContent()
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $id       = (int) $request->getPost('email_template_id');
        $template = (new EmailTemplateModel())->find($id) ?: [];

        return $this->response->setJSON([
            'email_template_body'          => $template['email_template_body'] ?? '',
            'email_template_subject'       => $template['email_template_subject'] ?? '',
            'email_template_from_name'     => $template['email_template_from_name'] ?? '',
            'email_template_from_email'    => $template['email_template_from_email'] ?? '',
            'email_template_cc'            => $template['email_template_cc'] ?? '',
            'email_template_bcc'           => $template['email_template_bcc'] ?? '',
            'email_template_pdf_template'  => $template['email_template_pdf_template'] ?? '',
        ]);
    }
}