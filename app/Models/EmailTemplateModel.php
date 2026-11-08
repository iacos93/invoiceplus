<?php

namespace App\Models;

/**
 * EmailTemplateModel
 *
 * Porting CI4 di Mdl_Email_Templates (CI3): modelli email
 * (tabella ip_email_templates).
 */
class EmailTemplateModel extends IpBaseModel
{
    protected $table            = 'ip_email_templates';
    protected $primaryKey       = 'email_template_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'email_template_title',
        'email_template_type',
        'email_template_subject',
        'email_template_from_name',
        'email_template_from_email',
        'email_template_cc',
        'email_template_bcc',
        'email_template_pdf_template',
        'email_template_body',
    ];

    protected $validationRules = [
        'email_template_title' => 'required',
    ];

    protected $validationMessages = [
        'email_template_title' => [
            'required' => 'Il titolo e\' obbligatorio',
        ],
    ];

    public function validationRulesLegacy(): array
    {
        return [
            'email_template_title'        => ['field' => 'email_template_title', 'label' => trans('title'), 'rules' => 'required'],
            'email_template_type'         => ['field' => 'email_template_type', 'label' => trans('type'), 'rules' => 'permit_empty'],
            'email_template_subject'      => ['field' => 'email_template_subject', 'label' => trans('subject'), 'rules' => 'permit_empty'],
            'email_template_from_name'    => ['field' => 'email_template_from_name', 'label' => trans('from_name'), 'rules' => 'permit_empty'],
            'email_template_from_email'   => ['field' => 'email_template_from_email', 'label' => trans('from_email'), 'rules' => 'permit_empty'],
            'email_template_cc'           => ['field' => 'email_template_cc', 'label' => trans('cc'), 'rules' => 'permit_empty'],
            'email_template_bcc'          => ['field' => 'email_template_bcc', 'label' => trans('bcc'), 'rules' => 'permit_empty'],
            'email_template_pdf_template' => ['field' => 'email_template_pdf_template', 'label' => trans('default_pdf_template'), 'rules' => 'permit_empty'],
            'email_template_body'         => ['field' => 'email_template_body', 'label' => trans('body'), 'rules' => 'permit_empty'],
        ];
    }

    /**
     * Tutti i modelli email ordinati per titolo
     * (equivalente di default_order_by() CI3).
     */
    public function getAllOrdered(?int $perPage = null, int $page = 1): array
    {
        $this->orderBy('email_template_title', 'ASC');

        return $this->listFetch($perPage, $page);
    }

    /**
     * Cerca un modello email con lo stesso titolo
     * (usato per il controllo duplicati in creazione).
     */
    public function findDuplicateTitle(string $title): ?array
    {
        return $this->where('email_template_title', $title)->first();
    }
}