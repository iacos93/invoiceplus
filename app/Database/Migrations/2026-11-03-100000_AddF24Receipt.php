<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Quietanza dell'F24.
 *
 * Il modello allegato (f24_file) è la delega da pagare; la quietanza è la
 * prova che il versamento è avvenuto, ed è il documento che si deve esibire
 * in caso di controllo. Sono due file diversi e vanno tenuti entrambi: per
 * questo non si riusa f24_file. Il protocollo telematico si salva a parte
 * perché è l'identificativo con cui l'Agenzia rintraccia il versamento, e
 * serve anche a non importare due volte la stessa quietanza.
 */
class AddF24Receipt extends Migration
{
    public function up()
    {
        $fields = [];

        if (!$this->db->fieldExists('f24_receipt_file', 'ip_f24')) {
            $fields['f24_receipt_file'] = ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true];
        }

        if (!$this->db->fieldExists('f24_receipt_protocol', 'ip_f24')) {
            $fields['f24_receipt_protocol'] = ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true];
        }

        if ($fields) {
            $this->forge->addColumn('ip_f24', $fields);
        }
    }

    public function down()
    {
        foreach (['f24_receipt_file', 'f24_receipt_protocol'] as $field) {
            if ($this->db->fieldExists($field, 'ip_f24')) {
                $this->forge->dropColumn('ip_f24', $field);
            }
        }
    }
}
