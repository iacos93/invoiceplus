<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Aggiunge quote_time_created a ip_quotes.
 *
 * ip_invoices ha invoice_time_created (TIME NOT NULL, default 00:00:00) ma
 * ip_quotes non aveva la colonna omonima: QuoteModel la valorizza sempre
 * (date('H:i:s') quando manca) e quindi ogni INSERT su ip_quotes falliva con
 * "column quote_time_created of relation ip_quotes does not exist" — la
 * creazione di preventivi era rotta sia da UI sia via MCP.
 *
 * Allineata al tipo della colonna gemella su ip_invoices.
 */
class AddQuoteTimeCreated extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ip_quotes', [
            'quote_time_created' => [
                'type'    => 'TIME',
                'null'    => false,
                'default' => '00:00:00',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('ip_quotes', 'quote_time_created');
    }
}
