<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Conti di saldo (coordinate di pagamento).
 *
 * Prima esisteva un solo IBAN, nel profilo utente (ip_users.user_iban), usato
 * per l'XML FatturaPA: chi incassa su più conti non poteva sceglierne uno per
 * documento. Qui i conti diventano un'anagrafica gestita dalle impostazioni,
 * con un predefinito e la possibilità di indicare il conto sulla singola
 * fattura.
 */
class CreateBankAccounts extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('ip_bank_accounts')) {
            $this->forge->addField([
                'bank_account_id' => [
                    'type'           => 'INT',
                    'auto_increment' => true,
                ],
                'bank_account_name'    => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
                'bank_account_holder'  => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'bank_account_bank'    => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'bank_account_iban'    => ['type' => 'VARCHAR', 'constraint' => 34, 'null' => true],
                'bank_account_bic'     => ['type' => 'VARCHAR', 'constraint' => 11, 'null' => true],
                'bank_account_note'    => ['type' => 'TEXT', 'null' => true],
                'bank_account_is_default' => [
                    'type'       => 'BOOLEAN',
                    'null'       => false,
                    'default'    => false,
                ],
            ]);

            $this->forge->addKey('bank_account_id', true);
            $this->forge->createTable('ip_bank_accounts');
        }

        // Conto indicato sul singolo documento: se assente vale il predefinito.
        if (!$this->db->fieldExists('bank_account_id', 'ip_invoices')) {
            $this->forge->addColumn('ip_invoices', [
                'bank_account_id' => [
                    'type'       => 'INT',
                    'null'       => true,
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('bank_account_id', 'ip_invoices')) {
            $this->forge->dropColumn('ip_invoices', 'bank_account_id');
        }

        if ($this->db->tableExists('ip_bank_accounts')) {
            $this->forge->dropTable('ip_bank_accounts');
        }
    }
}
