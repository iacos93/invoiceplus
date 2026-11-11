<?php

namespace App\Services;

use App\Models\CostingAccountModel;
use App\Models\CostingBudgetModel;

/**
 * ManagementReport — il "Bilancino": conto economico riclassificato per
 * destinazione, mese per mese.
 *
 * A differenza di FinanceReport, che parte dai totali dei documenti, qui si
 * parte dalle singole righe di fattura e dalle singole spese: è l'unico modo
 * per sapere *da dove* arriva un ricavo e *dove* è finito un costo. Le righe
 * senza voce non vengono spalmate su una categoria a caso: restano in una
 * riga "Non assegnati", perché un totale che le include senza dirlo non è
 * verificabile.
 *
 * Il padre di una voce non porta importi propri nel piano standard, ma può
 * averne (una voce generica con sottoconti). Per questo ogni nodo somma i
 * propri importi e quelli dei discendenti: il totale del padre e la somma
 * dei figli non possono divergere, per costruzione.
 *
 * L'EBITDA è una stima gestionale: l'applicazione non gestisce
 * ammortamenti e interessi, quindi coincide con il risultato operativo.
 */
class ManagementReport
{
    private CostingAccountModel $accounts;
    private CostingBudgetModel $budgets;

    public function __construct()
    {
        $this->accounts = new CostingAccountModel();
        $this->budgets  = new CostingBudgetModel();
    }

    /**
     * @return array<string, mixed>
     */
    public function forYear(int $year): array
    {
        $accounts = $this->accounts->allForCompany();
        $tree     = $this->accounts->tree($accounts);

        $revenue  = $this->revenueByAccount($year);
        $costs    = $this->costsByAccount($year);
        $budget   = $this->budgets->gridForYear($year);

        $rowsByType = [
            CostingAccountModel::TYPE_REVENUE  => [],
            CostingAccountModel::TYPE_VARIABLE => [],
            CostingAccountModel::TYPE_FIXED    => [],
        ];

        foreach ($tree as $node) {
            $type = (string) $node['account_type'];

            if (!isset($rowsByType[$type])) {
                continue;
            }

            $rowsByType[$type] = array_merge(
                $rowsByType[$type],
                $this->flatten($node, 0, $revenue, $costs, $budget)
            );
        }

        // I totali per tipo si sommano SOLO dalle radici: il padre contiene già
        // i figli, e sommare tutte le righe conterebbe due volte ogni
        // sottoconto. Sommare invece la mappa dei movimenti (che non conosce
        // l'albero) darebbe il numero giusto ma nessuna garanzia che la
        // tabella mostri la stessa cifra: qui i due numeri sono per
        // costruzione lo stesso.
        $revenueSeries  = $this->rootSeries($rowsByType[CostingAccountModel::TYPE_REVENUE]);
        $variableSeries = $this->rootSeries($rowsByType[CostingAccountModel::TYPE_VARIABLE]);
        $fixedSeries    = $this->rootSeries($rowsByType[CostingAccountModel::TYPE_FIXED]);

        // Righe/spese che puntano a una voce non più nel piano (voce
        // cancellata a mano dal database, o di un'altra azienda): senza questo
        // conguaglio sparirebbero dai totali senza comparire da nessuna parte,
        // e il Bilancino non tornerebbe più con "Utile e imposte".
        $unassignedRevenue = CostingService::add(
            $revenue[0] ?? CostingService::months(),
            $this->orphanSeries($revenue, $revenueSeries)
        );
        $unassignedCosts = CostingService::add(
            $costs[0] ?? CostingService::months(),
            $this->orphanSeries($costs, CostingService::add($variableSeries, $fixedSeries))
        );

        // Il "non assegnato" non entra nei ricavi/costi per tipo: è mostrato
        // a parte, e i KPI dichiarano esplicitamente quanto resta fuori.
        $margin = CostingService::contributionMargin($revenueSeries, $variableSeries);
        $ebitda = CostingService::ebitda($revenueSeries, $variableSeries, $fixedSeries);

        $cashflow = $this->cashflowSeries($year);

        $annualRevenue  = CostingService::total($revenueSeries);
        $annualVariable = CostingService::total($variableSeries);
        $annualFixed    = CostingService::total($fixedSeries);
        $annualEbitda   = CostingService::total($ebitda);
        $annualMargin   = CostingService::total($margin);

        // Punto di pareggio: quanto fatturato copre i costi fissi, al margine
        // di contribuzione percentuale dell'anno. Se il margine è negativo o
        // nullo non esiste un fatturato che pareggi: null, non zero.
        $marginRate  = CostingService::share($annualMargin, $annualRevenue);
        $breakEven   = $marginRate !== null && $marginRate > 0
            ? round($annualFixed / ($marginRate / 100), 2)
            : null;

        $budgetTotals = [
            CostingAccountModel::TYPE_REVENUE  => 0.0,
            CostingAccountModel::TYPE_VARIABLE => 0.0,
            CostingAccountModel::TYPE_FIXED    => 0.0,
        ];

        foreach ($rowsByType as $type => $rows) {
            foreach ($rows as $row) {
                // Solo le radici: i figli sono già dentro il budget del padre.
                if ($row['level'] === 0) {
                    $budgetTotals[$type] += (float) $row['budget_total'];
                }
            }
        }

        return [
            'year'       => $year,
            'accounts'   => $rowsByType,
            'totals'     => [
                'ricavo'          => $revenueSeries,
                'costo_variabile' => $variableSeries,
                'costo_fisso'     => $fixedSeries,
                'margine'         => $margin,
                'ebitda'          => $ebitda,
            ],
            'unassigned' => [
                'ricavo' => $unassignedRevenue,
                'costo'  => $unassignedCosts,
            ],
            'budget'     => [
                'grid'   => $budget,
                'totals' => $budgetTotals,
                'annual' => $this->budgets->annualForYear($year),
            ],
            'cashflow'   => $cashflow,
            'kpi'        => [
                'revenue'          => $annualRevenue,
                'variable'         => $annualVariable,
                'fixed'            => $annualFixed,
                'margin'           => $annualMargin,
                'margin_rate'      => $marginRate,
                'ebitda'           => $annualEbitda,
                'ebitda_rate'      => CostingService::share($annualEbitda, $annualRevenue),
                'unassigned_revenue' => CostingService::total($unassignedRevenue),
                'unassigned_costs'   => CostingService::total($unassignedCosts),
                'break_even'       => $breakEven,
                'cash_in'          => CostingService::total($cashflow['incassi']),
                'cash_out'         => CostingService::total($cashflow['uscite']),
                'cash_balance'     => CostingService::total($cashflow['saldo']),
            ],
            'accounts_count' => count($accounts),
        ];
    }

