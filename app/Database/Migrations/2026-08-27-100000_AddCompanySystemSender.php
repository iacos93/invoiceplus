<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Scelta per-azienda della casella email di sistema.
 *
 * Le notifiche automatiche (cron: digest scadenze, ricevute SdI, reset password)
 * e il ripiego PEC del canale SdI spediscono dalla casella selezionata qui,
 * anziché da parametri SMTP generali duplicati nelle impostazioni. La colonna è
 * letta tramite company_setting('company_system_sender_id'), così ogni società
 * usa le proprie caselle (ip_email_senders è a sua volta scorporato per azienda).
 */
class AddCompanySystemSender extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ip_companies', [
            'company_system_sender_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'default'    => null,
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('ip_companies', 'company_system_sender_id');
    }
}
