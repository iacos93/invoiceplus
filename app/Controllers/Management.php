<?php

namespace App\Controllers;

use App\Models\CostingAccountModel;
use App\Models\CostingBudgetModel;
use App\Services\CostingService;
use App\Services\FinanceService;
use App\Services\ManagementReport;

/**
 * Management — "Bilancino" e controllo di gestione.
 *
 * Quattro schermate, una sola fonte di numeri: il report (index/cashflow)
 * legge sempre ManagementReport, la stessa classe usata dall'esportazione.
 * Scrivere le query anche nell'export avrebbe prodotto due versioni dello
 * stesso conto economico, destinate a divergere alla prima modifica.
 *
 * Il piano dei conti e il budget sono tabelle dell'azienda attiva: il
 * perimetro lo applica il model (scopeToCompany), non il controller.
 */
class Management extends BaseController
{
    protected CostingAccountModel $accounts;
    protected CostingBudgetModel $budgets;

    public function __construct()
    {
        $this->accounts = new CostingAccountModel();
        $this->budgets  = new CostingBudgetModel();
    }

    public function index()
    {
        $year   = $this->year();
        $report = (new ManagementReport())->forYear($year);

        $report['month_names'] = FinanceService::monthNames(service('request')->getLocale());

        return view('management/index', $report);
    }

    /**
     * Esportazione del Bilancino: CSV per Excel, PDF per chi lo deve leggere
     * stampato. Stessi numeri della pagina perché la fonte è la stessa.
     */
    public function export(string $format)
    {
        $year   = $this->year();
        $report = (new ManagementReport())->forYear($year);
        $report['month_names'] = FinanceService::monthNames(service('request')->getLocale());

        if ($format === 'csv') {
            return $this->response
                ->setContentType('text/csv; charset=UTF-8')
                ->setHeader('Content-Disposition', 'attachment; filename="bilancino_' . $year . '_' . date('Ymd_His') . '.csv"')
                ->setBody($this->csv($report));
        }

        if ($format === 'pdf') {
            return pdf_create(view('management/pdf', $report), 'bilancino_' . $year . '.pdf', true);
        }

        throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
    }

    /* ---------------------------------------------------------------------
     * Piano dei conti
     * ------------------------------------------------------------------ */

    public function accounts()
    {
        $accounts = $this->accounts->allForCompany();
        $own      = $this->budgets->annualForYear($this->year());

        // Il budget mostrato accanto al padre comprende quello dei figli, come
        // nel report: se qui mostrasse solo il valore proprio, un conto con
        // sottoconti risulterebbe a zero pur avendo un budget, e le due
        // schermate direbbero numeri diversi sulla stessa voce.
        $budget = [];

        foreach ($accounts as $account) {
            $id = (int) $account['account_id'];

            $budget[$id] = round(array_sum(array_map(
                static fn(int $subId): float => (float) ($own[$subId] ?? 0),
                $this->accounts->idsWithDescendants($id, $accounts)
            )), 2);
        }

        return view('management/accounts', [
            'accounts'  => $accounts,
            'tree'      => $this->accounts->tree($accounts),
            'budget'    => $budget,
            'year'      => $this->year(),
            'has_plan'  => $accounts !== [],
        ]);
    }

    public function accountForm(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/management/accounts');
            }

            $post = $request->getPost();
            $data = [
                'parent_id'     => ($post['parent_id'] ?? '') !== '' ? (int) $post['parent_id'] : null,
                'account_code'  => trim((string) ($post['account_code'] ?? '')),
                'account_name'  => trim((string) ($post['account_name'] ?? '')),
                'account_type'  => (string) ($post['account_type'] ?? ''),
                'account_active' => !empty($post['account_active']) ? 1 : 0,
                'account_order' => (int) ($post['account_order'] ?? 0),
                'account_note'  => trim((string) ($post['account_note'] ?? '')) ?: null,
            ];

            $errors = $this->validateAccount($data, $id);

