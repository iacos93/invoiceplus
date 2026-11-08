<?php

namespace App\Controllers;

use App\Models\CustomFieldModel;
use App\Models\CustomValueModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * CustomValues controller
 *
 * Porting CI4 del controller Custom_Values (CI3).
 * Stessa logica applicativa: index (campi con conteggio valori),
 * field (valori di un campo), create/edit/delete dei valori.
 */
class CustomValues extends BaseController
{
    protected CustomValueModel $customValueModel;
    protected CustomFieldModel $customFieldModel;

    public function __construct()
    {
        $this->customValueModel = new CustomValueModel();
        $this->customFieldModel = new CustomFieldModel();
    }

    public function index()
    {
        $data = [
            'custom_values'      => $this->customValueModel->getGrouped(),
            'custom_value_types' => $this->customFieldModel->customValueTypes(),
        ];

        return view('custom_values/index', $data);
    }

    public function field(int $fieldId)
    {
        $field = $this->customFieldModel->find($fieldId);

        if (!$field) {
            throw PageNotFoundException::forPageNotFound();
        }

        $data = [
            'field'    => $field,
            'elements' => $this->customValueModel->byField($fieldId),
        ];

        return view('custom_values/field', $data);
    }

    public function create(int $fieldId)
    {
        $field = $this->customFieldModel->find($fieldId);

        if (!$field) {
            throw PageNotFoundException::forPageNotFound();
        }

        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/custom_values/field/' . $fieldId);
            }

            $post = $request->getPost();

            if ($this->customValueModel->validate($post)) {
                $this->customValueModel->saveCustom($fieldId, $post);

                session()->setFlashdata('alert_success', 'Valore personalizzato creato correttamente');

                return redirect()->to('/custom_values/field/' . $fieldId);
            }

            // Validazione fallita: ripopola i valori dal post per la view
            $this->customValueModel->formValues = $post;

            $data = [
                'field'            => $field,
                'errors'           => $this->customValueModel->errors(),
                'model'            => $this->customValueModel,
                'has_order_column' => $this->customValueModel->hasOrderColumn(),
            ];

            return view('custom_values/new', $data);
        }

        $data = [
            'field'            => $field,
            'errors'           => [],
            'model'            => $this->customValueModel,
            'has_order_column' => $this->customValueModel->hasOrderColumn(),
        ];

        return view('custom_values/new', $data);
    }

    public function edit(int $id)
    {
        $value = $this->customValueModel->find($id);

        if (!$value) {
            throw PageNotFoundException::forPageNotFound();
        }

        $fieldId = (int) $value['custom_values_field'];
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/custom_values/field/' . $fieldId);
            }

            $post = $request->getPost();

            if ($this->customValueModel->validate($post)) {
                $dbArray = $this->customValueModel->dbArrayFromPost($post);
                $this->customValueModel->saveRecord($id, $dbArray);

                session()->setFlashdata('alert_success', 'Valore personalizzato aggiornato correttamente');

                return redirect()->to('/custom_values/field/' . $fieldId);
            }

            // Validazione fallita: ripopola i valori dal post per la view
            $this->customValueModel->formValues = $post;
            $value = $post;
        } else {
            $this->customValueModel->formValues = $value;
        }

        $field = $this->customFieldModel->find($fieldId);

        $data = [
            'value'            => $value,
            'field'            => $field,
            'errors'           => $this->customValueModel->errors(),
            'model'            => $this->customValueModel,
            'has_order_column' => $this->customValueModel->hasOrderColumn(),
        ];

        return view('custom_values/edit', $data);
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $value = $this->customValueModel->find($id);

        if (!$value) {
            return redirect()->to('/custom_values');
        }

        $this->customValueModel->delete($id);
        session()->setFlashdata('alert_success', 'Valore personalizzato eliminato correttamente');

        return redirect()->to('/custom_values/field/' . $value['custom_values_field']);
    }
}