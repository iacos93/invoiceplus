<?php

namespace App\Controllers;

use App\Libraries\CsvWriter;
use App\Models\ExpenseModel;
use App\Models\InvoiceModel;
use App\Models\ItemModel;
use App\Models\PaymentModel;
use App\Models\QuoteModel;
use App\Services\ContactExport;
use App\Services\FinanceExport;
use App\Services\FinanceReport;

/**
 * Exports controller — estrazione dei dati per commercialista e archivio.
 *
 * Tre formati, perché rispondono a tre esigenze diverse:
 *   - CSV     → si apre in Excel, serve per controlli e invio al commercialista
 *   - ZIP PDF → l'archivio dei documenti come sono stati inviati al cliente
 *   - ZIP XML → i file FatturaPA, quelli che fanno fede
 *
 * Il periodo si filtra in SQL (getInPeriod() sui modelli), non in PHP: prima
 * l'estrazione di un trimestre leggeva comunque l'intero archivio con tutti i
 * join e scartava dopo. L'archivio ZIP viene servito dal file temporaneo, non
 * ricopiato in memoria: un anno di PDF sono centinaia di megabyte e
 * file_get_contents() li chiedeva tutti insieme.
 */
class Exports extends BaseController
{
    public function index()
    {
        // Anni dell'archivio dal piu' recente e senza doppioni: era un
        // array_merge non ordinato (prima le fatture, poi i preventivi, poi
        // l'anno corrente), quindi i pulsanti dei periodi rapidi cambiavano
        // ordine da soli e l'anno in corso poteva finire in mezzo.
        $years = array_values(array_unique(array_map(
            static fn ($year): int => (int) $year,
            array_merge(
                (new InvoiceModel())->getAvailableYears(),
                (new QuoteModel())->getAvailableYears(),
                [(int) date('Y')]
            )
        )));
        rsort($years);

        return view('exports/index', ['years' => $years]);
    }

    public function run()
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $type   = (string) $request->getPost('export_type');
        $format = (string) $request->getPost('export_format');
        $from   = $this->parseDate((string) $request->getPost('date_from'));
        $to     = $this->parseDate((string) $request->getPost('date_to'));

        $allowed = [
            'invoices', 'invoice_items', 'quotes', 'payments',
            'clients', 'suppliers', 'expenses',
            'finance', 'finance_months', 'finance_categories',
        ];

        if (!in_array($type, $allowed, true)) {
            session()->setFlashdata('alert_error', trans('export_invalid_type', '', 'Tipo di esportazione non valido'));

            return redirect()->to('/exports');
        }

        if ($format === 'pdf' || $format === 'xml') {
            return $this->exportArchive($type, $format, $from, $to);
        }

