<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * F24 e flussi RiBa.
 *
 * F24: i modelli di pagamento delle imposte, con scadenza e importo, così le
 * uscite fiscali stanno nello stesso posto del resto e non su un foglio a
 * parte. Non entrano fra i costi deducibili: imposte come IRPEF e IRES non lo
 * sono, e sommarle ai costi falserebbe l'utile imponibile.
 *
 * RiBa: per emettere le ricevute bancarie servono le coordinate della banca
 * del debitore, che oggi sull'anagrafica cliente non ci sono.
 */
class CreateF24AndRiba extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('ip_f24')) {
            $this->forge->addField([
                'f24_id' => ['type' => 'INT', 'auto_increment' => true],
                'company_id'   => ['type' => 'INT', 'null' => true],
                'f24_period'   => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                'f24_due_date' => ['type' => 'DATE', 'null' => false],
                'f24_paid_date' => ['type' => 'DATE', 'null' => true],
                'f24_amount'   => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => false, 'default' => 0],
                'f24_note'     => ['type' => 'TEXT', 'null' => true],
                'f24_file'     => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
                'bank_account_id' => ['type' => 'INT', 'null' => true],
                'f24_date_created' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('f24_id', true);
            $this->forge->createTable('ip_f24');
        }

        // Coordinate bancarie del debitore, necessarie per la RiBa.
        if (!$this->db->fieldExists('client_iban', 'ip_clients')) {
            $this->forge->addColumn('ip_clients', [
                'client_iban' => ['type' => 'VARCHAR', 'constraint' => 34, 'null' => true],
            ]);
        }

        // Distinta RiBa generata: serve a sapere quali fatture sono già state
        // presentate in banca, per non presentarle due volte.
        if (!$this->db->fieldExists('riba_flow_ref', 'ip_invoices')) {
            $this->forge->addColumn('ip_invoices', [
                'riba_flow_ref'  => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'riba_flow_date' => ['type' => 'DATE', 'null' => true],
            ]);
        }
    }

    public function down()
    {
        foreach (['riba_flow_ref', 'riba_flow_date'] as $field) {
            if ($this->db->fieldExists($field, 'ip_invoices')) {
                $this->forge->dropColumn('ip_invoices', $field);
            }
        }

        if ($this->db->fieldExists('client_iban', 'ip_clients')) {
            $this->forge->dropColumn('ip_clients', 'client_iban');
        }

        if ($this->db->tableExists('ip_f24')) {
            $this->forge->dropTable('ip_f24');
        }
    }
}
