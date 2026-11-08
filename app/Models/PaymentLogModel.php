<?php

namespace App\Models;

/**
 * PaymentLogModel
 *
 * Porting CI4 di Mdl_Payment_Logs (CI3): log delle risposte dei
 * gateway di pagamento online (tabella ip_merchant_responses) con
 * join sul numero fattura.
 */
class PaymentLogModel extends IpBaseModel
{
    protected $table            = 'ip_merchant_responses';
    protected $primaryKey       = 'merchant_response_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'invoice_id',
        'merchant_response_successful',
        'merchant_response_date',
        'merchant_response_driver',
        'merchant_response',
        'merchant_response_reference',
    ];

    /**
     * Log ordinati per id decrescente (equivalente di default_select() +
     * default_order_by() + default_join() CI3).
     */
    public function getAllOrdered(): array
    {
        return $this->select('ip_merchant_responses.*, ip_invoices.invoice_number', false)
            ->join('ip_invoices', 'ip_invoices.invoice_id = ip_merchant_responses.invoice_id')
            ->orderBy('ip_merchant_responses.merchant_response_id', 'DESC')
            ->findAll();
    }
}