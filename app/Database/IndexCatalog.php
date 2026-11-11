<?php

namespace App\Database;

use CodeIgniter\Database\BaseConnection;

/**
 * Indici sulle colonne che l'applicazione usa davvero, con i nomi che non si
 * pestano i piedi.
 *
 * Su PostgreSQL i nomi degli indici sono unici per schema, non per tabella:
 * creare `idx_client` su ip_invoices impedisce di creare un indice con lo
 * stesso nome su ip_quotes. Le migrazioni originali usavano
 * `CREATE INDEX IF NOT EXISTS`, che in quel caso non crea l'indice e non dice
 * niente: solo la prima tabella di ogni nome restava indicizzata, le altre
 * continuavano a fare scansioni complete. Su MySQL il problema non esiste
 * (i nomi sono per tabella), ma usare lo stesso schema su entrambi i motori
 * rende il comportamento prevedibile.
 *
 * Il nome definitivo e' quindi `<tabella>_<nome storico>`. Il controllo tiene
 * conto anche del nome storico, cosi' un database che ha gia' eseguito le
 * migrazioni vecchie non si ritrova due indici sulle stesse colonne.
 */
final class IndexCatalog
{
    /** [tabella, nome storico, colonne] — company_id e date dei documenti. */
    public const PERFORMANCE = [
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

    /** [tabella, nome storico, colonne] — relazioni secondarie usate nei JOIN. */
    public const RELATIONS = [
        ['ip_expenses',      'idx_supplier', 'supplier_id'],
        ['ip_invoice_items', 'idx_task',     'item_task_id'],
        ['ip_projects',      'idx_client',   'client_id'],
        ['ip_tasks',         'idx_project',  'project_id'],
        ['ip_user_clients',  'idx_client',   'client_id'],
    ];

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function all(): array
    {
        return array_merge(self::PERFORMANCE, self::RELATIONS);
    }

    /**
     * Crea gli indici che mancano. Una colonna assente dallo schema (per
     * esempio in un'installazione a cui manca una migrazione successiva) fa
     * saltare quell'indice, non fallire tutto.
     *
     * @param list<array{0: string, 1: string, 2: string}> $indexes
     */
    public static function ensure(BaseConnection $db, array $indexes): void
    {
        // CodeIgniter tiene in cache i nomi delle colonne letti da una tabella.
        // Le migrazioni precedenti hanno chiesto "company_id esiste?" prima di
        // aggiungerla: senza questo azzeramento la risposta resta quella di
        // allora, e gli indici su company_id vengono saltati come se la
        // colonna non ci fosse.
        $db->resetDataCache();

        foreach ($indexes as [$table, $name, $columns]) {
            if (!$db->tableExists($table)) {
                continue;
            }

            $missing = false;
            foreach (explode(',', $columns) as $column) {
                if (!$db->fieldExists(trim($column), $table)) {
                    $missing = true;
                }
            }
            if ($missing) {
                continue;
            }

            // Gia' coperto: dal nome nuovo, o dal nome storico di un database
            // che ha eseguito la migrazione prima di questo cambio.
            if (self::exists($db, $table, self::name($table, $name))
                || self::exists($db, $table, $name)) {
                continue;
            }

            $db->query('CREATE INDEX ' . self::name($table, $name) . " ON {$table} ({$columns})");
        }
    }

    /**
     * Toglie gli indici, sia quelli con il nome nuovo sia quelli storici.
     *
     * @param list<array{0: string, 1: string, 2: string}> $indexes
     */
    public static function drop(BaseConnection $db, array $indexes): void
    {
        $db->resetDataCache();

        foreach ($indexes as [$table, $name]) {
            if (!$db->tableExists($table)) {
                continue;
            }

            foreach ([self::name($table, $name), $name] as $candidate) {
                if (self::exists($db, $table, $candidate)) {
                    $db->query('DROP INDEX ' . $candidate
                        . ($db->DBDriver === 'MySQLi' ? " ON {$table}" : ''));
                }
            }
        }
    }

    public static function name(string $table, string $name): string
    {
        return $table . '_' . $name;
    }

    private static function exists(BaseConnection $db, string $table, string $name): bool
    {
        foreach ($db->getIndexData($table) as $index) {
            if ($index->name === $name) {
                return true;
            }
        }

        return false;
    }
}
