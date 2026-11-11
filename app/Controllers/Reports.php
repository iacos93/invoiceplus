<?php

namespace App\Controllers;

use App\Models\ReportModel;

/**
 * Reports controller
 *
 * Porting CI4 del controller Reports (CI3): elenco report, vendite per
 * cliente, storico pagamenti, scadenze fatture e vendite per anno.
 * I report con date accettano GET (form) e POST (esecuzione + PDF).
 */
class Reports extends BaseController
{
    public function index()
    {
        return view('reports/index', ['title' => trans('reports')]);
    }

    /**
     * Periodo predefinito dei report a date: l'anno selezionato in alto
     * (contesto globale anno), 1 gennaio - 31 dicembre: e' lo stesso intervallo
     * di "Quest'anno" / "Anno scorso" dei pulsanti rapidi, che si accendono
     * da soli. Prima era
     * "ultimi 12 mesi", che non corrispondeva a nessun pulsante ne' all'anno
     * scelto, e mescolava due esercizi.
     *
     * @return array{0: string, 1: string} date nel formato dell'utente
     */
    /**
     * Anno di riferimento dei report: quello scelto in alto, altrimenti il piu'
     * recente con documenti.
     *
     * selected_year() vale 0 quando il filtro e' "tutti gli anni". I report a
     * date lo usavano tale e quale, costruendo intervalli come "0-01-01" e,
     * per l'anno precedente, "-1-01-01": PostgreSQL rifiuta entrambi e le tre
     * pagine (vendite per cliente, storico pagamenti, vendite per anno)
     * rispondevano 500. Un report ha bisogno di un intervallo vero, e qui
     * "tutti gli anni" significa l'ultimo esercizio con dati.
     */
    private static function reportYear(): int
    {
        $year = selected_year();

        if ($year > 0) {
            return $year;
        }

        $years = available_years();

        return $years === [] ? (int) date('Y') : (int) max($years);
    }

    private static function defaultPeriod(): array
    {
        $year = self::reportYear();

        return [date_from_mysql($year . '-01-01'), date_from_mysql($year . '-12-31')];
    }

    public function salesByClient()
    {
        $request = service('request');

        [$fromDate, $toDate] = self::defaultPeriod();
        $results    = [];
        $hasResults = false;

        if ($request->getMethod() === 'POST') {
            $fromPost = trim((string) $request->getPost('from_date'));
            $toPost   = trim((string) $request->getPost('to_date'));

            if (!is_date($fromPost) || !is_date($toPost)) {
                session()->setFlashdata('alert_error', 'Le date inserite non sono valide');
                return redirect()->to('/reports/sales_by_client');
            }

            $from = (string) date_to_mysql($fromPost);
            $to   = (string) date_to_mysql($toPost);

            $results = (new ReportModel())->salesByClient($from, $to);
            $hasResults = true;

            if ($request->getPost('btn_submit_pdf')) {
                // La stampa deve dire la stessa cosa dello schermo: le righe
                // arrivano gia' ordinate per fatturato, quindi il PDF porta la
                // posizione e la quota, non solo gli importi.
                $totalNet = 0.0;
                foreach ($results as $row) {
                    $totalNet += (float) ($row['sales'] ?? 0);
                }

                $html = '<h2>' . trans('sales_by_client') . '</h2>'
                    . '<p>' . date_from_mysql($from) . ' - ' . date_from_mysql($to) . '</p>'
                    . '<table border="1" cellpadding="5" cellspacing="0" style="width:100%;border-collapse:collapse;font-family:Arial,sans-serif;font-size:12px">'
                    . '<tr style="background:#eee">'
                    . '<th>#</th>'
                    . '<th>' . trans('client') . '</th>'
                    . '<th>' . trans('share', '', 'Quota') . '</th>'
                    . '<th>' . trans('invoice_count') . '</th>'
                    . '<th>' . trans('sales') . '</th>'
                    . '<th>' . trans('sales_with_tax') . '</th>'
                    . '</tr>';

                foreach ($results as $i => $row) {
                    $rowNet = (float) ($row['sales'] ?? 0);
                    $share  = $totalNet > 0 ? $rowNet / $totalNet * 100 : 0.0;

                    $html .= '<tr>'
                        . '<td>' . ($i + 1) . '</td>'
                        . '<td>' . htmlsc(format_client($row)) . '</td>'
                        . '<td>' . format_amount($share) . '%</td>'
                        . '<td>' . (int) ($row['invoice_count'] ?? 0) . '</td>'
                        . '<td>' . format_amount($rowNet) . '</td>'
                        . '<td>' . format_amount($row['sales_with_tax'] ?? 0) . '</td>'
                        . '</tr>';
                }

                $html .= '</table>';

                pdf_create($html, 'report_sales_by_client_' . $from . '_' . $to . '.pdf');
            }

            $fromDate = $fromPost;
            $toDate   = $toPost;
        }

        // All'apertura il report e' gia' calcolato sul periodo predefinito:
        // prima la pagina arrivava vuota e serviva "Esegui report" anche per
        // vedere i dati che chiunque aprendola vuole vedere.
        if (!$hasResults) {
            $results    = (new ReportModel())->salesByClient((string) date_to_mysql($fromDate), (string) date_to_mysql($toDate));
            $hasResults = true;
        }

        return view('reports/sales_by_client_index', [
            'from_date'   => $fromDate,
            'to_date'     => $toDate,
            'results'     => $results,
            'has_results' => $hasResults,
            'title'       => trans('sales_by_client'),
        ]);
    }

