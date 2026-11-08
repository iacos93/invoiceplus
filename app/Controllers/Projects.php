<?php

namespace App\Controllers;

use App\Models\ClientModel;
use App\Models\ProjectModel;
use App\Models\TaskModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Projects controller
 *
 * Porting CI4 del controller Projects (CI3).
 * Stessa logica applicativa: index (lista progetti con cliente),
 * form (crea/modifica), view (dettaglio con attività), delete.
 */
class Projects extends BaseController
{
    protected ProjectModel $projectModel;

    public function __construct()
    {
        $this->projectModel = new ProjectModel();
    }

    public function index()
    {
        $data = [
            'projects' => $this->projectModel->getAllWithClient(),
            'summary'  => $this->projectModel->getSummary(),
        ];

        return view('projects/index', $data);
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/projects');
            }

            $post = $request->getPost();

            // Perimetro azienda anche in scrittura: la GET controlla già
            // l'appartenenza, ma il POST con lo stesso id la scavalcherebbe
            // (update() filtra solo per chiave primaria).
            if ($id && !$this->projectModel->findScoped($id)) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }

            if ($this->projectModel->validate($post)) {
                $dbArray = $this->projectModel->dbArrayFromPost($post);
                $this->projectModel->saveRecord($id, $dbArray);

                session()->setFlashdata('alert_success', $id
                    ? 'Progetto aggiornato correttamente'
                    : 'Progetto creato correttamente');

                return redirect()->to('/projects');
            }

            // Validazione fallita: ripopola i valori dal post per la view
            $this->projectModel->formValues = $post;

            return $this->renderForm($id, $this->projectModel->errors());
        }

        if ($id) {
            // findScoped() prima di prepForm(): il progetto va cercato dentro il
            // perimetro, che qui passa dal cliente collegato.
            if (!$this->projectModel->findScoped($id) || !$this->projectModel->prepForm($id)) {
                throw PageNotFoundException::forPageNotFound();
            }
        }

        return $this->renderForm($id, []);
    }

    private function renderForm(?int $id, array $errors)
    {
        $data = [
            'project_id' => $id,
            'errors'     => $errors,
            'model'      => $this->projectModel,
            'clients'    => (new ClientModel())->isActive()->getAllOrdered(),
        ];

        return view('projects/form', $data);
    }

    public function view(int $projectId)
    {
        $project = $this->projectModel->getByIdWithClient($projectId);

        if (!$project) {
            throw PageNotFoundException::forPageNotFound();
        }

        $tasks   = $this->projectModel->getTasks($projectId);
        $taskStatuses = (new TaskModel())->statuses();

        // Conteggi in ProjectModel::taskStats(), dove sono verificabili.
        $stats = ProjectModel::taskStats($tasks);

        $data = [
            'project'       => $project,
            'tasks'         => $tasks,
            'task_statuses' => $taskStatuses,
            'stats'         => $stats,
        ];

        return view('projects/view', $data);
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        // Prima scollega le attività del progetto (come update_on_project_delete CI3)
        (new TaskModel())->updateOnProjectDelete($id);

        // Il perimetro si verifica prima di cancellare: l'id arriva dall'URL e
        // una cancellazione non si annulla.
        if (!$this->projectModel->findScoped($id)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $this->projectModel->delete($id);
        session()->setFlashdata('alert_success', 'Progetto eliminato correttamente');

        return redirect()->to('/projects');
    }
}