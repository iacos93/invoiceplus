<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * ItemAmountModel
 *
 * Porting CI4 di Mdl_Item_Amounts (CI3): ricalcolo degli importi
 * della singola riga fattura (tabella ip_invoice_item_amounts) con
 * UPSERT come nell'originale. La percentuale IVA viene letta dalla
 * tabella ip_tax_rates tramite item_tax_rate_id.
 */
class ItemAmountModel extends Model
{
    protected $table            = 'ip_invoice_item_amounts';
    protected $primaryKey       = 'item_amount_id';
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
     * Ricalcola gli importi di una riga (equivalente di calculate() CI3).
     */
    public function calculate(int $itemId): void
    {
        $item = $this->db->table('ip_invoice_items')
            ->select('ip_invoice_items.*, ip_tax_rates.tax_rate_percent, ip_tax_rates.tax_rate_natura')
            ->join('ip_tax_rates', 'ip_tax_rates.tax_rate_id = ip_invoice_items.item_tax_rate_id', 'left')
            ->where('ip_invoice_items.item_id', $itemId)
            ->get()
            ->getRow();

        if (!$item) {
            return;
        }

        // La formula sta in InvoiceMath::lineAmounts(), condivisa con il
        // tracciato FatturaPA. Qui prima si moltiplicava in float e si
        // arrotondava con round() semplice: 3,5 × 45,90 con sconto 10% dava
        // 16,064999… e quindi 16,06 di sconto invece di 16,07. Lì sono anche
        // spiegate le due regole che contano: lo sconto a importo è per
        // unità, e una riga con Natura IVA (N1-N7) non porta imposta anche
        // con aliquota piena (un'inversione contabile N6 a 22% sommava
        // altrimenti il 22% in contabilità, gonfiando il totale documento).
        $amounts = \App\Services\InvoiceMath::lineAmounts(
            (float) $item->item_quantity,
            (float) $item->item_price,
            (float) $item->item_discount_amount,
            (float) $item->item_discount_percent,
            (float) $item->tax_rate_percent,
            !empty($item->tax_rate_natura)
        );

        $dbArray = [
            'item_id'        => $itemId,
            'item_subtotal'  => $amounts['subtotal'],
            'item_tax_total' => $amounts['tax'],
            'item_discount'  => $amounts['discount'],
            'item_total'     => $amounts['total'],
        ];

        $exists = $this->db->table('ip_invoice_item_amounts')
            ->where('item_id', $itemId)
            ->countAllResults() > 0;

        if ($exists) {
            $this->db->table('ip_invoice_item_amounts')
                ->where('item_id', $itemId)
                ->update($dbArray);
        } else {
            $this->db->table('ip_invoice_item_amounts')->insert($dbArray);
        }
    }
}