    public function paymentHistory()
    {
        $request = service('request');

        [$fromDate, $toDate] = self::defaultPeriod();
        $results    = [];
        $hasResults = false;

        if ($request->getMethod() === 'POST') {
            $fromPost = trim((string) $request->getPost('from_date'));
            $toPost   = trim((string) $request->getPost('to_date'));

            if (!is_date($fromPost) || !is_date($toPost)) {
                session()->setFlashdata('alert_error', 'Le date inserite non sono valide');
                return redirect()->to('/reports/payment_history');
            }

            $from = (string) date_to_mysql($fromPost);
            $to   = (string) date_to_mysql($toPost);

            $results = (new ReportModel())->paymentHistory($from, $to);
            $hasResults = true;

            if ($request->getPost('btn_submit_pdf')) {
                $html = '<h2>' . trans('payment_history') . '</h2>'
                    . '<p>' . date_from_mysql($from) . ' - ' . date_from_mysql($to) . '</p>'
                    . '<table border="1" cellpadding="5" cellspacing="0" style="width:100%;border-collapse:collapse;font-family:Arial,sans-serif;font-size:12px">'
                    . '<tr style="background:#eee">'
                    . '<th>' . trans('date') . '</th>'
                    . '<th>' . trans('invoice') . '</th>'
                    . '<th>' . trans('client') . '</th>'
                    . '<th>' . trans('payment_method') . '</th>'
                    . '<th>' . trans('amount') . '</th>'
                    . '</tr>';

                // Stessi stacchi di mese dello schermo: e' la copia che si
                // guarda accanto all'estratto conto, dove si riconcilia un
                // mese per volta. I record arrivano ordinati per data.
                $sum        = 0;
                $monthNames = \App\Services\FinanceService::monthNames(service('request')->getLocale());
                $current    = null;

                foreach ($results as $row) {
                    $monthKey = substr((string) $row['payment_date'], 0, 7);

                    if ($monthKey !== $current) {
                        $current = $monthKey;
                        [$mYear, $mNum] = array_map('intval', explode('-', $monthKey));
                        $html .= '<tr style="background:#f4f4f4">'
                            . '<td colspan="5"><strong>' . htmlsc(($monthNames[$mNum] ?? '') . ' ' . $mYear) . '</strong></td>'
                            . '</tr>';
                    }

                    $sum += (float) $row['payment_amount'];
                    $html .= '<tr>'
                        . '<td>' . date_from_mysql($row['payment_date']) . '</td>'
                        . '<td>' . htmlsc($row['invoice_number']) . '</td>'
                        . '<td>' . htmlsc(format_client($row)) . '</td>'
                        . '<td>' . htmlsc($row['payment_method_name']) . '</td>'
                        . '<td>' . format_amount($row['payment_amount']) . '</td>'
                        . '</tr>';
                }

                $html .= '<tr><td colspan="4">' . trans('total') . '</td><td>' . format_amount($sum) . '</td></tr></table>';

                pdf_create($html, 'report_payment_history_' . $from . '_' . $to . '.pdf');
            }

            $fromDate = $fromPost;
            $toDate   = $toPost;
        }

        // Come le vendite per cliente: calcolato subito sul periodo predefinito.
        if (!$hasResults) {
            $results    = (new ReportModel())->paymentHistory((string) date_to_mysql($fromDate), (string) date_to_mysql($toDate));
            $hasResults = true;
        }

        return view('reports/payment_history_index', [
            'from_date'   => $fromDate,
            'to_date'     => $toDate,
            'results'     => $results,
            'has_results' => $hasResults,
            'title'       => trans('payment_history'),
        ]);
    }

