<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Scadenza del token di reset password.
 *
 * Fino ad ora il token restava valido finche' non veniva consumato: un link
 * finito in un vecchio backup, in un log del server di posta o in una casella
 * abbandonata funzionava ancora mesi dopo. Con questa colonna il token vale
 * un'ora, che e' il tempo che serve a chi lo ha chiesto davvero.
 *
 * La colonna nasce NULL sui token gia' emessi: Sessions::passwordreset()
 * tratta il valore mancante come "scaduto", quindi i vecchi link smettono di
 * funzionare appena la migrazione passa -- che e' l'esito voluto, visto che
 * sono esattamente quelli senza scadenza.
 */
class AddPasswordResetTokenExpiry extends Migration
{
    public function up()
    {
        if ($this->db->fieldExists('user_passwordreset_token_expires', 'ip_users')) {
            return;
        }

        $this->forge->addColumn('ip_users', [
            'user_passwordreset_token_expires' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
                'after'   => 'user_passwordreset_token',
            ],
        ]);
    }

    public function down()
    {
        if ($this->db->fieldExists('user_passwordreset_token_expires', 'ip_users')) {
            $this->forge->dropColumn('ip_users', 'user_passwordreset_token_expires');
        }
    }
}
