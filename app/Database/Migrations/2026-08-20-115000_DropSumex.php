<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Rimuove ciò che restava della funzionalità SUMEX (fatturazione sanitaria
 * svizzera TARMED/SUMEX1), estranea alla gestione fiscale italiana.
 *
 * ip_invoice_sumex teneva i dati SUMEX per fattura: nessun altro codice la
 * legge o scrive dopo la rimozione di Libraries/Sumex, InvoiceSumexModel e
 * delle viste collegate.
 *
 * client_avs / client_insurednumber / client_veka (AVS, numero assicurato,
 * VEKA) e user_gln / user_rcc / user_subscribernumber erano campi svizzeri
 * esposti solo dai pannelli SUMEX; product_tariff era la tariffa TARMED.
 * I relativi input sono stati tolti dai form, quindi le colonne sono morte.
 */
class DropSumex extends Migration
{
    private const SUMEX_TABLE = 'ip_invoice_sumex';

    private const COLUMNS = [
        'ip_clients'  => ['client_avs', 'client_insurednumber', 'client_veka'],
        'ip_users'    => ['user_gln', 'user_rcc', 'user_subscribernumber'],
        'ip_products' => ['product_tariff'],
    ];

    public function up()
    {
        if ($this->db->tableExists(self::SUMEX_TABLE)) {
            $this->forge->dropTable(self::SUMEX_TABLE, true);
        }

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if ($this->db->fieldExists($column, $table)) {
                    $this->forge->dropColumn($table, $column);
                }
            }
        }
    }

    /**
     * Irreversibile per scelta: ricreare la tabella e le colonne vuote non
     * ripristinerebbe nessun dato utile, e la funzionalità è stata rimossa
     * anche a livello di codice.
     */
    public function down()
    {
    }
}