    public function invoiceAging()
    {
        $results = (new ReportModel())->invoiceAging();

        return view('reports/invoice_aging_index', [
            'results'     => $results,
            'has_results' => true,
            'title'       => trans('invoice_aging'),
        ]);
    }

    /**
     * Crediti scaduti in PDF — la copia che si manda al commercialista o si
     * porta in riunione. È una lettura, quindi GET: non cambia nulla e non ha
     * bisogno di un pulsante dentro il form dei filtri (che qui non esiste).
     */
    public function invoiceAgingPdf()
    {
        $results = (new ReportModel())->invoiceAging();
        usort($results, static fn ($a, $b) => (float) ($b['total_balance'] ?? 0) <=> (float) ($a['total_balance'] ?? 0));

        $ranges = [
            1 => [trans('invoice_aging_1_30', '', '1 - 30 giorni'), trans('aging_risk_1', '', 'recente')],
            2 => [trans('invoice_aging_31_60', '', '31 - 60 giorni'), trans('aging_risk_2', '', 'scaduto')],
            3 => [trans('invoice_aging_61_90', '', '61 - 90 giorni'), trans('aging_risk_3', '', 'arretrato')],
            4 => [trans('invoice_aging_91_120', '', '91 - 120 giorni'), trans('aging_risk_4', '', 'critico')],
            5 => [trans('invoice_aging_above_120', '', 'Oltre 120 giorni'), trans('aging_risk_5', '', 'sofferenza')],
        ];

        $rangeTotals = array_fill_keys(array_keys($ranges), 0.0);
        $grandTotal  = 0.0;

        $html = '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8">'
            . '<style>'
            . '@page{size:A4 landscape;margin:12mm}'
            . 'body{font-family:DejaVu Sans,Arial,sans-serif;font-size:10px;color:#222}'
            . 'h2{font-size:16px;margin:0 0 4px}'
            . '.sub{color:#555;margin:0 0 12px}'
            . 'table{width:100%;border-collapse:collapse}'
            . 'th,td{border:1px solid #bbb;padding:4px 5px}'
            . 'thead th{background:#eee;text-align:right}'
            . 'thead th:first-child,td:first-child{text-align:left}'
            . 'td.num{text-align:right}'
            . 'tfoot td{font-weight:bold;background:#f6f6f6}'
            . '</style></head><body>'
            . '<h2>' . htmlsc(trans('invoice_aging')) . '</h2>'
            . '<p class="sub">' . htmlsc(trans('as_of', '', 'al')) . ' ' . htmlsc(date_from_mysql(date('Y-m-d'))) . '</p>'
            . '<table><thead><tr><th>' . htmlsc(trans('client')) . '</th>';

        foreach ($ranges as [$label, $risk]) {
            $html .= '<th>' . htmlsc($label) . '<br><small>' . htmlsc($risk) . '</small></th>';
        }
        $html .= '<th>' . htmlsc(trans('total_balance')) . '</th></tr></thead><tbody>';

        if ($results === []) {
            $html .= '<tr><td colspan="7">' . htmlsc(trans('no_overdue_invoices', '', 'Nessuna fattura scaduta.')) . '</td></tr>';
        }

        foreach ($results as $row) {
            $html .= '<tr><td>' . htmlsc(format_client($row)) . '</td>';

            foreach ($ranges as $i => $range) {
                $value = (float) ($row['range_' . $i] ?? 0);
                $rangeTotals[$i] += $value;
                $html .= '<td class="num">' . ($value > 0 ? format_amount($value) : '&ndash;') . '</td>';
            }

            $grandTotal += (float) ($row['total_balance'] ?? 0);
            $html .= '<td class="num"><strong>' . format_amount($row['total_balance'] ?? 0) . '</strong></td></tr>';
        }

        $html .= '</tbody><tfoot><tr><td>' . htmlsc(trans('total')) . '</td>';
        foreach ($rangeTotals as $value) {
            $html .= '<td class="num">' . format_amount($value) . '</td>';
        }
        $html .= '<td class="num">' . format_amount($grandTotal) . '</td></tr></tfoot></table></body></html>';

        pdf_create($html, 'report_invoice_aging_' . date('Y-m-d') . '.pdf');
    }

