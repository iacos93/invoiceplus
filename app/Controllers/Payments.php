<?php

namespace App\Controllers;


use App\Models\ClientModel;
use App\Models\CustomFieldModel;
use App\Models\CustomValueModel;
use App\Models\InvoiceModel;
use App\Models\PaymentCustomModel;
use App\Models\PaymentLogModel;
use App\Models\PaymentMethodModel;
use App\Models\PaymentModel;
use App\Services\InvoiceMath;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Payments controller
 *
 * Porting CI4 del controller Payments (CI3): lista pagamenti con join
 * fattura/cliente/metodo, form crea/modifica (fatture aperte con saldo
 * > 0, campi custom), log dei pagamenti online e cancellazione con
 * ricalcolo degli importi della fattura.
 */
class Payments extends BaseController
{
    protected PaymentModel $paymentModel;

    public function __construct()
    {
        $this->paymentModel = new PaymentModel();
    }

    public function index()
    {
        $data = [
            'payments' => $this->paymentModel->getAllWithJoins(),
        ];

        return view('payments/index', $data);
    }

    /**
     * Azioni di gruppo dalla lista (elimina).
     *
     * Il pagamento non ha altri stati da cambiare in blocco: o esiste o non
     * esiste. La cancellazione però non è una riga in meno e basta —
     * PaymentModel::delete() ricalcola gli importi della fattura e, se era
     * l'ultimo incasso, la riporta a "inviata": senza passare da qui, una
     * cancellazione di gruppo lascerebbe i saldi fermi al primo pagamento.
     *
     * Stesso schema di Invoices::bulk(): il modulo contiene solo la barra, le
     * caselle di riga gli appartengono con l'attributo HTML5 "form".
     */
    public function bulk()
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $action = (string) $request->getPost('bulk_action');
        $ids    = array_values(array_unique(array_filter(array_map('intval', (array) ($request->getPost('ids') ?? [])))));
        $back   = (string) ($request->getPost('back') ?? '');

        if ($action !== 'delete' || $ids === []) {
            session()->setFlashdata('alert_error', trans('bulk_nothing_selected_payments', '', 'Nessun pagamento selezionato.'));

            return redirect()->to(ip_back_url('payments', $back));
        }

        $done    = 0;
        $skipped = 0;

        foreach ($ids as $paymentId) {
            // Perimetro azienda prima di cancellare: ip_payments non ha
            // company_id, quindi findScoped() qui legge l'azienda dalla
            // fattura pagata (override nel model). Senza, un id nel POST
            // cancellava l'incasso di un altro soggetto giuridico.
            if (! $this->paymentModel->findScoped($paymentId)) {
                $skipped++;
                continue;
            }

            $this->paymentModel->delete($paymentId);
            $done++;
        }

        if ($done > 0) {
            $msg = $done . ' ' . trans('payments', '', 'pagamenti') . ' ' . trans('bulk_done_delete_payments', '', 'eliminati');

            if ($skipped > 0) {
                $msg .= ' — ' . $skipped . ' ' . trans('bulk_skipped', '', 'saltate perché non ammesse per questa azione');
            }

            session()->setFlashdata('alert_success', $msg);
        } else {
            session()->setFlashdata('alert_error',
                trans('bulk_none_eligible_payments', '', 'Nessuno dei pagamenti selezionati era ammesso per questa azione.'));
        }

