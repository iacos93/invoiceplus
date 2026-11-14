<?php

namespace App\Database\Migrations;

use App\Services\CompanyOrphanReport;
use CodeIgniter\Database\Migration;

/**
 * Assegna all'azienda predefinita le righe per-azienda rimaste senza company_id.
 *
 * È lo stesso backfill di `php spark companies:orphans --fix`: la logica sta
 * in CompanyOrphanReport, qui non c'è SQL duplicato. La migrazione serve a
 * coprire le installazioni che aggiornano senza leggere il changelog: dopo
 * l'aggiornamento non restano orfani, senza che nessuno debba ricordarsi un
 * comando.
 *
 * Perché non si tocca la visibilità dei NULL
 * ------------------------------------------
 * Le query dell'applicazione trattano `company_id IS NULL` come "archivio
 * anteriore al multi-azienda" e mostrano quelle righe in tutte le aziende
 * (company_scope_sql(), IpBaseModel::scopeToCompany()). Togliere l'eccezione
 * qui farebbe sparire i dati storici delle installazioni che non hanno mai
 * fatto il backfill: è un cambio di comportamento che va deciso a parte, dopo
 * che il conteggio dice zero. Questa migrazione si limita a dare un'azienda
 * alle righe, lasciando intatta la regola per qualunque NULL che dovesse
 * ricomparire.
 *
 * Sicurezza su installazioni vecchie:
 *  - le tabelle assenti vengono saltate (CompanyOrphanReport::presentTables());
 *  - senza un'azienda con company_is_default non viene scritto nulla e resta
 *    una riga nel log: la migrazione non fallisce e non perde dati;
 *  - è idempotente: rilanciarla aggiorna zero righe.
 */
class BackfillOrphanCompanyData extends Migration
{
    public function up()
    {
        $esito = (new CompanyOrphanReport())->backfill($this->db);

        if (! $esito['ok']) {
            log_message('warning', 'BackfillOrphanCompanyData: {motivo}', ['motivo' => $esito['reason']]);

            return;
        }

        log_message('info', 'BackfillOrphanCompanyData: {righe} righe assegnate all\'azienda {azienda}.', [
            'righe'   => $esito['total'],
            'azienda' => $esito['company_id'],
        ]);
    }

    /**
     * Irreversibile: dopo l'aggiornamento non si distingue una riga
     * riassegnata da una che apparteneva già all'azienda predefinita. Rimetterla
     * NULL, oltre a non essere ricostruibile, la renderebbe di nuovo visibile a
     * tutte le aziende: meglio non offrire un down() che sembra un ripristino.
     */
    public function down()
    {
    }
}
