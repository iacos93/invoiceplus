<?php

namespace App\Controllers;

use App\Traits\ResolvesGuestClients;
use App\Models\InvoiceModel;
use App\Models\PaymentModel;
use App\Models\QuoteModel;

/**
 * Guest controller
 *
 * Porting CI4 del controller Guest (CI3): dashboard dell'utente guest
 * con conteggi di fatture aperte/scadute, preventivi in attesa di
 * approvazione e pagamenti dei clienti assegnati.
 */
class Guest extends BaseController
{
    use ResolvesGuestClients;


    public function index()
    {
        $clientIds = $this->clientIds();

        $invoiceModel = new InvoiceModel();
        $quoteModel   = new QuoteModel();
        $paymentModel = new PaymentModel();

        $data = [
            'title'              => trans('dashboard'),
            'open_invoices'      => count($invoiceModel->getForGuestClients($clientIds, 'open')),
            'overdue_invoices'   => count($invoiceModel->getForGuestClients($clientIds, 'overdue')),
            'open_quotes'        => count($quoteModel->getForGuestClients($clientIds, 'open')),
            'payments'           => count($paymentModel->getForGuestClients($clientIds)),
        ];

        return view('guest/index', $data);
    }
}