    public function salesByYear()
    {
        $request = service('request');

        // Anno selezionato in alto (contesto globale), non la data di oggi:
        // chi sta guardando il 2025 si aspetta il confronto 2024-2025. Con
        // "tutti gli anni" reportYear() sceglie l'ultimo esercizio con dati,
        // perche' 0-1 produceva l'anno -1 e una query non valida.
        $currentYear = self::reportYear();
        $yearFrom    = $currentYear - 1;
        $yearTo      = $currentYear;
        $minQuantity = null;
        $maxQuantity = null;
        $checkboxTax = false;
        $results     = [];
        $hasResults  = false;

        if ($request->getMethod() === 'POST') {
            // Scorciatoie "ultimi N anni": sono pulsanti di invio, quindi
            // arrivano con gli altri campi, ma l'intervallo lo decide il
            // pulsante. L'ancora è l'anno del contesto (lo stesso da cui parte
            // il report), non l'anno di oggi: chi sta guardando il 2025 si
            // aspetta 2023-2025, non 2023-2026.
            $rangeYears = (int) $request->getPost('btn_range');
            if (in_array($rangeYears, [1, 2, 3, 5], true)) {
                $yearFrom = $currentYear - ($rangeYears - 1);
                $yearTo   = $currentYear;
            } else {
                $yearFromPost = (int) $request->getPost('year_from');
                $yearToPost   = (int) $request->getPost('year_to');

                if ($yearFromPost < 1000 || $yearFromPost > 9999 || $yearToPost < 1000 || $yearToPost > 9999
                    || $yearFromPost > $yearToPost) {
                    session()->setFlashdata('alert_error', 'Gli anni inseriti non sono validi');
                    return redirect()->to('/reports/sales_by_year');
                }

                $yearFrom = $yearFromPost;
                $yearTo   = $yearToPost;
            }

            $minQuantity = $request->getPost('minQuantity') !== null && $request->getPost('minQuantity') !== ''
                ? (int) $request->getPost('minQuantity') : null;
            $maxQuantity = $request->getPost('maxQuantity') !== null && $request->getPost('maxQuantity') !== ''
                ? (int) $request->getPost('maxQuantity') : null;
            $checkboxTax = (bool) $request->getPost('checkboxTax');

            $results    = (new ReportModel())->salesByYear($yearFrom, $yearTo, $minQuantity, $maxQuantity, $checkboxTax);
            $hasResults = true;

            if ($request->getPost('btn_submit_pdf')) {
                $this->salesByYearPdf($results, $yearFrom, $yearTo, $checkboxTax);
            }
        }

        if (!$hasResults) {
            $results    = (new ReportModel())->salesByYear($yearFrom, $yearTo, null, null, false);
            $hasResults = true;
        }

        // Anni selezionabili: quelli con documenti, più i due del periodo in
        // corso e l'anno di oggi. Il report può essere aperto su un anno senza
        // fatture e i campi devono comunque mostrarlo.
        $yearOptions = available_years();
        foreach ([$yearFrom, $yearTo, (int) date('Y')] as $year) {
            if (!in_array($year, $yearOptions, true)) {
                $yearOptions[] = $year;
            }
        }
        rsort($yearOptions);

        return view('reports/sales_by_year_index', [
            'year_from'    => $yearFrom,
            'year_to'      => $yearTo,
            'year_options' => $yearOptions,
            'range_anchor' => $currentYear,
            'min_quantity' => $minQuantity,
            'max_quantity' => $maxQuantity,
            'checkbox_tax' => $checkboxTax,
            'results'      => $results,
            'has_results'  => $hasResults,
            'title'        => trans('sales_by_date'),
        ]);
    }

