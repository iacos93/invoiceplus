<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Aggiunge la colonna di guardia dei solleciti di fatture scadute.
 *
 * Il cron remind_overdue non deve rinviare lo stesso sollecito ogni giorno:
 * per ogni fattura si registra quando è stato spedito l'ultimo sollecito, e
 * il cron salta le fatture che ne hanno già ricevuto uno dopo la scadenza.
 * Manca la tabella degli invii perché un semplice timestamp sulla fattura è
 * sufficiente — non serve sapere quando è stato inviato un sollecito per
 * fatture già pagate, che comunque non compaiono più nella query.
 */
class AddInvoiceLastReminderSent extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('ip_invoices')) {
            return;
        }

        if (!$this->db->fieldExists('invoice_last_reminder_sent', 'ip_invoices')) {
            $this->forge->addColumn('ip_invoices', [
                'invoice_last_reminder_sent' => [
                    'type'       => 'DATE',
                    'null'       => true,
                    'default'    => null,
                    'after'      => 'invoice_date_due',
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->tableExists('ip_invoices')
            && $this->db->fieldExists('invoice_last_reminder_sent', 'ip_invoices')) {
            $this->forge->dropColumn('ip_invoices', 'invoice_last_reminder_sent');
        }
    }
}