        return $this->exportCsv($type, $from, $to);
    }

    /* ------------------------------------------------ esportazioni rapide */

    /**
     * Fatture in XLS con il periodo della lista (anno + mesi selezionati).
     *
     * Sono GET e non POST perché il bottone in coda alla lista deve portarsi
     * dietro anno e mesi senza un form: il periodo è già nell'URL della
     * pagina, e ricompilarlo a mano sarebbe un secondo modo di sceglierlo.
     */
    public function quickInvoices()
    {
        [$year, $months, $sign] = $this->quickPeriod();

        [$headers, $rows] = $this->dataFor('invoices', null, null, $year, $months, $sign);

        return $this->xlsResponse('fatture', 'Fatture', $headers, $rows);
    }

    /** Spese in XLS con lo stesso periodo della lista. */
    public function quickExpenses()
    {
        [$year, $months, $sign] = $this->quickPeriod();

        [$headers, $rows] = $this->dataFor('expenses', null, null, $year, $months, $sign);

        return $this->xlsResponse('spese', 'Spese', $headers, $rows);
    }

    /** Archivio ZIP degli XML FatturaPA del periodo mostrato. */
    public function quickXml()
    {
        [$year, $months, $sign] = $this->quickPeriod();

        return $this->archiveInvoices($this->invoicesInPeriod(null, null, $year, $months, $sign), 'xml');
    }

    /**
     * Periodo delle esportazioni rapide: anno della topbar e mesi della barra
     * periodo. year=0 significa "tutti gli anni": in quel caso i mesi valgono
     * su tutto l'archivio, esattamente come nella lista.
     *
     * @return array{0: ?int, 1: list<int>, 2: string}
     */
    private function quickPeriod(): array
    {
        $request = service('request');
        $yearGet = $request->getGet('year');
        $year    = $yearGet !== null ? (int) $yearGet : selected_year();

        // Tipo documento: il file deve contenere le stesse righe della lista
        // da cui è partito il bottone. 'credit' esporta solo le note di
        // credito, 'invoice' solo le fatture/spese normali.
        $sign = (string) ($request->getGet('sign') ?? '');
        $sign = in_array($sign, ['invoice', 'credit'], true) ? $sign : '';

        return [$year > 0 ? $year : null, request_months(), $sign];
    }

    /* ---------------------------------------------------------------- XLS */

    private function xlsResponse(string $type, string $sheet, array $headers, array $rows)
    {
        $filename = 'export_' . $type . '_' . date('Ymd_His') . '.xls';

        return $this->response
            ->setContentType('application/vnd.ms-excel; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($this->buildXls($sheet, $headers, $rows));
    }

    /**
     * SpreadsheetML 2003: un XML che Excel apre come cartella di lavoro.
     *
     * Scelto al posto del CSV perché il file deve chiamarsi .xls e contenere
     * numeri veri: con il CSV le colonne importo arrivavano come testo e le
     * somme in fondo al foglio non si potevano fare. I valori arrivano già
     * formattati in italiano (virgola decimale), quindi quelli con due
     * decimali tornano Number con il punto decimale; date, numeri documento e
     * codici restano String per non trasformare "01/02/2026" in una data
     * americana o perdere gli zeri iniziali.
     */
    private function buildXls(string $sheet, array $headers, array $rows): string
    {
        $escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $cell = static function ($value) use ($escape): string {
            $text = (string) $value;

            if (preg_match('/^-?\d+,\d{2}$/', $text)) {
                return '<Cell><Data ss:Type="Number">' . str_replace(',', '.', $text) . '</Data></Cell>';
            }

            return '<Cell><Data ss:Type="String">' . $escape($text) . '</Data></Cell>';
        };

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
             . '<?mso-application progid="Excel.Sheet"?>' . "\n"
             . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
             . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
             . '<Worksheet ss:Name="' . $escape($sheet) . '"><Table>';

        $xml .= '<Row>';
        foreach ($headers as $header) {
            $xml .= '<Cell><Data ss:Type="String">' . $escape($header) . '</Data></Cell>';
        }
        $xml .= '</Row>';

        foreach ($rows as $row) {
            $xml .= '<Row>';
            foreach ($row as $value) {
                $xml .= $cell($value);
            }
            $xml .= '</Row>';
        }

        return $xml . '</Table></Worksheet></Workbook>';
    }

    /* ---------------------------------------------------------------- CSV */

    private function exportCsv(string $type, ?string $from, ?string $to)
    {
        [$headers, $rows] = $this->dataFor($type, $from, $to);

        $filename = 'export_' . $type . '_' . date('Ymd_His') . '.csv';

        return $this->response
            ->setContentType('text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($this->buildCsv($headers, $rows));
    }

    private function buildCsv(array $headers, array $rows): string
    {
        // Scrittura condivisa con l'export dell'anagrafica e con i modelli
        // d'importazione: la copia locale di queste righe era una delle cinque
        // che potevano divergere e rendere un file non reimportabile.
        return CsvWriter::toString($headers, $rows);
    }

    /**
     * Intestazioni e righe per il tipo richiesto.
     *
     * $year e $months servono alle esportazioni rapide dalle liste: lì il
     * periodo non è un intervallo di date scelto a mano ma l'anno della topbar
     * più i mesi selezionati nella barra periodo (anche multipli).
     *
     * @param list<int> $months
     *
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    private function dataFor(string $type, ?string $from, ?string $to, ?int $year = null, array $months = [], string $sign = ''): array
    {
        // Il prospetto è annuale per natura — scaglioni IRPEF e accantonamenti
        // lo sono — quindi non segue l'intervallo libero degli altri tipi: si
        // prende l'anno della data iniziale, e in mancanza quello in cui si sta
        // lavorando.
        if (str_starts_with($type, 'finance')) {
            $year   = $from !== null ? (int) substr($from, 0, 4) : FinanceReport::currentYear();
            $report = (new FinanceReport())->forYear($year);

            // Le colonne e l'ordine delle voci stanno in FinanceExport: la
            // stessa struttura serve al bottone di esportazione sulla pagina
            // "Utile e imposte", e scritta due volte avrebbe divergito.
            $export = new FinanceExport();

            return match ($type) {
                'finance_months'     => $export->monthsRows($report),
                'finance_categories' => $export->categoriesRows($report),
                default              => $export->statementRows($report),
            };
        }

        switch ($type) {
            case 'invoices':
                $rows = [];
                foreach ($this->invoicesInPeriod($from, $to, $year, $months, $sign) as $invoice) {
                    $rows[] = [
                        $invoice['invoice_number'] ?: ('#' . $invoice['invoice_id']),
                        date_from_mysql($invoice['invoice_date_created']),
                        date_from_mysql($invoice['invoice_date_due']),
                        format_client($invoice),
                        $invoice['client_vat_id'] ?? '',
                        $this->statusLabel((new InvoiceModel())->statuses(), $invoice['invoice_status_id']),
                        $this->number($invoice['invoice_item_subtotal'] ?? 0),
                        $this->number(($invoice['invoice_item_tax_total'] ?? 0) + ($invoice['invoice_tax_total'] ?? 0)),
                        $this->number($invoice['invoice_total'] ?? 0),
                        $this->number($invoice['invoice_paid'] ?? 0),
                        $this->number($invoice['invoice_balance'] ?? 0),
                    ];
                }

                return [[
                    trans('invoice', '', 'Numero'), trans('date', '', 'Data'), trans('due_date', '', 'Scadenza'),
                    trans('client', '', 'Cliente'), trans('vat_id', '', 'P. IVA'), trans('status', '', 'Stato'),
                    trans('subtotal', '', 'Imponibile'), trans('vat', '', 'IVA'), trans('total', '', 'Totale'),
                    trans('paid', '', 'Pagato'), trans('balance', '', 'Saldo'),
                ], $rows];

            case 'invoice_items':
                // Righe di dettaglio: serve a chi deve analizzare cosa è stato
                // venduto, non solo quanto è stato fatturato.
                $invoices = $this->invoicesInPeriod($from, $to, $year, $months, $sign);
                $itemsBy  = (new ItemModel())->getByInvoices(array_column($invoices, 'invoice_id'));
                $rows     = [];

                foreach ($invoices as $invoice) {
                    foreach ($itemsBy[(int) $invoice['invoice_id']] ?? [] as $item) {
                        $rows[] = [
                            $invoice['invoice_number'] ?: ('#' . $invoice['invoice_id']),
                            date_from_mysql($invoice['invoice_date_created']),
                            format_client($invoice),
                            $item['item_name'] ?? '',
                            $item['item_description'] ?? '',
                            $this->number($item['item_quantity'] ?? 0),
                            $this->number($item['item_price'] ?? 0),
                            $this->number($item['item_discount'] ?? 0),
                            $this->number($item['item_subtotal'] ?? 0),
                            $this->number($item['item_tax_total'] ?? 0),
                            $this->number($item['item_total'] ?? 0),
                        ];
                    }
                }

                return [[
                    trans('invoice', '', 'Numero'), trans('date', '', 'Data'), trans('client', '', 'Cliente'),
                    trans('item', '', 'Articolo'), trans('description', '', 'Descrizione'),
                    trans('quantity', '', 'Qtà'), trans('price', '', 'Prezzo'), trans('discount', '', 'Sconto'),
                    trans('subtotal', '', 'Imponibile'), trans('vat', '', 'IVA'), trans('total', '', 'Totale'),
                ], $rows];

            case 'quotes':
                $rows = [];
                foreach ($this->quotesInPeriod($from, $to, $year, $months) as $quote) {
                    $rows[] = [
                        $quote['quote_number'] ?: ('#' . $quote['quote_id']),
                        date_from_mysql($quote['quote_date_created']),
                        date_from_mysql($quote['quote_date_expires']),
                        format_client($quote),
                        $this->statusLabel((new QuoteModel())->statuses(), $quote['quote_status_id']),
                        $this->number($quote['quote_item_subtotal'] ?? 0),
                        $this->number(($quote['quote_item_tax_total'] ?? 0) + ($quote['quote_tax_total'] ?? 0)),
                        $this->number($quote['quote_total'] ?? 0),
                        $quote['invoice_number'] ?? '',
                    ];
                }

                return [[
                    trans('quote', '', 'Numero'), trans('date', '', 'Data'), trans('expires', '', 'Scadenza'),
                    trans('client', '', 'Cliente'), trans('status', '', 'Stato'),
                    trans('subtotal', '', 'Imponibile'), trans('vat', '', 'IVA'), trans('total', '', 'Totale'),
                    trans('invoice', '', 'Fattura'),
                ], $rows];

            case 'payments':
                $rows = [];
                foreach ((new PaymentModel())->getInPeriod($from, $to) as $payment) {
                    $rows[] = [
                        date_from_mysql($payment['payment_date']),
                        $payment['invoice_number'] ?? '',
                        format_client($payment),
                        $payment['payment_method_name'] ?? '',
                        $payment['payment_note'] ?? '',
                        $this->number($payment['payment_amount'] ?? 0),
                    ];
                }

                return [[
                    trans('date', '', 'Data'), trans('invoice', '', 'Fattura'), trans('client', '', 'Cliente'),
                    trans('payment_method', '', 'Metodo'), trans('note', '', 'Nota'), trans('amount', '', 'Importo'),
                ], $rows];

            case 'expenses':
                // Ciclo passivo: imponibile, IVA e totale restano colonne
                // separate, come nella lista spese. Il saldo aperto è
                // totale - pagato, così una nota di credito ricevuta resta
                // leggibile anche quando i due segni si annullano.
                $rows = [];
                foreach ((new ExpenseModel())->getInPeriod($from, $to, $year, $months, $sign) as $expense) {
                    $rows[] = [
                        date_from_mysql($expense['expense_date']),
                        $expense['expense_number'] ?: ('#' . $expense['expense_id']),
                        $expense['supplier_name'] ?? '',
                        $expense['supplier_vat'] ?? '',
                        $expense['expense_category_name'] ?? '',
                        $this->number($expense['expense_amount'] ?? 0),
                        $this->number($expense['expense_tax'] ?? 0),
                        $this->number($expense['expense_total'] ?? 0),
                        $this->number($expense['expense_paid'] ?? 0),
                        $this->number(($expense['expense_total'] ?? 0) - ($expense['expense_paid'] ?? 0)),
                    ];
                }

                return [[
                    trans('date', '', 'Data'), trans('number', '', 'Numero'),
                    trans('supplier', '', 'Fornitore'), trans('vat_id', '', 'P. IVA'),
                    trans('category', '', 'Categoria'),
                    trans('subtotal', '', 'Imponibile'), trans('vat', '', 'IVA'),
                    trans('total', '', 'Totale'), trans('paid', '', 'Pagato'),
                    trans('balance', '', 'Saldo'),
                ], $rows];

            case 'clients':
                return (new ContactExport())->clients();

            case 'suppliers':
            default:
                // La whitelist in run() ha gia' scartato tutto il resto: se un
                // tipo nuovo arrivasse fin qui senza il suo case, uscirebbe
                // l'anagrafica fornitori al posto suo, in silenzio.
                return (new ContactExport())->suppliers();
        }
    }

    /* -------------------------------------------------------- ZIP PDF/XML */

    private function exportArchive(string $type, string $format, ?string $from, ?string $to)
    {
        // PDF e XML esistono solo per le fatture: sui preventivi l'XML non ha
        // senso, e il resto non è un documento.
        if ($type !== 'invoices') {
            session()->setFlashdata('alert_error', trans('export_format_unavailable', '', 'PDF e XML sono disponibili solo per le fatture.'));

            return redirect()->to('/exports');
        }

        return $this->archiveInvoices($this->invoicesInPeriod($from, $to), $format);
    }

    /**
     * Costruisce e invia lo ZIP di PDF o XML per le fatture ricevute.
     *
     * Estratto da exportArchive() perché anche l'esportazione rapida dalla
     * lista ("Archivio XML fatture") deve produrre lo stesso archivio, ma con
     * il periodo preso da anno + mesi invece che da due date.
     *
     * @param list<array<string, mixed>> $invoices
     */
    private function archiveInvoices(array $invoices, string $format)
    {
        if (!$invoices) {
            session()->setFlashdata('alert_error', trans('export_empty', '', 'Nessun documento nel periodo selezionato.'));

            return redirect()->to('/exports');
        }

        // Il file temporaneo nasce sotto writable/: sys_get_temp_dir() e'
        // condivisa con il resto della macchina, e su un hosting multi-utente
        // ci finivano fatture leggibili da chiunque abbia una shell.
        $tmpDir = WRITEPATH . 'temp/';

        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            session()->setFlashdata('alert_error', trans('export_failed', '', 'Creazione dell\'archivio non riuscita.'));

            return redirect()->to('/exports');
        }

        $tmpFile = (string) tempnam($tmpDir, 'ipexport');
        $zip     = new \ZipArchive();

        if ($zip->open($tmpFile, \ZipArchive::OVERWRITE) !== true) {
            session()->setFlashdata('alert_error', trans('export_failed', '', 'Creazione dell\'archivio non riuscita.'));

            return redirect()->to('/exports');
        }

        $controller = new Invoices();
        $added      = 0;

        foreach ($invoices as $invoice) {
            $invoiceId = (int) $invoice['invoice_id'];
            $name      = preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string) ($invoice['invoice_number'] ?: $invoiceId));

            try {
                if ($format === 'pdf') {
                    // false = non inviare al browser: qui il PDF ci serve come byte.
                    $content = $controller->generatePdf($invoiceId, false);
                    $zip->addFromString('Fattura_' . $name . '.pdf', (string) $content);
                } else {
                    $content = $controller->generateXml($invoiceId, false);

                    if ($content === null || $content === '') {
                        continue;
                    }

                    $zip->addFromString('Fattura_' . $name . '.xml', (string) $content);
                }

                $added++;
            } catch (\Throwable $e) {
                // Un documento che non si genera (dati incompleti per l'XML)
                // non deve far fallire l'intero archivio: si salta e si
                // prosegue, il conteggio finale dirà quanti ne sono usciti.
                log_message('error', 'Export ' . $format . ' fattura ' . $invoiceId . ': ' . $e->getMessage());
            }
        }

        $zip->close();

        if ($added === 0) {
            @unlink($tmpFile);
            session()->setFlashdata('alert_error', trans('export_empty', '', 'Nessun documento esportabile nel periodo selezionato.'));

            return redirect()->to('/exports');
        }

        $filename = 'fatture_' . $format . '_' . date('Ymd_His') . '.zip';

        // L'archivio si spedisce leggendolo dal disco: file_get_contents() ne
        // caricava una copia intera in memoria, e un anno di PDF supera senza
        // fatica il memory_limit tipico di un hosting condiviso. La
        // cancellazione va in shutdown perche' il file deve restare leggibile
        // fino a fine invio.
        register_shutdown_function(static function () use ($tmpFile) {
            @unlink($tmpFile);
        });

        return $this->response
            ->download($tmpFile, null)
            ->setFileName($filename)
            ->setContentType('application/zip');
    }

    /* ------------------------------------------------------------- helper */

    /** Estremi inclusi: "dal 1° al 31 gennaio" deve contenere il 31. */
    private function invoicesInPeriod(?string $from, ?string $to, ?int $year = null, array $months = [], string $sign = ''): array
    {
        return (new InvoiceModel())->getInPeriod($from, $to, $year, $months, $sign);
    }

    private function quotesInPeriod(?string $from, ?string $to, ?int $year = null, array $months = []): array
    {
        return (new QuoteModel())->getInPeriod($from, $to, $year, $months);
    }

    /** Data dal formato utente a Y-m-d; null se vuota o non valida. */
    private function parseDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $mysql = date_to_mysql($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $mysql) ? $mysql : null;
    }

    /**
     * Importo con la virgola decimale: il CSV è destinato a Excel in locale
     * italiano, dove "1234.56" verrebbe letto come testo o come 123456.
     */
    private function number($amount): string
    {
        return number_format((float) $amount, 2, ',', '');
    }

    private function statusLabel(array $statuses, $statusId): string
    {
        return (string) ($statuses[(string) $statusId]['label'] ?? $statuses[(int) $statusId]['label'] ?? '');
    }
}
