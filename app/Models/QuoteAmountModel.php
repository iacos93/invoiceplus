<?php

namespace App\Models;

class QuoteAmountModel extends IpBaseModel
{
    protected $table = 'ip_quote_amounts';
    protected $primaryKey = 'quote_amount_id';
    // Senza allowedFields, insert()/update() del Model CI4 lanciano
    // DataException("Allowed fields must be specified...") già alla prima
    // chiamata: qui elenco tutte le colonne scrivibili tranne la chiave
    // primaria, che è autoincrement e non va mai passata nei dbArray.
    protected $allowedFields = [
        'quote_id',
        'quote_item_subtotal',
        'quote_item_tax_total',
        'quote_tax_total',
        'quote_total',
    ];

    /**
     * Ricalcola gli importi totali del preventivo a partire dalle righe e imposte.
     */
    public function calculate(int $quoteId): void
    {
        $query = $this->db->query("
            SELECT SUM(item_subtotal) AS quote_item_subtotal,
                   SUM(item_tax_total) AS quote_item_tax_total,
                   SUM(item_subtotal) + SUM(item_tax_total) AS quote_total,
                   SUM(item_discount) AS quote_item_discount
            FROM ip_quote_item_amounts
            WHERE item_id IN (SELECT item_id FROM ip_quote_items WHERE quote_id = " . $this->db->escape($quoteId) . ")
        ");
        $row = $query->getRow();
        $itemSubtotal = (float) ($row->quote_item_subtotal ?? 0) - (float) ($row->quote_item_discount ?? 0);
        $itemTaxTotal = (float) ($row->quote_item_tax_total ?? 0);
        $subtotal = $itemSubtotal + $itemTaxTotal;

        $quote = $this->db->table('ip_quotes')->where('quote_id', $quoteId)->get()->getRowArray();
        $discountAmount = (float) ($quote['quote_discount_amount'] ?? 0);
        $discountPercent = (float) ($quote['quote_discount_percent'] ?? 0);
        $total = $subtotal - $discountAmount;
        $total -= round($total * $discountPercent / 100, 2);

        // round(..., 2) esplicito: stesso motivo di InvoiceAmountModel.
        $dbArray = [
            'quote_id'            => $quoteId,
            'quote_item_subtotal' => round($itemSubtotal, 2),
            'quote_item_tax_total'=> round($itemTaxTotal, 2),
            'quote_total'         => round($total, 2),
        ];

        $exists = $this->where('quote_id', $quoteId)->first();
        if ($exists) {
            $this->update($exists['quote_amount_id'], $dbArray);
        } else {
            $this->insert($dbArray);
        }

        $this->calculateQuoteTaxes($quoteId);
    }

    /**
     * Ricalcola le imposte a livello di preventivo (tabella ip_quote_tax_rates).
     */
    public function calculateQuoteTaxes(int $quoteId): void
    {
        $quoteTaxRates = $this->db->table('ip_quote_tax_rates')
            ->select('ip_quote_tax_rates.*, ip_tax_rates.tax_rate_percent')
            ->join('ip_tax_rates', 'ip_tax_rates.tax_rate_id = ip_quote_tax_rates.tax_rate_id', 'left')
            ->where('ip_quote_tax_rates.quote_id', $quoteId)
            ->get()
            ->getResultArray();

        if (!empty($quoteTaxRates)) {
            $quoteAmount = $this->db->table('ip_quote_amounts')
                ->where('quote_id', $quoteId)
                ->get()
                ->getRowArray();

            $subtotal = (float) ($quoteAmount['quote_item_subtotal'] ?? 0);
            $itemTaxTotal = (float) ($quoteAmount['quote_item_tax_total'] ?? 0);

            $taxTotal = 0.0;
            foreach ($quoteTaxRates as $taxRate) {
                $percent = (float) ($taxRate['tax_rate_percent'] ?? 0);
                if (!empty($taxRate['include_item_tax'])) {
                    $taxAmount = ($subtotal + $itemTaxTotal) * ($percent / 100);
                } else {
                    $taxAmount = $subtotal * ($percent / 100);
                }

                $taxAmount = round($taxAmount, 2);

                $this->db->table('ip_quote_tax_rates')
                    ->where('quote_tax_rate_id', $taxRate['quote_tax_rate_id'])
                    ->update(['quote_tax_rate_amount' => $taxAmount]);

                $taxTotal += $taxAmount;
            }

            $quote = $this->db->table('ip_quotes')->where('quote_id', $quoteId)->get()->getRowArray();
            $discountAmount = (float) ($quote['quote_discount_amount'] ?? 0);
            $discountPercent = (float) ($quote['quote_discount_percent'] ?? 0);

            $total = $subtotal + $itemTaxTotal + $taxTotal - $discountAmount;
            $total -= round($total * $discountPercent / 100, 2);
            $total = round($total, 2);

            $this->db->table('ip_quote_amounts')
                ->where('quote_id', $quoteId)
                ->update([
                    'quote_tax_total' => round($taxTotal, 2),
                    'quote_total'     => $total,
                ]);
        } else {
            $this->db->table('ip_quote_amounts')
                ->where('quote_id', $quoteId)
                ->update(['quote_tax_total' => 0.00]);
        }
    }

    /**
     * Importi di un preventivo.
     */
    public function getByQuote(int $quoteId): ?array
    {
        return $this->where('quote_id', $quoteId)->first();
    }

    /**
     * Totali per stato preventivo dell'anno selezionato nel filtro globale.
     */
    public function getStatusTotalsByYear(int $year): array
    {
        $dateFilter = $year > 0
            ? year_filter_sql('ip_quotes.quote_date_created', $year)
            : '1=1';

        return $this->statusTotals($dateFilter);
    }

    /** Query comune dei totali per stato preventivo ($dateFilter senza AND). */
    private function statusTotals(string $dateFilter): array
    {
        $results = $this->db->query("
            SELECT ip_quotes.quote_status_id,
                   COALESCE(SUM(ip_quote_amounts.quote_total), 0) AS sum_total,
                   COUNT(*) AS num_total
            FROM ip_quote_amounts
            JOIN ip_quotes ON ip_quotes.quote_id = ip_quote_amounts.quote_id
                AND " . $dateFilter . company_scope_sql('ip_quotes') . "
            GROUP BY ip_quotes.quote_status_id
        ")->getResultArray();

        $return = [];

        foreach ((new QuoteModel())->statuses() as $key => $status) {
            $return[$key] = [
                'quote_status_id' => $key,
                'class'           => $status['class'],
                'label'           => $status['label'],
                'href'            => $status['href'],
                'sum_total'       => 0,
                'num_total'       => 0,
            ];
        }

        foreach ($results as $result) {
            $statusId = (string) $result['quote_status_id'];
            if (isset($return[$statusId])) {
                $return[$statusId]['sum_total'] = $result['sum_total'];
                $return[$statusId]['num_total'] = $result['num_total'];
            }
        }

        return $return;
    }

    public function getMonthlySeries(int $months): array
    {
        $out = [];
        $months = max(1, $months);
        $firstTs = strtotime('-' . ($months - 1) . ' month', strtotime('first day of this month 00:00:00'));
        $startDate = date('Y-m-01', $firstTs);
        $endDate = date('Y-m-t');

        $scope = company_scope_sql('ip_quotes');

        $rows = $this->db->query("
            SELECT TO_CHAR(ip_quotes.quote_date_created, 'YYYY-MM') AS ym,
                   COALESCE(SUM(ip_quote_amounts.quote_total), 0) AS total
            FROM ip_quote_amounts
            JOIN ip_quotes ON ip_quotes.quote_id = ip_quote_amounts.quote_id
            WHERE ip_quotes.quote_status_id != 1
              AND ip_quotes.quote_date_created >= ?
              AND ip_quotes.quote_date_created <= ?
              {$scope}
            GROUP BY ym
        ", [$startDate, $endDate])->getResultArray();

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
}