<?php

namespace App\Controllers;

use App\Models\InvoiceModel;
use App\Models\TaskModel;

/**
 * TasksAjax controller
 *
 * Porting CI4 del controller Ajax del modulo tasks (CI3):
 * elenco attività completate da fatturare (modal) e processazione
 * delle selezioni in JSON per il modulo invoices.
 */
class TasksAjax extends BaseController
{
    protected TaskModel $taskModel;

    public function __construct()
    {
        $this->taskModel = new TaskModel();
    }

    /**
     * Attività completate e non ancora fatturate per il popup
     * "Aggiungi attività" (equivalente di modal_task_lookups() in CI3).
     */
    public function modalTaskLookups(?int $invoiceId = null)
    {
        $data = [
            'tasks' => [],
        ];

        // getTasksToInvoice() risale al cliente/azienda tramite l'invoice_id
        // passato qui: senza verificarlo prima, un id di un'altra azienda
        // faceva comparire nel modale le attività completate (con prezzo e
        // descrizione) del cliente di quell'altra azienda.
        if (!empty($invoiceId) && (new InvoiceModel())->findScoped($invoiceId)) {
            $data['tasks'] = $this->taskModel->getTasksToInvoice($invoiceId);
        }

        return view('tasks/modal_task_lookups', $data);
    }

    /**
     * Restituisce in JSON le attività selezionate (task_id[]) con il
     * prezzo formattato (equivalente di process_task_selections() in CI3).
     */
    public function processTaskSelections()
    {
        $taskIds = $this->request->getPost('task_ids');
        $taskIds = is_array($taskIds) ? array_map('intval', $taskIds) : [];

        $tasks = [];
        if ($taskIds) {
            // Stesso join di TaskModel::findScoped(): senza scoping, un
            // utente poteva inviare task_id di un'altra azienda e ricevere
            // nome/prezzo/descrizione, potenzialmente allegandoli a una
            // propria fattura tramite questo stesso endpoint.
            $tasks = $this->taskModel
                ->select('ip_tasks.*')
                ->join('ip_projects', 'ip_projects.project_id = ip_tasks.project_id', 'left')
                ->join('ip_clients', 'ip_clients.client_id = ip_projects.client_id', 'left')
                ->scopeToCompany(null, 'ip_clients')
                ->whereIn('ip_tasks.task_id', $taskIds)
                ->findAll();
        }

        foreach ($tasks as &$task) {
            $task['task_price'] = format_amount($task['task_price']);
        }
        unset($task);

        return $this->response->setJSON($tasks);
    }
}