<?php

namespace App\Controllers;

use App\Traits\ResolvesGuestClients;
use App\Models\InvoiceModel;
use App\Models\InvoiceTaxRateModel;
use App\Models\ItemModel;
use App\Services\DocumentPdfBuilder;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * GuestInvoices controller
 *
 * Porting CI4 del controller Invoices (CI3, modulo guest): elenco
 * fatture dei soli clienti assegnati all'utente guest, dettaglio,
 * generazione PDF e marcatura come vista.
 */
class GuestInvoices extends BaseController
{
    use ResolvesGuestClients;

    protected InvoiceModel $invoiceModel;

    public function __construct()
    {
        $this->invoiceModel = new InvoiceModel();
    }


    public function index()
    {
        return redirect()->to('/guest/invoices/status/open');
    }

    /**
     * Elenco fatture filtrato per stato: open (inviato/visto, default),
     * paid, overdue.
     */
    public function status(string $status = 'open')
    {
        $clientIds = $this->clientIds();

        $data = [
            'title'            => trans('invoices'),
            'invoices'         => $this->invoiceModel->getForGuestClients($clientIds, $status),
            'status'           => $status,
            'invoice_statuses' => $this->invoiceModel->statuses(),
        ];

        return view('guest/invoices_index', $data);
    }

    /**
     * Dettaglio fattura: verificato che appartenga a un cliente assegnato,
     * altrimenti 404. Marca la fattura come vista.
     */
    public function view(int $invoiceId)
    {
        $invoice = $this->invoiceModel->getById($invoiceId);

        if (!$invoice || !in_array((int) $invoice['client_id'], $this->clientIds(), true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->invoiceModel->markViewed($invoiceId);

        // Rilegge per avere lo stato aggiornato dopo la marcatura
        $invoice = $this->invoiceModel->getById($invoiceId);

        $data = [
            'title'            => trans('invoice') . ' #' . ($invoice['invoice_number'] ?: $invoiceId),
            'invoice'          => $invoice,
            'items'            => (new ItemModel())->getByInvoice($invoiceId),
            'invoice_tax_rates'=> (new InvoiceTaxRateModel())->getByInvoice($invoiceId),
            'invoice_id'       => $invoiceId,
            'public_view'      => false,
        ];

        return view('guest/invoices_view', $data);
    }

    /**
     * Genera il PDF della fattura (visibili solo quelle inviate/viste/pagate
     * dei clienti assegnati), marcandola come vista.
     */
    public function generatePdf(int $invoiceId)
    {
        $invoice = $this->invoiceModel->getById($invoiceId);

        if (!$invoice
            || !in_array((int) $invoice['client_id'], $this->clientIds(), true)
            || !in_array((int) $invoice['invoice_status_id'], [2, 3, 4], true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->invoiceModel->markViewed($invoiceId);

        return $this->renderInvoicePdf($invoiceId);
    }

    /**
     * Prepara i dati e renderizza il template PDF della fattura
     * (stessa logica di Invoices::renderInvoicePdf, qui privata).
     */
    private function renderInvoicePdf(int $invoiceId)
    {
        $invoice = $this->invoiceModel->getById($invoiceId);

        if (!$invoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Composizione condivisa con il resto dell'app: il PDF allegato alle
        // email non puo' divergere da quello scaricato (vedi DocumentPdfBuilder).
        $html = (new DocumentPdfBuilder())->invoice($invoice);

        $filename = (new DocumentPdfBuilder())->invoiceFilename($invoice);

        return pdf_create($html, $filename, true);
    }
}