<?php

namespace App\Models;

class ExpenseModel extends IpBaseModel
{
    protected $table            = 'ip_expenses';
    protected $primaryKey       = 'expense_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    // Stessa ragione di InvoiceModel: le spese entrano nel calcolo dell'utile
    // in "Utile e imposte" e il metodo clearFinanceCache() qui sotto non era
    // registrato, quindi restava codice morto.
    protected $afterInsert = ['clearFinanceCache'];
    protected $afterUpdate = ['clearFinanceCache'];
    protected $afterDelete = ['clearFinanceCache'];

    protected $allowedFields = [
        'company_id',
        'expense_date',
        'expense_number',
        'supplier_name',
        'supplier_vat',
        'supplier_tax_code',
        'supplier_id',
        'expense_category_id',
        'expense_amount',
        'expense_tax',
        'expense_total',
        'expense_paid',
        'expense_note',
        'bank_account_id',
        'expense_source',
        'expense_source_ref',
        'expense_file',
        'expense_date_created',
        // Voce del piano dei conti del Bilancino.
        'costing_account_id',
    ];

    protected $validationRules = [
        'expense_date'   => 'required',
        'supplier_name'  => 'required',
        'expense_amount' => 'required',
        'expense_tax'    => 'permit_empty',
        'expense_total'  => 'required',
    ];

    public function validationRulesLegacy(): array
    {
        return [
            'expense_date'        => 'required',
            'expense_number'      => 'permit_empty',
            'supplier_name'       => 'required',
            'supplier_vat'        => 'permit_empty',
            'supplier_tax_code'   => 'permit_empty',
            'supplier_id'         => 'permit_empty',
            'expense_category_id' => 'permit_empty',
            'expense_amount'      => 'required',
            'expense_tax'         => 'permit_empty',
            'expense_total'       => 'required',
            'expense_paid'        => 'permit_empty',
            'expense_note'        => 'permit_empty',
            'bank_account_id'     => 'permit_empty',
            'expense_source'      => 'permit_empty',
            'expense_source_ref'  => 'permit_empty',
            'expense_file'        => 'permit_empty',
            'costing_account_id'  => 'permit_empty',
        ];
    }

    private function withJoins(): static
    {
        $this->select('ip_expenses.*, ip_expense_categories.expense_category_name, ip_bank_accounts.bank_account_name')
            ->join('ip_expense_categories', 'ip_expense_categories.expense_category_id = ip_expenses.expense_category_id', 'left')
            ->join('ip_bank_accounts', 'ip_bank_accounts.bank_account_id = ip_expenses.bank_account_id', 'left');

        return $this;
    }

    /**
     * Lista spese filtrate per anno, mese e stato pagamento.
     *
     * Restituisce tutte le righe del periodo: la usano il riconciliatore
     * bancario e la lista, che però per mostrare una pagina passa da
     * getPage().
     */
    public function getAll(int $year = 0, int|array $month = 0, string $paid = '', string $sign = ''): array
    {
        $this->withJoins();
        $this->applyListFilters($year, $month, $paid, $sign);

        return $this->orderBy('ip_expenses.expense_date', 'DESC')
            ->orderBy('ip_expenses.expense_id', 'DESC')
            ->findAll();
    }

    /**
     * Una pagina della lista spese, con gli stessi filtri di getAll().
     *
     * La lista caricava tutte le spese del periodo: con qualche migliaio di
     * documenti la pagina diventava sempre più lenta e non aveva un numero
     * di pagina da cui riprendere. Il conteggio per il badge e i totali del
     * periodo arrivano da getPeriodTotals(), non dalle righe mostrate.
     */
    public function getPage(int $year = 0, int|array $month = 0, string $paid = '', string $sign = '', int $perPage = 15, int $page = 1): array
    {
        $this->withJoins();
        $this->applyListFilters($year, $month, $paid, $sign);

        return $this->orderBy('ip_expenses.expense_date', 'DESC')
            ->orderBy('ip_expenses.expense_id', 'DESC')
            ->paginate($perPage, 'ip_expenses', $page);
    }

