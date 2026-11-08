<?php

namespace App\Controllers;

use App\Models\InvoiceModel;
use App\Models\InvoiceTaxRateModel;
use App\Models\ItemModel;
use App\Models\QuoteItemModel;
use App\Models\QuoteModel;
use App\Models\QuoteTaxRateModel;
use App\Services\DocumentPdfBuilder;
use App\Services\QuoteDecision;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * GuestView controller (PUBBLICO)
 *
 * Porting CI4 del controller View (CI3, modulo guest): pagine pubbliche
 * di visualizzazione di fatture e preventivi tramite URL key, senza
 * richiedere login. Nessun filtro: solo sessioni flashdata per il
 * form password e i messaggi di approvazione/rifiuto.
 */
class GuestView extends BaseController
{
    /**
     * Visualizzazione pubblica di una fattura tramite URL key.
     * Se la fattura e' protetta da password viene chiesto l'inserimento
     * (form POST semplice, congedato via flashdata).
     */
    public function invoice(string $invoiceUrlKey)
    {
        $invoiceModel = new InvoiceModel();

        $invoice = $invoiceModel->getByUrlKey($invoiceUrlKey);

        if (!$invoice || !in_array((int) $invoice['invoice_status_id'], [2, 3, 4], true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Sblocco tenuto in sessione e non piu' come flashdata monouso: il
        // flashdata vive una sola richiesta, quindi non poteva coprire il link
        // "Scarica PDF" della pagina, che e' una richiesta successiva. Ed e'
        // proprio quel link che, non avendo alcun controllo, permetteva di
        // scaricare la fattura protetta saltando la password (vedi
        // generateInvoicePdf()).
        $unlockKey = 'guest_invoice_unlocked_' . $invoiceUrlKey;
        $unlocked  = (bool) session($unlockKey);

        if (!empty($invoice['invoice_password']) && !$unlocked) {
            // Solo POST: una password in query string finisce nei log del
            // server, nella cronologia del browser e nel Referer verso i
            // siti visitati dopo. Nessun link usa ?password=, quindi il
            // canale non serviva a nessuno.
            $request   = service('request');
            $submitted = $request->getPost('invoice_password');

            if ($submitted !== null) {
                if (hash_equals(decrypt_secret((string) $invoice['invoice_password']), (string) $submitted)) {
                    session()->set($unlockKey, true);
                } else {
                    session()->setFlashdata('alert_error', trans('wrong_invoice_password', '', 'Password errata'));
                }

                return redirect()->to('/guest/view/invoice/' . $invoiceUrlKey);
            }

            $data = [
                'title'                    => trans('invoice'),
                'invoice'                  => $invoice,
                'invoice_url_key'          => $invoiceUrlKey,
                'invoice_password_required'=> true,
            ];

            return view('guest/invoices_view', $data);
        }

        // Marca come vista solo per visitatori non admin
        if (session('user_type') != 1 && (int) $invoice['invoice_status_id'] === 2) {
            $invoiceModel->markViewed((int) $invoice['invoice_id']);
        }

        // Rilegge con i join (cliente, importi) per la visualizzazione
        $invoice = $invoiceModel->getById((int) $invoice['invoice_id']);

        if (!$invoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        $data = [
            'title'             => trans('invoice') . ' #' . ($invoice['invoice_number'] ?: $invoice['invoice_id']),
            'invoice'           => $invoice,
            'items'             => (new ItemModel())->getByInvoice((int) $invoice['invoice_id']),
            'invoice_tax_rates' => (new InvoiceTaxRateModel())->getByInvoice((int) $invoice['invoice_id']),
            'invoice_id'        => (int) $invoice['invoice_id'],
            'invoice_url_key'   => $invoiceUrlKey,
            'public_view'       => true,
        ];

        return view('guest/invoices_view', $data);
    }

    /**
     * Genera il PDF pubblico della fattura tramite URL key.
     */
    public function generateInvoicePdf(string $invoiceUrlKey)
    {
        $invoiceModel = new InvoiceModel();

        $invoice = $invoiceModel->getByUrlKey($invoiceUrlKey);

        if (!$invoice || !in_array((int) $invoice['invoice_status_id'], [2, 3, 4], true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Stesso cancello di invoice(): qui non c'era, quindi chi conosceva la
        // sola url key -- che viaggia comunque nel link mandato al cliente --
        // scaricava il PDF completo di una fattura protetta da password senza
        // mai inserirla. La password proteggeva la pagina, non il documento.
        if (!empty($invoice['invoice_password'])
            && !session('guest_invoice_unlocked_' . $invoiceUrlKey)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->renderInvoicePdf((int) $invoice['invoice_id']);
    }

    /**
     * Il preventivo e' sbloccato per questa sessione?
     *
     * ip_quotes ha da sempre la colonna quote_password, ed e' modificabile
     * dalla schermata del preventivo, ma nessuno la leggeva: la password si
     * poteva impostare e non faceva niente, il link pubblico mostrava il
     * documento comunque. Un controllo che l'utente crede attivo e che non lo
     * e' e' peggio di un controllo assente, perche' ci si fa affidamento.
     */
    private function quoteUnlocked(array $quote, string $quoteUrlKey): bool
    {
        if (empty($quote['quote_password'])) {
            return true;
        }

        return (bool) session('guest_quote_unlocked_' . $quoteUrlKey);
    }

    /**
     * Visualizzazione pubblica di un preventivo tramite URL key
     * (template pubblico quote_templates/public).
     */
    public function quote(string $quoteUrlKey)
    {
        $quoteModel = new QuoteModel();

        $quote = $quoteModel->getByUrlKey($quoteUrlKey);

        if (!$quote || !in_array((int) $quote['quote_status_id'], [2, 3, 4, 5], true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Cancello password, speculare a quello delle fatture.
        $unlockKey = 'guest_quote_unlocked_' . $quoteUrlKey;

        if (!empty($quote['quote_password']) && !session($unlockKey)) {
            // Come per la fattura: la password arriva solo dal form in POST.
            $request   = service('request');
            $submitted = $request->getPost('quote_password');

            if ($submitted !== null) {
                if (hash_equals(decrypt_secret((string) $quote['quote_password']), (string) $submitted)) {
                    session()->set($unlockKey, true);
                } else {
                    session()->setFlashdata('alert_error', trans('wrong_quote_password', '', 'Password errata'));
                }

                return redirect()->to('/guest/view/quote/' . $quoteUrlKey);
            }

            return view('guest/quotes_view', [
                'quote'                  => $quote,
                'quote_url_key'          => $quoteUrlKey,
                'quote_password_required'=> true,
            ]);
        }

        if (session('user_type') != 1 && (int) $quote['quote_status_id'] === 2) {
            $quoteModel->markViewed((int) $quote['quote_id']);
        }

        // Rilegge con i join (cliente, utente, importi) per la visualizzazione
        $quote = $quoteModel->getByIdWithJoins((int) $quote['quote_id']);

        if (!$quote) {
            throw PageNotFoundException::forPageNotFound();
        }

        $quoteId = (int) $quote['quote_id'];
        $expires = $quote['quote_date_expires'] ?? '';

        // Stessa pagina pubblica delle fatture (guest/invoices_view): prima il
        // preventivo aveva un template a parte (quote_templates/public), con i
        // suoi CSS inline e i suoi colori, e sembrava un programma diverso da
        // quello delle fatture. Ora entrambi i documenti pubblici sono lo stesso
        // template card, così il cliente ritrova la stessa faccia.
        $data = [
            'quote'           => $quote,
            'items'           => (new QuoteItemModel())->getByQuote($quoteId),
            'quote_tax_rates' => (new QuoteTaxRateModel())->getByQuote($quoteId),
            'quote_url_key'   => $quoteUrlKey,
            'quote_statuses'  => $quoteModel->statuses(),
            'public_view'     => true,
            'flash_message'   => session()->getFlashdata('flash_message'),
        ];

        return view('guest/quotes_view', $data);
    }

    /**
     * Genera il PDF pubblico del preventivo tramite URL key.
     */
    public function generateQuotePdf(string $quoteUrlKey)
    {
        $quoteModel = new QuoteModel();

        $quote = $quoteModel->getByUrlKey($quoteUrlKey);

        if (!$quote || !in_array((int) $quote['quote_status_id'], [2, 3, 4, 5], true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        if (!$this->quoteUnlocked($quote, $quoteUrlKey)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->renderQuotePdf((int) $quote['quote_id']);
    }

    /**
     * Approvazione pubblica del preventivo via chiave url (stato 4).
     */
    public function approveQuote(string $quoteUrlKey)
    {
        $quoteModel = new QuoteModel();

        $quote = $quoteModel->getByUrlKey($quoteUrlKey);

        if (!$quote) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Approvare cambia lo stato del documento: se e' protetto, la
        // protezione vale anche qui, non solo sulla lettura.
        if (!$this->quoteUnlocked($quote, $quoteUrlKey)) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Stessi stati del portale: la regola sta in QuoteDecision, cosi' i due
        // percorsi non possono dire cose diverse sullo stesso preventivo.
        $errore = QuoteDecision::error($quote, QuoteDecision::APPROVE);

        if ($errore !== null) {
            session()->setFlashdata('alert_error', $errore);

            return redirect()->to('/guest/view/quote/' . $quoteUrlKey);
        }

        $quoteModel->approveQuoteByKey($quoteUrlKey);
        session()->setFlashdata('flash_message', trans('quote_approved', '', 'Preventivo approvato'));

        return redirect()->to('/guest/view/quote/' . $quoteUrlKey);
    }

    /**
     * Rifiuto pubblico del preventivo via chiave url (stato 5).
     */
    public function rejectQuote(string $quoteUrlKey)
    {
        $quoteModel = new QuoteModel();

        $quote = $quoteModel->getByUrlKey($quoteUrlKey);

        if (!$quote) {
            throw PageNotFoundException::forPageNotFound();
        }

        if (!$this->quoteUnlocked($quote, $quoteUrlKey)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $errore = QuoteDecision::error($quote, QuoteDecision::REJECT);

        if ($errore !== null) {
            session()->setFlashdata('alert_error', $errore);

            return redirect()->to('/guest/view/quote/' . $quoteUrlKey);
        }

        $quoteModel->rejectQuoteByKey($quoteUrlKey);
        session()->setFlashdata('flash_message', trans('quote_rejected', '', 'Preventivo rifiutato'));

        return redirect()->to('/guest/view/quote/' . $quoteUrlKey);
    }

    /**
     * Prepara i dati e renderizza il template PDF della fattura
     * (stessa logica di Invoices::renderInvoicePdf, qui privata).
     */
    private function renderInvoicePdf(int $invoiceId)
    {
        $invoiceModel = new InvoiceModel();

        $invoice = $invoiceModel->getById($invoiceId);

        if (!$invoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Composizione condivisa con il resto dell'app: il PDF allegato alle
        // email non puo' divergere da quello scaricato (vedi DocumentPdfBuilder).
        $html = (new DocumentPdfBuilder())->invoice($invoice);

        $filename = (new DocumentPdfBuilder())->invoiceFilename($invoice);

        return pdf_create($html, $filename, true);
    }

    /**
     * Prepara i dati e renderizza il template PDF del preventivo
     * (stessa logica di Quotes::renderQuotePdf, qui privata).
     */
    private function renderQuotePdf(int $quoteId)
    {
        $quoteModel = new QuoteModel();

        // getById() e non find(): il template PDF prende l'emittente dal join
        // con ip_companies (vedi QuoteModel::pdfUser()).
        $quote = $quoteModel->getById($quoteId);

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