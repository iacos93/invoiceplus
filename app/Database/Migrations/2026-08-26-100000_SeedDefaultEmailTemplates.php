<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Modelli email predefiniti mancanti.
 *
 * La tabella ip_email_templates nasce vuota: chi apre la pagina "Modelli email"
 * non trova nessun modello di partenza e deve ricostruirsi a mano quelli che
 * InvoicePlane metteva a disposizione (fattura, preventivo, sollecito). Questa
 * migration inserisce tre modelli di base in italiano, pronti all'uso o da
 * clonare/rinominare, cosi' il menu a tendina del mailer e' subito popolato.
 *
 * Idempotente: salta i modelli il cui titolo esiste gia' (riesecuzione sicura,
 * e non sovrascrive quelli creati o rinominati a mano dall'utente).
 */
class SeedDefaultEmailTemplates extends Migration
{
    /**
     * @var list<array{title: string, type: string, subject: string, body: string}>
     */
    private const PRESETS = [
        [
            'title'   => 'Fattura',
            'type'    => 'invoice',
            'subject' => 'Fattura {{invoice_number}}',
            'body'    => "Gentile {{client_name}},\n\n"
                . "in allegato trovi la fattura {{invoice_number}} del {{invoice_date_created}}.\n\n"
                . "Importo totale: {{invoice_total}}\n"
                . "Saldo dovuto: {{invoice_balance}}\n\n"
                . "Puoi visualizzare e scaricare la fattura al link:\n"
                . "{{invoice_guest_url}}\n\n"
                . "Cordiali saluti,\n"
                . "{{user_name}}\n"
                . "{{user_company}}",
        ],
        [
            'title'   => 'Preventivo',
            'type'    => 'quote',
            'subject' => 'Preventivo {{quote_number}}',
            'body'    => "Gentile {{client_name}},\n\n"
                . "in allegato trovi il preventivo {{quote_number}} del {{quote_date_created}}.\n\n"
                . "Totale preventivo: {{quote_total}}\n\n"
                . "Puoi visualizzare e scaricare il preventivo al link:\n"
                . "{{quote_guest_url}}\n\n"
                . "Cordiali saluti,\n"
                . "{{user_name}}\n"
                . "{{user_company}}",
        ],
        [
            'title'   => 'Fattura scaduta',
            'type'    => 'overdue',
            'subject' => 'Fattura scaduta {{invoice_number}}',
            'body'    => "Gentile {{client_name}},\n\n"
                . "la fattura {{invoice_number}} del {{invoice_date_created}} e' scaduta il {{invoice_date_due}}.\n\n"
                . "Saldo dovuto: {{invoice_balance}}\n\n"
                . "Ti chiediamo cortesemente di regolarizzare il pagamento seguendo il link:\n"
                . "{{invoice_guest_url}}\n\n"
                . "Cordiali saluti,\n"
                . "{{user_name}}\n"
                . "{{user_company}}",
        ],
    ];

    public function up()
    {
        $existing = array_column(
            $this->db->table('ip_email_templates')->select('email_template_title')->get()->getResultArray(),
            'email_template_title'
        );

        foreach (self::PRESETS as $preset) {
            if (in_array($preset['title'], $existing, true)) {
                continue;
            }

            $this->db->table('ip_email_templates')->insert([
                'email_template_title'        => $preset['title'],
                'email_template_type'         => $preset['type'],
                'email_template_subject'      => $preset['subject'],
                'email_template_body'         => $preset['body'],
                'email_template_pdf_template' => 'default',
            ]);
        }
    }

    public function down()
    {
        $titles = array_column(self::PRESETS, 'title');

        $this->db->table('ip_email_templates')->whereIn('email_template_title', $titles)->delete();
    }
}