    /**
     * Totali dell'intero periodo filtrato, non della sola pagina mostrata.
     *
     * Sommare in PHP le righe di una pagina avrebbe fatto cambiare i totali
     * del piede a ogni cambio di pagina.
     *
     * @return array{count: int, net: float, tax: float, total: float, balance: float}
     */
    public function getPeriodTotals(int $year = 0, int|array $month = 0, string $paid = '', string $sign = ''): array
    {
        $builder = $this->db->table('ip_expenses')
            ->select('COUNT(*) AS documents, COALESCE(SUM(ip_expenses.expense_amount), 0) AS net, COALESCE(SUM(ip_expenses.expense_tax), 0) AS tax, COALESCE(SUM(ip_expenses.expense_total), 0) AS total, COALESCE(SUM(ip_expenses.expense_total - ip_expenses.expense_paid), 0) AS balance', false);

        if ($year > 0) {
            $builder->where(year_sql('ip_expenses.expense_date'), $year);
        }

        $this->applyMonthFilter($builder, 'ip_expenses.expense_date', $month);
        $this->applyPaidFilter($builder, $paid);
        $this->applySignFilter($builder, $sign);

        $row = scope_builder_to_company($builder, 'ip_expenses')->get()->getRowArray();

        return [
            'count'   => (int) ($row['documents'] ?? 0),
            'net'     => (float) ($row['net'] ?? 0),
            'tax'     => (float) ($row['tax'] ?? 0),
            'total'   => (float) ($row['total'] ?? 0),
            'balance' => (float) ($row['balance'] ?? 0),
        ];
    }

    /**
     * Filtri condivisi da lista completa e pagina singola: anno, mesi, stato
     * pagamento, tipo documento e perimetro azienda.
     *
     * @param int|array $month
     */
    private function applyListFilters(int $year, int|array $month, string $paid, string $sign): void
    {
        if ($year > 0) {
            $this->where(year_sql('ip_expenses.expense_date'), $year);
        }

        $this->applyMonthFilter($this, 'ip_expenses.expense_date', $month);
        $this->applyPaidFilter($this, $paid);
        $this->applySignFilter($this, $sign);
        $this->scopeToCompany();
    }

    /**
     * Totali per mese dell'anno (imponibile, IVA, totale, saldo), con lo
     * stesso filtro di pagamento della lista: la barra periodo li mostra
     * sotto il nome del mese.
     *
     * @return array<int, array{net: float, tax: float, total: float, balance: float}>
     */
    public function getMonthlyTotals(int $year = 0, string $paid = '', string $sign = ''): array
    {
        $out = [];

        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = ['net' => 0.0, 'tax' => 0.0, 'total' => 0.0, 'balance' => 0.0];
        }

        $builder = $this->db->table('ip_expenses')
            ->select('EXTRACT(MONTH FROM ip_expenses.expense_date) AS m, COALESCE(SUM(expense_amount), 0) AS net, COALESCE(SUM(expense_tax), 0) AS tax, COALESCE(SUM(expense_total), 0) AS total, COALESCE(SUM(expense_total - expense_paid), 0) AS balance', false);

        if ($year > 0) {
            $builder->where(year_sql('ip_expenses.expense_date'), $year);
        }

        $this->applyPaidFilter($builder, $paid);
        $this->applySignFilter($builder, $sign);

        $builder = scope_builder_to_company($builder, 'ip_expenses')
            ->groupBy('m')
            ->orderBy('m', 'ASC');

