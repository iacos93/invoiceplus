<?php

namespace App\Controllers;

use App\Models\TaxRateModel;

/**
 * TaxRates controller
 *
 * Porting CI4 del controller Tax_Rates (CI3).
 * Stessa logica applicativa: index (lista), form (crea/modifica), delete.
 */
class TaxRates extends BaseController
{
    protected TaxRateModel $taxRateModel;

    public function __construct()
    {
        $this->taxRateModel = new TaxRateModel();
    }

    public function index()
    {
        $data = [
            'tax_rates' => $this->taxRateModel->getAllOrdered(list_per_page(), list_page('ip_tax_rates')),
            'pager'     => $this->taxRateModel->pager,
            'group'     => 'ip_tax_rates',
        ];

        return view('tax_rates/index', $data);
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/tax_rates');
            }

            $post = $request->getPost();

            // In italiano l'aliquota si scrive "4,00": la regola numeric la
            // rifiutava ("deve essere un numero"). Non si passa da
            // standardize_amount() perché dipende dal separatore impostato,
            // e con il punto come decimale tratterebbe la virgola da
            // separatore delle migliaia, trasformando 4,00 in 400.
            if (isset($post['tax_rate_percent']) && str_contains((string) $post['tax_rate_percent'], ',')) {
                $post['tax_rate_percent'] = str_replace(',', '.', str_replace('.', '', trim((string) $post['tax_rate_percent'])));
            }

            if ($this->taxRateModel->validate($post)) {
                $dbArray = $this->taxRateModel->dbArrayFromPost($post);
                $this->taxRateModel->saveRecord($id, $dbArray);

                session()->setFlashdata('alert_success', $id
                    ? 'Aliquota aggiornata correttamente'
                    : 'Aliquota creata correttamente');

                return redirect()->to('/tax_rates');
            }

            // Validazione fallita: ripopola i valori dal post per la view
            $this->taxRateModel->formValues = $post;

            $data = [
                'tax_rate_id' => $id,
                'errors'      => $this->taxRateModel->errors(),
                'model'       => $this->taxRateModel,
            ];

            return view('tax_rates/form', $data);
        }

        if ($id) {
            if (!$this->taxRateModel->prepForm($id)) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }
        }

        $data = [
            'tax_rate_id' => $id,
            'errors'      => [],
            'model'       => $this->taxRateModel,
        ];

        return view('tax_rates/form', $data);
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $this->taxRateModel->delete($id);
        session()->setFlashdata('alert_success', 'Aliquota eliminata correttamente');

        return redirect()->to('/tax_rates');
    }
}
