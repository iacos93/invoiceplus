<?php

namespace App\Models;

/**
 * QuoteItemModel
 *
 * Porting CI4 del modulo quotes (CI3: Mdl_Quote_Items).
 * Tabella ip_quote_items: righe di preventivo con join su importi riga,
 * aliquote e prodotti; save/delete con ricalcolo importi.
 */
class QuoteItemModel extends IpBaseModel
{
    protected $table            = 'ip_quote_items';
    protected $primaryKey       = 'item_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'quote_id',
        'item_tax_rate_id',
        'item_product_id',
        'item_code',
        'item_name',
        'item_description',
        'item_quantity',
        'item_price',
        'item_product_unit_id',
        'item_product_unit',
        'item_discount_amount',
        'item_discount_percent',
        'item_order',
        'item_date_added',
    ];

    protected $validationRules = [
        'quote_id'          => 'required',
        'item_name'         => 'required',
        'item_description'  => 'permit_empty',
        'item_quantity'     => 'permit_empty',
        'item_price'        => 'permit_empty',
        'item_tax_rate_id'  => 'permit_empty',
        'item_product_id'   => 'permit_empty',
        'item_code'         => 'permit_empty|max_length[60]',
        'item_discount_percent' => 'permit_empty',
    ];

    protected $validationMessages = [
        'quote_id' => [
            'required' => 'Il preventivo e\' obbligatorio',
        ],
        'item_name' => [
            'required' => 'Il nome dell\'articolo e\' obbligatorio',
        ],
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * Righe del preventivo con importi, aliquota e prodotto
     * (equivalente di default_select + default_join CI3).
     */
    public function getByQuote(int $quoteId): array
    {
        return $this->select("
                ip_quote_item_amounts.*,
                ip_quote_items.*,
                item_tax_rates.tax_rate_percent AS item_tax_rate_percent,
                ip_products.product_sku,
                ip_products.product_name AS product_name", false)
            ->join('ip_quote_item_amounts', 'ip_quote_item_amounts.item_id = ip_quote_items.item_id', 'left')
            ->join('ip_tax_rates AS item_tax_rates', 'item_tax_rates.tax_rate_id = ip_quote_items.item_tax_rate_id', 'left')
            ->join('ip_products', 'ip_products.product_id = ip_quote_items.item_product_id', 'left')
            ->where('ip_quote_items.quote_id', $quoteId)
            ->orderBy('ip_quote_items.item_order', 'ASC')
            ->findAll();
    }

    /**
     * Salva una riga (insert/update), ricalcola gli importi riga e
     * quelli del preventivo (equivalente di save() CI3).
     */
    public function saveItem(?int $itemId, array $dbArray): int
    {
        $itemId = $this->saveRecord($itemId, $dbArray);

        (new QuoteItemAmountModel())->calculate($itemId);

        if (isset($dbArray['quote_id'])) {
            (new QuoteAmountModel())->calculate((int) $dbArray['quote_id']);
        }

        return $itemId;
    }

    /**
     * Elimina una riga e i suoi importi, ricalcola il preventivo
     * (equivalente di delete() CI3). Ritorna false se non trovata.
     */
    public function deleteItem(int $itemId): bool
    {
        $item = $this->find($itemId);
        if (!$item) {
            return false;
        }

        $quoteId = (int) $item['quote_id'];

        parent::delete($itemId);

        $this->db->table('ip_quote_item_amounts')->where('item_id', $itemId)->delete();

        (new QuoteAmountModel())->calculate($quoteId);

        return true;
    }

    /**
     * Nome unita' di misura per la riga (singolare/plurale in base alla
     * quantita', equivalente di Mdl_Units::get_name() CI3).
     */
    public function getProductUnitName(?int $unitId, $quantity = null): ?string
    {
        if (!$unitId) {
            return null;
        }

        $unit = (new UnitModel())->find($unitId);
        if (!$unit) {
            return null;
        }

        $name = (float) $quantity == 1 ? $unit['unit_name'] : $unit['unit_name_plrl'];

        return $name;
    }
}