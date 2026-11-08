<?php

namespace App\Controllers;

use App\Models\InvoiceModel;
use App\Models\PaymentMethodModel;
use App\Models\PaymentModel;

/**
 * PaymentsAjax controller
 *
 * Porting CI4 del controller Ajax del modulo payments (CI3):
 * aggiunta rapida di un pagamento (add) e render della modal
 * di inserimento (modal_add_payment).
 */
class PaymentsAjax extends BaseController
{
    protected PaymentModel $paymentModel;

    public function __construct()
    {
        $this->paymentModel = new PaymentModel();
    }

    /**
     * Salva un pagamento via AJAX e ricalcola gli importi della fattura;
     * risponde in JSON come in CI3 ({success, payment_id} / {validation_errors}).
     */
    public function add()
    {
        $post = $this->request->getPost();

        $response = [
            'success'   => 0,
            'new_token' => csrf_hash(),
        ];

        // Il pagamento viene scritto sull'invoice_id arrivato in POST, e
        // PaymentModel ricalcola i totali di QUELLA fattura: senza questo
        // controllo una invoice_id di un'altra azienda corromperebbe i suoi
        // importi (ip_payments non ha company_id, lo prende dalla fattura).
        // Stesso controllo di Payments::form() (vedi commento lì).
        $postInvoiceId = (int) ($post['invoice_id'] ?? 0);

        if ($postInvoiceId > 0 && !(new InvoiceModel())->findScoped($postInvoiceId)) {
            $response['validation_errors'] = ['invoice_id' => trans('invoice_not_found', '', 'Fattura non trovata o non appartenente all\'azienda attiva')];

            return $this->response->setJSON($response);
        }

        // Rimborso di una nota di credito scritto in positivo: il segno lo
        // mette il modello, come in Payments::form().
        $post = $this->paymentModel->applyDocumentSign($post);

        if ($this->paymentModel->validate($post)) {
            $dbArray   = $this->paymentModel->dbArrayFromPost($post);
            $paymentId = $this->paymentModel->saveRecord(null, $dbArray);

            $response = [
                'success'    => 1,
                'payment_id' => (int) $paymentId,
                'new_token'  => csrf_hash(),
            ];
        } else {
            $response['validation_errors'] = $this->paymentModel->errors();
        }

        return $this->response->setJSON($response);
    }

    /**
     * View della modal per inserire un pagamento per una fattura specifica
     * (equivalente di modal_add_payment() CI3). Richiede i parametri
     * invoice_id, invoice_balance, invoice_payment_method, payment_cf_exist.
     */
    public function modalAddPayment()
    {
        $data = [
            'payment_methods'        => (new PaymentMethodModel())->getAllOrdered(),
            'invoice_id'             => $this->request->getPost('invoice_id'),
            'invoice_balance'        => $this->request->getPost('invoice_balance'),
            'invoice_payment_method' => $this->request->getPost('invoice_payment_method'),
            'payment_cf_exist'       => $this->request->getPost('payment_cf_exist') ?: 'no',
        ];

        return view('payments/modal_add_payment', $data);
    }
}