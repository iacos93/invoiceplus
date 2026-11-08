<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Collegamento dei conti di saldo alla banca.
 *
 * L'accesso ai conti via PSD2 passa da un aggregatore con licenza AISP: non è
 * una scelta di comodo, è un requisito di legge, e nessuna applicazione può
 * interrogare le banche per conto proprio. Qui si conservano solo i
 * riferimenti alla connessione presso l'aggregatore, mai le credenziali della
 * banca, che l'utente inserisce sul sito della banca stessa.
 *
 * Il consenso PSD2 scade (di norma 90 giorni) e va rinnovato: senza tenerne
 * traccia la sincronizzazione smetterebbe di funzionare senza spiegazione.
 */
class AddBankSync extends Migration
{
    public function up()
    {
        $columns = [
            // Aggregatore usato: '' nessuno (solo import manuale).
            'sync_provider' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            // Id del conto presso l'aggregatore.
            'sync_account_ref' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            // Id del consenso/requisition, per rinnovarlo alla scadenza.
            'sync_consent_ref' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'sync_consent_expires' => ['type' => 'DATE', 'null' => true],
            'sync_last_run' => ['type' => 'DATETIME', 'null' => true],
            'sync_last_error' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ];

        foreach ($columns as $name => $definition) {
            if (!$this->db->fieldExists($name, 'ip_bank_accounts')) {
                $this->forge->addColumn('ip_bank_accounts', [$name => $definition]);
            }
        }
    }

    public function down()
    {
        foreach (['sync_provider', 'sync_account_ref', 'sync_consent_ref',
                  'sync_consent_expires', 'sync_last_run', 'sync_last_error'] as $name) {
            if ($this->db->fieldExists($name, 'ip_bank_accounts')) {
                $this->forge->dropColumn('ip_bank_accounts', $name);
            }
        }
    }
}
