<?php

namespace App\Controllers;

use App\Models\QuoteModel;
use App\Models\QuoteItemModel;
use App\Models\QuoteTaxRateModel;
use App\Models\QuoteCustomModel;
use App\Models\TaxRateModel;
use App\Models\UnitModel;
use App\Models\ClientModel;
use App\Models\InvoiceGroupModel;
use App\Models\QuoteAmountModel;
use App\Services\DocumentPdfBuilder;

/**
 * Quotes controller
 *
 * Porting CI4 del controller Quotes (CI3).
 * Stessa logica applicativa: index (redirect a status/all), status
 * (lista filtrata), view (dettaglio con righe, tasse e status),
 * delete, generate_pdf / preview_pdf, delete_quote_tax,
 * recalculate_all_quotes.
 */
class Quotes extends BaseController
{
    protected QuoteModel $quoteModel;

    public function __construct()
    {
        $this->quoteModel = new QuoteModel();
    }

    public function index()
    {
        return redirect()->to('/quotes/status/all');
    }

    public function status(string $status = 'all')
    {
        // Periodo dalla query string, come per le fatture (?year=&month=).
        // year assente in URL → filtro anno globale della topbar come default.
        $request = service('request');
        $yearGet = $request->getGet('year');
        $year    = $yearGet !== null ? (int) $yearGet : selected_year();
        $months  = request_months();
        $month   = count($months) === 1 ? $months[0] : 0;

        // Preferiti: '' tutti, '1' solo preferiti, '0' solo non preferiti.
        $favourite = (string) ($request->getGet('fav') ?? '');
        $favourite = in_array($favourite, ['0', '1'], true) ? $favourite : '';

        $quotes = $this->quoteModel->getAllWithStatus($status, $year, $months, $favourite);

        $data = [
            'quotes'         => $quotes,
            'status'         => $status,
            'quote_statuses' => $this->quoteModel->statuses(),
            'years'          => $this->quoteModel->getAvailableYears(),
            'year'           => $year,
            'month'          => $month,
            'months'         => $months,
            'favourite'      => $favourite,
            // I preventivi non hanno saldo: conteggio, imponibile, IVA e totale.
            // Aggregati in SQL come per fatture e spese, così la barra periodo
            // parla dello stesso perimetro della lista.
            'period_totals'  => $this->quoteModel->getPeriodTotals($status, $year, $months, $favourite),
            'month_totals'   => $this->quoteModel->getMonthlyTotals($status, $year, $favourite),
            // Ultimo invio per riga: la lista dice se l'email è stata aperta,
            // con una sola query per pagina (vedi email_track_note()).
            'email_map'      => (new \App\Models\EmailTrackModel())
                ->latestMap('quote', array_column($quotes, 'quote_id')),
        ];

        return view('quotes/index', $data);
    }

