<?php

namespace App\Controllers;

use App\Models\FamilyModel;

/**
 * Families controller
 *
 * Porting CI4 del controller Families (CI3).
 * Stessa logica applicativa: index (lista), form (crea/modifica, con
 * controllo anti-duplicato sul nome famiglia), delete.
 */
class Families extends BaseController
{
    protected FamilyModel $familyModel;

    public function __construct()
    {
        $this->familyModel = new FamilyModel();
    }

    public function index()
    {
        $data = [
            'families' => $this->familyModel->getAllOrdered(list_per_page(), list_page('ip_families')),
            'pager'    => $this->familyModel->pager,
            'group'    => 'ip_families',
        ];

        return view('families/index', $data);
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/families');
            }

            $post = $request->getPost();

            // Controllo anti-duplicato sul nome famiglia (solo in creazione,
            // stesso comportamento del controller CI3 originale).
            if (!$id && !empty($post['family_name']) && $this->familyModel->nameExists($post['family_name'])) {
                session()->setFlashdata('alert_error', 'Una famiglia con questo nome esiste gia\'');
                return redirect()->to('/families/form');
            }

            if ($this->familyModel->validate($post)) {
                $dbArray = $this->familyModel->dbArrayFromPost($post);
                $this->familyModel->saveRecord($id, $dbArray);

                session()->setFlashdata('alert_success', $id
                    ? 'Famiglia aggiornata correttamente'
                    : 'Famiglia creata correttamente');

                return redirect()->to('/families');
            }

            // Validazione fallita: ripopola i valori dal post per la view
            $this->familyModel->formValues = $post;

            $data = [
                'family_id' => $id,
                'errors'    => $this->familyModel->errors(),
                'model'     => $this->familyModel,
            ];

            return view('families/form', $data);
        }

        if ($id) {
            if (!$this->familyModel->prepForm($id)) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }
        }

        $data = [
            'family_id' => $id,
            'errors'    => [],
            'model'     => $this->familyModel,
        ];

        return view('families/form', $data);
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $this->familyModel->delete($id);
        session()->setFlashdata('alert_success', 'Famiglia eliminata correttamente');

        return redirect()->to('/families');
    }
}
