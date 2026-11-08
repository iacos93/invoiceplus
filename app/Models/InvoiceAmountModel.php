<?php

namespace App\Models;

use App\Services\InvoiceMath;

class InvoiceAmountModel extends IpBaseModel
{
    protected $table = 'ip_invoice_amounts';
    protected $primaryKey = 'invoice_amount_id';
    // Vedi QuoteAmountModel: senza allowedFields, insert()/update() lanciano
    // DataException al primo ricalcolo di una fattura. invoice_sign esiste
    // in tabella ma non viene mai scritto da questo model, quindi resta
    // fuori dall'elenco.
    protected $allowedFields = [
        'invoice_id',
        'invoice_item_subtotal',
        'invoice_item_tax_total',
        'invoice_tax_total',
        'invoice_discount_total',
        'invoice_fund_amount',
        'invoice_withholding_amount',
        'invoice_total',
        'invoice_paid',
        'invoice_balance',
    ];

    /**
     * Ricalcola i totali derivati della fattura.
     *
     * Il riepilogo (sconto di documento ripartito per aliquota, contributo
     * cassa, IVA per aliquota, ritenuta) viene da InvoiceMath::documentSummary(),
     * lo stesso che Invoices::generateXml usa per i DatiRiepilogo: prima qui lo
     * sconto di documento si toglieva dal totale IVA inclusa (regola CI3) e il
     * contributo cassa non entrava affatto, così il totale salvato e quello
     * del tracciato raccontavano due fatture diverse.
     *
     * invoice_item_subtotal resta l'imponibile delle righe (al netto dei soli
     * sconti di riga), invoice_item_tax_total diventa l'IVA del riepilogo —
     * dopo lo sconto e comprensiva di quella sul contributo cassa.
     */
    public function calculate(int $invoiceId): void
    {
        $invoice = $this->db->table('ip_invoices')->where('invoice_id', $invoiceId)->get()->getRowArray();

        if (!$invoice) {
            return;
        }

        $summary = InvoiceMath::documentSummary(
            InvoiceMath::linesFromItems((new ItemModel())->getByInvoice($invoiceId)),
            (float) ($invoice['invoice_discount_amount'] ?? 0),
            (float) ($invoice['invoice_discount_percent'] ?? 0),
            trim((string) ($invoice['invoice_fund_type'] ?? '')) !== '' ? (float) ($invoice['invoice_fund_rate'] ?? 0) : 0.0,
            InvoiceMath::fundSubjectToWithholding($invoice['invoice_fund_type'] ?? null),
            $invoice['invoice_withholding_type'] ?? null,
            (float) ($invoice['invoice_withholding_rate'] ?? 0)
        );

        // Imposte di documento (ip_invoice_tax_rates, eredità CI3): calcolate
        // sull'imponibile del riepilogo, cioè già scontato e con la cassa.
        $taxTotal = 0.0;
        $invoiceTaxRates = $this->db->table('ip_invoice_tax_rates')
            ->select('ip_invoice_tax_rates.*, ip_tax_rates.tax_rate_percent')
            ->join('ip_tax_rates', 'ip_tax_rates.tax_rate_id = ip_invoice_tax_rates.tax_rate_id', 'left')
            ->where('ip_invoice_tax_rates.invoice_id', $invoiceId)
            ->get()
            ->getResultArray();

        foreach ($invoiceTaxRates as $taxRate) {
            $base = db_bool($taxRate['include_item_tax'] ?? false)
                ? $summary['taxable'] + $summary['tax']
                : $summary['taxable'];
            $taxAmount = InvoiceMath::round2($base * (float) ($taxRate['tax_rate_percent'] ?? 0) / 100);

            $this->db->table('ip_invoice_tax_rates')
                ->where('invoice_tax_rate_id', $taxRate['invoice_tax_rate_id'])
                ->update(['invoice_tax_rate_amount' => $taxAmount]);

            $taxTotal = InvoiceMath::round2($taxTotal + $taxAmount);
        }

        $paidRow = $this->db->query('SELECT SUM(payment_amount) AS invoice_paid FROM ip_payments WHERE invoice_id = ' . $this->db->escape($invoiceId))->getRow();
        $paid    = InvoiceMath::round2((float) ($paidRow->invoice_paid ?? 0));
        $total   = InvoiceMath::round2($summary['total'] + $taxTotal);

        // Il saldo vero lo scrive settle() subito dopo (split payment e
        // ritenuta): qui serve solo un valore coerente per la riga nuova.
        $dbArray = [
            'invoice_id'                 => $invoiceId,
            'invoice_item_subtotal'      => $summary['lines_taxable'],
            'invoice_item_tax_total'     => $summary['tax'],
            'invoice_tax_total'          => $taxTotal,
            'invoice_discount_total'     => $summary['discount'],
            'invoice_fund_amount'        => $summary['fund'],
            'invoice_withholding_amount' => $summary['withholding'],
            'invoice_total'              => $total,
            'invoice_paid'               => $paid,
            'invoice_balance'            => InvoiceMath::round2($total - $paid),
        ];

        $exists = $this->where('invoice_id', $invoiceId)->first();
        if ($exists) {
            $this->update($exists['invoice_amount_id'], $dbArray);
        } else {
            $this->insert($dbArray);
        }

        $this->settle($invoiceId);
    }