    /**
     * Segna/desegna il preventivo come preferito e torna da dove si è arrivati.
     */
    public function toggleFavourite(int $quoteId)
    {
        if ($this->request->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $quote = $this->quoteModel->findScoped($quoteId);

        if (!$quote) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $this->quoteModel->update($quoteId, [
            'quote_is_favourite' => empty($quote['quote_is_favourite']),
        ]);

        $back = $this->request->getPost('back');

        return redirect()->to(ip_back_url('quotes', $back));
    }

    /**
     * Form di creazione preventivo (POST a /quotes/ajax/create).
     */
    public function formCreate()
    {
        $igModel = new InvoiceGroupModel();
        $allGroups = $igModel->getAllOrdered();
        $quoteGroupId      = (int) company_setting('default_quote_group', 0);
        $draftQuoteGroupId = (int) company_setting('draft_quote_group', 0);
        $quoteGroups = array_filter($allGroups, fn ($g) => in_array((int) $g['invoice_group_id'], [$quoteGroupId, $draftQuoteGroupId], true));

        $data = [
            'clients'         => (new ClientModel())->getAllOrdered(),
            'invoice_groups'  => $quoteGroups ?: $allGroups,
            // Aliquote per il select IVA delle righe del documento.
            'tax_rates'       => (new TaxRateModel())->getAllOrdered(),
            // Unità di misura selezionabili sulla riga (dopo la quantità).
            'unit_groups'     => (new UnitModel())->getGroupedByType(),
            // Preselezione cliente da ?client_id=, usata dal bottone
            // "Nuova fattura"/"Nuovo preventivo" nella scheda cliente.
            'selected_client' => (int) (service('request')->getGet('client_id') ?? 0),
        ];

        return view('quotes/form_create', $data);
    }

    /**
     * Vista principale del preventivo: anteprima del foglio, come verrà
     * scaricato o inviato. La modifica sta su /quotes/edit.
     */
    public function view(int $quoteId)
    {
        $quote = $this->quoteModel->getByIdScoped($quoteId);

        if (!$quote) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        return view('quotes/preview', [
            'quote'          => $quote,
            'quote_id'       => $quoteId,
            'quote_statuses' => $this->quoteModel->statuses(),
            'email_tracks'   => (new \App\Models\EmailTrackModel())->latestFor('quote', $quoteId),
        ]);
    }

    public function edit(int $quoteId)
    {
        $quote = $this->quoteModel->getByIdScoped($quoteId);

        if (!$quote) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $data = [
            'quote'           => $quote,
            'quote_id'        => $quoteId,
            'items'           => (new QuoteItemModel())->getByQuote($quoteId),
            'tax_rates'       => (new TaxRateModel())->getAllOrdered(),
            'unit_groups'     => (new UnitModel())->getGroupedByType(),
            'quote_tax_rates' => (new QuoteTaxRateModel())->getByQuote($quoteId),
            'quote_statuses'  => $this->quoteModel->statuses(),
            'clients'         => (new ClientModel())->getAllOrdered(),
            'custom_fields'   => (new QuoteCustomModel())->getValues($quoteId),
        ];

        return view('quotes/edit', $data);
    }

    public function delete(int $quoteId)
    {
        if ($this->request->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Il gemello Invoices::delete() legge la fattura prima di cancellarla;
        // qui la lettura mancava del tutto e l'id dell'URL andava dritto alla
        // delete, cancellando il preventivo di qualunque azienda.
        if (!$this->quoteModel->findScoped($quoteId)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $this->quoteModel->delete($quoteId);
        session()->setFlashdata('alert_success', 'Preventivo eliminato correttamente');

        return redirect()->to('/quotes');
    }

    /**
     * Azioni di gruppo dalla lista (preferiti, segna come inviato, elimina).
     *
     * La barra era gia' nella vista, ma la rotta e il metodo mancavano: ogni
     * pulsante di gruppo rispondeva 404. Stesso schema di Invoices::bulk() —
     * il modulo contiene solo la barra, le caselle di riga gli appartengono con
     * l'attributo HTML5 "form".
     */
    public function bulk()
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $action = (string) $request->getPost('bulk_action');
        $ids    = array_values(array_unique(array_filter(array_map('intval', (array) ($request->getPost('ids') ?? [])))));
        $back   = (string) ($request->getPost('back') ?? '');

        if (!in_array($action, ['favourite_on', 'favourite_off', 'mark_sent', 'delete'], true) || $ids === []) {
            session()->setFlashdata('alert_error', trans('bulk_nothing_selected_quote', '', 'Nessun preventivo selezionato.'));

            return redirect()->to(ip_back_url('quotes', $back));
        }

        $done    = 0;
        $skipped = 0;

        foreach ($ids as $quoteId) {
            // Perimetro azienda prima di toccare la riga: gli id arrivano da un
            // POST, e senza findScoped() si modificherebbe il preventivo di un
            // altro soggetto giuridico.
            $quote = $this->quoteModel->findScoped($quoteId);

            if (!$quote) {
                $skipped++;
                continue;
            }

            switch ($action) {
                case 'favourite_on':
                case 'favourite_off':
                    $this->quoteModel->update($quoteId, [
                        'quote_is_favourite' => $action === 'favourite_on',
                    ]);
                    $done++;
                    break;

                case 'mark_sent':
                    // Solo bozze: markSent() non fa nulla sugli altri stati, e
                    // contarli come fatti sarebbe una bugia nel riepilogo.
                    if ((int) $quote['quote_status_id'] !== 1) {
                        $skipped++;
                        break;
                    }

                    // Le stesse due chiamate del PDF (generatePdf): prima il
                    // numero definitivo, poi il passaggio a "inviato".
                    $this->quoteModel->generateQuoteNumberIfApplicable($quoteId);
                    $this->quoteModel->markSent($quoteId);
                    $done++;
                    break;

                case 'delete':
                    // QuoteModel::delete() elimina anche righe, importi, tasse e
                    // campi custom: non serve altro da qui.
                    $this->quoteModel->delete($quoteId);
                    $done++;
                    break;
            }
        }

        // Il riepilogo dice sempre quante righe sono state saltate: in
        // un'azione di massa il silenzio su cio' che non e' stato fatto e' il
        // modo piu' rapido per convincersi che sia stato fatto tutto.
        $labels = [
            'favourite_on'  => trans('bulk_done_favourite_on_quote', '', 'aggiunti ai preferiti'),
            'favourite_off' => trans('bulk_done_favourite_off_quote', '', 'tolti dai preferiti'),
            'mark_sent'     => trans('bulk_done_mark_sent_quote', '', 'segnati come inviati'),
            'delete'        => trans('bulk_done_delete_quote', '', 'eliminati'),
        ];

        if ($done > 0) {
            $msg = $done . ' ' . trans('quotes', '', 'preventivi') . ' ' . $labels[$action];

            if ($skipped > 0) {
                $msg .= ' — ' . $skipped . ' ' . trans('bulk_skipped', '', 'saltate perché non ammesse per questa azione');
            }

            session()->setFlashdata('alert_success', $msg);
        } else {
            session()->setFlashdata('alert_error',
                trans('bulk_none_eligible_quote', '', 'Nessuno dei preventivi selezionati era ammesso per questa azione.'));
        }

        return redirect()->to(ip_back_url('quotes', $back));
    }

    /**
     * Genera il PDF del preventivo (equivalente di generate_pdf() CI3).
     * Se mark_quotes_sent_pdf e' attivo genera il numero e marca come inviato.
     */
    public function generatePdf(int $quoteId, bool $stream = true, ?string $template = null)
    {
        // Stesso motivo di Invoices::generatePdf(): mark_quotes_sent_pdf
        // numera e marca come inviato prima del rendering, quindi il perimetro
        // va verificato qui e non solo dentro renderQuotePdf().
        if (!$this->quoteModel->getByIdScoped($quoteId)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        if ((int) get_setting('mark_quotes_sent_pdf') === 1) {
            $this->quoteModel->generateQuoteNumberIfApplicable($quoteId);
            $this->quoteModel->markSent($quoteId);
        }

        return $this->renderQuotePdf($quoteId, $stream, $template);
    }

    /**
     * Anteprima HTML del preventivo (custom IT): come generatePdf ma senza
     * marcare come inviato e senza generare il PDF.
     */
    public function previewPdf(int $quoteId)
    {
        // Anteprima a schermo: il logo va referenziato via http (vedi
        // invoice_logo_tag), altrimenti il browser mostra un'immagine rotta.
        $GLOBALS['pdf_preview'] = true;

        $html = $this->renderQuotePdf($quoteId, false, null, true);

        // Anteprima nell'iframe, non PDF: la classe tiene il foglio un A4 vero
        // e layout/preview_sheet.php lo divide in fogli e lo scala alla
        // larghezza del riquadro (vedi html.ip-sheet-embedded in
        // layout/pdf_styles.php). Il percorso PDF (renderQuotePdf con
        // $preview = false) non passa di qui.
        return str_replace('<html ', '<html class="ip-sheet-embedded" ', $html);
    }

    /**
     * Raccoglie i dati e renderizza il template PDF del preventivo.
     */
    private function renderQuotePdf(int $quoteId, bool $stream, ?string $template, bool $preview = false)
    {
        // getByIdScoped() invece di find(): serve il join con ip_companies per
        // i dati dell'emittente (vedi QuoteModel::pdfUser()).
        $quote = $this->quoteModel->getByIdScoped($quoteId);

        if (!$quote) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Composizione condivisa con portale cliente, link pubblico e Mailer
        // (vedi DocumentPdfBuilder): l'allegato email non puo' divergere dal PDF
        // scaricato.
        $html = (new DocumentPdfBuilder())->quote($quote, $template);

        if ($preview) {
            return $html;
        }

        // La bozza puo' non avere ancora un numero: senza il ripiego sull'id
        // str_replace() riceveva null (deprecazione PHP 8.1+) e il file si
        // chiamava solo "Preventivo_".
        $filename = (new DocumentPdfBuilder())->quoteFilename($quote);

        return pdf_create($html, $filename, $stream);
    }
    /**
     * Elimina una tassa preventivo e ricalcola gli importi
     * (equivalente di delete_quote_tax() CI3).
     */
    public function deleteQuoteTax(int $quoteId, int $quoteTaxRateId)
    {
        // Stessa ragione di Invoices::deleteInvoiceTax(): deleteTaxRate()
        // cancella per id, quindi il quote_id dell'URL da solo non verifica
        // nulla. Perimetro azienda e appartenenza dell'imposta al preventivo.
        $taxRateModel = new QuoteTaxRateModel();
        $taxRate      = $taxRateModel->find($quoteTaxRateId);

        if (!$taxRate
            || (int) $taxRate['quote_id'] !== $quoteId
            || !$this->quoteModel->findScoped($quoteId)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $taxRateModel->deleteTaxRate($quoteTaxRateId, $quoteId);

        session()->setFlashdata('alert_success', 'Tassa eliminata correttamente');

        return redirect()->to('/quotes/view/' . $quoteId);
    }

    /**
     * Ricalcola gli importi di tutti i preventivi
     * (equivalente di recalculate_all_quotes() CI3).
     */
    public function recalculateAllQuotes()
    {
        $quoteIds = scope_builder_to_company(\Config\Database::connect()->table('ip_quotes'), 'ip_quotes')
            ->select('quote_id')->get()->getResultArray();

        foreach ($quoteIds as $quoteId) {
            (new QuoteAmountModel())->calculate((int) $quoteId['quote_id']);
        }

        session()->setFlashdata('alert_success', 'Importi dei preventivi ricalcolati correttamente');

        // Come in Invoices::recalculateAllInvoices(): /quotes rimanda a
        // /quotes/status/all e il salto intermedio consumava il flashdata,
        // quindi il messaggio di esito non arrivava mai a schermo.
        return redirect()->to('/quotes/status/all');
    }
}