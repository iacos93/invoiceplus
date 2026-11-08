<?php

namespace App\Controllers;

use App\Models\ProjectModel;
use App\Models\TaskModel;
use App\Models\TaxRateModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Tasks controller
 *
 * Porting CI4 del controller Tasks (CI3).
 * Stessa logica applicativa: index (lista attività con progetto e
 * flag is_overdue), form (crea/modifica con valori predefiniti per
 * le nuove attività), delete.
 */
class Tasks extends BaseController
{
    protected TaskModel $taskModel;

    public function __construct()
    {
        $this->taskModel = new TaskModel();
    }

    public function index()
    {
        return redirect()->to('/tasks/status/all');
    }

    public function status(string $status = 'all')
    {
        $data = [
            'tasks'         => $this->taskModel->getAllWithProject($status),
            'status'        => $status,
            'task_statuses' => $this->taskModel->statuses(),
            'summary'       => $this->taskModel->getStatusCounts(),
        ];

        return view('tasks/index', $data);
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/tasks');
            }

            $post = $request->getPost();

            // Perimetro azienda anche in scrittura: la GET controlla già
            // l'appartenenza, ma il POST con lo stesso id la scavalcherebbe
            // (update() filtra solo per chiave primaria).
            if ($id && !$this->taskModel->findScoped($id)) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }

            // Attività già fatturata: sola lettura (come il form CI3, che
            // disabilitava i campi). Il guard sta qui, lato server, perché
            // un POST diretto non deve poterla modificare.
            if ($id) {
                $existing = $this->taskModel->find($id);
                if ($existing && (int) $existing['task_status'] === 4) {
                    session()->setFlashdata('alert_error', trans('info_task_readonly'));

                    return redirect()->to('/tasks/form/' . $id);
                }
            }

            if ($this->taskModel->validate($post)) {
                $dbArray = $this->taskModel->dbArrayFromPost($post);
                $this->taskModel->saveRecord($id, $dbArray);

                session()->setFlashdata('alert_success', $id
                    ? "Attivita' aggiornata correttamente"
                    : "Attivita' creata correttamente");

                return redirect()->to('/tasks');
            }

            // Validazione fallita: ripopola i valori dal post per la view
            $this->taskModel->formValues = $post;

            return $this->renderForm($id, $this->taskModel->errors());
        }

        if ($id) {
            // findScoped(): il perimetro dell'attivita' passa da progetto e
            // cliente, ip_tasks non ha company_id.
            if (!$this->taskModel->findScoped($id) || !$this->taskModel->prepForm($id)) {
                throw PageNotFoundException::forPageNotFound();
            }

            // Data in formato di visualizzazione
            $this->taskModel->formValues['task_finish_date'] = date_from_mysql($this->taskModel->formValue('task_finish_date'));
        } else {
            // Nuova attività: valori predefiniti (come prep_form() CI3),
            // con il progetto già selezionato se si arriva dal pulsante
            // "Nuova attività" del dettaglio progetto (?project_id=).
            $preselectedProject = (int) service('request')->getGet('project_id');
            $this->taskModel->formValues = [
                'task_finish_date' => date_from_mysql(date('Y-m-d')),
                'task_price'       => get_setting('default_hourly_rate'),
                'task_status'      => 1,
                'project_id'       => $preselectedProject ?: '',
            ];
        }

        return $this->renderForm($id, []);
    }

    private function renderForm(?int $id, array $errors)
    {
        $data = [
            'task_id'       => $id,
            'errors'        => $errors,
            'model'         => $this->taskModel,
            'projects'      => (new ProjectModel())->orderBy('project_name', 'ASC')->findAll(),
            'tax_rates'     => (new TaxRateModel())->findAll(),
            'task_statuses' => $this->taskModel->statuses(),
        ];

        return view('tasks/form', $data);
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        if (!$this->taskModel->findScoped($id)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $this->taskModel->delete($id);
        session()->setFlashdata('alert_success', "Attivita' eliminata correttamente");

        return redirect()->to('/tasks');
    }
}