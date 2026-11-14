<?php

namespace App\Libraries;

use App\Models\CompanyModel;
use App\Models\UserModel;

/**
 * Notifiche interne dell'applicazione al titolare (non ai clienti).
 *
 * Stava come metodo privato del controller dei cron; serve anche agli
 * endpoint che lo SdI chiama (un controller diverso, senza sessione), quindi
 * vive qui. Il destinatario e' la casella di sistema dell'azienda attiva, con
 * ripiego sull'email del primo amministratore.
 *
 * Senza una casella di invio configurata non si manda niente e lo si dice nel
 * log: mail() di sistema dal cron esce senza firma SPF/DKIM della casella
 * aziendale, o resta in coda all'MTA locale.
 */
final class SystemNotifier
{
    public function recipient(): string
    {
        $sender = system_email_sender();

        if (! empty($sender['sender_email'])) {
            return (string) $sender['sender_email'];
        }

        $admin = (new UserModel())->where('user_type', 1)->first();

        return trim((string) ($admin['user_email'] ?? ''));
    }

    /** Ragione sociale dell'azienda attiva, per mittente, oggetto e firma. */
    public function companyName(?int $companyId = null): string
    {
        $companyId ??= active_company_id();
        $company   = $companyId ? (new CompanyModel())->find($companyId) : null;

        return trim((string) ($company['company_name'] ?? '')) ?: 'Invoice+';
    }

    public function send(string $subject, string $body): bool
    {
        $sender = system_email_sender();

        if (empty($sender['sender_email'])) {
            log_message('error', 'Email automatica non inviata: nessuna casella di invio configurata per l\'azienda '
                . $this->companyName() . ' (Impostazioni > Email e mittenti)');

            return false;
        }

        $to = $this->recipient();

        if ($to === '') {
            log_message('error', 'Notifica non inviata: nessun destinatario configurato (casella di sistema o primo amministratore)');

            return false;
        }

        $email = \Config\Services::email(null, false);
        $email->initialize(build_mail_config($sender));
        $email->setFrom(system_mail_from(), $this->companyName());
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($body);

        return $email->send();
    }
}
