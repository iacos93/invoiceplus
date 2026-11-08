<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Codice riga libero per fatture e preventivi.
 *
 * Le righe avevano un solo codice possibile: quello del prodotto in catalogo
 * (ip_products.product_sku), che il PDF mostrava come "Codice". Chi vende alla
 * Pubblica Amministrazione ha però bisogno di un codice che nel catalogo non
 * c'è — il codice articolo del MEPA, o quello concordato con l'ente — e che
 * spesso cambia da ordine a ordine. Una colonna libera sulla riga copre
 * entrambi i casi: se compilata vince sul codice del prodotto, altrimenti si
 * continua a mostrare quello.
 */
class AddItemCode extends Migration
{
    /** Le due tabelle riga: fatture e preventivi. */
    private const TABLES = ['ip_invoice_items', 'ip_quote_items'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if ($this->db->fieldExists('item_code', $table)) {
                continue;
            }

            $this->forge->addColumn($table, [
                'item_code' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 60,
                    'null'       => true,
                ],
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if ($this->db->fieldExists('item_code', $table)) {
                $this->forge->dropColumn($table, 'item_code');
            }
        }
    }
}
