<?php

namespace App\Controllers;

use App\Models\InvoiceModel;
use App\Models\InvoiceRecurringModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * RecurringInvoices controller
 *
 * Porting CI4 del controller Recurring_invoices (CI3): elenco delle
 * fatture ricorrenti e interruzione/eliminazione della ricorrenza.
 */
class RecurringInvoices extends BaseController
{
    public function index()
    {
        $data = [
            'recurring_invoices' => (new InvoiceRecurringModel())->getAllWithJoins(),
        ];

        return view('invoices/index_recurring', $data);
    }

    public function stop(int $invoiceId)
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        // findScoped() sulla fattura collegata: senza questo controllo un
        // utente poteva fermare la ricorrenza di un'altra azienda passando
        // solo l'id in POST (la lista e' scopata via join, questa azione no).
        if (!(new InvoiceModel())->findScoped($invoiceId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        (new InvoiceRecurringModel())->stop($invoiceId);

        session()->setFlashdata('alert_success', trans('recurring_invoice_stopped', '', 'Ricorrenza interrotta'));

        return redirect()->to('/invoices/recurring');
    }

    public function delete(int $invoiceId)
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        // Stesso motivo di stop(): senza findScoped() si cancellava la
        // ricorrenza di un'altra azienda passando solo l'id in POST.
        if (!(new InvoiceModel())->findScoped($invoiceId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        (new InvoiceRecurringModel())->where('invoice_id', $invoiceId)->delete();

        session()->setFlashdata('alert_success', trans('recurring_invoice_deleted', '', 'Ricorrenza eliminata'));

        return redirect()->to('/invoices/recurring');
    }
}