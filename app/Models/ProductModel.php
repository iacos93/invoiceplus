<?php

namespace App\Models;

/**
 * ProductModel
 *
 * Porting CI4 del modulo products (CI3: Mdl_Products).
 * Include normalizzazione importi (product_price, purchase_price) secondo
 * i separatori configurati in ip_settings (decimal_point,
 * thousands_separator) e conversione delle foreign key vuote a NULL,
 * come nel db_array() originale.
 */
class ProductModel extends IpBaseModel
{
    protected $table            = 'ip_products';
    protected $primaryKey       = 'product_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'family_id',
        'product_sku',
        'product_name',
        'product_description',
        'product_price',
        'purchase_price',
        'provider_name',
        'tax_rate_id',
        'unit_id',
        // Necessario perché withCompany() (in IpBaseModel::saveRecord) non
        // venga scartato da insert(): senza, i prodotti nascevano con
        // company_id NULL e comparivano nel listino di tutte le aziende. Dal
        // form non arriva: dbArrayFromPost() filtra su validationRulesLegacy().
        'company_id',
    ];

    protected $validationRules = [
        'product_sku'         => 'permit_empty',
        'product_name'        => 'required',
        'product_description' => 'permit_empty',
        'product_price'       => 'required',
        'purchase_price'      => 'permit_empty',
        'provider_name'       => 'permit_empty',
        'family_id'           => 'permit_empty|numeric',
        'unit_id'             => 'permit_empty|numeric',
        'tax_rate_id'         => 'permit_empty|numeric',
    ];

    protected $validationMessages = [
        'product_name' => [
            'required' => 'Il nome del prodotto e\' obbligatorio',
        ],
        'product_price' => [
            'required' => 'Il prezzo e\' obbligatorio',
        ],
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * Lista con join su families/units/tax_rates (equivalente a
     * default_join() + default_order_by() in CI3), per mostrare i nomi
     * anziche' i soli id nella tabella prodotti.
     */
    public function getAllWithJoins(): array
    {
        $this->scopeToCompany();

        return $this->select('ip_products.*, ip_families.family_name, ip_units.unit_name, ip_tax_rates.tax_rate_percent')
            ->join('ip_families', 'ip_families.family_id = ip_products.family_id', 'left')
            ->join('ip_units', 'ip_units.unit_id = ip_products.unit_id', 'left')
            ->join('ip_tax_rates', 'ip_tax_rates.tax_rate_id = ip_products.tax_rate_id', 'left')
            ->orderBy('ip_families.family_name', 'ASC')
            ->orderBy('ip_products.product_name', 'ASC')
            ->findAll();
    }

    /**
     * Legge un separatore da ip_settings (equivalente a
     * $CI->mdl_settings->setting() usato in standardize_amount() CI3).
     */
    private function getSetting(string $key): ?string
    {
        $row = $this->db->table('ip_settings')
            ->select('setting_value')
            ->where('setting_key', $key)
            ->get()
            ->getRow();

        return $row->setting_value ?? null;
    }

    /**
     * Normalizza un importo secondo i separatori di sistema
     * (equivalente a standardize_amount() in CI3).
     */
    public function standardizeAmount(string $amount): string
    {
        $thousandsSeparator = $this->getSetting('thousands_separator');
        $decimalPoint       = $this->getSetting('decimal_point');

        if ($thousandsSeparator !== null && $thousandsSeparator !== '') {
            $amount = str_replace($thousandsSeparator, '', $amount);
        }
        if ($decimalPoint !== null && $decimalPoint !== '' && $decimalPoint !== '.') {
            $amount = str_replace($decimalPoint, '.', $amount);
        }

        return $amount;
    }

    /**
     * Costruisce l'array da salvare su DB a partire dal POST,
     * con la stessa normalizzazione del db_array() originale CI3:
     * importi standardizzati, foreign key vuote convertite a NULL.
     */
    public function dbArrayFromPost(array $post): array
    {
        $dbArray = parent::dbArrayFromPost($post);

        // L'azienda la decide withCompany() in creazione, mai il form.
        unset($dbArray['company_id']);

        $dbArray['product_price'] = empty($dbArray['product_price'])
            ? null
            : $this->standardizeAmount($dbArray['product_price']);

        $dbArray['purchase_price'] = empty($dbArray['purchase_price'] ?? null)
            ? null
            : $this->standardizeAmount($dbArray['purchase_price']);

        $dbArray['family_id']   = empty($dbArray['family_id'] ?? null) ? null : $dbArray['family_id'];
        $dbArray['unit_id']     = empty($dbArray['unit_id'] ?? null) ? null : $dbArray['unit_id'];
        $dbArray['tax_rate_id'] = empty($dbArray['tax_rate_id'] ?? null) ? null : $dbArray['tax_rate_id'];

        return $dbArray;
    }
}
