<?php

namespace App\Services;

use App\Models\DeliveryNoteModel;
use App\Models\ExpenseModel;
use App\Models\FiscalYearModel;
use App\Models\InvoiceAmountModel;
use App\Models\InvoiceModel;
use App\Models\ProjectModel;
use App\Models\QuoteAmountModel;
use App\Models\QuoteModel;
use App\Models\TaskModel;

/**
 * Dati della Panoramica: totali per stato, KPI, serie mensili, IVA, delta
 * anno su anno, liste recenti.
 *
 * Era il corpo di Dashboard::index, duecentosettanta righe con undici accessi
 * ai model e una ventina di query, tutte dentro un metodo che finiva dritto in
 * una view: i numeri si potevano vedere solo aprendo la pagina. Qui il calcolo
 * ha un ingresso ($year) e un'uscita (l'array per la view), quindi si interroga
 * anche dai test.
 *
 * Le note di ottimizzazione restano valide e sono il motivo per cui il calcolo
 * non è stato riscritto: le liste "recenti" arrivano dai Model con
 * is_overdue/is_expired già in SQL; i totali scaduti sono una sola query
 * COUNT+SUM; projects_enabled si legge una volta; i totali aggregati si
 * calcolano qui e la view non cicla più sulle righe.
 */
class DashboardOverview
{
    /**
     * Tutti i dati della Panoramica per l'anno indicato (0 = tutti gli anni).
     *
     * @return array<string, mixed>
     */
    public function overview(int $year): array
    {

        $invoiceAmountModel = new InvoiceAmountModel();
        $quoteAmountModel   = new QuoteAmountModel();
        $invoiceModel       = new InvoiceModel();
        $quoteModel         = new QuoteModel();

        // Anno del filtro globale in topbar (0 = tutti gli anni): la
        // Panoramica fatture/preventivi segue sempre quell'anno, senza
        // selettori di periodo. L'anno precedente serve ai trend (delta).
        $prevYear = $year > 0 ? $year - 1 : 0;

        // Totali per stato (panoramica breakdown) dell'anno selezionato
        $invoiceStatusTotals = $invoiceAmountModel->getStatusTotalsByYear($year);
        $quoteStatusTotals   = $quoteAmountModel->getStatusTotalsByYear($year);

        // Aggregati dell'anno per le KPI card: totali + conteggio elementi
        // Le bozze non sono fatturato: non sono state emesse, e una nota di
        // credito in bozza toglieva dal "Totale fatturato" una fattura vera
        // (con i dati della simulazione: 381,80 € su 3.022,75 € emessi, e un
        // tasso di incasso del 731%). Il grafico mensile e "Utile e imposte"
        // le escludevano già: i KPI ora dicono la stessa cosa.
        [$invoiceTotal, $invoiceCount] = $this->aggregate($invoiceStatusTotals, [1]);
        [$quoteTotal,   $quoteCount]   = $this->aggregate($quoteStatusTotals);

        // Sintesi fatture scadute (una sola query COUNT+SUM)
        $overdue = $invoiceAmountModel->getOverdueSummary();

        // Saldo totale ancora da incassare su fatture non pagate
        $outstandingTotal = $invoiceAmountModel->getOutstandingTotal();

        // Serie mensile (ultimi 6 mesi) per grafico fatturato/incassato e
        // per le sparkline delle KPI card. Fonti distinte (date di creazione
        // fattura vs data pagamento) gia' adottate dai Model.
        $chartSeries = $invoiceAmountModel->getMonthlySeries(6);
        $quoteSeries = $quoteAmountModel->getMonthlySeries(6);

        // Spese mensili (stessa finestra dei 6 mesi) e utile per mese:
        // fatturato meno spese, la serie che alimenta la sparkline della
        // KPI "Utile di impresa" e il grafico spese.
        $expenseModel   = new ExpenseModel();
        $expenseSeries  = $expenseModel->getMonthlySeries(6);
        $expensesTotal6m = 0.0;
        $incomeNet6m    = 0.0;
        $profitSeries   = [];

        // Le due serie delle imposte servono qui per portare l'utile mensile
        // al netto dell'IVA (lordo meno imposta, la stessa base della card) e
        // vengono riusate più sotto per la finestra IVA: sono già allineate
        // mese per mese a chartSeries/expenseSeries (stessa finestra di 6 mesi).
        $invoiceTaxSeries = $invoiceAmountModel->getMonthlyTaxSeries(6);
        $expenseTaxSeries = $expenseModel->getMonthlyTaxSeries(6);

        foreach ($chartSeries as $i => $row) {
            $expensesGross = (float) ($expenseSeries[$i]['total'] ?? 0);
            $expensesNet   = $expensesGross - (float) ($expenseTaxSeries[$i]['total'] ?? 0);
            $incomeNet     = (float) $row['invoiced'] - (float) ($invoiceTaxSeries[$i]['total'] ?? 0);

            $expensesTotal6m += $expensesGross;
            $incomeNet6m     += $incomeNet;
            $profitSeries[]  = $incomeNet - $expensesNet;
        }

        // Intervallo dell'anno selezionato per spese e IVA (null = tutti
        // gli anni: i metodi interrogati lo trattano come nessun filtro).
        [$from, $to] = $year > 0 ? [$year . '-01-01', $year . '-12-31'] : [null, null];

        $expensesPeriod = $expenseModel->totalsForPeriod($from, $to);
        $expensesTotal  = $expensesPeriod['total'];

        // Composizione del fatturato (imponibile / IVA) sullo stesso periodo:
        // le card "Ricavi" e "Costi" mostrano il lordo come cifra e sotto le
        // due voci che lo compongono, così netto e lordo si leggono insieme
        // invece di lasciare il netto solo dentro "Utile e imposte".
        $invoicePeriod = $invoiceAmountModel->totalsForPeriod($from, $to);
        $invoiceNet    = $invoicePeriod['net'];

        // Utile di impresa al netto dell'IVA: l'IVA non è né ricavo né costo
        // (è debito verso l'Erario, o credito se gli acquisti superano le
        // vendite), quindi sottrarre il lordo speso al lordo fatturato
        // mescolava due posizioni e faceva cambiare l'utile al cambiare
        // dell'aliquota applicata. "Fuori l'IVA" è lo stesso criterio di
        // "Utile e imposte", che però sui ricavi somma l'imponibile delle righe
        // (senza sconti di documento e contributo cassa): qui il netto è lordo
        // meno imposta, così imponibile + IVA tornano col totale della card
        // Ricavi anche in presenza di sconto di documento o cassa.
        // Il margine ha per denominatore i ricavi netti, altrimenti la
        // percentuale non è più quel margine; il confronto con l'anno
        // precedente usa lo stesso criterio.
        $profitTotal    = $invoiceNet - $expensesPeriod['net'];
        $profitMargin   = $invoiceNet > 0 ? $profitTotal / $invoiceNet * 100.0 : 0.0;

        $prevInvoiceTotal = $this->statusTotalSum($invoiceAmountModel, $prevYear, [1]);
        $profitDelta      = null;
        $expensesDelta    = null;
        $ivaDelta         = null;

        // Finestra "IVA" (stile Fattura24): IVA a debito del periodo =
        // IVA sulle vendite meno IVA sugli acquisti, e serie mensile
        // allineata mese per mese per la sparkline.
        // L'IVA in scissione dei pagamenti non e' un debito del venditore
        // (la PA la versa direttamente allo Stato), quindi la "IVA da
        // pagare" la esclude e la mostra a parte come "versata da enti".
        $ivaSalesTax     = $invoiceAmountModel->taxForPeriod($from, $to);
        $ivaSplitTax     = $invoiceAmountModel->taxForPeriod($from, $to, true);
        $ivaPayableTax   = $ivaSalesTax - $ivaSplitTax;
        $ivaPurchaseTax  = $expensesPeriod['tax'];
        $ivaTotal        = $ivaPayableTax - $ivaPurchaseTax;

        if ($prevInvoiceTotal !== null && $prevInvoiceTotal > 0) {
            [$prevFrom, $prevTo] = [$prevYear . '-01-01', $prevYear . '-12-31'];
            $prevExpenses = $expenseModel->totalsForPeriod($prevFrom, $prevTo);
            $prevInvoiceNet = $invoiceAmountModel->totalsForPeriod($prevFrom, $prevTo)['net'];
            $prevProfit   = $prevInvoiceNet - $prevExpenses['net'];
            $profitDelta  = $this->signedPercentDelta($profitTotal, $prevProfit);
            $expensesDelta = $this->percentDelta($expensesTotal, $prevExpenses['total']);

            $prevIvaSales   = $invoiceAmountModel->taxForPeriod($prevFrom, $prevTo, false);
            $prevIvaSplit   = $invoiceAmountModel->taxForPeriod($prevFrom, $prevTo, true);
            $prevIvaPayable = $prevIvaSales - $prevIvaSplit;
            $prevIvaTotal   = $prevIvaPayable - $prevExpenses['tax'];
            $ivaDelta       = $this->signedPercentDelta($ivaTotal, $prevIvaTotal);
        }

        $chartMax = 0.0;
        foreach ($chartSeries as $row) {
            $chartMax = max($chartMax, abs((float) $row['invoiced']), abs((float) $row['paid']));
        }
        $chartMax = $chartMax > 0 ? $chartMax : 1.0;

        $ivaSeries       = [];
        $invoiceSplitSeries = $invoiceAmountModel->getMonthlyTaxSeries(6, true);

        foreach ($invoiceTaxSeries as $i => $row) {
            $payableTax = (float) $row['total'] - (float) ($invoiceSplitSeries[$i]['total'] ?? 0);
            $ivaSeries[] = $payableTax - (float) ($expenseTaxSeries[$i]['total'] ?? 0);
        }

        // Donut composizione stati fattura: label + valore + colore per la
        // legenda, totalizzato a parte (somma di chi ha sum_total > 0).
        $donutData  = [];
        $donutTotal = 0.0;
        foreach ($invoiceStatusTotals as $row) {
            $value = (float) ($row['sum_total'] ?? 0);
            if ($value <= 0) {
                continue;
            }
            // La tinta la decide il CSS dalla classe di stato: qui si passa
            // solo la chiave, così il segmento segue il tema chiaro/scuro
            // insieme ai timbri della stessa tabella.
            $donutData[] = [
                'label'  => $row['label'],
                'value'  => $value,
                'status' => $row['class'],
            ];
            $donutTotal += $value;
        }

        // L'incassato della card resta una cifra di cassa (pagamenti con data
        // nel periodo, la stessa lista del link): il tasso no. Pagamenti
        // dell'anno diviso fatturato dell'anno metteva a rapporto incassi di
        // fatture vecchie con fatture emesse ora, e poteva superare il 100%:
        // il tasso è quindi la quota del fatturato dell'anno già saldata,
        // misurata sul saldo delle sue fatture.
        $paidCurrent     = $invoiceAmountModel->getPaidInYear($year);
        $collectionRate  = $invoiceAmountModel->getCollectedRatioForYear($year);

        // Trend (Delta %) vs anno precedente. null quando l'anno corrente
        // e' "tutti gli anni" (nessun predecessore) o quando il
        // predecessore ha totale zero (delta non significativo).
        $invoiceDelta = $this->percentDelta($invoiceTotal, $prevInvoiceTotal);
        $quoteDelta   = $this->percentDelta(
            $quoteTotal,
            $this->statusTotalSum($quoteAmountModel, $prevYear)
        );

        // Tasso di incasso dell'anno precedente, in punti percentuali.
        $collectionDelta = null;
        if ($prevInvoiceTotal !== null && $prevInvoiceTotal > 0) {
            $collectionDelta = $collectionRate - $invoiceAmountModel->getCollectedRatioForYear($prevYear);
        }

        // Liste "recenti" dai Model: include is_overdue / is_expired in SQL.
        // "Fatture/Preventivi recenti" rispettano il filtro anno globale
        // scelto in topbar (selected_year(), 0 = tutti).
        $invoices = $invoiceModel->getLatest(10, $year);
        $quotes   = $quoteModel->getLatest(10, $year);
        $deliveryNotes = (new DeliveryNoteModel())->getLatest(10, $year);

        // Progetti e attività recenti
        $projectsEnabled = (string) get_setting('projects_enabled', '0') === '1';
        $projects = $projectsEnabled ? (new ProjectModel())->getLatest(5) : [];

        $taskModel = new TaskModel();
        $tasks     = $projectsEnabled ? $taskModel->getLatest(5) : [];
        $openTasks = $taskModel->countOpen();

        // Avviso "anno fiscale non configurato". Il ripiego silenzioso di
        // paramsForYear() è corretto per gli anni storici, ma per l'anno
        // corrente nasconde una dimenticanza: la stima IRPEF/IRES userebbe
        // i parametri dell'anno più vicino, che possono non essere più
        // validi. L'avviso compare solo se la funzione è in uso (almeno un
        // anno configurato) e l'anno corrente non ha la sua riga: senza
        // quella condizione, chi non usa i parametri fiscali vedrebbe un
        // banner permanente e lo ignorerebbe insieme a quelli importanti.
        // Basta sapere se esiste almeno una riga: una pagina da 1 invece
        // dell'intera tabella, e un solo model per le due domande.
        $fiscalYearModel   = new FiscalYearModel();
        $fiscalYearWarning = $fiscalYearModel->getAllOrdered(1) !== []
            && !$fiscalYearModel->findByYear((int) date('Y'));

        $data = [
            'invoice_status_totals' => $invoiceStatusTotals,
            'quote_status_totals'   => $quoteStatusTotals,
            'overview_year'         => $year,
            'overview_year_label'   => $year > 0 ? (string) $year : trans('all_years', '', 'Tutti gli anni'),
            'overview_prev_year'    => $prevYear > 0 ? (string) $prevYear : null,
            'invoices'              => $invoices,
            'quotes'                => $quotes,
            'delivery_notes'        => $deliveryNotes,
            'invoice_statuses'      => $invoiceModel->statuses(),
            'quote_statuses'        => $quoteModel->statuses(),
            'overdue_invoices'      => [], // tenuto per compatibilità view legacy
            'overdue_total'         => $overdue['total'],
            'overdue_count'         => $overdue['count'],
            'invoice_total'         => $invoiceTotal,
            'invoice_net'           => $invoicePeriod['net'],
            'invoice_tax'           => $invoicePeriod['tax'],
            'invoice_count'         => $invoiceCount,
            'quote_total'           => $quoteTotal,
            'quote_count'           => $quoteCount,
            'chart_series'          => $chartSeries,
            'quote_series'          => $quoteSeries,
            'expense_series'        => $expenseSeries,
            'profit_series'         => $profitSeries,
            'expenses_total_6m'     => $expensesTotal6m,
            'profit_total_6m'       => array_sum($profitSeries),
            'income_net_6m'         => $incomeNet6m,
            'expenses_net'          => $expensesPeriod['net'],
            'expenses_total'        => $expensesTotal,
            'expenses_tax'          => $expensesPeriod['tax'],
            'expenses_delta'        => $expensesDelta,
            'profit_total'          => $profitTotal,
            'profit_margin'         => $profitMargin,
            'profit_delta'          => $profitDelta,
            'iva_total'             => $ivaTotal,
            'iva_sales_tax'         => $ivaSalesTax,
            'iva_payable_tax'       => $ivaPayableTax,
            'iva_split_tax'         => $ivaSplitTax,
            'iva_purchase_tax'      => $ivaPurchaseTax,
            'iva_delta'             => $ivaDelta,
            'iva_series'            => $ivaSeries,
            'chart_max'             => $chartMax,
            'donut_data'            => $donutData,
            'donut_total'           => $donutTotal,
            'invoice_delta'         => $invoiceDelta,
            'quote_delta'           => $quoteDelta,
            'collection_delta'      => $collectionDelta,
            'collection_rate'       => $collectionRate,
            'paid_current'          => $paidCurrent,
            'outstanding_total'     => $outstandingTotal,
            'projects'              => $projects,
            'tasks'                 => $tasks,
            'task_statuses'         => $taskModel->statuses(),
            'open_tasks'            => $openTasks,
            'projects_enabled'      => $projectsEnabled,
            'fiscal_year_warning'   => $fiscalYearWarning,
        ];

        return $data;
    }

