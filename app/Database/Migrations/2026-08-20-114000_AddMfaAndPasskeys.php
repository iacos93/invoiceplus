<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Autenticazione a due fattori e passkey.
 *
 * MFA (TOTP) e WebAuthn vivono sull'account utente, non sulle impostazioni
 * globali: ogni utente decide se e come proteggere il proprio accesso. Le
 * colonne restano tutte nullable/off di default, così l'aggiornamento non
 * cambia il comportamento di nessuno finché l'utente non attiva qualcosa.
 *
 * La tabella ip_passkeys contiene le credenziali WebAuthn (la coppia di
 * chiavi vive sul dispositivo dell'utente; qui resta solo la chiave pubblica
 * PEM, l'id della credenziale e il contatore anti-clonazione). Nessun segreto
 * lato server: una passkey persa si elimina semplicemente.
 */
class AddMfaAndPasskeys extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('ip_users')) {
            $fields = [
                // Segreto TOTP (base32). Il fatto che sia impostato ma
                // user_mfa_enabled a 0 significa "setup in corso, non ancora
                // confermato": l'utente non è mai bloccato da un setup a metà.
                'user_mfa_secret' => ['type' => 'TEXT', 'null' => true, 'default' => null],
                'user_mfa_enabled' => [
                    'type' => 'BOOLEAN', 'null' => false, 'default' => false,
                ],
                // Codici di recupero monouso, salvati come hash sha256 (JSON).
                'user_mfa_backup_codes' => ['type' => 'TEXT', 'null' => true, 'default' => null],
                // Ultimo contatore TOTP accettato: un codice già usato non
                // deve funzionare una seconda volta (anti-replay).
                'user_mfa_last_counter' => ['type' => 'BIGINT', 'null' => true, 'default' => null],
            ];

            foreach ($fields as $field => $def) {
                if (!$this->db->fieldExists($field, 'ip_users')) {
                    $this->forge->addColumn('ip_users', [$field => $def]);
                }
            }
        }

        if (!$this->db->tableExists('ip_passkeys')) {
            $this->forge->addField([
                'passkey_id' => [
                    'type' => 'INT', 'auto_increment' => true,
                ],
                'user_id'   => ['type' => 'INT', 'null' => false],
                'passkey_name' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false, 'default' => ''],
                // Id della credenziale (base64) e chiave pubblica PEM.
                'passkey_credential_id' => ['type' => 'TEXT', 'null' => false],
                'passkey_public_key'    => ['type' => 'TEXT', 'null' => false],
                // Contatore degli authenticator: se regredisce, la chiave è
                // stata clonata e il login va rifiutato (lbuchs/webauthn).
                'passkey_counter' => ['type' => 'INT', 'null' => false, 'default' => 0],
                'passkey_created_at'  => ['type' => 'DATETIME', 'null' => false],
                'passkey_last_used_at' => ['type' => 'DATETIME', 'null' => true, 'default' => null],
            ]);

            $this->forge->addKey('passkey_id', true);
            $this->forge->addKey('user_id');
            $this->forge->createTable('ip_passkeys');

            // Lookup diretto per credential id (login passkey senza username):
            // serve un indice con lunghezza fissa perché TEXT non si indicizza
            // per intero su MySQL; su PostgreSQL si usa un indice su espressione.
            if ($this->db->DBDriver === 'Postgre') {
                $this->db->query('CREATE INDEX idx_ip_passkeys_credential ON ip_passkeys (LEFT(passkey_credential_id, 64))');
            } else {
                $this->db->query('CREATE INDEX idx_ip_passkeys_credential ON ip_passkeys (passkey_credential_id)');
            }
        }
    }

    public function down()
    {
        if ($this->db->tableExists('ip_passkeys')) {
            $this->forge->dropTable('ip_passkeys');
        }

        if ($this->db->tableExists('ip_users')) {
            foreach (['user_mfa_secret', 'user_mfa_enabled', 'user_mfa_backup_codes', 'user_mfa_last_counter'] as $field) {
                if ($this->db->fieldExists($field, 'ip_users')) {
                    $this->forge->dropColumn('ip_users', $field);
                }
            }
        }
    }
}