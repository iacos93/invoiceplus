<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Anagrafica fornitori.
 *
 * I fornitori esistevano solo come testo libero sulla singola spesa
 * (supplier_name / supplier_vat / supplier_tax_code): lo stesso fornitore
 * ricompariva scritto in modi diversi su spese diverse, non era possibile
 * vedere quanto si spende con ciascuno, e i dati anagrafici andavano
 * ridigitati ogni volta. Qui diventa un'anagrafica come quella dei clienti,
 * con separazione per azienda.
 *
 * Le colonne di testo sulle spese restano: valgono per le spese gia' inserite
 * e per quelle in cui non si vuole creare un fornitore. supplier_id, quando
 * valorizzato, e' la fonte buona.
 */
class CreateSuppliers extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('ip_suppliers')) {
            $this->forge->addField([
                'supplier_id' => [
                    'type'           => 'INT',
                    'auto_increment' => true,
                ],
                'supplier_name'       => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => false],
                'supplier_vat_id'     => ['type' => 'VARCHAR', 'constraint' => 30,  'null' => true],
                'supplier_tax_code'   => ['type' => 'VARCHAR', 'constraint' => 30,  'null' => true],
                'supplier_address_1'  => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'supplier_address_2'  => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'supplier_city'       => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'supplier_state'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'supplier_zip'        => ['type' => 'VARCHAR', 'constraint' => 20,  'null' => true],
                'supplier_country'    => ['type' => 'VARCHAR', 'constraint' => 5,   'null' => true],
                'supplier_phone'      => ['type' => 'VARCHAR', 'constraint' => 50,  'null' => true],
                'supplier_email'      => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'supplier_web'        => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'supplier_iban'       => ['type' => 'VARCHAR', 'constraint' => 34,  'null' => true],
                'supplier_sdi_code'   => ['type' => 'VARCHAR', 'constraint' => 7,   'null' => true],
                'supplier_sdi_pec'    => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'supplier_note'       => ['type' => 'TEXT', 'null' => true],
                'supplier_active'     => ['type' => 'BOOLEAN', 'null' => false, 'default' => true],
                'company_id'          => ['type' => 'INT', 'null' => true],
                'supplier_date_created'  => ['type' => 'DATETIME', 'null' => true],
                'supplier_date_modified' => ['type' => 'DATETIME', 'null' => true],
            ]);

            $this->forge->addKey('supplier_id', true);
            $this->forge->addKey('company_id');
            $this->forge->addKey('supplier_vat_id');
            $this->forge->createTable('ip_suppliers');
        }

        if (!$this->db->fieldExists('supplier_id', 'ip_expenses')) {
            $this->forge->addColumn('ip_expenses', [
                'supplier_id' => [
                    'type'       => 'INT',
                    'null'       => true,
                    'after'      => 'supplier_tax_code',
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('supplier_id', 'ip_expenses')) {
            $this->forge->dropColumn('ip_expenses', 'supplier_id');
        }

        if ($this->db->tableExists('ip_suppliers')) {
            $this->forge->dropTable('ip_suppliers');
        }
    }
}
