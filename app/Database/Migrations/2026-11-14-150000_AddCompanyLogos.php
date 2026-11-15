<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Loghi dei documenti per azienda.
 *
 * I due loghi stampati su fatture, preventivi e DDT (intestazione e piè di
 * pagina) erano impostazioni globali: con più aziende nella stessa
 * installazione tutte uscivano con lo stesso marchio, cioè con il logo di un
 * altro soggetto giuridico sui propri documenti. Le due colonne portano lo
 * stesso nome delle impostazioni (invoice_logo, invoice_logo_footer) perché
 * si leggono con la stessa regola di company_setting(): il valore
 * dell'azienda se c'è, altrimenti quello globale — così chi ha una sola
 * azienda e il logo nelle Impostazioni non deve fare nulla.
 */
class AddCompanyLogos extends Migration
{
    private const COLUMNS = ['invoice_logo', 'invoice_logo_footer'];

    public function up(): void
    {
        foreach (self::COLUMNS as $column) {
            if ($this->db->fieldExists($column, 'ip_companies')) {
                continue;
            }

            $this->forge->addColumn('ip_companies', [
                $column => [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                ],
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            if ($this->db->fieldExists($column, 'ip_companies')) {
                $this->forge->dropColumn('ip_companies', $column);
            }
        }
    }
}
