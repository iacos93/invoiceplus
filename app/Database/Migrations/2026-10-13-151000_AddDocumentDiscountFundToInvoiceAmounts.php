<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Sconto di documento, contributo cassa e ritenuta fra i totali derivati.
 *
 * Con lo sconto di documento spostato sull'imponibile e il contributo cassa
 * finalmente dentro il totale (InvoiceMath::documentSummary), PDF e pagina
 * fattura devono poterli mostrare come righe del riepilogo: ricalcolarli a
 * ogni lettura vorrebbe dire rifare lì la ripartizione per aliquota, cioè
 * una seconda copia della formula — proprio ciò che InvoiceMath esiste per
 * evitare. Si salvano accanto agli altri totali, scritti dallo stesso
 * InvoiceAmountModel::calculate().
 *
 * Dopo la migrazione va lanciato un ricalcolo (Impostazioni > ricalcola
 * fatture, o invoices/recalculate_all_invoices): fino ad allora le colonne
 * valgono zero e i totali restano quelli calcolati con la regola vecchia.
 */
class AddDocumentDiscountFundToInvoiceAmounts extends Migration
{
    private const COLUMNS = [
        'invoice_discount_total',
        'invoice_fund_amount',
        'invoice_withholding_amount',
    ];

    public function up()
    {
        foreach (self::COLUMNS as $column) {
            if (!$this->db->fieldExists($column, 'ip_invoice_amounts')) {
                $this->forge->addColumn('ip_invoice_amounts', [
                    $column => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0, 'null' => false],
                ]);
            }
        }
    }

    public function down()
    {
        foreach (self::COLUMNS as $column) {
            if ($this->db->fieldExists($column, 'ip_invoice_amounts')) {
                $this->forge->dropColumn('ip_invoice_amounts', $column);
            }
        }
    }
}
