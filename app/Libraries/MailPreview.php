<?php

namespace App\Libraries;

/**
 * Dati di esempio per l'anteprima dei modelli email.
 *
 * Un modello contiene tag {{...}} che a invio avvenuto diventano i dati veri
 * del cliente e del documento. Nell'anteprima non c'è un documento da cui
 * prenderli: si usa un insieme fisso di valori plausibili, così si vede subito
 * dove finisce ogni tag e se il testo scorre bene, senza dover creare una
 * fattura di prova.
 *
 * I valori sono finti apposta (dominio example.test, nomi di fantasia): una
 * pagina che sembra una fattura vera è un ottimo modo per ritrovarsela
 * stampata o inoltrata per errore.
 *
 * Sta fuori dal controller per poter essere provato senza database: l'unica
 * dipendenza è MailTemplate.
 */
final class MailPreview
{
    /**
     * Valori per tutti i tag documentati in email_templates/template_tags.php.
     * I campi personalizzati ({{ip_cf_<id>}}) non ci sono: dipendono dal
     * cliente e restano visibili, come quando un tag non è riconosciuto.
     *
     * @return array<string, string>
     */
    public static function sampleData(): array
    {
        return [
            // Cliente
            'client_name'      => 'Mario',
            'client_surname'   => 'Rossi',
            'client_fullname'  => 'Mario Rossi',
            'client_address_1' => 'Via Verdi 10',
            'client_address_2' => 'Scala B',
            'client_city'      => 'Torino',
            'client_state'     => 'TO',
            'client_zip'       => '10121',
            'client_country'   => 'Italia',
            'client_phone'     => '+39 011 1234567',
            'client_fax'       => '+39 011 1234568',
            'client_mobile'    => '+39 333 1234567',
            'client_email'     => 'mario.rossi@example.test',
            'client_web'       => 'www.rossi.example.test',
            'client_vat_id'    => 'IT01234567890',
            'client_tax_code'  => 'RSSMRA80A01L219X',

            // Utente / azienda che invia
            'user_name'      => 'Fabio Bianchi',
            'user_company'   => 'Studio Bianchi S.r.l.',
            'user_address_1' => 'Via Roma 1',
            'user_address_2' => '',
            'user_city'      => 'Milano',
            'user_state'     => 'MI',
            'user_zip'       => '20100',
            'user_country'   => 'Italia',
            'user_phone'     => '+39 02 1234567',
            'user_fax'       => '',
            'user_mobile'    => '+39 333 7654321',
            'user_email'     => 'info@studio-bianchi.example.test',
            'user_web'       => 'www.studio-bianchi.example.test',
            'user_iban'      => 'IT60 X054 2811 1010 0000 0123 456',

            // Fattura
            'invoice_number'         => '2026/001',
            'invoice_date_created'   => '10/11/2026',
            'invoice_date_due'       => '10/12/2026',
            'invoice_status'         => 'Emessa',
            'invoice_item_subtotal'  => '1.000,00 €',
            'invoice_item_tax_total' => '220,00 €',
            'invoice_tax_total'      => '220,00 €',
            'invoice_total'          => '1.220,00 €',
            'invoice_paid'           => '0,00 €',
            'invoice_balance'        => '1.220,00 €',
            'invoice_terms'          => 'Pagamento a 30 giorni data fattura.',
            'invoice_guest_url'      => 'https://esempio.test/guest/view/invoice/anteprima',

            // Preventivo
            'quote_number'         => '2026/007',
            'quote_date_created'   => '05/11/2026',
            'quote_date_expires'   => '05/12/2026',
            'quote_item_subtotal'  => '2.000,00 €',
            'quote_item_tax_total' => '440,00 €',
            'quote_tax_total'      => '440,00 €',
            'quote_total'          => '2.440,00 €',
            'quote_guest_url'      => 'https://esempio.test/guest/view/quote/anteprima',
        ];
    }

    /**
     * L'oggetto è un campo di testo, non HTML: i valori entrano senza escape
     * (altrimenti un "&" diventerebbe "&amp;" scritto nell'oggetto).
     */
    public static function subject(string $subject): string
    {
        return MailTemplate::render($subject, self::sampleData(), false);
    }

    /**
     * Corpo come lo riceve il destinatario: tag sostituiti e stessa
     * conversione testo → HTML dell'invio.
     */
    public static function body(string $body): string
    {
        return MailTemplate::toHtml(MailTemplate::render($body, self::sampleData()));
    }
}
