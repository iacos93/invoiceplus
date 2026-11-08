<?php

namespace App\Models;

/**
 * QuoteItemAmountModel
 *
 * Porting CI4 di Mdl_Quote_Item_Amounts (CI3): tabella ip_quote_item_amounts.
 * Calcola subtotal, imposta, sconto e totale di una riga con UPSERT.
 */
class QuoteItemAmountModel extends IpBaseModel
{
    protected $table            = 'ip_quote_item_amounts';
    protected $primaryKey       = 'item_amount_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'item_id',
        'item_subtotal',
        'item_tax_total',
        'item_discount',
        'item_total',
    ];

    /**
     * Calcola e salva (UPSERT) gli importi della riga
     * (equivalente di calculate() CI3).
     */
    public function calculate(int $itemId): void
    {
        // $this->select()->first() interroga la tabella del model
        // (ip_quote_item_amounts, da $table sopra), non ip_quote_items: la
        // riga da leggere sta in un'altra tabella, quindi va presa con una
        // query indipendente su $this->db, come fa ItemAmountModel per le
        // fatture. Con il builder del model il FROM restava sempre
        // ip_quote_item_amounts e "ip_quote_items.*" nel SELECT falliva
        // (MySQL: "Unknown table 'ip_quote_items'").
        $item = $this->db->table('ip_quote_items')
            ->select('ip_quote_items.*, item_tax_rates.tax_rate_percent AS item_tax_rate_percent, item_tax_rates.tax_rate_natura AS item_tax_rate_natura')
            ->join('ip_tax_rates AS item_tax_rates', 'item_tax_rates.tax_rate_id = ip_quote_items.item_tax_rate_id', 'left')
            ->where('ip_quote_items.item_id', $itemId)
            ->get()
            ->getRowArray();

        if (!$item) {
            return;
        }

        // Stessa formula delle fatture (InvoiceMath::lineAmounts): sconto a
        // importo per unità, percentuale sul prezzo pieno, niente IVA sulle
        // righe con Natura. Deve restare identica, altrimenti il preventivo e
        // la fattura che ne nasce mostrano totali diversi — e con il vecchio
        // round() semplice lo sconto 10% di 160,65 usciva 16,06 invece di 16,07.
        $amounts = \App\Services\InvoiceMath::lineAmounts(
            (float) $item['item_quantity'],
            (float) $item['item_price'],
            (float) ($item['item_discount_amount'] ?? 0),
            (float) ($item['item_discount_percent'] ?? 0),
            (float) ($item['item_tax_rate_percent'] ?? 0),
            !empty($item['item_tax_rate_natura'])
        );

        $dbArray = [
            'item_id'        => $itemId,
            'item_subtotal'  => $amounts['subtotal'],
            'item_tax_total' => $amounts['tax'],
            'item_discount'  => $amounts['discount'],
            'item_total'     => $amounts['total'],
        ];

        $existing = $this->where('item_id', $itemId)->first();

        if ($existing) {
            $this->update($existing['item_amount_id'], $dbArray);
        } else {
            $this->insert($dbArray);
        }
    }
}