    /**
     * Ricalcolo dopo una modifica alle imposte di documento
     * (InvoiceTaxRateModel). Era un calcolo a parte, con la sua copia della
     * regola sullo sconto: ora le imposte di documento stanno dentro
     * calculate(), che è idempotente, e questo resta solo come nome storico.
     */
    public function calculateInvoiceTaxes(int $invoiceId): void
    {
        $this->calculate($invoiceId);
    }

    /**
     * Saldo definitivo e passaggio a "Pagato".
     *
     * Il saldo non è sempre totale − incassato: con lo split payment
     * (esigibilità "S") la PA versa al fornitore solo l'imponibile e l'IVA
     * all'Erario, quindi una fattura PA incassata per intero restava aperta
     * per l'importo dell'IVA — in scadenzario, nei solleciti, nel totale da
     * incassare della dashboard. Lo stesso vale per la ritenuta d'acconto,
     * che il cliente trattiene e versa lui. La regola sta in
     * InvoiceMath::balance().
     *
     * Poi lo stato: CI3 (Mdl_invoice_amounts::calculate) portava la fattura a
     * "Pagato" quando il saldo arrivava a zero, e il porting l'aveva perso —
     * una fattura saldata restava "Inviata" e continuava a ricevere
     * solleciti. Come in CI3 il metodo di pagamento della fattura diventa
     * quello dell'incasso, e se l'impostazione "sola lettura" scatta in uno
     * stato già attraversato, la fattura pagata diventa di sola lettura.
     */
    private function settle(int $invoiceId): void
    {
        $row = $this->db->table('ip_invoices')
            ->select('ip_invoices.invoice_status_id, ip_invoices.invoice_vat_exigibility,
                ip_invoices.creditinvoice_parent_id, ip_invoices.invoice_doc_type, ip_invoices.is_read_only,
                ip_invoice_amounts.invoice_withholding_amount,
                ip_invoice_amounts.invoice_total, ip_invoice_amounts.invoice_paid,
                ip_invoice_amounts.invoice_item_tax_total, ip_invoice_amounts.invoice_tax_total')
            ->join('ip_invoice_amounts', 'ip_invoice_amounts.invoice_id = ip_invoices.invoice_id')
            ->where('ip_invoices.invoice_id', $invoiceId)
            ->get()
            ->getRowArray();

        if (!$row) {
            return;
        }

        $total   = (float) $row['invoice_total'];
        $balance = InvoiceMath::balance(
            $total,
            (float) $row['invoice_item_tax_total'] + (float) $row['invoice_tax_total'],
            $row['invoice_vat_exigibility'],
            (float) $row['invoice_paid'],
            // Calcolata da documentSummary() in calculate(): sull'imponibile
            // scontato, con il contributo cassa quando è rivalsa INPS.
            (float) $row['invoice_withholding_amount']
        );

        $this->db->table('ip_invoice_amounts')
            ->where('invoice_id', $invoiceId)
            ->update(['invoice_balance' => $balance]);

        $isCredit = !empty($row['creditinvoice_parent_id']) || $row['invoice_doc_type'] === 'TD04';
        $status   = (int) $row['invoice_status_id'];

        // Verso opposto: "Pagato" con il saldo di nuovo aperto (un pagamento
        // eliminato o ridotto) torna "Inviata", come faceva CI3 quando si
        // cancellava l'ultimo pagamento — ma anche quando ne resta qualcuno,
        // altrimenti il residuo usciva da scadenzario e solleciti. La regola,
        // con l'eccezione del "Pagato" messo a mano, è in InvoiceMath.
        $hasPayments = $this->db->table('ip_payments')->where('invoice_id', $invoiceId)->countAllResults() > 0;

        if (InvoiceMath::shouldReopen($status, $total, $balance, $isCredit, $hasPayments)) {
            (new InvoiceModel())->update($invoiceId, ['invoice_status_id' => 2]);

            return;
        }

        if (!InvoiceMath::shouldMarkPaid($status, $total, $balance, $isCredit)) {
            return;
        }

        $update = ['invoice_status_id' => 4];

        $payment = $this->db->table('ip_payments')
            ->where('invoice_id', $invoiceId)
            ->orderBy('payment_id', 'ASC')
            ->get(1)
            ->getRowArray();

        if (!empty($payment['payment_method_id'])) {
            $update['payment_method'] = (int) $payment['payment_method_id'];
        }

        $readOnlyToggle = (int) get_setting('read_only_toggle', 0);

        if ($readOnlyToggle > 0 && $status >= $readOnlyToggle && (int) $row['is_read_only'] !== 1) {
            $update['is_read_only'] = 1;
        }

        // Attraverso InvoiceModel e non il builder: l'afterUpdate svuota la
        // cache del report finanziario, che conta incassato e da incassare.
        (new InvoiceModel())->update($invoiceId, $update);
    }

    public function getStatusTotalsByYear(int $year): array
    {
        $dateFilter = $year > 0
            ? year_filter_sql('ip_invoices.invoice_date_created', $year)
            : '1=1';

        return $this->statusTotals($dateFilter);
    }

    private function statusTotals(string $dateFilter): array
    {
        $results = $this->db->query("
            SELECT ip_invoices.invoice_status_id,
                   COALESCE(SUM(ip_invoice_amounts.invoice_total), 0) AS sum_total,
                   COUNT(*) AS num_total
            FROM ip_invoice_amounts
            JOIN ip_invoices ON ip_invoices.invoice_id = ip_invoice_amounts.invoice_id
                AND " . $dateFilter . company_scope_sql('ip_invoices') . "
            GROUP BY ip_invoices.invoice_status_id
        ")->getResultArray();

        $return = [];
        foreach ((new InvoiceModel())->statuses() as $key => $status) {
            $return[$key] = [
                'invoice_status_id' => $key,
                'class'           => $status['class'],
                'label'           => $status['label'],
                'href'            => $status['href'],
                'sum_total'       => 0,
                'num_total'       => 0,
            ];
        }
        foreach ($results as $result) {
            $statusId = (string) $result['invoice_status_id'];
            if (isset($return[$statusId])) {
                $return[$statusId]['sum_total'] = $result['sum_total'];
                $return[$statusId]['num_total'] = $result['num_total'];
            }
        }
        return $return;
    }
    public function getOverdueSummary(): array
    {
        $scope = company_scope_sql('ip_invoices');
        // invoice_balance > 0, come getOutstandingTotal() e lo scadenzario:
        // una nota di credito ha saldo negativo (internamente e' uno storno),
        // e senza il filtro contava come "fattura scaduta" e ne abbassava il
        // totale. Anche le fatture gia' saldate ma non ancora in stato 4
        // finivano nel conteggio.
        $row = $this->db->query("SELECT COUNT(*) AS count, COALESCE(SUM(invoice_balance),0) AS total FROM ip_invoice_amounts JOIN ip_invoices ON ip_invoices.invoice_id = ip_invoice_amounts.invoice_id WHERE ip_invoices.invoice_status_id NOT IN (1,4) AND ip_invoice_amounts.invoice_balance > 0 AND ip_invoices.invoice_date_due < CURRENT_DATE AND ip_invoices.invoice_date_due IS NOT NULL $scope")->getRowArray();
        return ['count' => (int) ($row['count'] ?? 0), 'total' => (float) ($row['total'] ?? 0)];
    }

    public function getOutstandingTotal(): float
    {
        $scope = company_scope_sql('ip_invoices');
        $row = $this->db->query("SELECT COALESCE(SUM(invoice_balance),0) AS total FROM ip_invoice_amounts JOIN ip_invoices ON ip_invoices.invoice_id = ip_invoice_amounts.invoice_id WHERE ip_invoices.invoice_status_id NOT IN (1, 4) AND ip_invoice_amounts.invoice_balance > 0 $scope")->getRow();
        return (float) ($row->total ?? 0);
    }

    public function getMonthlySeries(int $months): array
    {
        $out = [];
        $months = max(1, $months);
        $firstTs = strtotime('-' . ($months - 1) . ' month', strtotime('first day of this month 00:00:00'));
        $startDate = date('Y-m-01', $firstTs);
        $endDate = date('Y-m-t');

        $scopeInv = company_scope_sql('ip_invoices');
        $scopePay = company_scope_sql('ip_invoices');

        $invoicedRows = $this->db->query("
            SELECT TO_CHAR(ip_invoices.invoice_date_created, 'YYYY-MM') AS ym,
                   COALESCE(SUM(ip_invoice_amounts.invoice_total), 0) AS invoiced
            FROM ip_invoice_amounts
            JOIN ip_invoices ON ip_invoices.invoice_id = ip_invoice_amounts.invoice_id
            WHERE ip_invoices.invoice_status_id != 1
              AND ip_invoices.invoice_date_created >= ?
              AND ip_invoices.invoice_date_created <= ?
              {$scopeInv}
            GROUP BY ym
        ", [$startDate, $endDate])->getResultArray();

        $invoicedMap = [];
        foreach ($invoicedRows as $row) {
            $invoicedMap[$row['ym']] = (float) $row['invoiced'];
        }

        $paidRows = $this->db->query("
            SELECT TO_CHAR(ip_payments.payment_date, 'YYYY-MM') AS ym,
                   COALESCE(SUM(ip_payments.payment_amount), 0) AS paid
            FROM ip_payments
            JOIN ip_invoices ON ip_invoices.invoice_id = ip_payments.invoice_id
            WHERE ip_payments.payment_date >= ?
              AND ip_payments.payment_date <= ?
              {$scopePay}
            GROUP BY ym
        ", [$startDate, $endDate])->getResultArray();

        $paidMap = [];
        foreach ($paidRows as $row) {
            $paidMap[$row['ym']] = (float) $row['paid'];
        }


        for ($i = $months - 1; $i >= 0; $i--) {
            $ts = strtotime("-$i month", strtotime('first day of this month 00:00:00'));
            $ym = date('Y-m', $ts);
            $label = month_label($ts);
            $out[] = [
                'invoiced' => $invoicedMap[$ym] ?? 0.0,
                'paid'     => $paidMap[$ym] ?? 0.0,
                'label'    => $label,
            ];
        }

        return $out;
    }

    /**
     * Quota di fatturato dell'anno già incassata, in percentuale.
     *
     * Il denominatore è il fatturato dell'anno e il numeratore la parte di
     * quel fatturato già saldata, ricavata dal saldo delle sue fatture: è
     * l'unico modo per avere due numeri sullo stesso perimetro. Dividere i
     * pagamenti incassati nell'anno (che possono riferirsi a fatture di anni
     * precedenti, o mancare per fatture pagate dopo) per il fatturato dello
     * stesso anno mescolava cassa e competenza e poteva superare il 100%.
     *
     * Il saldo è quello di settle(), che azzera anche la quota di IVA versata
     * dagli enti in scissione: una fattura PA incassata per intero risulta
     * saldata e non all'80%.
     */
    public function getCollectedRatioForYear(int $year): float
    {
        $scope  = company_scope_sql('ip_invoices');
        $filter = $year > 0
            ? ' AND ' . year_filter_sql('ip_invoices.invoice_date_created', $year)
            : '';

        $row = $this->db->query("
            SELECT COALESCE(SUM(ip_invoice_amounts.invoice_total), 0)   AS invoiced,
                   COALESCE(SUM(ip_invoice_amounts.invoice_balance), 0) AS balance
            FROM ip_invoice_amounts
            JOIN ip_invoices ON ip_invoices.invoice_id = ip_invoice_amounts.invoice_id
            WHERE ip_invoices.invoice_status_id != 1{$filter}{$scope}
        ")->getRowArray();

        $invoiced = (float) ($row['invoiced'] ?? 0);

        if ($invoiced <= 0) {
            return 0.0;
        }

        return ($invoiced - (float) ($row['balance'] ?? 0)) / $invoiced * 100.0;
    }

    public function getPaidInYear(int $year): float
    {
        $scope = company_scope_sql('ip_invoices');
        $filter = $year > 0 ? year_filter_sql('payment_date', $year) : '1=1';
        $row = $this->db->query("SELECT COALESCE(SUM(payment_amount),0) AS total FROM ip_payments JOIN ip_invoices ON ip_invoices.invoice_id = ip_payments.invoice_id WHERE $filter $scope")->getRow();
        return (float) ($row->total ?? 0);
    }

    public function taxForPeriod(?string $from, ?string $to, bool $split = false): float
    {
        $scope = company_scope_sql('ip_invoices');
        $where = "ip_invoices.invoice_status_id != 1{$scope}";
        $params = [];

        if ($from !== null && $from !== '') {
            $where .= ' AND ip_invoices.invoice_date_created >= ?';
            $params[] = $from;
        }

        if ($to !== null && $to !== '') {
            $where .= ' AND ip_invoices.invoice_date_created <= ?';
            $params[] = $to;
        }

        $joinClient = '';
        if ($split) {
            $joinClient = 'JOIN ip_clients ON ip_clients.client_id = ip_invoices.client_id';
            $where .= " AND (ip_clients.client_split_payment = 'yes' OR ((ip_clients.client_split_payment = 'auto' OR ip_clients.client_split_payment IS NULL OR ip_clients.client_split_payment = '') AND ip_clients.client_type = 'pa'))";
        }

        $row = $this->db->query("
            SELECT COALESCE(SUM(COALESCE(ip_invoice_amounts.invoice_item_tax_total, 0) + COALESCE(ip_invoice_amounts.invoice_tax_total, 0)), 0) AS total_tax
            FROM ip_invoice_amounts
            JOIN ip_invoices ON ip_invoices.invoice_id = ip_invoice_amounts.invoice_id
            {$joinClient}
            WHERE {$where}
        ", $params)->getRowArray();

        return (float) ($row['total_tax'] ?? 0);
    }

    /**
     * Lordo, IVA e imponibile delle fatture emesse nel periodo.
     *
     * Serve alla card "Ricavi" della dashboard, che mostra il totale come
     * cifra e sotto la sua composizione: senza, l'unico modo per sapere
     * quanta parte di quel fatturato è IVA era aprire "Utile e imposte".
     *
     * L'imponibile è calcolato come differenza (lordo - IVA) e non sommando
     * invoice_item_subtotal: sconti di documento e contributi di cassa stanno
     * sul totale, non sull'imponibile delle righe, e l'imponibile che non
     * torna col lordo mostrato in card sarebbe un errore da spiegare.
     *
     * Le bozze restano fuori (stato 1), come nel totale con cui la card
     * confronta questa composizione.
     */
    public function totalsForPeriod(?string $from = null, ?string $to = null): array
    {
        $where  = 'ip_invoices.invoice_status_id != 1' . company_scope_sql('ip_invoices');
        $params = [];

        if ($from !== null && $from !== '') {
            $where .= ' AND ip_invoices.invoice_date_created >= ?';
            $params[] = $from;
        }

        if ($to !== null && $to !== '') {
            // Il limite superiore arriva come data ("2026-12-31"): se la colonna
            // è un timestamp, un confronto secco lascerebbe fuori l'ultimo giorno
            // e imponibile + IVA non tornerebbe col lordo mostrato in card.
            $where .= ' AND ip_invoices.invoice_date_created <= ?';
            $params[] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to . ' 23:59:59' : $to;
        }

        $row = $this->db->query("
            SELECT COALESCE(SUM(COALESCE(ip_invoice_amounts.invoice_total, 0)), 0) AS total,
                   COALESCE(SUM(COALESCE(ip_invoice_amounts.invoice_item_tax_total, 0)
                              + COALESCE(ip_invoice_amounts.invoice_tax_total, 0)), 0) AS tax
            FROM ip_invoice_amounts
            JOIN ip_invoices ON ip_invoices.invoice_id = ip_invoice_amounts.invoice_id
            WHERE {$where}
        ", $params)->getRowArray();

        $total = (float) ($row['total'] ?? 0);
        $tax   = (float) ($row['tax'] ?? 0);

        return ['total' => $total, 'tax' => $tax, 'net' => $total - $tax];
    }

    public function getMonthlyTaxSeries(int $months, bool $split = false): array
    {
        $out = [];
        $months = max(1, $months);
        $firstTs = strtotime('-' . ($months - 1) . ' month', strtotime('first day of this month 00:00:00'));
        $startDate = date('Y-m-01', $firstTs);
        $endDate = date('Y-m-t');

        $scope = company_scope_sql('ip_invoices');
        $where = "ip_invoices.invoice_status_id != 1
          AND ip_invoices.invoice_date_created >= ?
          AND ip_invoices.invoice_date_created <= ?
          {$scope}";
        $params = [$startDate, $endDate];

        $joinClient = '';
        if ($split) {
            $joinClient = 'JOIN ip_clients ON ip_clients.client_id = ip_invoices.client_id';
            $where .= " AND (ip_clients.client_split_payment = 'yes' OR ((ip_clients.client_split_payment = 'auto' OR ip_clients.client_split_payment IS NULL OR ip_clients.client_split_payment = '') AND ip_clients.client_type = 'pa'))";
        }

        $rows = $this->db->query("
            SELECT TO_CHAR(ip_invoices.invoice_date_created, 'YYYY-MM') AS ym,
                   COALESCE(SUM(COALESCE(ip_invoice_amounts.invoice_item_tax_total, 0) + COALESCE(ip_invoice_amounts.invoice_tax_total, 0)), 0) AS total_tax
            FROM ip_invoice_amounts
            JOIN ip_invoices ON ip_invoices.invoice_id = ip_invoice_amounts.invoice_id
            {$joinClient}
            WHERE {$where}
            GROUP BY ym
        ", $params)->getResultArray();

        $taxMap = [];
        foreach ($rows as $row) {
            $taxMap[$row['ym']] = (float) $row['total_tax'];
        }

        for ($i = $months - 1; $i >= 0; $i--) {
            $ts = strtotime("-$i month", strtotime('first day of this month 00:00:00'));
            $ym = date('Y-m', $ts);
            $out[] = [
                'total' => $taxMap[$ym] ?? 0.0,
            ];
        }

        return $out;
    }

    public function getMonthlySeriesForClient(int $clientId, int $months): array
    {
        $out = [];
        $months = max(1, $months);
        $firstTs = strtotime('-' . ($months - 1) . ' month', strtotime('first day of this month 00:00:00'));
        $startDate = date('Y-m-01', $firstTs);
        $endDate = date('Y-m-t');

        $scopeInv = company_scope_sql('ip_invoices');
        $scopePay = company_scope_sql('ip_invoices');

        $invoicedRows = $this->db->query("
            SELECT TO_CHAR(ip_invoices.invoice_date_created, 'YYYY-MM') AS ym,
                   COALESCE(SUM(ip_invoice_amounts.invoice_total), 0) AS invoiced
            FROM ip_invoice_amounts
            JOIN ip_invoices ON ip_invoices.invoice_id = ip_invoice_amounts.invoice_id
            WHERE ip_invoices.invoice_status_id != 1
              AND ip_invoices.client_id = ?
              AND ip_invoices.invoice_date_created >= ?
              AND ip_invoices.invoice_date_created <= ?
              {$scopeInv}
            GROUP BY ym
        ", [$clientId, $startDate, $endDate])->getResultArray();

        $invoicedMap = [];
        foreach ($invoicedRows as $row) {
            $invoicedMap[$row['ym']] = (float) $row['invoiced'];
        }

        $paidRows = $this->db->query("
            SELECT TO_CHAR(ip_payments.payment_date, 'YYYY-MM') AS ym,
                   COALESCE(SUM(ip_payments.payment_amount), 0) AS paid
            FROM ip_payments
            JOIN ip_invoices ON ip_invoices.invoice_id = ip_payments.invoice_id
            WHERE ip_invoices.client_id = ?
              AND ip_payments.payment_date >= ?
              AND ip_payments.payment_date <= ?
              {$scopePay}
            GROUP BY ym
        ", [$clientId, $startDate, $endDate])->getResultArray();

        $paidMap = [];
        foreach ($paidRows as $row) {
            $paidMap[$row['ym']] = (float) $row['paid'];
        }


        for ($i = $months - 1; $i >= 0; $i--) {
            $ts = strtotime("-$i month", strtotime('first day of this month 00:00:00'));
            $ym = date('Y-m', $ts);
            $label = month_label($ts);
            $out[] = [
                'invoiced' => $invoicedMap[$ym] ?? 0.0,
                'paid'     => $paidMap[$ym] ?? 0.0,
                'label'    => $label,
            ];
        }

        return $out;
    }
}