<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Parametri fiscali per azienda.
 *
 * ip_fiscal_years era nata quando l'applicazione gestiva un solo soggetto,
 * con un UNIQUE globale su fiscal_year: una ditta individuale (IRPEF,
 * addizionali del suo comune, INPS artigiani) e una S.r.l. (IRES/IRAP)
 * dovevano condividere la stessa riga 2026, e chi correggeva l'addizionale
 * comunale di una cambiava la stima dell'altra.
 *
 * Si aggiunge company_id a anni e scaglioni. Le righe esistenti restano con
 * company_id NULL, che nel resto dell'applicazione vuol dire "visibile a
 * tutte le aziende": diventano i parametri condivisi di partenza, che ogni
 * azienda può sovrascrivere con una riga sua dello stesso anno
 * (FiscalYearModel::scopeEffective() sceglie quella dell'azienda quando c'è).
 *
 * L'unicità diventa (azienda, anno). Su PostgreSQL due NULL sono distinti in
 * un UNIQUE normale, quindi due righe condivise dello stesso anno passerebbero
 * il vincolo: si usa un indice su COALESCE(company_id, 0), che tratta il
 * "condiviso" come un'azienda in più.
 */
class ScopeFiscalYearsPerCompany extends Migration
{
    public function up()
    {
        foreach (['ip_fiscal_years', 'ip_fiscal_brackets'] as $table) {
            if (!$this->db->fieldExists('company_id', $table)) {
                $this->forge->addColumn($table, [
                    'company_id' => ['type' => 'INT', 'null' => true],
                ]);
            }
        }

        if ($this->db->DBDriver === 'Postgre') {
            // Il vincolo creato da addUniqueKey() su PostgreSQL si chiama
            // "<tabella>_<colonna>" (vedi DropGlobalImportUniques).
            $this->db->query('ALTER TABLE "ip_fiscal_years" DROP CONSTRAINT IF EXISTS "ip_fiscal_years_fiscal_year"');
            $this->db->query('CREATE UNIQUE INDEX IF NOT EXISTS "ip_fiscal_years_company_year"
                ON "ip_fiscal_years" ((COALESCE(company_id, 0)), fiscal_year)');
            $this->db->query('CREATE INDEX IF NOT EXISTS "ip_fiscal_brackets_company_year"
                ON "ip_fiscal_brackets" (company_id, fiscal_year)');

            return;
        }

        // MySQL: l'indice si chiama come la colonna. Due NULL restano
        // distinti anche qui, ma le righe condivise si creano solo da questa
        // migrazione e dal seed, non dall'interfaccia (vedi FiscalYears::form).
        $this->db->query('ALTER TABLE `ip_fiscal_years` DROP INDEX `fiscal_year`');
        $this->db->query('ALTER TABLE `ip_fiscal_years` ADD UNIQUE KEY `ip_fiscal_years_company_year` (`company_id`, `fiscal_year`)');
        $this->db->query('ALTER TABLE `ip_fiscal_brackets` ADD KEY `ip_fiscal_brackets_company_year` (`company_id`, `fiscal_year`)');
    }

    public function down()
    {
        // Le righe per azienda non hanno un posto nello schema precedente:
        // tornare indietro senza cancellarle farebbe fallire il vincolo
        // globale appena due aziende hanno lo stesso anno. Si tengono solo
        // quelle condivise, come prima di questa migrazione.
        $this->db->table('ip_fiscal_brackets')->where('company_id IS NOT NULL', null, false)->delete();
        $this->db->table('ip_fiscal_years')->where('company_id IS NOT NULL', null, false)->delete();

        if ($this->db->DBDriver === 'Postgre') {
            $this->db->query('DROP INDEX IF EXISTS "ip_fiscal_years_company_year"');
            $this->db->query('DROP INDEX IF EXISTS "ip_fiscal_brackets_company_year"');
            $this->db->query('ALTER TABLE "ip_fiscal_years" ADD CONSTRAINT "ip_fiscal_years_fiscal_year" UNIQUE (fiscal_year)');
        } else {
            $this->db->query('ALTER TABLE `ip_fiscal_years` DROP INDEX `ip_fiscal_years_company_year`');
            $this->db->query('ALTER TABLE `ip_fiscal_brackets` DROP INDEX `ip_fiscal_brackets_company_year`');
            $this->db->query('ALTER TABLE `ip_fiscal_years` ADD UNIQUE KEY `fiscal_year` (`fiscal_year`)');
        }

        $this->forge->dropColumn('ip_fiscal_brackets', 'company_id');
        $this->forge->dropColumn('ip_fiscal_years', 'company_id');
    }
}