    /**
     * Vendite per anno in PDF: la stessa matrice della pagina, senza tinte di
     * calore (su carta stampata il grigio non si distingue dal bianco) e in
     * orizzontale, perché due anni sono già dieci colonne.
     */
    private function salesByYearPdf(array $results, int $from, int $to, bool $withTax): void
    {
        $years = range($from, $to);

        $grandTotal    = 0.0;
        $quarterTotals = [];
        foreach ($results as $row) {
            $grandTotal += (float) ($row['total_payment'] ?? 0);
            foreach ($years as $year) {
                for ($q = 1; $q <= 4; $q++) {
                    $key = 'payment_t' . $q . '_' . $year;
                    $quarterTotals[$key] = ($quarterTotals[$key] ?? 0.0) + (float) ($row[$key] ?? 0);
                }
            }
        }

        $period = $from === $to ? (string) $from : $from . '–' . $to;

        $html = '<!DOCTYPE html><html lang="it"><head><meta charset="utf-8">'
            . '<style>'
            . '@page{size:A4 landscape;margin:10mm}'
            . 'body{font-family:DejaVu Sans,Arial,sans-serif;font-size:9px;color:#222}'
            . 'h2{font-size:16px;margin:0 0 4px}'
            . '.sub{color:#555;margin:0 0 12px}'
            . 'table{width:100%;border-collapse:collapse}'
            . 'th,td{border:1px solid #bbb;padding:3px 5px}'
            . 'thead th{background:#eee}'
            . 'th.client,td.client{text-align:left}'
            . 'td.num,th.num{text-align:right}'
            . 'th.year{text-align:center}'
            . 'tfoot td{font-weight:bold;background:#f6f6f6}'
            . '</style></head><body>'
            . '<h2>' . htmlsc(trans('sales_by_date')) . '</h2>'
            . '<p class="sub">' . htmlsc($period)
            . ($withTax ? ' · ' . htmlsc(trans('values_with_taxes')) : '') . '</p>'
            . '<table><thead><tr>'
            . '<th rowspan="2" class="client">' . htmlsc(trans('client')) . '</th>'
            . '<th rowspan="2" class="client">' . htmlsc(trans('vat_id')) . '</th>';

        foreach ($years as $year) {
            $html .= '<th colspan="4" class="year">' . $year . '</th>';
        }
        $html .= '<th rowspan="2" class="num">' . htmlsc(trans('total')) . '</th></tr><tr>';

        foreach ($years as $year) {
            for ($q = 1; $q <= 4; $q++) {
                $html .= '<th class="num">T' . $q . '</th>';
            }
        }
        $html .= '</tr></thead><tbody>';

        if ($results === []) {
            $html .= '<tr><td colspan="' . (2 + count($years) * 4 + 1) . '">'
                . htmlsc(trans('report_no_rows', '', 'Nessun dato nel periodo scelto.')) . '</td></tr>';
        }

        foreach ($results as $row) {
            $html .= '<tr><td class="client">' . htmlsc(format_client($row)) . '</td>'
                . '<td class="client">' . htmlsc($row['VAT_ID'] ?? '') . '</td>';

            foreach ($years as $year) {
                for ($q = 1; $q <= 4; $q++) {
                    $value = (float) ($row['payment_t' . $q . '_' . $year] ?? 0);
                    $html .= '<td class="num">' . ($value > 0 ? format_amount($value) : '&ndash;') . '</td>';
                }
            }

            $html .= '<td class="num"><strong>' . format_amount($row['total_payment'] ?? 0) . '</strong></td></tr>';
        }

        $html .= '</tbody><tfoot><tr><td colspan="2">' . htmlsc(trans('total')) . '</td>';
        foreach ($years as $year) {
            for ($q = 1; $q <= 4; $q++) {
                $value = $quarterTotals['payment_t' . $q . '_' . $year] ?? 0.0;
                $html .= '<td class="num">' . ($value > 0 ? format_amount($value) : '&ndash;') . '</td>';
            }
        }
        $html .= '<td class="num">' . format_amount($grandTotal) . '</td></tr></tfoot></table></body></html>';

        pdf_create($html, 'report_sales_by_year_' . $from . '_' . $to . '.pdf');
    }
}
