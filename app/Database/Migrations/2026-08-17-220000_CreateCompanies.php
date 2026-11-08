<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Gestione multi-azienda.
 *
 * Serve a chi opera su più soggetti giuridici (tipicamente holding più società
 * operativa): ognuno ha partita IVA, numerazione, conti e documenti propri, e
 * i dati non devono mai mescolarsi.
 *
 * L'azienda si aggiunge come colonna sulle tabelle transazionali invece di
 * duplicare il database: i documenti restano in un unico archivio, e la
 * separazione è una condizione nelle query. Le tabelle esistenti ricevono
 * company_id nullable, così i dati già presenti non diventano invisibili: al
 * primo avvio vengono assegnati all'azienda predefinita.
 *
 * Le anagrafiche di servizio (aliquote, unità, metodi di pagamento) restano
 * condivise: sono tabelle di supporto, non dati fiscali del soggetto.
 */
class CreateCompanies extends Migration
{
    /** Tabelle che contengono dati riferibili a un soggetto giuridico. */
    private const SCOPED_TABLES = [
        'ip_invoices',
        'ip_quotes',
        'ip_clients',
        'ip_expenses',
        'ip_bank_accounts',
        'ip_invoice_groups',
        'ip_bank_transactions',
        'ip_products',
    ];

    public function up()
    {
        if (!$this->db->tableExists('ip_companies')) {
            $this->forge->addField([
                'company_id' => [
                    'type' => 'INT', 'auto_increment' => true,
                ],
                'company_name'      => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => false],
                'company_vat_id'    => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
                'company_tax_code'  => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
                'company_address_1' => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                'company_city'      => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'company_state'     => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
                'company_zip'       => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => true],
                'company_country'   => ['type' => 'VARCHAR', 'constraint' => 5, 'null' => true],
                'company_phone'     => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
                'company_email'     => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => true],
                // Forma giuridica e parametri che guidano la stima delle imposte.
                'company_form'          => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'company_coefficient'   => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
                'company_contributions' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'null' => true],
                // Codice SIA: identifica il creditore nei flussi RiBa verso la banca.
                'company_sia_code'  => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true],
                'company_is_default' => [
                    'type' => 'BOOLEAN', 'null' => false, 'default' => false,
                ],
                'company_is_active' => [
                    'type' => 'BOOLEAN', 'null' => false, 'default' => true,
                ],
                'company_date_created' => ['type' => 'DATETIME', 'null' => true],
            ]);

            $this->forge->addKey('company_id', true);
            $this->forge->createTable('ip_companies');
        }

        foreach (self::SCOPED_TABLES as $table) {
            if ($this->db->tableExists($table) && !$this->db->fieldExists('company_id', $table)) {
                $this->forge->addColumn($table, [
                    'company_id' => [
                        'type' => 'INT', 'null' => true,
                    ],
                ]);
            }
        }

        $this->seedDefaultCompany();
    }

    /**
     * Prima azienda a partire dai dati già presenti nel profilo utente, e
     * assegnazione dei documenti esistenti.
     *
     * Senza questo passaggio, dopo la migrazione tutto avrebbe company_id NULL
     * e sparirebbe dalle liste filtrate: l'utente vedrebbe un archivio vuoto.
     */
    private function seedDefaultCompany(): void
    {
        if ($this->db->table('ip_companies')->countAllResults() > 0) {
            return;
        }

        $user = $this->db->table('ip_users')->orderBy('user_id', 'ASC')->get(1)->getRowArray();

        $name = trim((string) ($user['user_company'] ?? '')) ?: trim((string) ($user['user_name'] ?? '')) ?: 'Azienda';

        $this->db->table('ip_companies')->insert([
            'company_name'      => $name,
            'company_vat_id'    => $user['user_vat_id'] ?? null,
            'company_tax_code'  => $user['user_tax_code'] ?? null,
            'company_address_1' => $user['user_address_1'] ?? null,
            'company_city'      => $user['user_city'] ?? null,
            'company_state'     => $user['user_state'] ?? null,
            'company_zip'       => $user['user_zip'] ?? null,
            'company_country'   => $user['user_country'] ?? null,
            'company_phone'     => $user['user_phone'] ?? null,
            'company_email'     => $user['user_email'] ?? null,
            'company_form'      => 'semplificato',
            // Booleani nativi: PostgreSQL rifiuta gli interi su colonne boolean.
            'company_is_default' => true,
            'company_is_active'  => true,
            'company_date_created' => date('Y-m-d H:i:s'),
        ]);

        $companyId = (int) $this->db->insertID();

        foreach (self::SCOPED_TABLES as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->table($table)->where('company_id IS NULL', null, false)
                         ->update(['company_id' => $companyId]);
            }
        }
    }

    public function down()
    {
        foreach (self::SCOPED_TABLES as $table) {
            if ($this->db->tableExists($table) && $this->db->fieldExists('company_id', $table)) {
                $this->forge->dropColumn($table, 'company_id');
            }
        }

        if ($this->db->tableExists('ip_companies')) {
            $this->forge->dropTable('ip_companies');
        }
    }
}