    /**
     * Somma dei soli nodi di primo livello: i figli sono già compresi nel
     * padre, quindi il totale del tipo non dipende da quanti sottoconti ci
     * sono.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, float>
     */
    private function rootSeries(array $rows): array
    {
        $series = CostingService::months();

        foreach ($rows as $row) {
            if ((int) $row['level'] === 0) {
                $series = CostingService::add($series, $row['months']);
            }
        }

        return $series;
    }

    /**
     * Differenza fra il totale dei movimenti e quello mostrato in tabella:
     * è denaro che esiste nel database ma non ha una voce nel piano dei conti
     * (id orfano). Finisce nel "non assegnato" invece di essere perso.
     *
     * @param array<int, array<int, float>> $byAccount
     * @param array<int, float>             $shown
     *
     * @return array<int, float>
     */
    private function orphanSeries(array $byAccount, array $shown): array
    {
        $total = CostingService::months();

        foreach ($byAccount as $accountId => $months) {
            if ((int) $accountId === 0) {
                // Lo zero è già il "non assegnato": lo somma il chiamante.
                continue;
            }

            $total = CostingService::add($total, $months);
        }

        return CostingService::subtract($total, $shown);
    }

    /**
     * Ricavi per voce e mese, dalle righe delle fatture non in bozza.
     *
     * L'importo è l'imponibile di riga (subtotale meno sconto di riga). Lo
     * sconto di documento e il contributo cassa non si possono attribuire a
     * una riga sola, quindi restano fuori dal Bilancino: quando ci sono, il
     * totale dei ricavi si discosta da quello di "Utile e imposte", dove il
     * netto è lordo meno IVA sull'intero documento.
     *
     * @return array<int, array<int, float>> account_id (0 = non assegnato) => mesi
     */
    private function revenueByAccount(int $year): array
    {
        $scope = company_scope_sql('i');

        $rows = $this->db()->query("
            SELECT EXTRACT(MONTH FROM i.invoice_date_created) AS m,
                   COALESCE(ii.costing_account_id, 0) AS account_id,
                   SUM(COALESCE(ia.item_subtotal, 0) - COALESCE(ia.item_discount, 0)) AS amount
            FROM ip_invoices i
            JOIN ip_invoice_items ii ON ii.invoice_id = i.invoice_id
            LEFT JOIN ip_invoice_item_amounts ia ON ia.item_id = ii.item_id
            WHERE EXTRACT(YEAR FROM i.invoice_date_created) = ?
              AND i.invoice_status_id <> 1{$scope}
            GROUP BY m, account_id
        ", [$year])->getResultArray();

        $out = [];

        foreach ($rows as $row) {
            $accountId = (int) $row['account_id'];
            $month     = (int) $row['m'];
            $out[$accountId] ??= CostingService::months();
            $out[$accountId][$month] = round(
                ($out[$accountId][$month] ?? 0.0) + (float) $row['amount'],
                2
            );
        }

        return $out;
    }

    /**
     * Costi per voce e mese, dalle spese registrate.
     *
     * Si usa l'imponibile (expense_amount), non il totale con IVA: l'IVA a
     * credito non è un costo, e metterla qui gonfierebbe i costi del 22%.
     *
     * @return array<int, array<int, float>> account_id (0 = non assegnato) => mesi
     */
    private function costsByAccount(int $year): array
    {
        $scope = company_scope_sql('ip_expenses');

        $rows = $this->db()->query("
            SELECT EXTRACT(MONTH FROM expense_date) AS m,
                   COALESCE(costing_account_id, 0) AS account_id,
                   SUM(COALESCE(expense_amount, 0)) AS amount
            FROM ip_expenses
            WHERE EXTRACT(YEAR FROM expense_date) = ?{$scope}
            GROUP BY m, account_id
        ", [$year])->getResultArray();

        $out = [];

        foreach ($rows as $row) {
            $accountId = (int) $row['account_id'];
            $month     = (int) $row['m'];
            $out[$accountId] ??= CostingService::months();
            $out[$accountId][$month] = round(
                ($out[$accountId][$month] ?? 0.0) + (float) $row['amount'],
                2
            );
        }

        return $out;
    }

    /**
     * Flusso di cassa dell'anno.
     *
     * Entrate: gli incassi delle fatture, per data di pagamento. Uscite: le
     * spese per cui risulta pagato, attribuite al mese del documento perché
     * l'applicazione non registra la data del singolo pagamento di spesa
     * (expense_paid è un saldo, non un movimento). È un'approssimazione
     * dichiarata nell'interfaccia: senza, il saldo sarebbe una data inventata.
     *
     * @return array{incassi: array<int, float>, uscite: array<int, float>, saldo: array<int, float>, cumulato: array<int, float>}
     */
    private function cashflowSeries(int $year): array
    {
        $invoiceScope = company_scope_sql('i');

        $in = $this->db()->query("
            SELECT EXTRACT(MONTH FROM p.payment_date) AS m,
                   SUM(COALESCE(p.payment_amount, 0)) AS amount
            FROM ip_payments p
            JOIN ip_invoices i ON i.invoice_id = p.invoice_id
            WHERE EXTRACT(YEAR FROM p.payment_date) = ?{$invoiceScope}
            GROUP BY m
        ", [$year])->getResultArray();

        $outScope = company_scope_sql('ip_expenses');

        $out = $this->db()->query("
            SELECT EXTRACT(MONTH FROM expense_date) AS m,
                   SUM(COALESCE(expense_paid, 0)) AS amount
            FROM ip_expenses
            WHERE EXTRACT(YEAR FROM expense_date) = ?
              AND COALESCE(expense_paid, 0) <> 0{$outScope}
            GROUP BY m
        ", [$year])->getResultArray();

        $incassi = CostingService::months();
        $uscite  = CostingService::months();

        foreach ($in as $row) {
            $incassi[(int) $row['m']] = round((float) $row['amount'], 2);
        }

        foreach ($out as $row) {
            $uscite[(int) $row['m']] = round((float) $row['amount'], 2);
        }

        $saldo = CostingService::subtract($incassi, $uscite);

        return [
            'incassi'  => $incassi,
            'uscite'   => $uscite,
            'saldo'    => $saldo,
            'cumulato' => CostingService::cumulative($saldo),
        ];
    }

    /**
     * Appiattisce un ramo calcolando, per ogni nodo, la somma propria più
     * quella dei discendenti (importi e budget). Il padre viene restituito
     * prima dei figli, così la view può renderizzare l'elenco così com'è.
     *
     * @param array<string, mixed>          $node
     * @param array<int, array<int, float>> $revenue
     * @param array<int, array<int, float>> $costs
     * @param array<int, array<int, float>> $budget
     *
     * @return list<array<string, mixed>>
     */
    private function flatten(array $node, int $level, array $revenue, array $costs, array $budget): array
    {
        $id   = (int) $node['account_id'];
        $type = (string) $node['account_type'];

        $source = $type === CostingAccountModel::TYPE_REVENUE ? $revenue : $costs;

        // Importi propri della voce, poi quelli dei figli: il nodo somma tutto
        // ciò che sta sotto di sé, così il totale non dipende da come è stato
        // disegnato l'albero.
        $months      = $source[$id] ?? CostingService::months();
        $ownBudget   = $budget[$id] ?? [];
        $budgetTotal = round(array_sum($ownBudget), 2);

        $childrenRows = [];

        foreach ((array) ($node['children'] ?? []) as $child) {
            $childRows = $this->flatten($child, $level + 1, $revenue, $costs, $budget);
            $childHead = $childRows[0];

            $months       = CostingService::add($months, $childHead['months']);
            $budgetTotal += (float) $childHead['budget_total'];
            $childrenRows = array_merge($childrenRows, $childRows);
        }

        $total    = CostingService::total($months);
        $variance = CostingService::variance($total, $budgetTotal);

        return array_merge([[
            'account_id'       => $id,
            'code'             => (string) $node['account_code'],
            'name'             => (string) $node['account_name'],
            'type'             => $type,
            'level'            => $level,
            'has_children'     => $childrenRows !== [],
            'months'           => $months,
            'total'            => $total,
            'budget_months'    => $ownBudget,
            'budget_total'     => $budgetTotal,
            'variance_amount'  => $variance['amount'],
            'variance_percent' => $variance['percent'],
        ]], $childrenRows);
    }

    private function db()
    {
        return db_connect();
    }
}
