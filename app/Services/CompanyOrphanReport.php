<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/**
 * Righe per-azienda rimaste senza company_id.
 *
 * Il multi-azienda è arrivato dopo i dati: le righe create prima non hanno
 * azienda, e finché restano `NULL` non appartengono a nessuno. Storicamente le
 * query le includevano (visibili a tutte), ma l'eccezione è stata rimossa dopo
 * il backfill: ora una riga senza azienda non compare in nessuna lista, quindi
 * il conteggio deve restare a zero ed è il segnale che qualcosa scrive senza
 * `company_id`.
 *
 * Questo servizio le conta e, solo su richiesta esplicita (`--fix` o la
 * migrazione), le assegna all'azienda predefinita.
 *
 * La classificazione viene dallo schema reale, non da un elenco copiato:
 * LEGACY_TABLES è il risultato di `listTables()` + `fieldExists('company_id')`
 * sul database di sviluppo (e delle migrazioni che hanno creato o popolato le
 * tabelle). Le tabelle assenti in un'installazione vecchia vengono saltate in
 * silenzio: il servizio deve poter girare anche su uno schema parziale.
 */
final class CompanyOrphanReport
{
    /**
     * Tabelle per-azienda in cui `company_id NULL` è un residuo storico.
     *
     * Ognuna è nata o è stata popolata prima del multi-azienda (vedi
     * BackfillCompanyId, BackfillSuppliers, EnsureCompanyGroups) oppure viene
     * scritta sempre con l'azienda attiva. Un NULL qui è un dato da assegnare,
     * non una scelta: eccezione voluta solo per i parametri fiscali, che stanno
     * in GLOBAL_TABLES.
     *
     * Restano fuori:
     *  - ip_companies, dove company_id è la chiave primaria, non uno scope;
     *  - le tabelle figlie senza company_id (ip_invoice_items, ip_payments,
     *    ip_sdi_transmissions...): l'azienda si ricava dal documento padre.
     */
    public const LEGACY_TABLES = [
        'ip_bank_accounts',
        'ip_bank_transactions',
        'ip_clients',
        'ip_costing_accounts',
        'ip_costing_budgets',
        'ip_delivery_notes',
        'ip_email_senders',
        'ip_expenses',
        'ip_f24',
        'ip_imports',
        'ip_invoice_groups',
        'ip_invoices',
        'ip_products',
        'ip_quotes',
        'ip_suppliers',
    ];

    /**
     * Tabelle in cui `company_id NULL` è legittimo: sono i parametri condivisi.
     *
     * ScopeFiscalYearsPerCompany ha lasciato NULL di proposito le righe
     * esistenti di anni e scaglioni: FiscalYearModel::scopeEffective() e
     * FiscalBracketModel::ofCompany() le leggono come "modello comune" che ogni
     * azienda può sovrascrivere con una riga propria. Assegnarle all'azienda
     * predefinita toglierebbe il modello alle altre, quindi non vanno contate
     * come orfane né toccate dal backfill.
     */
    public const GLOBAL_TABLES = [
        'ip_fiscal_brackets',
        'ip_fiscal_years',
    ];

    /**
     * Conteggi delle righe orfane, tabella per tabella.
     *
     * @param BaseConnection|null $db connessione da usare (default: quella attiva)
     *
     * @return array{
     *     tables: array<string, array{nulls: int, total: int}>,
     *     total: int,
     *     rows: int,
     *     skipped: list<string>
     * } `total` è la somma delle righe NULL nelle tabelle legacy; `skipped`
     *   elenca le tabelle dell'elenco che non esistono (o non hanno la
     *   colonna) in questa installazione.
     */
    public function counts(?BaseConnection $db = null): array
    {
        $db ??= db_connect();

        $tabelle = [];
        $totale  = 0;
        $righe   = 0;

        foreach ($this->presentTables($db) as $table) {
            $conteggio = $this->countTable($db, $table);

            $tabelle[$table] = $conteggio;
            $totale         += $conteggio['nulls'];
            $righe          += $conteggio['total'];
        }

        return [
            'tables'  => $tabelle,
            'total'   => $totale,
            'rows'    => $righe,
            'skipped' => array_values(array_diff(self::LEGACY_TABLES, array_keys($tabelle))),
        ];
    }

