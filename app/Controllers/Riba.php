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

        return view('riba/index', [
            'invoices'        => $this->presentable($ribaMethods),
            'has_riba_method' => $ribaMethods !== [],
            'accounts'        => (new BankAccountModel())->getAllOrdered(),
        ]);
    }

    /**
     * Fatture presentabili: emesse con pagamento RiBa (metodo con modalità
     * MP12), con saldo aperto e non già in una distinta.
     *
     * Solo RiBa: prima la pagina elencava sotto anche tutte le altre fatture
     * aperte, "presentabili comunque", e con l'archivio importato ci finivano
     * decine di fatture pagate a bonifico. Una fattura va in distinta solo se
     * è stata emessa come RiBa; per presentarne una che non lo è, si cambia il
     * metodo di pagamento sulla fattura. Le bozze non sono crediti e le già
     * presentate darebbero un doppio incasso.
     *
     * @param list<int> $ribaMethods
     */
    private function presentable(array $ribaMethods): array
    {
        if ($ribaMethods === []) {
            return [];
        }

        return array_values(array_filter(
            $this->invoiceModel->getAllWithStatus('all', 0, 0),
            static fn($invoice) => (int) $invoice['invoice_status_id'] !== 1
                && (float) ($invoice['invoice_balance'] ?? 0) > 0
                && empty($invoice['riba_flow_ref'])
                && in_array((int) ($invoice['payment_method'] ?? 0), $ribaMethods, true)
        ));
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
            $this->presentable((new PaymentMethodModel())->ribaMethodIds()),
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
