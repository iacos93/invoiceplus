<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Controllo di gestione: piano dei conti, budget e associazione delle righe.
 *
 * Il "Bilancino" di Fattura24 (Controllo di gestione) non è un prospetto
 * fiscale: è un conto economico riclassificato per destinazione. Per
 * ottenerlo servono tre cose che prima non esistevano:
 *
 *  - un piano dei conti alberato con la distinzione che conta per il margine
 *    (ricavi, costi variabili, costi fissi: l'EBITDA nasce da lì);
 *  - il collegamento riga per riga, perché una fattura può contenere sia
 *    vendite sia servizi e una spesa può essere una materia prima o un
 *    canone d'affitto;
 *  - il budget per centro, mese per mese, altrimenti il report dice com'è
 *    andata ma non se era quello che ci si aspettava.
 *
 * Le tre tabelle sono per azienda. Le righe con company_id NULL non vengono
 * create qui: il piano standard lo importa l'utente dalla pagina, così nasce
 * già dell'azienda giusta e non diventa un modello condiviso che una società
 * modificherebbe per tutte.
 */
class CreateManagementControl extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('ip_costing_accounts')) {
            $this->forge->addField([
                'account_id'     => ['type' => 'INT', 'auto_increment' => true],
                'company_id'     => ['type' => 'INT', 'null' => true],
                // Albero a un livello di profondità illimitato: i sottoconti
                // servono a distinguere "Vendite" da "Vendite Italia" senza
                // duplicare il totale nel report (il padre somma i figli).
                'parent_id'      => ['type' => 'INT', 'null' => true],
                'account_code'   => ['type' => 'VARCHAR', 'constraint' => 20],
                'account_name'   => ['type' => 'VARCHAR', 'constraint' => 150],
                // ricavo | costo_variabile | costo_fisso
                'account_type'   => ['type' => 'VARCHAR', 'constraint' => 20],
                'account_active' => ['type' => 'SMALLINT', 'default' => 1],
                'account_order'  => ['type' => 'INT', 'default' => 0],
                'account_note'   => ['type' => 'TEXT', 'null' => true],
                'account_created' => ['type' => 'TIMESTAMP', 'null' => true],
            ]);
            $this->forge->addKey('account_id', true);
            $this->forge->addKey('company_id');
            $this->forge->addKey('parent_id');
            $this->forge->addKey('account_type');
            $this->forge->createTable('ip_costing_accounts', true);
        }

        if (!$this->db->tableExists('ip_costing_budgets')) {
            $this->forge->addField([
                'budget_id'     => ['type' => 'INT', 'auto_increment' => true],
                'company_id'    => ['type' => 'INT', 'null' => true],
                'account_id'    => ['type' => 'INT'],
                'budget_year'   => ['type' => 'INT'],
                // 1-12: un budget annuale diviso in parti uguali non basta a
                // leggere la stagionalità, che è proprio ciò che si vuole
                // confrontare mese per mese.
                'budget_month'  => ['type' => 'SMALLINT', 'default' => 1],
                'budget_amount' => ['type' => 'DECIMAL', 'constraint' => '20,2', 'default' => 0],
                'budget_updated' => ['type' => 'TIMESTAMP', 'null' => true],
            ]);
            $this->forge->addKey('budget_id', true);
            $this->forge->addKey('account_id');
            $this->forge->addUniqueKey(['account_id', 'budget_year', 'budget_month'], 'budget_account_year_month');
            $this->forge->createTable('ip_costing_budgets', true);
        }

        // Associazione riga fattura → voce di ricavo. NULL = non assegnata:
        // il report la mostra a parte invece di spalmarla su una voce a caso,
        // perché un totale che include il non classificato non è leggibile.
        if (!$this->db->fieldExists('costing_account_id', 'ip_invoice_items')) {
            $this->forge->addColumn('ip_invoice_items', [
                'costing_account_id' => ['type' => 'INT', 'null' => true],
            ]);
            $this->db->query('CREATE INDEX idx_invoice_items_costing ON ip_invoice_items (costing_account_id)');
        }

        // Associazione spesa → voce di costo. Le spese non hanno righe: la
        // classificazione è a livello di documento, come in Fattura24.
        if (!$this->db->fieldExists('costing_account_id', 'ip_expenses')) {
            $this->forge->addColumn('ip_expenses', [
                'costing_account_id' => ['type' => 'INT', 'null' => true],
            ]);
            $this->db->query('CREATE INDEX idx_expenses_costing ON ip_expenses (costing_account_id)');
        }
    }

    public function down()
    {
        $mysql = $this->db->DBDriver === 'MySQLi';

        if ($this->db->fieldExists('costing_account_id', 'ip_expenses')) {
            $this->db->query('DROP INDEX idx_expenses_costing' . ($mysql ? ' ON ip_expenses' : ''));
            $this->forge->dropColumn('ip_expenses', 'costing_account_id');
        }

        if ($this->db->fieldExists('costing_account_id', 'ip_invoice_items')) {
            $this->db->query('DROP INDEX idx_invoice_items_costing' . ($mysql ? ' ON ip_invoice_items' : ''));
            $this->forge->dropColumn('ip_invoice_items', 'costing_account_id');
        }

        $this->forge->dropTable('ip_costing_budgets', true);
        $this->forge->dropTable('ip_costing_accounts', true);
    }
}
