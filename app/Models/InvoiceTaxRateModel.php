<?php

namespace App\Models;

/**
 * InvoiceTaxRateModel
 *
 * Porting CI4 di Mdl_Invoice_Tax_Rates (CI3): imposte a livello di
 * fattura (tabella ip_invoice_tax_rates) con join su ip_tax_rates
 * per nome e percentuale, ricalcolo degli importi dopo salvataggio
 * o cancellazione.
 */
class InvoiceTaxRateModel extends IpBaseModel
{
    protected $table            = 'ip_invoice_tax_rates';
    protected $primaryKey       = 'invoice_tax_rate_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'invoice_id',
        'tax_rate_id',
        'include_item_tax',
        'invoice_tax_rate_amount',
    ];

    protected $validationRules = [
        'invoice_id'       => 'required',
        'tax_rate_id'      => 'required',
        'include_item_tax' => 'required',
    ];

    protected $validationMessages = [
        'invoice_id' => [
            'required' => "L'id fattura e' obbligatorio",
        ],
        'tax_rate_id' => [
            'required' => "L'imposta e' obbligatoria",
        ],
        'include_item_tax' => [
            'required' => 'Il posizionamento imposta e\' obbligatorio',
        ],
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * Imposte di una fattura con nome e percentuale (come default_select()
     * + default_join() in CI3).
     */
    public function getByInvoice(int $invoiceId): array
    {
        return $this->select('ip_tax_rates.tax_rate_name AS invoice_tax_rate_name, ip_tax_rates.tax_rate_percent AS invoice_tax_rate_percent, ip_invoice_tax_rates.*')
            ->join('ip_tax_rates', 'ip_tax_rates.tax_rate_id = ip_invoice_tax_rates.tax_rate_id')
            ->where('ip_invoice_tax_rates.invoice_id', $invoiceId)
            ->orderBy('ip_invoice_tax_rates.invoice_tax_rate_id', 'ASC')
            ->findAll();
    }

    /**
     * Salva un'imposta e ricalcola gli importi della fattura
     * (equivalente di save() CI3).
     */
    public function saveTaxRate(?int $id = null, ?array $dbArray = null): int
    {
        $invoiceId = $dbArray['invoice_id'] ?? (int) service('request')->getPost('invoice_id');

        $savedId = (int) $this->saveRecord($id, $dbArray);

        if ($invoiceId) {
            $amounts = new InvoiceAmountModel();
            $amounts->calculateInvoiceTaxes($invoiceId);
            $amounts->calculate($invoiceId);
        }

        return $savedId;
    }

    /**
     * Elimina un'imposta e ricalcola gli importi della fattura.
     */
    public function deleteWithRecalculate(int $invoiceTaxRateId, int $invoiceId): void
    {
        $this->delete($invoiceTaxRateId);

        $amounts = new InvoiceAmountModel();
        $amounts->calculateInvoiceTaxes($invoiceId);
        $amounts->calculate($invoiceId);
    }
}