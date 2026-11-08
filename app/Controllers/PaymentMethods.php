<?php

namespace App\Controllers;

use App\Models\BankAccountModel;
use App\Models\PaymentMethodModel;

/**
 * PaymentMethods controller
 *
 * Porting CI4 del controller Payment_Methods (CI3).
 */
class PaymentMethods extends BaseController
{
    protected PaymentMethodModel $paymentMethodModel;

    public function __construct()
    {
        $this->paymentMethodModel = new PaymentMethodModel();
    }

    public function index()
    {
        $data = [
            'payment_methods' => $this->paymentMethodModel->getAllOrdered(list_per_page(), list_page('ip_payment_methods')),
            'usage'           => $this->paymentMethodModel->usageCounts(),
            'pager'           => $this->paymentMethodModel->pager,
            'group'           => 'ip_payment_methods',
        ];

        return view('payment_methods/index', $data);
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/payment_methods');
            }

            $post = $request->getPost();

            // Il nome duplicato lo intercetta la regola is_unique del model:
            // prima il controllo stava qui, girava solo in creazione (quindi
            // rinominando si creavano due metodi identici) e rispondeva con un
            // redirect che buttava via quanto era stato digitato. Ora l'errore
            // torna sul campo, con il form ancora compilato.
            $post['payment_method_id'] = $id; // usato dal placeholder di is_unique

            if ($this->paymentMethodModel->validate($post)) {
                $dbArray = $this->paymentMethodModel->dbArrayFromPost($post);
                $this->paymentMethodModel->saveRecord($id, $dbArray);

                session()->setFlashdata('alert_success', $id
                    ? 'Metodo di pagamento aggiornato correttamente'
                    : 'Metodo di pagamento creato correttamente');

                return redirect()->to('/payment_methods');
            }

            $this->paymentMethodModel->formValues = $post;

            $data = [
                'payment_method_id' => $id,
                'errors'            => $this->paymentMethodModel->errors(),
                'model'             => $this->paymentMethodModel,
                'default_account'   => (new BankAccountModel())->getDefault(),
            ];

            return view('payment_methods/form', $data);
        }

        if ($id) {
            if (!$this->paymentMethodModel->prepForm($id)) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }
        }

        // Il conto predefinito serve solo all'anteprima: e' il conto che
        // verrebbe stampato accanto a questo metodo su una fattura di oggi.
        $data = [
            'payment_method_id' => $id,
            'errors'            => [],
            'model'             => $this->paymentMethodModel,
            'default_account'   => (new BankAccountModel())->getDefault(),
        ];

        return view('payment_methods/form', $data);
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Fatture e pagamenti tengono solo l'id del metodo: eliminarlo lascia
        // celle vuote nello storico e nel report degli incassi per metodo,
        // senza modo di ricostruire quale fosse.
        $usage = $this->paymentMethodModel->usageFor($id);

        if ($usage > 0) {
            session()->setFlashdata('alert_error',
                'Il metodo è usato in ' . $usage . ' ' . ($usage === 1 ? 'documento' : 'documenti')
                . ': non può essere eliminato. Rinominalo se non lo usi più.');

            return redirect()->to('/payment_methods');
        }

        $this->paymentMethodModel->delete($id);
        session()->setFlashdata('alert_success', 'Metodo di pagamento eliminato correttamente');

        return redirect()->to('/payment_methods');
    }
}
