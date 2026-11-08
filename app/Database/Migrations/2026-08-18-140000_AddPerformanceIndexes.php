<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Indici mancanti sulle colonne su cui l'applicazione filtra davvero.
 *
 * Due famiglie:
 *
 * 1. company_id — da quando ogni lista, report e ricerca e' ristretta
 *    all'azienda attiva, questa colonna compare in quasi ogni WHERE e non
 *    era indicizzata da nessuna parte. Dove esiste anche una colonna data
 *    l'indice e' composto (company_id, data), perche' il filtro tipico e'
 *    "questa azienda, quest'anno" e cosi' viene servito da un indice solo.
 *
 * 2. Chiavi esterne e date usate da report e scadenzario (client_id,
 *    invoice_date_due, expense_date, payment_date...), finora tutte scansioni
 *    complete.
 *
 * ip_settings.setting_key diventa UNIQUE: l'indice esisteva ma non univoco,
 * e un ON DUPLICATE KEY UPDATE su quella colonna inseriva righe doppie
 * invece di aggiornarle.
 */
class AddPerformanceIndexes extends Migration
{
    /** [tabella, nome indice, colonne] */
    private const INDEXES = [
        ['ip_invoices',          'idx_company_date',   'company_id, invoice_date_created'],
        ['ip_invoices',          'idx_client',         'client_id'],
        ['ip_invoices',          'idx_date_due',       'invoice_date_due'],
        ['ip_invoices',          'idx_group',          'invoice_group_id'],
        ['ip_quotes',            'idx_company_date',   'company_id, quote_date_created'],
        ['ip_quotes',            'idx_client',         'client_id'],
        ['ip_quotes',            'idx_group',          'invoice_group_id'],
        ['ip_clients',           'idx_company',        'company_id'],
        ['ip_products',          'idx_company',        'company_id'],
        ['ip_products',          'idx_family',         'family_id'],
        ['ip_invoice_groups',    'idx_company',        'company_id'],
        ['ip_email_senders',     'idx_company',        'company_id'],
        ['ip_bank_accounts',     'idx_company',        'company_id'],
        ['ip_f24',               'idx_company',        'company_id'],
        ['ip_expenses',          'idx_company_date',   'company_id, expense_date'],
        ['ip_expenses',          'idx_category',       'expense_category_id'],
        ['ip_expenses',          'idx_bank_account',   'bank_account_id'],
        ['ip_delivery_notes',    'idx_company_date',   'company_id, delivery_note_date'],
        ['ip_delivery_notes',    'idx_client',         'client_id'],
        ['ip_delivery_notes',    'idx_invoice',        'invoice_id'],
        ['ip_bank_transactions', 'idx_company_date',   'company_id, transaction_date'],
        ['ip_bank_transactions', 'idx_bank_account',   'bank_account_id'],
        ['ip_payments',          'idx_payment_date',   'payment_date'],
        ['ip_delivery_note_items', 'idx_delivery_note', 'delivery_note_id'],
    ];

    public function up()
    {
        foreach (self::INDEXES as [$table, $name, $columns]) {
            if (!$this->db->tableExists($table) || $this->indexExists($table, $name)) {
                continue;
            }

            // Una colonna citata nell'elenco ma assente dallo schema (per
            // esempio in un'installazione a cui manca una migrazione
            // successiva) deve saltare l'indice, non far fallire la migrazione.
            $missing = false;
            foreach (explode(',', $columns) as $column) {
                if (!$this->db->fieldExists(trim($column), $table)) {
                    $missing = true;
                }
            }
            if ($missing) {
                continue;
            }

            $this->db->query("CREATE INDEX IF NOT EXISTS {$name} ON {$table} ({$columns})");
        }

        // setting_key: da indice semplice a univoco.
        if ($this->db->tableExists('ip_settings') && !$this->indexExists('ip_settings', 'setting_key_unique')) {
            // Eventuali doppioni gia' presenti impedirebbero la creazione
            // dell'indice: si tiene la riga con id piu' alto, cioe' l'ultima
            // salvata, che e' quella che l'applicazione stava leggendo.
            if ($this->db->DBDriver === 'MySQLi') {
                $this->db->query("
                    DELETE s1 FROM ip_settings s1
                    INNER JOIN ip_settings s2
                      ON s1.setting_key = s2.setting_key AND s1.setting_id < s2.setting_id");
            } else {
                $this->db->query("
                    DELETE FROM ip_settings
                    WHERE setting_id NOT IN (
                        SELECT MAX(setting_id) FROM ip_settings GROUP BY setting_key
                    )");
            }

            if ($this->db->DBDriver === 'MySQLi') {
                $this->db->query('DROP INDEX setting_key ON ip_settings');
            } else {
                $this->db->query('DROP INDEX IF EXISTS setting_key');
            }
            $this->db->query('CREATE UNIQUE INDEX IF NOT EXISTS setting_key_unique ON ip_settings (setting_key)');
        }
    }

    public function down()
    {
        foreach (self::INDEXES as [$table, $name, $columns]) {
            if ($this->db->tableExists($table) && $this->indexExists($table, $name)) {
                $this->db->query("DROP INDEX IF EXISTS {$name}" . ($this->db->DBDriver === 'MySQLi' ? " ON {$table}" : ''));
            }
        }

        if ($this->db->tableExists('ip_settings') && $this->indexExists('ip_settings', 'setting_key_unique')) {
            $this->db->query('DROP INDEX IF EXISTS setting_key_unique' . ($this->db->DBDriver === 'MySQLi' ? ' ON ip_settings' : ''));
            $this->db->query('CREATE INDEX IF NOT EXISTS setting_key ON ip_settings (setting_key)');
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        foreach ($this->db->getIndexData($table) as $index) {
            if ($index->name === $name) {
                return true;
            }
        }

        return false;
    }
}
