<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Aggiunge il tipo di unita' di misura (Pezzi, Peso, Lunghezza, ...) alla
 * tabella ip_units, cosi' le scelte nella riga fattura/preventivo e nel
 * prodotto si possono raggruppare per categoria e l'utente trova subito
 * l'unita' giusta invece di scorrere un elenco piatto.
 */
class AddUnitTypeToUnits extends Migration
{
    public function up()
    {
        if ($this->db->fieldExists('unit_type', 'ip_units')) {
            return;
        }

        $this->forge->addColumn('ip_units', [
            'unit_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'null'       => true,
                'default'    => null,
            ],
        ]);
    }

    public function down()
    {
        if ($this->db->fieldExists('unit_type', 'ip_units')) {
            $this->forge->dropColumn('ip_units', 'unit_type');
        }
    }
}
