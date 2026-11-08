<?php

namespace App\Libraries\Sdi;

use App\Models\EmailSenderModel;

/**
 * Canale PEC: invio del file FatturaPA come allegato di una email
 * trasmessa da una casella PEC agli indirizzi dello SdI
 * (sdi01@sdi.fatturapa.gov.it ... sdi10@sdi.fatturapa.gov.it).
 *
 * Il canale e' gratuito ma lascia all'utente due incombenze che il
 * webservice accreditato assorbe:
 *  1. firma digitale del file prima dell'invio (obbligatoria per i
 *     trasmittenti non accreditati): il file firmato (p7m) va posizionato
 *     dalla casella PEC oppure tramite un servizio di firma remota;
 *  2. conservazione a norma della fattura (conservatore accreditato o
 *     sistema interno) e lettura delle ricevute dalla PEC in arrivo
 *     (Cron::sdiPoll le importa automaticamente se il polling e' attivo).
 *
 * La configurazione SMTP e' quella di un mittente EmailSenders (scelto
 * da Impostazioni > SdI); senza mittente specifico si usa la
 * configurazione email predefinita dell'app (email_send_method...).
 */
class SdiPecChannel implements SdiChannelInterface
{
    // Indirizzo ufficiale per il PRIMO invio (guida AdE - fatturazione elettronica).
    // Al primo invio lo SdI risponde, dalla stessa casella sdi01@pec.fatturapa.it,
    // comunicando un nuovo indirizzo PEC-SdI dedicato da usare per gli invii
    // successivi: quell'indirizzo va salvato in sdi_pec_recipient_full (impostazione
    // separata) e ha precedenza su questo default una volta noto.
    private const SDI_DOMAIN = '@pec.fatturapa.it';

    public function send(string $xml, string $filename, array $invoice): array
    {
        // Se lo SdI ha gia' comunicato l'indirizzo PEC dedicato (dopo il primo
        // invio), va usato quello per intero: ha priorita' sul default sdi01.
        $dedicated = trim((string) get_setting('sdi_pec_recipient_full', ''));

        if ($dedicated !== '') {
            $to = $dedicated;
        } else {
            $recipient = (string) get_setting('sdi_pec_recipient', 'sdi01');
            $recipient = preg_replace('/[^a-z0-9]/i', '', $recipient) ?: 'sdi01';
            $to        = $recipient . self::SDI_DOMAIN;
        }

        $email = \Config\Services::email(null, false);

        $this->configureSender($email);

        $email->setTo($to);
        $email->setSubject($filename);
        $email->setMessage(
            'Fattura elettronica ' . ($invoice['invoice_number'] ?? '') . "\n"
            . "Allegato: {$filename}\n"
            . "Inviata da InvoicePlane il " . date('d/m/Y H:i')
        );

        // attach() con nome esplicito: altrimenti l'allegato prende il
        // nome del file temporaneo e lo SdI non trova la fattura.
        $tmp = $this->tempFile($xml);

        try {
            $email->attach($tmp, 'attachment', $filename);

            if (!$email->send()) {
                return ['ok' => false, 'message' => 'Invio PEC fallito: ' . $email->printDebugger(['headers', 'subject'])];
            }

            return ['ok' => true, 'message' => 'Fattura inviata via PEC a ' . $to];
        } finally {
            // Il file conteneva l'XML fiscale completo (P.IVA, importi,
            // anagrafiche): senza questa pulizia restava indefinitamente
            // nella temp dir di sistema, leggibile da altri processi/utenti
            // locali su un server condiviso.
            @unlink($tmp);
        }
    }

    /** Configura il mittente: casella EmailSenders se impostata, altrimenti SMTP di sistema. */
    private function configureSender($email): void
    {
        $senderId = (int) get_setting('sdi_pec_sender_id', 0);

        if ($senderId > 0) {
            // findScoped() e non find(): sdi_pec_sender_id e' un'impostazione
            // globale (non per-azienda), quindi puo' restare puntata al
            // mittente di un'altra azienda dopo un cambio di contesto. Senza
            // questo controllo si inviava silenziosamente con le credenziali
            // SMTP di un'altra azienda; con findScoped() un id non
            // appartenente all'azienda attiva viene trattato come "non
            // configurato" e si ricade sull'SMTP di sistema.
            $sender = (new EmailSenderModel())->findScoped($senderId);

            if ($sender) {
                $config = [
                    'protocol' => 'smtp',
                    'SMTPHost' => (string) ($sender['sender_smtp_host'] ?? ''),
                    'SMTPPort' => (int) ($sender['sender_smtp_port'] ?: 25),
                    'SMTPUser' => (string) ($sender['sender_smtp_user'] ?? ''),
                    'SMTPPass' => decrypt_secret((string) ($sender['sender_smtp_password'] ?? '')),
                ];

                if (!empty($sender['sender_smtp_security'])) {
                    $config['SMTPCrypto'] = $sender['sender_smtp_security'];
                }

                $email->initialize($config + ['mailType' => 'text', 'newline' => "\r\n"]);

                if (!empty($sender['sender_email'])) {
                    $email->setFrom($sender['sender_email'], $sender['sender_name'] ?: '');
                }

                return;
            }
        }

        // Nessun mittente PEC dedicato: si usa la casella di sistema
        // (ip_email_senders), da cui l'SMTP è ora configurato.
        $sender = system_email_sender();

        $email->initialize(build_mail_config($sender) + ['mailType' => 'text', 'newline' => "\r\n"]);

        $from = $sender['sender_email'] ?? '';
        if ($from !== '') {
            $email->setFrom($from, $sender['sender_name'] ?? (string) get_setting('company_name', 'InvoicePlane'));
        }
    }

    /** Il file temporaneo vive quanto basta alla send(); CI4 lo legge su disco. */
    private function tempFile(string $xml): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sdi_') . '.xml';
        file_put_contents($tmp, $xml);

        return $tmp;
    }
}