<?php

namespace App\Controllers;

use App\Traits\ResolvesGuestClients;
use App\Models\QuoteItemModel;
use App\Models\QuoteModel;
use App\Models\QuoteTaxRateModel;
use App\Services\DocumentPdfBuilder;
use App\Services\QuoteDecision;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * GuestQuotes controller
 *
 * Porting CI4 del controller Quotes (CI3, modulo guest): elenco
 * preventivi dei soli clienti assegnati all'utente guest, dettaglio,
 * generazione PDF, approvazione e rifiuto per id.
 */
class GuestQuotes extends BaseController
{
    use ResolvesGuestClients;

    protected QuoteModel $quoteModel;

    public function __construct()
    {
        $this->quoteModel = new QuoteModel();
    }


    public function index()
    {
        return redirect()->to('/guest/quotes/status/open');
    }

    /**
     * Elenco preventivi filtrato per stato: open (inviato/visto, default),
     * approved, rejected.
     */
    public function status(string $status = 'open')
    {
        $clientIds = $this->clientIds();

        $data = [
            'title'         => trans('quotes'),
            'quotes'        => $this->quoteModel->getForGuestClients($clientIds, $status),
            'status'        => $status,
            'quote_statuses'=> $this->quoteModel->statuses(),
        ];

        return view('guest/quotes_index', $data);
    }

    /**
     * Dettaglio preventivo: verificato che appartenga a un cliente
     * assegnato, altrimenti 404. Marca il preventivo come visto.
     */
    public function view(int $quoteId)
    {
        $quote = $this->quoteModel->getByIdWithJoins($quoteId);

        if (!$quote || !in_array((int) $quote['client_id'], $this->clientIds(), true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->quoteModel->markViewed($quoteId);

        // Rilegge per avere lo stato aggiornato dopo la marcatura
        $quote = $this->quoteModel->getByIdWithJoins($quoteId);

        $data = [
            'title'           => trans('quote') . ' #' . ($quote['quote_number'] ?: $quoteId),
            'quote'           => $quote,
            'items'           => (new QuoteItemModel())->getByQuote($quoteId),
            'quote_tax_rates' => (new QuoteTaxRateModel())->getByQuote($quoteId),
            'quote_id'        => $quoteId,
            'quote_statuses'  => $this->quoteModel->statuses(),
            'public_view'     => false,
        ];

        return view('guest/quotes_view', $data);
    }

    /**
     * Genera il PDF del preventivo (visibili solo quelli inviati/visti/
     * approvati/rifiutati dei clienti assegnati), marcandolo come visto.
     */
    public function generatePdf(int $quoteId)
    {
        $quote = $this->quoteModel->find($quoteId);

        if (!$quote
            || !in_array((int) $quote['client_id'], $this->clientIds(), true)
            || !in_array((int) $quote['quote_status_id'], [2, 3, 4, 5], true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->quoteModel->markViewed($quoteId);

        return $this->renderQuotePdf($quoteId);
    }

    /**
     * Approva il preventivo per id (stato 4) e torna al dettaglio.
     */
    public function approve(int $quoteId)
    {
        $quote = $this->quoteModel->find($quoteId);

        if (!$quote || !in_array((int) $quote['client_id'], $this->clientIds(), true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        // La regola (si decide solo su un preventivo aperto) sta in
        // QuoteDecision: portale e link pubblico non possono divergere.
        $errore = QuoteDecision::error($quote, QuoteDecision::APPROVE);

        if ($errore !== null) {
            session()->setFlashdata('alert_error', $errore);

            return redirect()->to('/guest/quotes/view/' . $quoteId);
        }

        $this->quoteModel->approveQuoteById($quoteId);
        session()->setFlashdata('alert_success', trans('quote_approved', '', 'Preventivo approvato'));

        return redirect()->to('/guest/quotes/view/' . $quoteId);
    }

    /**
     * Rifiuta il preventivo per id (stato 5) e torna al dettaglio.
     */
    public function reject(int $quoteId)
    {
        $quote = $this->quoteModel->find($quoteId);

        if (!$quote || !in_array((int) $quote['client_id'], $this->clientIds(), true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $errore = QuoteDecision::error($quote, QuoteDecision::REJECT);

        if ($errore !== null) {
            session()->setFlashdata('alert_error', $errore);

            return redirect()->to('/guest/quotes/view/' . $quoteId);
        }

        $this->quoteModel->rejectQuoteById($quoteId);
        session()->setFlashdata('alert_success', trans('quote_rejected', '', 'Preventivo rifiutato'));

        return redirect()->to('/guest/quotes/view/' . $quoteId);
    }

    /**
     * Prepara i dati e renderizza il template PDF del preventivo
     * (stessa logica di Quotes::renderQuotePdf, qui privata).
     */
    private function renderQuotePdf(int $quoteId)
    {
        // getById() e non find(): serve il join con ip_companies per i dati
        // dell'emittente (vedi QuoteModel::pdfUser()).
        $quote = $this->quoteModel->getById($quoteId);

        if (!$quote) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Composizione condivisa con il resto dell'app (vedi DocumentPdfBuilder):
        // l'allegato email non puo' divergere dal PDF scaricato.
        $html = (new DocumentPdfBuilder())->quote($quote);

        $filename = (new DocumentPdfBuilder())->quoteFilename($quote);

        return pdf_create($html, $filename, true);
    }
}