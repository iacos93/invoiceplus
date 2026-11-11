<?php

namespace App\Database\Migrations;

use App\Database\IndexCatalog;
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
 * L'elenco e il modo di creare gli indici stanno in IndexCatalog: su
 * PostgreSQL il nome di un indice e' unico per schema, quindi `idx_client` su
 * ip_invoices impediva di crearlo su ip_quotes e le altre tabelle restavano
 * senza. Il nome definitivo e' `<tabella>_<nome storico>`.
 *
 * ip_settings.setting_key diventa UNIQUE: l'indice esisteva ma non univoco,
 * e un ON DUPLICATE KEY UPDATE su quella colonna inseriva righe doppie
 * invece di aggiornarle.
 */
class AddPerformanceIndexes extends Migration
{
    public function up()
    {
        IndexCatalog::ensure($this->db, IndexCatalog::PERFORMANCE);

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

            // L'indice semplice setting_key non esiste in un'installazione
            // nuova: su MySQL il DROP fallisce e fermava la migrazione.
            if ($this->indexExists('ip_settings', 'setting_key')) {
                $this->db->query(
                    'DROP INDEX setting_key' . ($this->db->DBDriver === 'MySQLi' ? ' ON ip_settings' : '')
                );
            }

            $this->db->query('CREATE UNIQUE INDEX setting_key_unique ON ip_settings (setting_key)');
        }
    }

    public function down()
    {
        IndexCatalog::drop($this->db, IndexCatalog::PERFORMANCE);

        if ($this->db->tableExists('ip_settings') && $this->indexExists('ip_settings', 'setting_key_unique')) {
            $this->db->query('DROP INDEX setting_key_unique' . ($this->db->DBDriver === 'MySQLi' ? ' ON ip_settings' : ''));
            $this->db->query('CREATE INDEX setting_key ON ip_settings (setting_key)');
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