    private function aggregate(array $statusTotals, array $skipStatuses = []): array
    {
        $sum   = 0.0;
        $count = 0;
        foreach ($statusTotals as $row) {
            if (in_array((int) ($row['invoice_status_id'] ?? $row['quote_status_id'] ?? 0), $skipStatuses, true)) {
                continue;
            }

            $sum   += (float) ($row['sum_total'] ?? 0);
            $count += (int) ($row['num_total'] ?? 0);
        }

        return [$sum, $count];
    }

    private function statusTotalSum($model, int $year, array $skipStatuses = []): ?float
    {
        if ($year <= 0) {
            return null;
        }

        [$sum] = $this->aggregate($model->getStatusTotalsByYear($year), $skipStatuses);

        return (float) $sum;
    }

    private function percentDelta(float $current, ?float $previous): ?float
    {
        if ($previous === null || $previous <= 0) {
            return null;
        }

        return ($current - $previous) / $previous * 100.0;
    }

    /**
     * Delta per grandezze con segno (utile, saldo IVA).
     *
     * percentDelta() scarta un anno precedente <= 0, giusto per i totali di
     * fatturato ma non qui: un anno in perdita o a credito IVA faceva sparire
     * il trend proprio quando il cambiamento e' piu' netto. Il denominatore e'
     * il valore assoluto, cosi' da -2.000 a +5.000 il delta resta positivo.
     * Solo lo zero non ha un confronto percentuale sensato.
     */
    public static function signedPercentDelta(float $current, ?float $previous): ?float
    {
        if ($previous === null || abs($previous) < 0.005) {
            return null;
        }

        return ($current - $previous) / abs($previous) * 100.0;
    }
}
