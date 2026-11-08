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
            $yearFromPost = (int) $request->getPost('year_from');
            $yearToPost   = (int) $request->getPost('year_to');

            if ($yearFromPost < 1000 || $yearFromPost > 9999 || $yearToPost < 1000 || $yearToPost > 9999
                || $yearFromPost > $yearToPost) {
                session()->setFlashdata('alert_error', 'Gli anni inseriti non sono validi');
                return redirect()->to('/reports/sales_by_year');
            }

            $yearFrom    = $yearFromPost;
            $yearTo      = $yearToPost;
            $minQuantity = $request->getPost('minQuantity') !== null && $request->getPost('minQuantity') !== ''
                ? (int) $request->getPost('minQuantity') : null;
            $maxQuantity = $request->getPost('maxQuantity') !== null && $request->getPost('maxQuantity') !== ''
                ? (int) $request->getPost('maxQuantity') : null;
            $checkboxTax = (bool) $request->getPost('checkboxTax');

            $results    = (new ReportModel())->salesByYear($yearFrom, $yearTo, $minQuantity, $maxQuantity, $checkboxTax);
            $hasResults = true;
        }

        if (!$hasResults) {
            $results    = (new ReportModel())->salesByYear($yearFrom, $yearTo, null, null, false);
            $hasResults = true;
        }

        return view('reports/sales_by_year_index', [
            'year_from'    => $yearFrom,
            'year_to'      => $yearTo,
            'min_quantity' => $minQuantity,
            'max_quantity' => $maxQuantity,
            'checkbox_tax' => $checkboxTax,
            'results'      => $results,
            'has_results'  => $hasResults,
            'title'        => trans('sales_by_date'),
        ]);
    }
}