            if ($errors === []) {
                if ($id === null) {
                    $data['account_created'] = date('Y-m-d H:i:s');
                    $this->accounts->insert($this->withCompanyId($data));
                } else {
                    $this->accounts->update($id, $data);
                }

                session()->setFlashdata('alert_success', $id
                    ? 'Voce aggiornata'
                    : 'Voce creata');

                return redirect()->to('/management/accounts');
            }

            $this->accounts->formValues = $data;

            return view('management/account_form', [
                'account_id' => $id,
                'model'      => $this->accounts,
                'errors'     => $errors,
                'parents'    => $this->accounts->options(null, false),
                'types'      => CostingAccountModel::TYPES,
            ]);
        }

        if ($id !== null) {
            $row = $this->accounts->findScoped($id);

            if ($row === null) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }

            $this->accounts->formValues = $row;
        }

        return view('management/account_form', [
            'account_id' => $id,
            'model'      => $this->accounts,
            'errors'     => [],
            'parents'    => $this->accounts->options(null, false),
            'types'      => CostingAccountModel::TYPES,
        ]);
    }

    public function deleteAccount(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        if ($this->accounts->findScoped($id) === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $usage = $this->accounts->usage($id);

        // Cancellare una voce in uso lascerebbe righe e spese con un id che
        // non esiste più: il report le perderebbe senza dirlo. Meglio
        // chiedere di disattivarla, che la toglie dalle select ma non dai
        // totali storici.
        if ($usage['items'] > 0 || $usage['expenses'] > 0) {
            session()->setFlashdata('alert_error', sprintf(
                'Voce non eliminata: è usata da %d righe e %d spese. Disattivala per toglierla dalle nuove assegnazioni.',
                $usage['items'],
                $usage['expenses']
            ));

            return redirect()->to('/management/accounts');
        }

        if ($usage['children'] > 0) {
            session()->setFlashdata('alert_error', 'Voce non eliminata: ha sottoconti. Elimina o sposta prima quelli.');
            return redirect()->to('/management/accounts');
        }

        // Il budget della voce se ne va con lei: senza la voce, quelle dodici
        // celle non sono più raggiungibili da nessuna schermata e resterebbero
        // nel database come righe orfane che nessuno può più correggere.
        $this->db()->table('ip_costing_budgets')->where('account_id', $id)->delete();

        $this->accounts->delete($id);
        session()->setFlashdata('alert_success', 'Voce eliminata');

        return redirect()->to('/management/accounts');
    }

    public function importStandard()
    {
        if (service('request')->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $created = $this->accounts->importStandardPlan((int) active_company_id());

        session()->setFlashdata(
            $created > 0 ? 'alert_success' : 'alert_error',
            $created > 0
                ? sprintf('Piano standard importato: %d voci create.', $created)
                : 'Il piano standard è già presente: nessuna voce duplicata.'
        );

        return redirect()->to('/management/accounts');
    }

    /* ---------------------------------------------------------------------
     * Budget
     * ------------------------------------------------------------------ */

    public function budget()
    {
        $request = service('request');
        $year    = $this->year();

        if ($request->getMethod() === 'POST') {
            $values = (array) ($request->getPost('budget') ?? []);
            $ids    = array_map(
                static fn(array $a): int => (int) $a['account_id'],
                $this->accounts->allForCompany()
            );

            $saved = $this->budgets->saveGrid($year, $values, $ids, (int) active_company_id());

            session()->setFlashdata('alert_success', sprintf('Budget %d salvato (%d valori).', $year, $saved));

            return redirect()->to('/management/budget');
        }

        $report = (new ManagementReport())->forYear($year);

        return view('management/budget', [
            'year'        => $year,
            'grouped'     => $this->accounts->groupedTree(),
            'grid'        => $this->budgets->gridForYear($year),
            'report'      => $report,
            'month_names' => FinanceService::monthNames($request->getLocale()),
        ]);
    }

    /* ---------------------------------------------------------------------
     * Flusso di cassa
     * ------------------------------------------------------------------ */

    public function cashflow()
    {
        $year   = $this->year();
        $report = (new ManagementReport())->forYear($year);

        $report['month_names'] = FinanceService::monthNames(service('request')->getLocale());

        return view('management/cashflow', $report);
    }

    /* ---------------------------------------------------------------------
     * Assegnazione voci
     * ------------------------------------------------------------------ */

    /**
     * Assegna una voce a più documenti in una volta.
     *
     * Per le fatture l'assegnazione è per riga: si applica alle righe ancora
     * senza voce (di norma il caso d'uso) oppure a tutte, se l'utente lo
     * chiede esplicitamente. Per le spese è a livello di documento.
     */
    public function assign()
    {
        $request = service('request');
        $year    = $this->year();

        if ($request->getMethod() === 'POST') {
            $target    = (string) $request->getPost('target');
            $accountId = (int) $request->getPost('account_id');
            $ids       = array_map('intval', (array) $request->getPost('document_ids'));
            $overwrite = !empty($request->getPost('overwrite'));

            $account = $this->accounts->findScoped($accountId);

            if ($account === null || $ids === []) {
                session()->setFlashdata('alert_error', 'Scegli una voce e almeno un documento.');
                return redirect()->to('/management/assign');
            }

            if ($target === 'expenses' && $account['account_type'] === CostingAccountModel::TYPE_REVENUE) {
                session()->setFlashdata('alert_error', 'Una voce di ricavo non può essere assegnata a una spesa.');
                return redirect()->to('/management/assign');
            }

            if ($target === 'invoices' && $account['account_type'] === CostingAccountModel::TYPE_REVENUE) {
                $count = $this->assignToInvoices($ids, $accountId, $overwrite);
                session()->setFlashdata('alert_success', sprintf('Voce assegnata a %d righe di fattura.', $count));
                return redirect()->to('/management/assign');
            }

            if ($target === 'expenses' && $account['account_type'] !== CostingAccountModel::TYPE_REVENUE) {
                $count = $this->assignToExpenses($ids, $accountId, $overwrite);
                session()->setFlashdata('alert_success', sprintf('Voce assegnata a %d spese.', $count));
                return redirect()->to('/management/assign');
            }

            session()->setFlashdata('alert_error', 'Tipo di documento non compatibile con la voce scelta.');
            return redirect()->to('/management/assign');
        }

        // Documenti dell'anno con almeno una riga/spesa non assegnata: sono
        // gli unici su cui l'assegnazione ha qualcosa da fare.
        $unassignedInvoices = $this->invoicesMissingAccount($year);
        $unassignedExpenses = $this->expensesMissingAccount($year);

        return view('management/assign', [
            'year'               => $year,
            'invoices'           => $unassignedInvoices,
            'expenses'           => $unassignedExpenses,
            'revenue_accounts'   => $this->accounts->options(CostingAccountModel::TYPE_REVENUE),
            'cost_accounts'      => $this->costAccountOptions(),
        ]);
    }

    /**
     * @param list<int> $invoiceIds
     */
    private function assignToInvoices(array $invoiceIds, int $accountId, bool $overwrite): int
    {
        // Si leggono prima gli id delle righe, poi si aggiorna la sola tabella
        // delle righe: un UPDATE con JOIN non è supportato allo stesso modo da
        // PostgreSQL e MySQL, e la query deve restare una sola per entrambi.
        $builder = $this->db()->table('ip_invoice_items ii')
            ->select('ii.item_id')
            ->join('ip_invoices i', 'i.invoice_id = ii.invoice_id')
            ->whereIn('ii.invoice_id', $invoiceIds)
            ->where('(i.company_id = ' . (int) active_company_id() . ' OR i.company_id IS NULL)', null, false);

        if (!$overwrite) {
            $builder->where('ii.costing_account_id IS NULL', null, false);
        }

        $itemIds = array_map(
            static fn(array $row): int => (int) $row['item_id'],
            $builder->get()->getResultArray()
        );

        if ($itemIds === []) {
            return 0;
        }

        $this->db()->table('ip_invoice_items')
            ->whereIn('item_id', $itemIds)
            ->update(['costing_account_id' => $accountId]);

        return count($itemIds);
    }

    /**
     * @param list<int> $expenseIds
     */
    private function assignToExpenses(array $expenseIds, int $accountId, bool $overwrite): int
    {
        $builder = $this->db()->table('ip_expenses')
            ->whereIn('expense_id', $expenseIds)
            ->where('(company_id = ' . (int) active_company_id() . ' OR company_id IS NULL)', null, false);

        if (!$overwrite) {
            $builder->where('costing_account_id IS NULL', null, false);
        }

        $expenseIds = array_map(
            static fn(array $row): int => (int) $row['expense_id'],
            $builder->select('expense_id')->get()->getResultArray()
        );

        if ($expenseIds === []) {
            return 0;
        }

        $this->db()->table('ip_expenses')
            ->whereIn('expense_id', $expenseIds)
            ->update(['costing_account_id' => $accountId]);

        return count($expenseIds);
    }

    /**
     * Fatture dell'anno con almeno una riga senza voce.
     *
     * @return list<array<string, mixed>>
     */
    private function invoicesMissingAccount(int $year): array
    {
        $scope = company_scope_sql('i');

        return $this->db()->query("
            SELECT i.invoice_id, i.invoice_number, i.invoice_date_created,
                   COALESCE(c.client_name, '—') AS client_name,
                   COUNT(*) AS missing_lines,
                   -- Imponibile delle sole righe senza voce, con la stessa
                   -- espressione di ManagementReport: la somma dell'elenco
                   -- torna con i Ricavi non assegnati del Bilancino, e
                   -- chi assegna vede quanto pesa ogni fattura.
                   SUM(COALESCE(ia.item_subtotal, 0) - COALESCE(ia.item_discount, 0)) AS missing_amount
            FROM ip_invoices i
            JOIN ip_invoice_items ii ON ii.invoice_id = i.invoice_id
            LEFT JOIN ip_invoice_item_amounts ia ON ia.item_id = ii.item_id
            LEFT JOIN ip_clients c ON c.client_id = i.client_id
            WHERE EXTRACT(YEAR FROM i.invoice_date_created) = ?
              AND i.invoice_status_id <> 1
              AND ii.costing_account_id IS NULL{$scope}
            GROUP BY i.invoice_id, i.invoice_number, i.invoice_date_created, c.client_name
            ORDER BY i.invoice_date_created DESC, i.invoice_id DESC
        ", [$year])->getResultArray();
    }

    /**
     * Spese dell'anno senza voce di costo.
     *
     * @return list<array<string, mixed>>
     */
    private function expensesMissingAccount(int $year): array
    {
        $scope = company_scope_sql('ip_expenses');

        return $this->db()->query("
            SELECT expense_id, expense_date, expense_number, supplier_name, expense_total
            FROM ip_expenses
            WHERE EXTRACT(YEAR FROM expense_date) = ?
              AND costing_account_id IS NULL{$scope}
            ORDER BY expense_date DESC, expense_id DESC
        ", [$year])->getResultArray();
    }

    /**
     * @return array<int, string>
     */
    private function costAccountOptions(): array
    {
        return $this->accounts->options(CostingAccountModel::TYPE_VARIABLE)
            + $this->accounts->options(CostingAccountModel::TYPE_FIXED);
    }

    /* ---------------------------------------------------------------------
     * Helper interni
     * ------------------------------------------------------------------ */

    private function year(): int
    {
        // Stesso contesto globale di bacheca e "Utile e imposte": cambiare
        // anno in topbar cambia anche il Bilancino.
        return selected_year() ?: (int) date('Y');
    }

    /**
     * @return array<string, string>
     */
    private function validateAccount(array $data, ?int $id): array
    {
        $errors = [];

        if ($data['account_code'] === '') {
            $errors['account_code'] = 'Il codice è obbligatorio';
        } elseif (mb_strlen($data['account_code']) > 20) {
            $errors['account_code'] = 'Massimo 20 caratteri';
        }

        if ($data['account_name'] === '') {
            $errors['account_name'] = 'Il nome è obbligatorio';
        } elseif (mb_strlen($data['account_name']) > 150) {
            $errors['account_name'] = 'Massimo 150 caratteri';
        }

        if (!isset(CostingAccountModel::TYPES[$data['account_type']])) {
            $errors['account_type'] = 'Scegli il tipo';
        }

        $duplicateQuery = $this->accounts->scopeToCompany()
            ->where('account_code', $data['account_code']);

        if ($id !== null) {
            $duplicateQuery->where('account_id !=', $id);
        }

        $duplicate = $duplicateQuery->first();

        if ($duplicate !== null) {
            $errors['account_code'] = 'Esiste già una voce con questo codice';
        }

        if ($data['parent_id'] !== null) {
            $parent = $this->accounts->findScoped((int) $data['parent_id']);

            if ($parent === null) {
                $errors['parent_id'] = 'La voce padre non esiste';
            } elseif ($parent['account_type'] !== $data['account_type']) {
                // Un costo variabile sotto un ricavo non avrebbe senso nel
                // report: il padre somma i figli, e finirebbe per sommare
                // costi a ricavi.
                $errors['parent_id'] = 'Il padre deve essere dello stesso tipo';
            } elseif ($id !== null && $this->accounts->wouldCreateCycle($id, (int) $data['parent_id'])) {
                $errors['parent_id'] = 'Una voce non può stare sotto sé stessa';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function withCompanyId(array $data): array
    {
        $data['company_id'] = active_company_id();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function csv(array $report): string
    {
        $out = fopen('php://temp', 'r+');

        // BOM UTF-8: senza, Excel in italiano apre le accentate sbagliate.
        fwrite($out, "\xEF\xBB\xBF");

        $months = array_values($report['month_names']);
        fputcsv($out, [trans('management', '', 'Bilancino') . ' ' . $report['year']], ';', '"', '\\');
        fputcsv($out, array_merge(
            [trans('management_item', '', 'Voce'), trans('management_code', '', 'Codice')],
            $months,
            [trans('total', '', 'Totale'), trans('management_budget', '', 'Budget'), trans('management_variance', '', 'Scostamento')]
        ), ';', '"', '\\');

        $sections = [
            CostingAccountModel::TYPE_REVENUE  => mb_strtoupper(trans('management_revenue', '', 'Ricavi')),
            CostingAccountModel::TYPE_VARIABLE => mb_strtoupper(trans('management_variable_costs', '', 'Costi variabili')),
            CostingAccountModel::TYPE_FIXED    => mb_strtoupper(trans('management_fixed_costs', '', 'Costi fissi')),
        ];

        foreach ($sections as $type => $label) {
            fputcsv($out, [$label], ';', '"', '\\');

            foreach ($report['accounts'][$type] as $row) {
                $cells = array_merge(
                    [str_repeat('  ', (int) $row['level']) . $row['name'], $row['code']],
                    array_map(static fn($v): string => number_format((float) $v, 2, ',', ''), array_values($row['months']))
                );
                $cells[] = number_format((float) $row['total'], 2, ',', '');
                $cells[] = number_format((float) $row['budget_total'], 2, ',', '');
                $cells[] = number_format((float) $row['variance_amount'], 2, ',', '');
                fputcsv($out, $cells, ';', '"', '\\');
            }
        }

        $totals = [
            trans('management_margin', '', 'Margine di contribuzione') => $report['totals']['margine'],
            'EBITDA'                                                   => $report['totals']['ebitda'],
            trans('management_unassigned_revenue', '', 'Ricavi non assegnati') => $report['unassigned']['ricavo'],
            trans('management_unassigned_costs', '', 'Costi non assegnati')     => $report['unassigned']['costo'],
            trans('management_cash_in', '', 'Incassi')                          => $report['cashflow']['incassi'],
            trans('management_cash_out', '', 'Uscite')                          => $report['cashflow']['uscite'],
            trans('management_cash_balance', '', 'Saldo di cassa')              => $report['cashflow']['saldo'],
        ];

        foreach ($totals as $label => $series) {
            fputcsv($out, array_merge(
                [$label, ''],
                array_map(static fn($v): string => number_format((float) $v, 2, ',', ''), array_values($series)),
                [number_format(CostingService::total($series), 2, ',', '')]
            ), ';', '"', '\\');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    private function db()
    {
        return db_connect();
    }
}
