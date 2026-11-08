<?php

namespace App\Controllers;

use App\Models\CustomFieldModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * CustomFields controller
 *
 * Porting CI4 del controller Custom_Fields (CI3).
 * Stessa logica applicativa: index (lista campi), form (crea/modifica),
 * delete (con pulizia dei valori collegati).
 */
class CustomFields extends BaseController
{
    protected CustomFieldModel $customFieldModel;

    public function __construct()
    {
        $this->customFieldModel = new CustomFieldModel();
    }

    public function index()
    {
        $data = [
            'custom_fields'      => $this->customFieldModel->getAllOrdered(list_per_page(), list_page('ip_custom_fields')),
            'custom_tables'      => $this->customFieldModel->customTables(),
            'custom_value_types' => $this->customFieldModel->customValueTypes(),
            'pager'              => $this->customFieldModel->pager,
            'group'              => 'ip_custom_fields',
        ];

        return view('custom_fields/index', $data);
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/custom_fields');
            }

            $post = $request->getPost();

            if ($this->customFieldModel->validate($post)) {
                $dbArray = $this->customFieldModel->dbArrayFromPost($post);

                try {
                    $this->customFieldModel->saveRecord($id, $dbArray);

                    session()->setFlashdata('alert_success', $id
                        ? 'Campo personalizzato aggiornato correttamente'
                        : 'Campo personalizzato creato correttamente');

                    return redirect()->to('/custom_fields');
                } catch (DatabaseException $e) {
                    // Vincolo UNIQUE (custom_field_table, custom_field_label)
                    session()->setFlashdata('alert_error',
                        'Etichetta già utilizzata per la tabella selezionata');
                }
            }

            // Validazione fallita: ripopola i valori dal post per la view
            $this->customFieldModel->formValues = $post;

            $data = [
                'custom_field_id'     => $id,
                'errors'              => $this->customFieldModel->errors(),
                'model'               => $this->customFieldModel,
                'custom_field_tables' => $this->customFieldModel->customTables(),
                'custom_field_types'  => $this->customFieldModel->customTypes(),
                'has_required_column' => $this->customFieldModel->hasRequiredColumn(),
            ];

            return view('custom_fields/form', $data);
        }

        if ($id) {
            if (!$this->customFieldModel->prepForm($id)) {
                throw PageNotFoundException::forPageNotFound();
            }
        }

        $data = [
            'custom_field_id'     => $id,
            'errors'              => [],
            'model'               => $this->customFieldModel,
            'custom_field_tables' => $this->customFieldModel->customTables(),
            'custom_field_types'  => $this->customFieldModel->customTypes(),
            'has_required_column' => $this->customFieldModel->hasRequiredColumn(),
        ];

        return view('custom_fields/form', $data);
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->customFieldModel->deleteWithValues($id);
        session()->setFlashdata('alert_success', 'Campo personalizzato eliminato correttamente');

        return redirect()->to('/custom_fields');
    }
}