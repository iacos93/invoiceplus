<?php

namespace App\Models;

/**
 * EmailSenderModel — caselle da cui l'applicazione può spedire.
 */
class EmailSenderModel extends IpBaseModel
{
    protected $table            = 'ip_email_senders';
    protected $primaryKey       = 'sender_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'company_id', 'sender_name', 'sender_email',
        'sender_smtp_host', 'sender_smtp_port', 'sender_smtp_user',
        'sender_smtp_password', 'sender_smtp_security', 'sender_is_default',
    ];

    protected $validationRules = [
        'sender_name'          => 'required',
        'sender_email'         => 'required|valid_email',
        'sender_smtp_host'     => 'permit_empty',
        'sender_smtp_port'     => 'permit_empty|numeric',
        'sender_smtp_user'     => 'permit_empty',
        'sender_smtp_password' => 'permit_empty',
        'sender_smtp_security' => 'permit_empty',
        'sender_is_default'    => 'permit_empty',
    ];

    protected $validationMessages = [
        'sender_name'  => ['required' => 'Il nome mittente è obbligatorio'],
        'sender_email' => [
            'required'    => 'L\'indirizzo è obbligatorio',
            'valid_email' => 'L\'indirizzo non è valido',
        ],
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    public function getAllOrdered(?int $perPage = null, int $page = 1): array
    {
        $this->scopeToCompany()
             ->orderBy('sender_is_default', 'DESC')
             ->orderBy('sender_name', 'ASC');

        return $this->listFetch($perPage, $page);
    }

    public function getDefault(): ?array
    {
        $senders = $this->getAllOrdered();

        return $senders[0] ?? null;
    }

    /**
     * Un solo predefinito per azienda: altrimenti da quale casella parte il
     * messaggio dipenderebbe dall'ordine di lettura.
     */
    public function saveSender(?int $id, array $dbArray): int
    {
        $savedId = (int) $this->saveRecord($id, $dbArray);

        if (!empty($dbArray['sender_is_default'])) {
            $builder = $this->builder()->where('sender_id !=', $savedId);

            if ($companyId = active_company_id()) {
                $builder->where('company_id', $companyId);
            }

            $builder->update(['sender_is_default' => false]);
        }

        return $savedId;
    }
}