        foreach ($builder->get()->getResultArray() as $row) {
            $m = (int) ($row['m'] ?? 0);

            if ($m < 1 || $m > 12) {
                continue;
            }

            $out[$m] = [
                'net'     => (float) ($row['net'] ?? 0),
                'tax'     => (float) ($row['tax'] ?? 0),
                'total'   => (float) ($row['total'] ?? 0),
                'balance' => (float) ($row['balance'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Spese in un intervallo di date, per l'esportazione Excel.
     */
    public function getInPeriod(?string $from = null, ?string $to = null, ?int $year = null, array $months = [], string $sign = ''): array
    {
        $this->withJoins();

        if ($from !== null) {
            $this->where('ip_expenses.expense_date >=', $from);
        }

        if ($to !== null) {
            $this->where('ip_expenses.expense_date <=', $to);
        }

        if ($year !== null && $year > 0) {
            $this->where(year_sql('ip_expenses.expense_date'), $year);
        }

        $this->applyMonthFilter($this, 'ip_expenses.expense_date', $months);
        $this->applySignFilter($this, $sign);

        $this->scopeToCompany();

        return $this->orderBy('ip_expenses.expense_date', 'ASC')
            ->orderBy('ip_expenses.expense_id', 'ASC')
            ->findAll();
    }

    /**
     * Filtro "da pagare / pagate" condiviso da lista e totali mensili.
     *
     * @param mixed $builder
     *
     * @return mixed
     */
    private function applyPaidFilter($builder, string $paid)
    {
        // Nel verso del documento: una nota di credito ricevuta ha totale
        // negativo e si chiude quando il fornitore rimborsa (pagato che scende
        // fino al totale). Con il confronto solo "pagato >= totale" una nota
        // da -100 non ancora rimborsata (pagato 0) risultava saldata, e non
        // compariva mai fra quelle aperte.
        if ($paid === 'open') {
            return $builder->where('((ip_expenses.expense_total >= 0 AND ip_expenses.expense_paid < ip_expenses.expense_total)
                OR (ip_expenses.expense_total < 0 AND ip_expenses.expense_paid > ip_expenses.expense_total))', null, false);
        }

        if ($paid === 'paid') {
            return $builder->where('((ip_expenses.expense_total >= 0 AND ip_expenses.expense_paid >= ip_expenses.expense_total)
                OR (ip_expenses.expense_total < 0 AND ip_expenses.expense_paid <= ip_expenses.expense_total))', null, false);
        }

        return $builder;
    }

    /**
     * Filtro "note di credito / spese" condiviso da lista e totali mensili.
     *
     * Le note di credito ricevute non hanno un flag di segno: vivono con
     * expense_total negativo (vedi PurchaseInvoiceImporter e i commenti sul
     * saldo). Quindi il tipo si ricava dal totale, non da una colonna.
     *
     * @param mixed $builder
     *
     * @return mixed
     */
    private function applySignFilter($builder, string $sign)
    {
        if ($sign === 'credit') {
            return $builder->where('ip_expenses.expense_total <', 0);
        }

        if ($sign === 'invoice') {
            return $builder->where('ip_expenses.expense_total >=', 0);
        }

        return $builder;
    }

    /**
     * Filtro mesi condiviso da lista e totali (mese singolo o selezione
     * multipla dalla barra periodo).
     *
     * @param mixed         $builder
     * @param int|list<int> $month
     *
     * @return mixed
     */
    private function applyMonthFilter($builder, string $column, int|array $month)
    {
        $months = is_array($month) ? $month : [$month];
        $months = array_values(array_filter(
            array_map('intval', $months),
            static fn (int $m): bool => $m >= 1 && $m <= 12
        ));

        if ($months === []) {
            return $builder;
        }

        if (count($months) === 1) {
            return $builder->where('EXTRACT(MONTH FROM ' . $column . ')', $months[0]);
        }

        return $builder->where('EXTRACT(MONTH FROM ' . $column . ') IN (' . implode(', ', $months) . ')', null, false);
    }

    /**
     * Dettaglio spesa per ID.
     */
    public function getById(int $id): ?array
    {
        // Unico metodo del model senza perimetro: alimentava preview_xml e
        // download_xml, quindi con l'id nell'URL si scaricava l'XML FatturaPA
        // della fattura d'acquisto di un'altra azienda (fornitore, importi,
        // dati fiscali) da una cartella tenuta apposta fuori dal docroot.
        return $this->withJoins()
            ->scopeToCompany()
            ->where('ip_expenses.expense_id', $id)
            ->first();
    }

    /**
     * Spese di un fornitore, piu' recenti prima (scheda fornitore).
     */
    public function forSupplier(int $supplierId): array
    {
        // scopeToCompany(): l'unico chiamante (Suppliers::view()) passa gia'
        // un supplier_id validato nel perimetro azienda, quindi oggi non e'
        // sfruttabile -- ma senza questo controllo qui, un futuro riuso con
        // un id non pre-validato diventerebbe un leak cross-tenant, come per
        // ogni altro metodo di lettura del model.
        return $this->withJoins()
            ->where('ip_expenses.supplier_id', $supplierId)
            ->scopeToCompany()
            ->orderBy('ip_expenses.expense_date', 'DESC')
            ->orderBy('ip_expenses.expense_id', 'DESC')
            ->findAll();
    }

    /**
     * Callback eventi CI4: invalida la cache della pagina Finance.
     */
    protected function clearFinanceCache(array $data): array
    {
        if (function_exists('invalidate_finance_cache')) {
            invalidate_finance_cache();
        }

        return $data;
    }

    // =========================================================================
    // Finance — metodi per la pagina "Utile e imposte" e Dashboard
    // =========================================================================

    /**
     * Totali spese per un intervallo di date.
     *
     * Chiavi restituite:
     *   - amount/net : imponibile totale (senza IVA)
     *   - tax        : IVA totale
     *   - total      : imponibile + IVA
     *   - deductible : imponibile delle sole spese con categoria deducibile
     *                  (expense_category_deductible IS TRUE, o senza categoria)
     *
     * @return array{amount: float, net: float, tax: float, total: float, deductible: float}
     */
    public function totalsForPeriod(?string $from = null, ?string $to = null): array
    {
        $scope = company_scope_sql('e');

        $where = "1=1{$scope}";
        $params = [];

        if ($from) {
            $where .= ' AND e.expense_date >= ?';
            $params[] = $from;
        }

        if ($to) {
            $where .= ' AND e.expense_date <= ?';
            $params[] = $to;
        }

        $row = $this->db->query("
            SELECT COALESCE(SUM(e.expense_amount), 0) AS net,
                   COALESCE(SUM(e.expense_tax), 0)    AS tax,
                   COALESCE(SUM(e.expense_total), 0)  AS total,
                   COALESCE(SUM(
                       CASE
                           WHEN c.expense_category_deductible IS TRUE OR c.expense_category_id IS NULL
                           THEN e.expense_amount
                           ELSE 0
                       END
                   ), 0) AS deductible
            FROM ip_expenses e
            LEFT JOIN ip_expense_categories c ON c.expense_category_id = e.expense_category_id
            WHERE {$where}", $params)->getRowArray();

        $amount = (float) ($row['net'] ?? 0);
        $tax    = (float) ($row['tax'] ?? 0);
        $total  = (float) ($row['total'] ?? 0);

        return [
            'amount'     => $amount,
            'net'        => $amount,
            'tax'        => $tax,
            'total'      => $total,
            'deductible' => (float) ($row['deductible'] ?? 0),
        ];
    }

    /**
     * Spese raggruppate per categoria in un intervallo di date.
     */
    public function byCategory(?string $from = null, ?string $to = null): array
    {
        $scope = company_scope_sql('e');

        $where = "1=1{$scope}";
        $params = [];

        if ($from) {
            $where .= ' AND e.expense_date >= ?';
            $params[] = $from;
        }

        if ($to) {
            $where .= ' AND e.expense_date <= ?';
            $params[] = $to;
        }

        $rows = $this->db->query("
            SELECT COALESCE(c.expense_category_name, 'Senza categoria') AS category,
                   COUNT(*) AS documents,
                   COALESCE(SUM(e.expense_amount), 0) AS amount
            FROM ip_expenses e
            LEFT JOIN ip_expense_categories c ON c.expense_category_id = e.expense_category_id
            WHERE {$where}
            GROUP BY c.expense_category_id, c.expense_category_name
            ORDER BY amount DESC", $params)->getResultArray();

        foreach ($rows as &$row) {
            $row['documents'] = (int) $row['documents'];
            $row['amount']    = (float) $row['amount'];
        }

        return $rows;
    }

    /**
     * Uscite mese per mese per un anno specifico.
     *
     * @return array<int, float> Importi indicizzati per mese (1-12)
     */
    public function expensesByMonth(int $year): array
    {
        $months = array_fill(1, 12, 0.0);

        $scope = company_scope_sql('e');

        $rows = $this->db->query("
            SELECT EXTRACT(MONTH FROM e.expense_date) AS m, SUM(e.expense_amount) AS total
            FROM ip_expenses e
            WHERE EXTRACT(YEAR FROM e.expense_date) = ?{$scope}
            GROUP BY m", [$year])->getResultArray();

        foreach ($rows as $row) {
            $months[(int) $row['m']] = (float) $row['total'];
        }

        return $months;
    }

    public function getMonthlySeries(int $months): array
    {
        $out = [];
        $months = max(1, $months);
        $firstTs = strtotime('-' . ($months - 1) . ' month', strtotime('first day of this month 00:00:00'));
        $startDate = date('Y-m-01', $firstTs);
        $endDate = date('Y-m-t');

        $scope = company_scope_sql('e');

        $rows = $this->db->query("
            SELECT TO_CHAR(e.expense_date, 'YYYY-MM') AS ym,
                   COALESCE(SUM(e.expense_total), 0) AS total
            FROM ip_expenses e
            WHERE e.expense_date >= ? AND e.expense_date <= ?{$scope}
            GROUP BY ym",
            [$startDate, $endDate]
        )->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['ym']] = (float) $row['total'];
        }


        for ($i = $months - 1; $i >= 0; $i--) {
            $ts = strtotime("-$i month", strtotime('first day of this month 00:00:00'));
            $ym = date('Y-m', $ts);
            $label = month_label($ts);
            $out[] = [
                'total' => $map[$ym] ?? 0.0,
                'label' => $label,
            ];
        }

        return $out;
    }

    public function getMonthlyTaxSeries(int $months): array
    {
        $out = [];
        $months = max(1, $months);
        $firstTs = strtotime('-' . ($months - 1) . ' month', strtotime('first day of this month 00:00:00'));
        $startDate = date('Y-m-01', $firstTs);
        $endDate = date('Y-m-t');

        $scope = company_scope_sql('e');

        $rows = $this->db->query("
            SELECT TO_CHAR(e.expense_date, 'YYYY-MM') AS ym,
                   COALESCE(SUM(e.expense_tax), 0) AS total
            FROM ip_expenses e
            WHERE e.expense_date >= ? AND e.expense_date <= ?{$scope}
            GROUP BY ym",
            [$startDate, $endDate]
        )->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['ym']] = (float) $row['total'];
        }

        for ($i = $months - 1; $i >= 0; $i--) {
            $ts = strtotime("-$i month", strtotime('first day of this month 00:00:00'));
            $ym = date('Y-m', $ts);
            $out[] = [
                'total' => $map[$ym] ?? 0.0,
            ];
        }

        return $out;
    }
}
