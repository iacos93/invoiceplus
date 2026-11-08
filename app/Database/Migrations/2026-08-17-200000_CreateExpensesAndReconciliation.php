<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ciclo passivo, riconciliazione bancaria e aree merceologiche.
 *
 * Finora l'applicazione conosceva solo il ciclo attivo (fatture, preventivi,
 * incassi): senza un archivio delle spese qualunque report "entrate/uscite" o
 * "utile" mostrerebbe solo metà dei numeri. Qui si aggiungono:
 *
 *   - ip_expenses            fatture di acquisto e spese, anche importate da XML
 *   - ip_expense_categories  voci di costo, per i report per categoria
 *   - ip_bank_transactions   movimenti bancari da estratto conto, da abbinare
 *                            a fatture (incassi) e spese (pagamenti)
 *   - ip_invoice_items.item_family_id
 *                            area merceologica della riga, copiata dal prodotto
 *                            al momento della fatturazione: serve a reggere il
 *                            report per area anche quando il prodotto viene poi
 *                            modificato o eliminato
 */
class CreateExpensesAndReconciliation extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('ip_expense_categories')) {
            $this->forge->addField([
                'expense_category_id' => [
                    'type' => 'INT', 'auto_increment' => true,
                ],
                'expense_category_name' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => false],
                // Costo deducibile: incide sul calcolo dell'utile imponibile.
                'expense_category_deductible' => [
                    'type' => 'BOOLEAN', 'null' => false, 'default' => true,
                ],
            ]);
            $this->forge->addKey('expense_category_id', true);
            $this->forge->createTable('ip_expense_categories');
        }

        if (!$this->db->tableExists('ip_expenses')) {
            $this->forge->addField([
                'expense_id' => [
                    'type' => 'INT', 'auto_increment' => true,
                ],
                'expense_date'      => ['type' => 'DATE', 'null' => false],
                'expense_number'    => ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true],
                // Fornitore denormalizzato: le spese importate da XML arrivano
                // con i dati dentro il file, e obbligare a creare un'anagrafica
                // per ogni fornitore occasionale bloccherebbe l'importazione.
                'supplier_name'     => ['type' => 'VARCHAR', 'constraint' => 150, 'null' => false],
                'supplier_vat'      => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
                'supplier_tax_code' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true],
                'expense_category_id' => [
                    'type' => 'INT', 'null' => true,
                ],
                'expense_amount'    => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => false, 'default' => 0],
                'expense_tax'       => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => false, 'default' => 0],
                'expense_total'     => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => false, 'default' => 0],
                'expense_paid'      => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => false, 'default' => 0],
                'expense_note'      => ['type' => 'TEXT', 'null' => true],
                'bank_account_id'   => ['type' => 'INT', 'null' => true],
                // Provenienza: 'manual' oppure 'xml' (fattura elettronica).
                'expense_source'    => ['type' => 'VARCHAR', 'constraint' => 20, 'null' => false, 'default' => 'manual'],
                // Identificativo del documento importato: evita di caricare due
                // volte la stessa fattura ricevuta.
                'expense_source_ref' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
                'expense_file'      => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
                'expense_date_created' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('expense_id', true);
            $this->forge->addUniqueKey('expense_source_ref');
            $this->forge->createTable('ip_expenses');
        }

        if (!$this->db->tableExists('ip_bank_transactions')) {
            $this->forge->addField([
                'transaction_id' => [
                    'type' => 'INT', 'auto_increment' => true,
                ],
                'bank_account_id'  => ['type' => 'INT', 'null' => true],
                'transaction_date' => ['type' => 'DATE', 'null' => false],
                // Positivo = entrata, negativo = uscita: un solo campo evita di
                // dover tenere allineati importo e verso.
                'transaction_amount' => ['type' => 'DECIMAL', 'constraint' => '20,2', 'null' => false, 'default' => 0],
                'transaction_description' => ['type' => 'TEXT', 'null' => true],
                // Impronta della riga di estratto conto: reimportare lo stesso
                // file non deve duplicare i movimenti.
                'transaction_hash' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => false],
                // Abbinamento: al massimo uno dei due è valorizzato.
                'invoice_id' => ['type' => 'INT', 'null' => true],
                'expense_id' => ['type' => 'INT', 'null' => true],
                'is_reconciled' => ['type' => 'BOOLEAN', 'null' => false, 'default' => false],
            ]);
            $this->forge->addKey('transaction_id', true);
            $this->forge->addUniqueKey('transaction_hash');
            $this->forge->createTable('ip_bank_transactions');
        }

        if (!$this->db->fieldExists('item_family_id', 'ip_invoice_items')) {
            $this->forge->addColumn('ip_invoice_items', [
                'item_family_id' => [
                    'type' => 'INT', 'null' => true,
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('item_family_id', 'ip_invoice_items')) {
            $this->forge->dropColumn('ip_invoice_items', 'item_family_id');
        }

        foreach (['ip_bank_transactions', 'ip_expenses', 'ip_expense_categories'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table);
            }
        }
    }
}
