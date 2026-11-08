<?php

namespace App\Controllers;

use App\Models\UnitModel;

/**
 * Units controller
 *
 * Porting CI4 del controller Units (CI3).
 * Stessa logica applicativa: index (lista), form (crea/modifica, con
 * controllo anti-duplicato sul nome unita'), delete.
 */
class Units extends BaseController
{
    protected UnitModel $unitModel;

    public function __construct()
    {
        $this->unitModel = new UnitModel();
    }

    public function index()
    {
        $data = [
            'units' => $this->unitModel->getAllOrdered(list_per_page(), list_page('ip_units')),
            'pager' => $this->unitModel->pager,
            'group' => 'ip_units',
        ];

        return view('units/index', $data);
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/units');
            }

            $post = $request->getPost();

            // Controllo anti-duplicato sul nome unita' (solo in creazione,
            // stesso comportamento del controller CI3 originale).
            if (!$id && !empty($post['unit_name']) && $this->unitModel->nameExists($post['unit_name'])) {
                session()->setFlashdata('alert_error', 'Un\'unita\' con questo nome esiste gia\'');
                return redirect()->to('/units/form');
            }

            if ($this->unitModel->validate($post)) {
                $dbArray = $this->unitModel->dbArrayFromPost($post);
                $this->unitModel->saveRecord($id, $dbArray);

                session()->setFlashdata('alert_success', $id
                    ? 'Unita\' aggiornata correttamente'
                    : 'Unita\' creata correttamente');

                return redirect()->to('/units');
            }

            // Validazione fallita: ripopola i valori dal post per la view
            $this->unitModel->formValues = $post;

            $data = [
                'unit_id'     => $id,
                'errors'      => $this->unitModel->errors(),
                'model'       => $this->unitModel,
                'unit_types' => UnitModel::UNIT_TYPES,
            ];

            return view('units/form', $data);
        }

        if ($id) {
            if (!$this->unitModel->prepForm($id)) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }
        }

        $data = [
            'unit_id'     => $id,
            'errors'      => [],
            'model'       => $this->unitModel,
            'unit_types'  => UnitModel::UNIT_TYPES,
        ];

        return view('units/form', $data);
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $this->unitModel->delete($id);
        session()->setFlashdata('alert_success', 'Unita\' eliminata correttamente');

        return redirect()->to('/units');
    }
}
