<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Codice SIA: dall'azienda al conto di accredito.
 *
 * Il SIA identifica il creditore nei flussi CBI, ma non lo assegna l'anagrafe
 * delle imprese: lo assegna la banca, dentro il rapporto di conto. Un'azienda
 * che presenta RiBa su due banche ha due codici diversi, e tenerne uno solo
 * sulla scheda azienda voleva dire generare il flusso della seconda banca col
 * codice della prima — un file che la banca scarta, o peggio accetta
 * appoggiando gli incassi altrove.
 *
 * Il valore esistente viene copiato su tutti i conti dell'azienda: chi ne ha
 * uno solo non deve fare nulla, chi ne ha più di uno corregge quelli che non
 * appartengono a quella banca.
 */
class MoveSiaCodeToBankAccount extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ip_bank_accounts', [
            'bank_account_sia_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 5,
                'null'       => true,
                'after'      => 'bank_account_bic',
            ],
        ]);

        $this->db->query(
            'UPDATE ip_bank_accounts
                SET bank_account_sia_code = c.company_sia_code
               FROM ip_companies c
              WHERE c.company_id = ip_bank_accounts.company_id
                AND c.company_sia_code IS NOT NULL AND c.company_sia_code <> \'\''
        );

        $this->forge->dropColumn('ip_companies', 'company_sia_code');
    }

    public function down()
    {
        $this->forge->addColumn('ip_companies', [
            'company_sia_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 5,
                'null'       => true,
            ],
        ]);

        // Un codice per azienda non basta a rappresentarne uno per conto: si
        // riporta indietro quello del conto predefinito, il piu' probabile.
        $this->db->query(
            'UPDATE ip_companies
                SET company_sia_code = a.bank_account_sia_code
               FROM ip_bank_accounts a
              WHERE a.company_id = ip_companies.company_id
                AND a.bank_account_is_default = 1'
        );

        $this->forge->dropColumn('ip_bank_accounts', 'bank_account_sia_code');
    }
}
