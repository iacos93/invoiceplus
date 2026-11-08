<?php

namespace App\Controllers;

use App\Models\ProductModel;
use App\Models\FamilyModel;
use App\Models\UnitModel;
use App\Models\TaxRateModel;

/**
 * Products controller
 *
 * Porting CI4 del controller Products (CI3).
 * Stessa logica applicativa: index (lista), form (crea/modifica), delete.
 */
class Products extends BaseController
{
    protected ProductModel $productModel;

    public function __construct()
    {
        $this->productModel = new ProductModel();
    }

    public function index()
    {
        $data = [
            'products' => $this->productModel->getAllWithJoins(),
        ];

        return view('products/index', $data);
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/products');
            }

            $post = $request->getPost();

            // Perimetro azienda anche in scrittura: la GET controlla già
            // l'appartenenza, ma il POST con lo stesso id la scavalcherebbe
            // (update() filtra solo per chiave primaria).
            if ($id && !$this->productModel->findScoped($id)) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }

            if ($this->productModel->validate($post)) {
                $dbArray = $this->productModel->dbArrayFromPost($post);
                $this->productModel->saveRecord($id, $dbArray);

                session()->setFlashdata('alert_success', $id
                    ? 'Prodotto aggiornato correttamente'
                    : 'Prodotto creato correttamente');

                return redirect()->to('/products');
            }

            // Validazione fallita: ripopola i valori dal post per la view
            $this->productModel->formValues = $post;

            $data = [
                'product_id' => $id,
                'errors'     => $this->productModel->errors(),
                'model'      => $this->productModel,
                'families'   => (new FamilyModel())->findAll(),
                'unit_groups' => (new UnitModel())->getGroupedByType(),
                'tax_rates'  => (new TaxRateModel())->findAll(),
            ];

            return view('products/form', $data);
        }

        if ($id) {
            // findScoped() prima di prepForm(): il record va cercato dentro il
            // perimetro dell'azienda attiva, altrimenti con l'id nell'URL si apre
            // in modifica la scheda di un altro soggetto giuridico -- e il
            // salvataggio successivo la riscrive senza spostarla, quindi la
            // modifica resta invisibile a chi la subisce.
            if (!$this->productModel->findScoped($id) || !$this->productModel->prepForm($id)) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }
        }

        $data = [
            'product_id' => $id,
            'errors'     => [],
            'model'      => $this->productModel,
            'families'   => (new FamilyModel())->findAll(),
            'unit_groups' => (new UnitModel())->getGroupedByType(),
            'tax_rates'  => (new TaxRateModel())->findAll(),
        ];

        return view('products/form', $data);
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Il perimetro azienda si verifica prima di cancellare: con l'id nel
        // POST si distruggeva il record di un altro soggetto giuridico, e una
        // cancellazione non si annulla.
        if (!$this->productModel->findScoped($id)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $this->productModel->delete($id);
        session()->setFlashdata('alert_success', 'Prodotto eliminato correttamente');

        return redirect()->to('/products');
    }
}