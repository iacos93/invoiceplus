<?php

namespace App\Controllers;

use App\Services\FinanceExport;
use App\Services\FinanceReport;
use App\Services\FinanceService;

/**
 * Finance controller — entrate/uscite, utile e stima delle imposte.
 *
 * L'assemblatura dei dati sta in FinanceReport, perché la stessa serve
 * all'esportazione del prospetto: scritta due volte sarebbe divergita alla
 * prima modifica, e l'export avrebbe mostrato numeri diversi dalla pagina.
 *
 * IMPORTANTE sulle imposte: quella prodotta qui è una **stima gestionale**,
 * utile per accantonare durante l'anno, non un calcolo fiscale. Le aliquote
 * reali dipendono da variabili che l'applicazione non conosce (altri redditi,
 * deduzioni e detrazioni personali, acconti già versati, minimi contributivi,
 * regimi agevolati con requisiti di accesso). Il calcolo definitivo resta del
 * commercialista: qui si risponde a "quanto sto mettendo da parte".
 */
class Finance extends BaseController
{
    public function index()
    {
        // L'anno viene dal filtro globale in topbar (selected_year, sessione):
        // lo stesso contesto di navigazione di bacheca, fatture e scadenzario.
        $year   = FinanceReport::currentYear();
        $report = (new FinanceReport())->forYear($year);

        // I nomi dei mesi sono calcolati dal service (una sola istanza di
        // IntlDateFormatter invece di 12 dentro il loop della view)
        $report['month_names'] = FinanceService::monthNames(service('request')->getLocale());

        return view('finance/index', $report);
    }

    /**
     * Esportazione del prospetto dell'anno in corso, senza passare dalla
     * pagina Esportazioni: chi sta guardando il prospetto vuole portarselo
     * via subito, non ripetere la scelta del tipo e del periodo.
     *
     *   - csv → il prospetto scalare, stesso tracciato dell'Esportazioni
     *   - pdf → l'intero prospetto impaginato (Dompdf, già in uso per i
     *           documenti): è la forma da girare al commercialista
     */
    public function export(string $format)
    {
        $year   = FinanceReport::currentYear();
        $report = (new FinanceReport())->forYear($year);

        if ($format === 'csv') {
            $export     = new FinanceExport();
            [$headers, $rows] = $export->statementRows($report);

            return $this->response
                ->setContentType('text/csv; charset=UTF-8')
                ->setHeader('Content-Disposition', 'attachment; filename="prospetto_' . $year . '_' . date('Ymd_His') . '.csv"')
                ->setBody($export->csv($headers, $rows));
        }

        if ($format === 'pdf') {
            $report['month_names'] = FinanceService::monthNames(service('request')->getLocale());

            return pdf_create(view('finance/pdf', $report), 'utile_e_imposte_' . $year . '.pdf', true);
        }

        throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
    }
}