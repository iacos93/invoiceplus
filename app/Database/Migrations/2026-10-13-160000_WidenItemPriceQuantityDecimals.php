<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Prezzo unitario e quantità delle righe a 8 decimali.
 *
 * Il tracciato FatturaPA ammette fino a 8 decimali su PrezzoUnitario e
 * Quantita, e i gestionali li usano: ore di assistenza a 51,2052, un
 * materiale a 5,8926 € al pezzo. Le colonne erano a 2 decimali, quindi la
 * riga veniva salvata troncata e il totale ricalcolato non era più quello
 * fatturato — sulle fatture importate dall'archivio fino a 28 centesimi di
 * differenza per documento, e una fattura rigenerata con Quantita ×
 * PrezzoUnitario diverso da PrezzoTotale è uno scarto 00423.
 *
 * Gli importi (sconto a importo, totali derivati) restano a 2 decimali:
 * sono euro e centesimi, l'arrotondamento lo fa InvoiceMath.
 */
class WidenItemPriceQuantityDecimals extends Migration
{
    private const TABLES = ['ip_invoice_items', 'ip_quote_items'];

    public function up()
    {
        foreach (self::TABLES as $table) {
            $this->forge->modifyColumn($table, [
                'item_price'    => ['type' => 'DECIMAL', 'constraint' => '20,8', 'null' => true],
                'item_quantity' => ['type' => 'DECIMAL', 'constraint' => '20,8', 'null' => true],
            ]);
        }
    }

    public function down()
    {
        // Riportare a 2 decimali arrotonda i valori più precisi: è
        // esattamente la perdita che questa migrazione evita.
        foreach (self::TABLES as $table) {
            $this->forge->modifyColumn($table, [
                'item_price'    => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true],
                'item_quantity' => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => true],
            ]);
        }
    }
}
