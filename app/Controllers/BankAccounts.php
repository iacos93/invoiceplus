<?php

namespace App\Controllers;

use App\Models\BankAccountModel;

/**
 * BankAccounts controller — anagrafica dei conti di saldo.
 * Stessa impostazione degli altri moduli di impostazioni (tax_rates,
 * payment_methods): elenco, form di creazione/modifica, cancellazione.
 */
class BankAccounts extends BaseController
{
    protected BankAccountModel $bankAccountModel;

    public function __construct()
    {
        $this->bankAccountModel = new BankAccountModel();
    }

    public function index()
    {
        return view('bank_accounts/index', [
            'bank_accounts' => $this->bankAccountModel->getAllOrdered(list_per_page(), list_page('ip_bank_accounts')),
            'usage'         => $this->bankAccountModel->usageCounts(),
            'pager'         => $this->bankAccountModel->pager,
            'group'         => 'ip_bank_accounts',
        ]);
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        // In modifica il conto deve essere dell'azienda attiva: l'id arriva
        // dall'URL, e senza questo controllo si aprivano (e si salvavano) i
        // conti di un altro soggetto giuridico.
        $account = $id ? $this->bankAccountModel->findScoped($id) : null;

        if ($id && !$account) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/bank_accounts');
            }

            // IBAN/BIC/SIA ripuliti prima della validazione: l'IBAN copiato
            // dall'home banking arriva spaziato, e va sia validato sia salvato
            // nella forma continua che finisce nell'XML.
            $post = BankAccountModel::normalizePost($request->getPost());

            if ($this->bankAccountModel->validate($post)) {
                $dbArray = $this->bankAccountModel->dbArrayFromPost($post);

                // La checkbox non arriva nel POST quando è spenta: senza questo
                // non si potrebbe più togliere il predefinito a un conto.
                $dbArray['bank_account_is_default'] = !empty($post['bank_account_is_default']);

                $this->bankAccountModel->saveAccount($id, $dbArray);

                session()->setFlashdata('alert_success', $id
                    ? 'Conto aggiornato correttamente'
                    : 'Conto creato correttamente');

                return redirect()->to('/bank_accounts');
            }

            $this->bankAccountModel->formValues = $post;

            return view('bank_accounts/form', [
                'bank_account_id' => $id,
                'errors'          => $this->bankAccountModel->errors(),
                'model'           => $this->bankAccountModel,
            ]);
        }

        if ($account) {
            $this->bankAccountModel->formValues = $account;
        }

        return view('bank_accounts/form', [
            'bank_account_id' => $id,
            'errors'          => [],
            'model'           => $this->bankAccountModel,
        ]);
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        if (!$this->bankAccountModel->findScoped($id)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Fatture, spese e movimenti conservano solo l'id del conto: cancellarlo
        // non svuota quei riferimenti, li rende muti. Una fattura emessa con
        // questo IBAN ripiegherebbe in silenzio sul conto predefinito, quindi
        // stamperebbe coordinate diverse da quelle comunicate al cliente.
        $usage = $this->bankAccountModel->usageFor($id);

        if ($usage > 0) {
            session()->setFlashdata('alert_error',
                'Il conto è collegato a ' . $usage . ' ' . ($usage === 1 ? 'documento' : 'documenti')
                . ': non può essere eliminato. Toglilo dai documenti oppure lascialo in archivio.');

            return redirect()->to('/bank_accounts');
        }

        $this->bankAccountModel->delete($id);
        session()->setFlashdata('alert_success', 'Conto eliminato correttamente');

        return redirect()->to('/bank_accounts');
    }
}
