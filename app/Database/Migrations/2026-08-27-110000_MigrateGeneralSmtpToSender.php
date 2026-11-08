<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migra i parametri SMTP generali in un mittente di ip_email_senders.
 *
 * Fino a oggi l'SMTP di sistema viveva nelle impostazioni (email_send_method,
 * smtp_server_address, smtp_username, smtp_port, smtp_password, smtp_security,
 * smtp_mail_from) e veniva ricostruito in quattro punti duplicati. Ora l'SMTP
 * sta solo nei mittenti: questa migration crea un mittente dai valori esistenti
 * (se SMTP configurato), lo marca predefinito e lo aggancia a ogni azienda come
 * casella di sistema, poi elimina le chiavi obsolete. Idempotente: se esiste già
 * un mittente predefinito non crea duplicati e le chiavi vecchie vengono comunque
 * rimosse.
 */
class MigrateGeneralSmtpToSender extends Migration
{
    /** Chiavi impostazioni SMTP generali ormai obsolete. */
    private const OLD_KEYS = [
        'email_send_method', 'smtp_server_address', 'smtp_username', 'smtp_port',
        'smtp_password', 'smtp_security', 'smtp_mail_from',
    ];

    public function up()
    {
        $settings = $this->readSettings();

        $method = $settings['email_send_method'] ?? '';
        $host   = trim((string) ($settings['smtp_server_address'] ?? ''));

        $defaultExists = (bool) $this->db->table('ip_email_senders')
            ->where('sender_is_default', true)
            ->countAllResults();

        if ($method === 'smtp' && $host !== '' && !$defaultExists) {
            $email = $this->resolveSenderEmail($settings['smtp_mail_from'] ?? '');

            if ($email !== '') {
                $password = '';
                if (!empty($settings['smtp_password'])) {
                    // Nelle impostazioni era cifrato con l'encrypter nativo CI4
                    // (decrypt_setting_password); nei mittenti si usa Cryptor
                    // (encrypt_secret), quindi lo ri-cifriamo al formato giusto.
                    $plain   = decrypt_setting_password((string) $settings['smtp_password']);
                    $password = $plain !== '' ? encrypt_secret($plain) : '';
                }

                $this->db->table('ip_email_senders')->insert([
                    'company_id'           => null,
                    'sender_name'          => 'SMTP di sistema (migrazione)',
                    'sender_email'         => $email,
                    'sender_smtp_host'     => $host,
                    'sender_smtp_port'     => (int) ($settings['smtp_port'] ?? 25),
                    'sender_smtp_user'     => $settings['smtp_username'] ?? '',
                    'sender_smtp_password' => $password,
                    'sender_smtp_security' => $settings['smtp_security'] ?? '',
                    'sender_is_default'    => true,
                ]);

                $senderId = (int) $this->db->insertID();

                // Aggancia il mittente a ogni azienda come casella di sistema.
                if ($senderId > 0) {
                    $this->db->table('ip_companies')
                        ->where('company_system_sender_id IS NULL', null, false)
                        ->update(['company_system_sender_id' => $senderId]);
                }

                // Le chiavi SMTP generali sono state migrate: si possono rimuovere.
                $this->db->table('ip_settings')->whereIn('setting_key', self::OLD_KEYS)->delete();
            }
        }
    }

    public function down()
    {
        $this->db->table('ip_email_senders')
            ->where('sender_name', 'SMTP di sistema (migrazione)')
            ->delete();
    }

    /**
     * Legge le impostazioni SMTP generali in un array chiave => valore.
     */
    private function readSettings(): array
    {
        $rows = $this->db->table('ip_settings')
            ->whereIn('setting_key', self::OLD_KEYS)
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }

        return $out;
    }

    /**
     * L'indirizzo mittente: lo smtp_mail_from se valido, altrimenti la prima
     * email utente disponibile; vuoto se non si trova nulla di utilizzabile.
     */
    private function resolveSenderEmail(string $candidate): string
    {
        if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            return $candidate;
        }

        $user = $this->db->table('ip_users')
            ->select('user_email')
            ->where('user_email !=', '')
            ->orderBy('user_id', 'ASC')
            ->get()
            ->getRowArray();

        return $user['user_email'] ?? '';
    }
}
