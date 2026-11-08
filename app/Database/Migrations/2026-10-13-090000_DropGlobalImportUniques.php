<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Completa ScopeImportDedupPerCompany su PostgreSQL.
 *
 * Quella migrazione cercava il vincolo globale con il nome che ha su MySQL
 * (l'indice "expense_source_ref"), ma su PostgreSQL la forge di CI4 crea i
 * UNIQUE come vincoli di tabella chiamati "<tabella>_<colonna>": il vecchio
 * vincolo non veniva trovato e restava accanto a quello per azienda. Effetto
 * pratico: la stessa fattura di acquisto importata in una seconda azienda
 * non veniva "saltata" né importata, ma faceva esplodere l'insert con una
 * DatabaseException -- dopo che il fornitore era già stato creato, e quindi
 * lasciato orfano in anagrafica. Stesso discorso per i movimenti bancari.
 *
 * Si tolgono i vincoli globali; quelli per azienda (company_id, chiave)
 * restano l'unica definizione di duplicato.
 */
class DropGlobalImportUniques extends Migration
{
    /** [tabella, vincolo globale su PostgreSQL] */
    private const CONSTRAINTS = [
        ['ip_expenses',          'ip_expenses_expense_source_ref'],
        ['ip_bank_transactions', 'ip_bank_transactions_transaction_hash'],
    ];

    public function up()
    {
        if ($this->db->DBDriver !== 'Postgre') {
            // Su MySQL il vincolo globale l'ha già tolto ScopeImportDedupPerCompany.
            return;
        }

        foreach (self::CONSTRAINTS as [$table, $constraint]) {
            if ($this->db->tableExists($table)) {
                $this->db->query("ALTER TABLE \"{$table}\" DROP CONSTRAINT IF EXISTS \"{$constraint}\"");
            }
        }
    }

    public function down()
    {
        // Volutamente vuoto: ricreare il vincolo globale fallirebbe appena lo
        // stesso documento esiste in due aziende, che è proprio il caso che
        // questa migrazione rende possibile.
    }
}
