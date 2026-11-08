<?php

namespace App\Controllers;

use App\Models\ExpenseCategoryModel;

/**
 * ExpenseCategories — voci di costo usate dai report per categoria.
 */
class ExpenseCategories extends BaseController
{
    protected ExpenseCategoryModel $model;

    public function __construct()
    {
        $this->model = new ExpenseCategoryModel();
    }

    public function index()
    {
        return view('expense_categories/index', [
            'categories' => $this->model->getAllOrdered(list_per_page(), list_page('ip_expense_categories')),
            'pager'      => $this->model->pager,
            'group'      => 'ip_expense_categories',
        ]);
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            $post = $request->getPost();
            // La checkbox non arriva quando è spenta: senza questo non si
            // potrebbe più marcare una categoria come non deducibile.
            // Booleano nativo: PostgreSQL rifiuta gli interi su colonne boolean.
            $post['expense_category_deductible'] = !empty($post['expense_category_deductible']);

            if ($this->model->validate($post)) {
                $this->model->saveRecord($id, $this->model->dbArrayFromPost($post));
                session()->setFlashdata('alert_success', 'Categoria salvata correttamente');

                return redirect()->to('/expense_categories');
            }

            $this->model->formValues = $post;

            return view('expense_categories/form', [
                'expense_category_id' => $id, 'errors' => $this->model->errors(), 'model' => $this->model,
            ]);
        }

        if ($id && !$this->model->prepForm($id)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        return view('expense_categories/form', [
            'expense_category_id' => $id, 'errors' => [], 'model' => $this->model,
        ]);
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $this->model->delete($id);
        session()->setFlashdata('alert_success', 'Categoria eliminata correttamente');

        return redirect()->to('/expense_categories');
    }
}