    /**
     * Assegna le righe orfane all'azienda predefinita.
     *
     * Non fa nulla, e lo dice, quando manca un'azienda con
     * `company_is_default`: senza un destinatario le righe restano NULL e
     * continuano a essere visibili a tutte, che è il comportamento di oggi.
     * Non tocca GLOBAL_TABLES.
     *
     * È idempotente: una seconda esecuzione trova zero righe da aggiornare, e
     * una tabella che non esiste viene semplicemente saltata.
     *
     * @return array{
     *     ok: bool,
     *     reason: string,
     *     company_id: int|null,
     *     tables: array<string, int>,
     *     total: int,
     *     skipped: list<string>
     * }
     */
    public function backfill(?BaseConnection $db = null): array
    {
        $db ??= db_connect();

        $esito = [
            'ok'         => false,
            'reason'     => '',
            'company_id' => null,
            'tables'     => [],
            'total'      => 0,
            'skipped'    => [],
        ];

        if (! $db->tableExists('ip_companies')) {
            $esito['reason'] = 'Tabella ip_companies assente: non c\'è un\'azienda a cui assegnare le righe.';

            return $esito;
        }

        $predefinita = $db->table('ip_companies')
            ->where('company_is_default', true)
            ->orderBy('company_id', 'ASC')
            ->get()
            ->getRowArray();

        if (! $predefinita) {
            $esito['reason'] = 'Nessuna azienda predefinita (company_is_default): le righe restano senza azienda. '
                . 'Imposta un\'azienda predefinita e rilancia.';

            return $esito;
        }

        $companyId = (int) $predefinita['company_id'];

        $esito['company_id'] = $companyId;

        $presenti = $this->presentTables($db);

        $esito['skipped'] = array_values(array_diff(self::LEGACY_TABLES, $presenti));

        foreach ($presenti as $table) {
            // Un UPDATE per tabella: se una fallisce le altre restano fatte e
            // una nuova esecuzione completa il lavoro, perché la condizione è
            // sempre "company_id IS NULL".
            $db->table($table)
                ->where('company_id IS NULL', null, false)
                ->update(['company_id' => $companyId]);

            $aggiornate = (int) $db->affectedRows();

            $esito['tables'][$table] = $aggiornate;
            $esito['total']         += $aggiornate;
        }

        $esito['ok'] = true;

        return $esito;
    }

    /**
     * Tabelle dell'elenco legacy presenti davvero e con la colonna company_id.
     *
     * `listTables()` è la fonte di verità sullo schema: un'installazione
     * vecchia può non avere ip_costing_accounts o ip_f24, e non deve
     * interrompere il conteggio. Se è configurato un prefisso di tabella, i
     * nomi restituiti lo includono e vanno riportati alla forma senza prefisso.
     *
     * @param list<string> $tabelle
     *
     * @return list<string>
     */
    private function presentTables(BaseConnection $db, array $tabelle = self::LEGACY_TABLES): array
    {
        $prefix    = (string) $db->DBPrefix;
        $disponibili = [];

        foreach ($db->listTables() as $nome) {
            if ($prefix !== '' && str_starts_with($nome, $prefix)) {
                $nome = substr($nome, strlen($prefix));
            }

            $disponibili[$nome] = true;
        }

        $presenti = [];

        foreach ($tabelle as $table) {
            if (isset($disponibili[$table]) && $db->fieldExists('company_id', $table)) {
                $presenti[] = $table;
            }
        }

        return $presenti;
    }

    /**
     * Righe che appartengono a una singola azienda, tabella per tabella.
     *
     * Serve prima di eliminare un'azienda: verso ip_companies non c'è nessuna
     * chiave esterna, quindi togliere la riga lascerebbe clienti, fatture e
     * impostazioni con un company_id che non esiste più. Quelle righe non
     * sarebbero visibili a nessuna azienda e non comparirebbero nemmeno qui:
     * counts() guarda solo i NULL.
     *
     * @return array{tables: array<string, int>, total: int}
     */
    public function countForCompany(int $companyId, ?BaseConnection $db = null): array
    {
        $db ??= db_connect();

        $tabelle = [];
        $totale  = 0;

        foreach ($this->presentTables($db, array_merge(self::LEGACY_TABLES, self::GLOBAL_TABLES)) as $table) {
            $righe = (int) $db->table($table)->where('company_id', $companyId)->countAllResults();

            if ($righe > 0) {
                $tabelle[$table] = $righe;
                $totale         += $righe;
            }
        }

        return ['tables' => $tabelle, 'total' => $totale];
    }

    /**
     * Righe NULL e righe totali di una tabella.
     *
     * SQL portabile (niente FILTER, che MySQL non conosce): un solo giro sul
     * database per tabella, anche su tabelle grandi.
     *
     * @return array{nulls: int, total: int}
     */
    private function countTable(BaseConnection $db, string $table): array
    {
        $row = $db->query(
            'SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN company_id IS NULL THEN 1 ELSE 0 END), 0) AS nulls'
            . ' FROM ' . $db->escapeIdentifiers($table)
        )->getRowArray() ?? [];

        return [
            'nulls' => (int) ($row['nulls'] ?? 0),
            'total' => (int) ($row['total'] ?? 0),
        ];
    }
}
