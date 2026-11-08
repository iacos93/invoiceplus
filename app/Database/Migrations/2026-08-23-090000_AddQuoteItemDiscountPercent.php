<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Allinea ip_quote_items a ip_invoice_items: le righe fattura gestiscono
 * lo sconto sia ad importo che a percentuale (item_discount_percent), quelle
 * preventivo solo ad importo. Cosi' la tabella righe dei due documenti accetta
 * gli stessi dati e la UI puo' mostrare lo stesso box "%" identico.
 */
class AddQuoteItemDiscountPercent extends Migration
{
    public function up()
    {
        if ($this->db->fieldExists('item_discount_percent', 'ip_quote_items')) {
            return;
        }

        $this->forge->addColumn('ip_quote_items', [
            'item_discount_percent' => [
                'type'       => 'DECIMAL',
                'constraint' => '20,4',
                'null'       => true,
                'default'    => 0.0000,
            ],
        ]);
    }

    public function down()
    {
        if ($this->db->fieldExists('item_discount_percent', 'ip_quote_items')) {
            $this->forge->dropColumn('ip_quote_items', 'item_discount_percent');
        }
    }
}
