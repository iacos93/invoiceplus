<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Preferiti su fatture e preventivi.
 *
 * Serve a tenere sott'occhio pochi documenti fra molti (quelli in lavorazione,
 * quelli da ricontrollare) senza doverli cercare ogni volta nell'elenco.
 */
class AddFavouriteFlag extends Migration
{
    public function up()
    {
        $field = [
            'type'       => 'BOOLEAN',
            'null'       => false,
            'default'    => false,
        ];

        if (!$this->db->fieldExists('invoice_is_favourite', 'ip_invoices')) {
            $this->forge->addColumn('ip_invoices', ['invoice_is_favourite' => $field]);
        }

        if (!$this->db->fieldExists('quote_is_favourite', 'ip_quotes')) {
            $this->forge->addColumn('ip_quotes', ['quote_is_favourite' => $field]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('invoice_is_favourite', 'ip_invoices')) {
            $this->forge->dropColumn('ip_invoices', 'invoice_is_favourite');
        }

        if ($this->db->fieldExists('quote_is_favourite', 'ip_quotes')) {
            $this->forge->dropColumn('ip_quotes', 'quote_is_favourite');
        }
    }
}
