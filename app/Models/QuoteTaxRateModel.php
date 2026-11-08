<?php

namespace App\Models;

/**
 * QuoteTaxRateModel
 *
 * Porting CI4 del modulo quotes (CI3: Mdl_Quote_Tax_Rates).
 * Tabella ip_quote_tax_rates: tasse del preventivo con join su ip_tax_rates;
 * save/delete con ricalcolo degli importi.
 */
class QuoteTaxRateModel extends IpBaseModel
{
    protected $table            = 'ip_quote_tax_rates';
    protected $primaryKey       = 'quote_tax_rate_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'quote_id',
        'tax_rate_id',
        'include_item_tax',
        'quote_tax_rate_amount',
    ];

    protected $validationRules = [
        'quote_id'         => 'required',
        'tax_rate_id'      => 'required',
        'include_item_tax' => 'required',
    ];

    protected $validationMessages = [
        'quote_id' => [
            'required' => 'Il preventivo e\' obbligatorio',
        ],
        'tax_rate_id' => [
            'required' => 'L\'aliquota e\' obbligatoria',
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
     * Tasche del preventivo con nome e percentuale dell'aliquota
     * (equivalente di default_select + default_join CI3).
     */
    public function getByQuote(int $quoteId): array
    {
        return $this->select("
                ip_tax_rates.tax_rate_name AS quote_tax_rate_name,
                ip_tax_rates.tax_rate_percent AS quote_tax_rate_percent,
                ip_quote_tax_rates.*", false)
            ->join('ip_tax_rates', 'ip_tax_rates.tax_rate_id = ip_quote_tax_rates.tax_rate_id')
            ->where('ip_quote_tax_rates.quote_id', $quoteId)
            ->orderBy('ip_quote_tax_rates.quote_tax_rate_id', 'ASC')
            ->findAll();
    }

    /**
     * Salva una tassa preventivo e ricalcola gli importi
     * (equivalente di save() CI3).
     */
    public function saveTaxRate(array $dbArray): int
    {
        $id = $this->insert($dbArray);

        if (isset($dbArray['quote_id'])) {
            (new QuoteAmountModel())->calculate((int) $dbArray['quote_id']);
        }

        return (int) $id;
    }

    /**
     * Elimina una tassa preventivo e ricalcola gli importi.
     */
    public function deleteTaxRate(int $quoteTaxRateId, int $quoteId): void
    {
        parent::delete($quoteTaxRateId);

        (new QuoteAmountModel())->calculate($quoteId);
    }
}