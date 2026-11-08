<?php

namespace App\Controllers;

use App\Libraries\RibaFlow;
use App\Models\BankAccountModel;
use App\Models\CompanyModel;
use App\Models\InvoiceModel;
use App\Models\PaymentMethodModel;

/**
 * Riba controller — presentazione delle ricevute bancarie in banca.
 *
 * Si selezionano le fatture da incassare a RiBa e si genera il flusso CBI da
 * caricare nell'home banking. Le fatture presentate restano marcate con il
 * riferimento della distinta, così non si ripresentano per errore.
 */
class Riba extends BaseController
{
    protected InvoiceModel $invoiceModel;

    public function __construct()
    {
        $this->invoiceModel = new InvoiceModel();
    }

    public function index()
    {
        $ribaMethods = (new PaymentMethodModel())->ribaMethodIds();
        $invoices    = $this->presentable();

        // Le fatture emesse *come* RiBa vanno in cima e arrivano già spuntate:
        // sono quelle che si intendeva presentare, e cercarle in mezzo a tutte
        // le altre aperte era il vero lavoro di questa pagina. Le altre restano
        // sotto e selezionabili: il metodo di pagamento non sempre è compilato,
        // e nasconderle vorrebbe dire non poterle più presentare da qui.
        [$ribaInvoices, $otherInvoices] = $this->partitionByRiba($invoices, $ribaMethods);

        return view('riba/index', [
            'invoices'       => array_merge($ribaInvoices, $otherInvoices),
            'riba_count'     => count($ribaInvoices),
            'has_riba_method' => $ribaMethods !== [],
            'accounts'       => (new BankAccountModel())->getAllOrdered(),
        ]);
    }

    /**
     * Fatture presentabili: emesse, con saldo aperto e non già in una distinta.
     * Le bozze non sono crediti e le già presentate darebbero un doppio incasso.
     */
    private function presentable(): array
    {
        return array_values(array_filter(
            $this->invoiceModel->getAllWithStatus('all', 0, 0),
            static fn($invoice) => (int) $invoice['invoice_status_id'] !== 1
                && (float) ($invoice['invoice_balance'] ?? 0) > 0
                && empty($invoice['riba_flow_ref'])
        ));
    }

    /**
     * Divide le fatture in "con metodo RiBa" e le altre, conservando l'ordine
     * di partenza dentro ciascun gruppo. Marca le prime con is_riba, che la
     * view usa per l'etichetta e per la spunta iniziale.
     */
    private function partitionByRiba(array $invoices, array $ribaMethods): array
    {
        $riba  = [];
        $other = [];

        foreach ($invoices as $invoice) {
            $isRiba = $ribaMethods !== []
                && in_array((int) ($invoice['payment_method'] ?? 0), $ribaMethods, true);

            $invoice['is_riba'] = $isRiba;

            if ($isRiba) {
                $riba[] = $invoice;
            } else {
                $other[] = $invoice;
            }
        }

        return [$riba, $other];
    }

    public function generate()
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $ids       = array_map('intval', (array) $request->getPost('invoice_ids'));
        $accountId = (int) $request->getPost('bank_account_id');

        if (!$ids) {
            session()->setFlashdata('alert_error', 'Nessuna fattura selezionata.');

            return redirect()->to('/riba');
        }

        // findScoped() e non find(): il conto arriva dal POST e senza perimetro
        // una distinta poteva essere costruita accreditando l'IBAN e il codice
        // SIA di un altro soggetto giuridico, con dentro fatture di questo.
        $account = (new BankAccountModel())->findScoped($accountId);

        if (!$account) {
            session()->setFlashdata('alert_error', 'Conto di accredito non valido.');

            return redirect()->to('/riba');
        }

        $company  = (new CompanyModel())->find(active_company_id()) ?: [];
        $selected = array_values(array_filter(
            $this->presentable(),
            static fn($invoice) => in_array((int) $invoice['invoice_id'], $ids, true)
        ));

        if (!$selected) {
            session()->setFlashdata('alert_error', 'Le fatture selezionate non sono più presentabili.');

            return redirect()->to('/riba');
        }

        $flow = (new RibaFlow())->build($company, $account, $selected);

        // Gli avvisi (SIA o IBAN mancanti) non bloccano: il file va comunque
        // prodotto per poterlo esaminare, ma l'utente deve sapere che così
        // com'è la banca lo scarterebbe.
        if ($flow['warnings']) {
            session()->setFlashdata('alert_error', 'Flusso generato con avvisi: ' . implode(' ', $flow['warnings']));
        }

        foreach ($selected as $invoice) {
            $this->invoiceModel->update((int) $invoice['invoice_id'], [
                'riba_flow_ref'  => $flow['reference'],
                'riba_flow_date' => date('Y-m-d'),
            ]);
        }

        return $this->response
            ->setContentType('text/plain; charset=US-ASCII')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $flow['reference'] . '.txt"')
            ->setBody($flow['content']);
    }
}
