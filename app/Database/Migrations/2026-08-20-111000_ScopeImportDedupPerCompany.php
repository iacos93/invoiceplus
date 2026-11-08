<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Rende per-azienda le due impronte anti-duplicato degli import.
 *
 * ip_bank_transactions.transaction_hash e ip_expenses.expense_source_ref
 * erano UNIQUE sull'intera tabella. In multi-azienda è la definizione
 * sbagliata di duplicato: lo stesso bonifico che compare nell'estratto conto
 * di due società, o la stessa fattura di acquisto ricevuta da entrambe,
 * venivano scartati silenziosamente per la seconda — con il risultato che
 * l'azienda B non vedeva mai quel movimento e la riconciliazione non
 * tornava, senza alcun messaggio che spiegasse perché.
 *
 * Il vincolo diventa (company_id, chiave). Le righe con company_id NULL
 * (archivio pre multi-azienda) restano coperte perché MySQL non considera
 * duplicate due chiavi che contengono NULL: è esattamente il comportamento
 * che serve, quei movimenti non vanno più deduplicati fra loro.
 */
class ScopeImportDedupPerCompany extends Migration
{
    /** [tabella, colonna chiave, nome indice attuale] */
    private const UNIQUES = [
        ['ip_bank_transactions', 'transaction_hash',   'transaction_hash'],
        ['ip_expenses',          'expense_source_ref', 'expense_source_ref'],
    ];

    public function up()
    {
        foreach (self::UNIQUES as [$table, $column, $index]) {
            if (!$this->db->tableExists($table)) {
                continue;
            }

            if ($this->indexExists($table, $index)) {
                $this->db->query("DROP INDEX IF EXISTS \"{$index}\"" . ($this->isPgsql() ? '' : " ON \"{$table}\""));
            }

            $new = $column . '_company';

            if (!$this->indexExists($table, $new)) {
                $this->db->query("CREATE UNIQUE INDEX IF NOT EXISTS \"{$new}\" ON \"{$table}\" (\"company_id\", \"{$column}\")");
            }
        }
    }

    public function down()
    {
        foreach (self::UNIQUES as [$table, $column, $index]) {
            if (!$this->db->tableExists($table)) {
                continue;
            }

            $new = $column . '_company';

            if ($this->indexExists($table, $new)) {
                $this->db->query("DROP INDEX IF EXISTS \"{$new}\"" . ($this->isPgsql() ? '' : " ON \"{$table}\""));
            }

            if (!$this->indexExists($table, $index)) {
                $this->db->query("CREATE UNIQUE INDEX IF NOT EXISTS \"{$index}\" ON \"{$table}\" (\"{$column}\")");
            }
        }
    }

    private function isPgsql(): bool
    {
        return $this->db->DBDriver === 'Postgre';
    }

    private function indexExists(string $table, string $index): bool
    {
        if ($this->isPgsql()) {
            return (int) $this->db->query(
                "SELECT COUNT(*) AS n FROM pg_indexes WHERE tablename = ? AND indexname = ?",
                [$table, $index]
            )->getRowArray()['n'] > 0;
        }

        return (int) $this->db->query(
            'SELECT COUNT(*) AS n FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        )->getRowArray()['n'] > 0;
    }
}
