<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Numerazione provvisoria delle bozze.
 *
 * Un documento si prepara anche mesi prima di spedirlo, ma finché è bozza non
 * deve consumare il progressivo ufficiale: gli si assegna un numero da una
 * "serie bozze" (es. T-1) che all'uscita dalla bozza viene sostituito da quello
 * definitivo.
 *
 * Il flag serve a distinguere un numero provvisorio (da rimpiazzare) da uno
 * scritto a mano dall'utente (da rispettare): senza, l'unico modo per decidere
 * sarebbe indovinare dal formato, e si finirebbe per sovrascrivere numeri
 * inseriti apposta.
 */
class AddDraftNumberFlag extends Migration
{
    public function up()
    {
        $field = [
            'type'       => 'BOOLEAN',
            'null'       => false,
            'default'    => false,
        ];

        if (!$this->db->fieldExists('invoice_number_is_draft', 'ip_invoices')) {
            $this->forge->addColumn('ip_invoices', ['invoice_number_is_draft' => $field]);
        }

        if (!$this->db->fieldExists('quote_number_is_draft', 'ip_quotes')) {
            $this->forge->addColumn('ip_quotes', ['quote_number_is_draft' => $field]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('invoice_number_is_draft', 'ip_invoices')) {
            $this->forge->dropColumn('ip_invoices', 'invoice_number_is_draft');
        }

        if ($this->db->fieldExists('quote_number_is_draft', 'ip_quotes')) {
            $this->forge->dropColumn('ip_quotes', 'quote_number_is_draft');
        }
    }
}