        return redirect()->to(ip_back_url('payments', $back));
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/payments');
            }

            $post = $request->getPost();

            // Il pagamento viene scritto sull'invoice_id arrivato in POST, e
            // PaymentModel ricalcola i totali di QUELLA fattura: senza questo
            // controllo una invoice_id di un'altra azienda corromperebbe i suoi
            // importi (ip_payments non ha company_id, lo prende dalla fattura).
            $postInvoiceId = (int) ($post['invoice_id'] ?? 0);

            if ($postInvoiceId > 0 && !(new InvoiceModel())->findScoped($postInvoiceId)) {
                session()->setFlashdata('alert_error', trans('invoice_not_found', '', 'Fattura non trovata o non appartenente all\'azienda attiva'));

                return redirect()->to('/payments');
            }

            // Stesso controllo sul pagamento in modifica: l'id arriva dall'URL
            // e senza findScoped() un update lo sposterebbe su una fattura
            // dell'azienda attiva, alterando i totali di entrambe.
            if ($id && !$this->paymentModel->findScoped($id)) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }

            // Il form mostra i rimborsi delle note di credito in positivo: il
            // segno lo rimette il modello. $rawPost serve a ripopolare il
            // form com'era stato scritto, se la validazione fallisce.
            $rawPost = $post;

            // Incasso ripartito su più metodi (es. parte con Carta del docente
            // e il resto con bonifico): solo in creazione, perché in modifica
            // si lavora sul singolo pagamento già registrato.
            if (! $id && $this->splitRows($post) !== []) {
                return $this->saveSplit($post);
            }

            $post    = $this->paymentModel->applyDocumentSign($post);

            if ($this->paymentModel->validate($post)) {
                $dbArray   = $this->paymentModel->dbArrayFromPost($post);
                $paymentId = $this->paymentModel->saveRecord($id, $dbArray);

                // saveRecord() torna false se l'insert/update non va a buon
                // fine: prima il messaggio era "creato correttamente" in ogni
                // caso, e il pagamento mancante lo si scopriva solo dal saldo.
                if (! $paymentId) {
                    $this->paymentModel->formValues = $rawPost;
                    session()->setFlashdata('alert_error', trans('payment_not_saved', '', 'Pagamento non salvato: controlla i dati e riprova'));

                    return $this->renderForm($id, $this->paymentModel->errors());
                }

                if (! empty($post['custom'])) {
                    (new PaymentCustomModel())->saveCustom((int) $paymentId, $post['custom']);
                }

                session()->setFlashdata('alert_success', $id
                    ? 'Pagamento aggiornato correttamente'
                    : 'Pagamento creato correttamente');

                return redirect()->to('/payments');
            }

            // Validazione fallita: ripopola i valori dal post per la view
            $this->paymentModel->formValues = $rawPost;

            return $this->renderForm($id, $this->paymentModel->errors());
        }

        if ($id) {
            // findScoped() prima di prepForm(): il record va cercato dentro il
            // perimetro dell'azienda attiva, altrimenti con l'id nell'URL si apre
            // in modifica la scheda di un altro soggetto giuridico -- e il
            // salvataggio successivo la riscrive senza spostarla, quindi la
            // modifica resta invisibile a chi la subisce.
            if (! $this->paymentModel->findScoped($id) || ! $this->paymentModel->prepForm($id)) {
                throw PageNotFoundException::forPageNotFound();
            }

            // Il rimborso di una nota di credito è salvato negativo ma nel
            // form si legge e si scrive in positivo (applyDocumentSign()).
            $paymentInvoice = (new InvoiceModel())->getByIdScoped((int) $this->paymentModel->formValue('invoice_id'));
            if ($paymentInvoice && InvoiceMath::documentSign((float) $paymentInvoice['invoice_total']) < 0) {
                $this->paymentModel->formValues['payment_amount'] = (string) abs((float) $this->paymentModel->formValue('payment_amount'));
            }

            // Valori dei campi custom gia' salvati
            foreach ((new PaymentCustomModel())->getByPayment($id) as $value) {
                $this->paymentModel->formValues['custom[' . $value['payment_custom_fieldid'] . ']'] = $value['payment_custom_fieldvalue'];
            }
        } else {
            // Nuovo pagamento: data odierna come default (prep_form() CI3)
            $this->paymentModel->formValues['payment_date'] = date('Y-m-d');

            // "Inserisci pagamento" dalla fattura arriva con ?invoice_id=: la
            // fattura va in querystring e non nel path, perché
            // payments/form/{id} è la MODIFICA del pagamento {id} -- con
            // l'id della fattura lì si otteneva un 404 o, peggio, si apriva
            // il pagamento che per caso aveva quel numero.
            $presetInvoiceId = (int) $request->getGet('invoice_id');

            if ($presetInvoiceId > 0 && ($invoice = (new InvoiceModel())->getByIdScoped($presetInvoiceId))) {
                $this->paymentModel->formValues['invoice_id']     = $invoice['invoice_id'];
                // Il residuo nel verso del documento, sempre positivo: su
                // una nota di credito si proponeva "-1.049,00", e su una
                // fattura pagata in eccesso un importo negativo.
                $suggested = InvoiceMath::suggestedPayment((float) $invoice['invoice_total'], (float) $invoice['invoice_balance']);
                $this->paymentModel->formValues['payment_amount'] = $suggested > 0 ? (string) $suggested : '';

                if (! empty($invoice['payment_method'])) {
                    $this->paymentModel->formValues['payment_method_id'] = $invoice['payment_method'];
                }

                return $this->renderForm(null, [], $invoice);
            }
        }

        return $this->renderForm($id, []);
    }

    /**
     * Righe aggiuntive della ripartizione (split_amount[] / split_method[]),
     * scartando quelle lasciate vuote.
     */
    private function splitRows(array $post): array
    {
        $rows    = [];
        $amounts = (array) ($post['split_amount'] ?? []);
        $methods = (array) ($post['split_method'] ?? []);

        foreach ($amounts as $i => $amount) {
            $amount = trim((string) $amount);

            if ($amount !== '') {
                $rows[] = ['payment_amount' => $amount, 'payment_method_id' => (int) ($methods[$i] ?? 0)];
            }
        }

        return $rows;
    }

    /**
     * Registra un incasso ripartito: un pagamento per metodo, stessa fattura,
     * data e note. Resta una riga per metodo in ip_payments (il modello che
     * report, riconciliazione e XML già leggono), invece di introdurre una
     * tabella di "quote" che tutti dovrebbero imparare a sommare.
     *
     * Si valida prima la SOMMA contro il saldo e poi si scrive: validando riga
     * per riga, la seconda quota poteva essere respinta dopo che la prima era
     * già salvata, lasciando un incasso a metà.
     */
    private function saveSplit(array $post)
    {
        $rows = array_merge(
            [['payment_amount' => trim((string) ($post['payment_amount'] ?? '')), 'payment_method_id' => (int) ($post['payment_method_id'] ?? 0)]],
            $this->splitRows($post)
        );

        $total = 0.0;
        foreach ($rows as $row) {
            if ($row['payment_amount'] === '' || $row['payment_method_id'] <= 0) {
                $this->paymentModel->formValues = $post;

                return $this->renderForm(null, ['payment_amount' => 'Ogni quota della ripartizione deve avere importo e metodo di pagamento']);
            }
            $total += abs((float) standardize_amount($row['payment_amount']));
        }

        // La somma torna nel formato locale perché applyDocumentSign() e
        // validate() si aspettano l'importo come scritto nel form.
        $check = $this->paymentModel->applyDocumentSign(array_merge($post, ['payment_amount' => format_amount($total)]));

        if (! $this->paymentModel->validate($check)) {
            $this->paymentModel->formValues = $post;

            return $this->renderForm(null, $this->paymentModel->errors());
        }

        $saved = 0;
        foreach ($rows as $row) {
            $rowPost   = $this->paymentModel->applyDocumentSign(array_merge($post, $row));
            $paymentId = $this->paymentModel->saveRecord(null, $this->paymentModel->dbArrayFromPost($rowPost));

            if ($paymentId) {
                $saved++;
                if (! empty($post['custom'])) {
                    (new PaymentCustomModel())->saveCustom((int) $paymentId, $post['custom']);
                }
            }
        }

        session()->setFlashdata($saved === count($rows) ? 'alert_success' : 'alert_error', $saved === count($rows)
            ? 'Incasso registrato su ' . $saved . ' metodi di pagamento'
            : 'Salvate solo ' . $saved . ' quote su ' . count($rows) . ': controlla i pagamenti della fattura');

        return redirect()->to('/payments');
    }

    private function renderForm(?int $id, array $errors, ?array $presetInvoice = null)
    {
        $invoiceModel = new InvoiceModel();

        // Documenti aperti (stato inviata/vista) con qualcosa da incassare o,
        // per le note di credito, da rimborsare: con il filtro "saldo > 0" le
        // note di credito in attesa di rimborso non comparivano mai.
        $openInvoices = array_values(array_filter(
            $invoiceModel->isOpen()->getAllWithStatus('all'),
            static fn ($invoice) => InvoiceMath::suggestedPayment(
                (float) ($invoice['invoice_total'] ?? 0),
                (float) ($invoice['invoice_balance'] ?? 0)
            ) > 0
        ));

        // In modifica, la fattura del pagamento va mostrata anche se
        // non e' piu' aperta (comportamento del form CI3)
        $payment = null;
        if ($id) {
            $payment = $this->paymentModel->findScoped($id);

            if ($payment) {
                // findScoped() legge solo ip_payments, ma la view mostra
                // nell'opzione preselezionata numero, cliente e saldo della
                // fattura: senza questo merge il form andava in errore
                // (chiave invoice_number assente) appena si apriva un
                // pagamento esistente.
                $invoice = $invoiceModel->getByIdScoped((int) $payment['invoice_id']);

                if ($invoice) {
                    $payment = array_merge($payment, $invoice);

                    if (! in_array($payment['invoice_id'], array_column($openInvoices, 'invoice_id'), true)) {
                        $openInvoices[] = $invoice;
                    }
                }
            }
        }

        // Fattura preselezionata da ?invoice_id=: va in elenco anche se non è
        // fra le "aperte" (per esempio ancora in bozza), altrimenti la
        // tendina la perderebbe e la preselezione non avrebbe effetto.
        if ($presetInvoice && ! in_array($presetInvoice['invoice_id'], array_column($openInvoices, 'invoice_id'), true)) {
            $openInvoices[] = $presetInvoice;
        }

        $customFieldModel = new CustomFieldModel();
        $customFields     = $customFieldModel->byTable('ip_payment_custom');
        $customValues     = [];

        foreach ($customFields as $customField) {
            if (in_array($customField['custom_field_type'], $customFieldModel->customValueTypes(), true)) {
                $customValues[$customField['custom_field_id']] = (new CustomValueModel())->byField($customField['custom_field_id']);
            }
        }

        // Importi e metodi di pagamento per l'autofill JS del form
        $amounts               = [];
        $invoicePaymentMethods = [];

        foreach ($openInvoices as $openInvoice) {
            $suggested = InvoiceMath::suggestedPayment((float) ($openInvoice['invoice_total'] ?? 0), (float) ($openInvoice['invoice_balance'] ?? 0));
            $amounts['invoice' . $openInvoice['invoice_id']]               = $suggested > 0 ? format_amount($suggested) : '';
            $invoicePaymentMethods['invoice' . $openInvoice['invoice_id']] = $openInvoice['payment_method'] ?? 0;
        }

        $data = [
            'payment_id'              => $id,
            'errors'                  => $errors,
            'model'                   => $this->paymentModel,
            'payment'                 => $payment,
            'open_invoices'           => $openInvoices,
            'payment_methods'         => (new PaymentMethodModel())->getAllOrdered(),
            'clients'                 => (new ClientModel())->getAllOrdered(),
            'custom_fields'           => $customFields,
            'custom_values'           => $customValues,
            'amounts'                 => json_encode($amounts),
            'invoice_payment_methods' => json_encode($invoicePaymentMethods),
        ];

        return view('payments/form', $data);
    }

    public function onlineLogs()
    {
        $data = [
            'payment_logs' => (new PaymentLogModel())->getAllOrdered(),
        ];

        return view('payments/online_logs', $data);
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        // Il perimetro azienda si verifica prima di cancellare: con l'id nel
        // POST si distruggeva il record di un altro soggetto giuridico, e una
        // cancellazione non si annulla.
        if (!$this->paymentModel->findScoped($id)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->paymentModel->delete($id);
        session()->setFlashdata('alert_success', 'Pagamento eliminato correttamente');

        return redirect()->to('/payments');
    }
}