<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Documenti di trasporto e mittenti email.
 *
 * DDT: il documento che accompagna la merce. I campi non sono liberi — la
 * normativa richiede causale del trasporto, aspetto esteriore dei beni, numero
 * colli, vettore e porto — quindi stanno in colonne dedicate e non in una nota
 * generica, altrimenti non sarebbero riportabili in stampa né riutilizzabili.
 *
 * Mittenti: finora l'indirizzo di invio era uno solo per l'installazione. Con
 * più aziende serve poter scegliere da quale casella parte il messaggio, e
 * ciascuna può avere il proprio SMTP: holding e operativa spediscono da domini
 * diversi e lo stesso server non è detto le autorizzi entrambe.
 */
class CreateDeliveryNotesAndSenders extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('ip_delivery_notes')) {
            $this->forge->addField([
                'delivery_note_id' => ['type' => 'INT', 'auto_increment' => true],
                'company_id'       => ['type' => 'INT', 'null' => true],
                'client_id'        => ['type' => 'INT', 'null' => false],
                'invoice_group_id' => ['type' => 'INT', 'null' => true],
                // Fattura emessa a fronte del DDT: un DDT si fattura dopo,
                // spesso raggruppando più consegne.
                'invoice_id'       => ['type' => 'INT', 'null' => true],
                'delivery_note_number' => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
                'delivery_note_date'   => ['type' => 'DATE', 'null' => false],
                // Data e ora di inizio trasporto: obbligatorie sul documento.
                'transport_datetime'   => ['type' => 'DATETIME', 'null' => true],
                'transport_reason'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'goods_appearance'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'packages'             => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                'weight'               => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
                'carrier'              => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                // Porto: franco (a carico del mittente) o assegnato (destinatario).
                'transport_charge'     => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                // Destinazione diversa dalla sede del cliente.
                'shipping_address'     => ['type' => 'TEXT', 'null' => true],
                'delivery_note_notes'  => ['type' => 'TEXT', 'null' => true],
                'delivery_note_url_key' => ['type' => 'CHAR', 'constraint' => 32, 'null' => true],
                'delivery_note_date_created' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('delivery_note_id', true);
            $this->forge->createTable('ip_delivery_notes');
        }

        if (!$this->db->tableExists('ip_delivery_note_items')) {
            $this->forge->addField([
                'item_id' => ['type' => 'INT', 'auto_increment' => true],
                'delivery_note_id' => ['type' => 'INT', 'null' => false],
                'item_name'        => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
                'item_description' => ['type' => 'TEXT', 'null' => true],
                'item_quantity'    => ['type' => 'DECIMAL', 'constraint' => '20,4', 'null' => false, 'default' => 0],
                'item_unit'        => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
                'item_order'       => ['type' => 'INT', 'null' => false, 'default' => 0],
            ]);
            $this->forge->addKey('item_id', true);
            $this->forge->createTable('ip_delivery_note_items');
        }

        if (!$this->db->tableExists('ip_email_senders')) {
            $this->forge->addField([
                'sender_id'  => ['type' => 'INT', 'auto_increment' => true],
                'company_id' => ['type' => 'INT', 'null' => true],
                'sender_name'  => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => false],
                'sender_email' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => false],
                // SMTP proprio: se vuoto si usa quello generale delle impostazioni.
                'sender_smtp_host'     => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
                'sender_smtp_port'     => ['type' => 'INT', 'null' => true],
                'sender_smtp_user'     => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
                'sender_smtp_password' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
                'sender_smtp_security' => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
                'sender_is_default'    => ['type' => 'BOOLEAN', 'null' => false, 'default' => false],
            ]);
            $this->forge->addKey('sender_id', true);
            $this->forge->createTable('ip_email_senders');
        }
    }

    public function down()
    {
        foreach (['ip_email_senders', 'ip_delivery_note_items', 'ip_delivery_notes'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table);
            }
        }
    }
}
