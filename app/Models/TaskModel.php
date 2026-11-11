<?php

namespace App\Models;

class TaskModel extends IpBaseModel
{
    protected $table            = 'ip_tasks';
    protected $primaryKey       = 'task_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'project_id',
        'task_name',
        'task_description',
        'task_price',
        'task_finish_date',
        'task_status',
        'tax_rate_id',
    ];

    protected $validationRules = [
        'task_name'        => 'required',
        'task_description' => 'permit_empty',
        'task_price'       => 'required',
        'task_finish_date' => 'required',
        'project_id'       => 'permit_empty',
        'task_status'      => 'permit_empty',
        'tax_rate_id'      => 'permit_empty',
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * Recupera tutte le attività con nome progetto e flag is_overdue.
     */
    /**
     * Attivita' dell'azienda attiva, o null se e' di un'altra.
     *
     * Come per ProjectModel: ip_tasks non ha company_id e il findScoped()
     * generico sarebbe un no-op. Si passa da progetto e cliente con lo stesso
     * join di getAllWithProject(), quindi le attivita' senza progetto restano
     * visibili come nella lista (il left join le lascia con company_id NULL, e
     * le righe NULL sono visibili a tutti per la regola dell'archivio).
     */
    public function findScoped(int $id): ?array
    {
        $row = $this->select('ip_tasks.*')
            ->join('ip_projects', 'ip_projects.project_id = ip_tasks.project_id', 'left')
            ->join('ip_clients', 'ip_clients.client_id = ip_projects.client_id', 'left')
            ->scopeToCompany(null, 'ip_clients')
            ->where('ip_tasks.task_id', $id)
            ->first();

        return $row ? (array) $row : null;
    }

    public function getAllWithProject(string $status = 'all'): array
    {
        $this->select('ip_tasks.*, ip_projects.project_name, (CASE WHEN ip_tasks.task_status != 4 AND (CURRENT_DATE - ip_tasks.task_finish_date) > 0 THEN 1 ELSE 0 END) AS is_overdue', false)
            ->join('ip_projects', 'ip_projects.project_id = ip_tasks.project_id', 'left')
            ->join('ip_clients', 'ip_clients.client_id = ip_projects.client_id', 'left');

        if (is_numeric($status)) {
            $this->where('ip_tasks.task_status', (int) $status);
        } elseif ($status === 'overdue') {
            $this->where('ip_tasks.task_status !=', 4)
                 ->where('(CURRENT_DATE - ip_tasks.task_finish_date) >', 0);
        }

        $this->scopeToCompany(null, 'ip_clients');

        return $this->orderBy('ip_projects.project_name', 'ASC')
            ->orderBy('ip_tasks.task_name', 'ASC')
            ->findAll();
    }

    /**
     * Conteggi per stato e scadute (per i badge nella vista index).
     *
     * @return array{counts: array<int, int>, total: int, overdue: int}
     */
    public function getStatusCounts(): array
    {
        $builder = $this->db->table('ip_tasks')
            ->select('ip_tasks.task_status, COUNT(*) as cnt, SUM(CASE WHEN ip_tasks.task_finish_date < CURRENT_DATE AND ip_tasks.task_status != 4 THEN 1 ELSE 0 END) as overdue_cnt')
            ->join('ip_projects', 'ip_projects.project_id = ip_tasks.project_id', 'left')
            ->join('ip_clients', 'ip_clients.client_id = ip_projects.client_id', 'left');

        $builder = scope_builder_to_company($builder, 'ip_clients');

        $results = $builder->groupBy('ip_tasks.task_status')->get()->getResultArray();

        $counts = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        $total = 0;
        $overdue = 0;

        foreach ($results as $row) {
            $statusId = (int) $row['task_status'];
            $cnt = (int) $row['cnt'];
            if (isset($counts[$statusId])) {
                $counts[$statusId] = $cnt;
            }
            $total += $cnt;
            $overdue += (int) ($row['overdue_cnt'] ?? 0);
        }

        return [
            'counts'  => $counts,
            'total'   => $total,
            'overdue' => $overdue,
        ];
    }

    /**
     * Attività completate (stato 3) pronte per essere fatturate in una fattura.
     */
    public function getTasksToInvoice(int $invoiceId): array
    {
        if (!$invoiceId) {
            return [];
        }

        // 1. Attività senza progetto. Allineata a getAllWithProject() per
        // coerenza: senza company_id proprio, ip_tasks scopa tramite
        // progetto->cliente, ma un'attività senza progetto non ha quel
        // percorso, quindi resta visibile a tutte le aziende come in
        // getAllWithProject() (stessa regola "company_id NULL sempre
        // visibile" dell'archivio pre multi-azienda). Prima non aveva
        // nemmeno il join: era un'incoerenza rispetto al resto del model,
        // non solo un'assenza di scoping.
        $builder1 = $this->db->table('ip_tasks')
            ->select('ip_tasks.*, NULL as project_name')
            ->join('ip_projects', 'ip_projects.project_id = ip_tasks.project_id', 'left')
            ->join('ip_clients', 'ip_clients.client_id = ip_projects.client_id', 'left')
            ->where('ip_tasks.project_id IS NULL OR ip_tasks.project_id = 0', null, false)
            ->where('ip_tasks.task_status', 3);

        $builder1 = scope_builder_to_company($builder1, 'ip_clients');

        $noProjectTasks = $builder1->get()->getResultArray();

        // 2. Attività di progetti del cliente della fattura
        $builder2 = $this->db->table('ip_tasks')
            ->select('ip_tasks.*, ip_projects.project_name')
            ->join('ip_projects', 'ip_projects.project_id = ip_tasks.project_id')
            ->join('ip_invoices', 'ip_invoices.client_id = ip_projects.client_id')
            ->where('ip_invoices.invoice_id', $invoiceId)
            ->where('ip_tasks.task_status', 3)
            ->orderBy('ip_tasks.task_finish_date', 'ASC')
            ->orderBy('ip_projects.project_name', 'ASC')
            ->orderBy('ip_tasks.task_name', 'ASC');

        $clientTasks = $builder2->get()->getResultArray();

        return array_merge($noProjectTasks, $clientTasks);
    }

    /**
     * Riporta a "completata" (3) le attività fatturate in una fattura
     * cancellata (equivalente di update_on_invoice_delete() in CI3).
     */
    public function updateOnInvoiceDelete(int $invoiceId): void
    {
        if (!$invoiceId) {
            return;
        }

        $tasks = $this->db->table('ip_tasks')
            ->select('ip_tasks.task_id')
            ->join('ip_invoice_items', 'ip_invoice_items.item_task_id = ip_tasks.task_id')
            ->where('ip_invoice_items.invoice_id', $invoiceId)
            ->get()
            ->getResultArray();

        foreach ($tasks as $task) {
            $this->updateStatus((int) $task['task_id'], 3);
        }
    }

    /**
     * Scollega le attività di un progetto cancellato mantenendone lo stato
     * (equivalente di update_on_project_delete() in CI3).
     */
    public function updateOnProjectDelete(int $projectId): void
    {
        if (!$projectId) {
            return;
        }

        $tasks = $this->db->table('ip_tasks')
            ->select('ip_tasks.task_id')
            ->where('ip_tasks.project_id', $projectId)
            ->get()
            ->getResultArray();

        foreach ($tasks as $task) {
            $this->update($task['task_id'], ['project_id' => null]);
        }
    }

    /**
     * Aggiorna lo stato di un'attività solo se lo stato esiste.
     */
    public function updateStatus(int $taskId, int $statusId): bool
    {
        if (!isset($this->statuses()[$statusId])) {
            return false;
        }

        return $this->update($taskId, ['task_status' => $statusId]);
    }

    /**
     * Costruisce l'array da salvare su DB a partire dal POST.
     */
    public function dbArrayFromPost(array $post): array
    {
        $dbArray = parent::dbArrayFromPost($post);

        $dbArray['task_finish_date'] = date_to_mysql($dbArray['task_finish_date'] ?? null);
        $dbArray['task_price']       = standardize_amount($dbArray['task_price'] ?? '0');
        $dbArray['project_id']       = empty($dbArray['project_id'] ?? null) ? null : $dbArray['project_id'];
        $dbArray['task_status']      = empty($dbArray['task_status'] ?? null) ? 1 : $dbArray['task_status'];

        return $dbArray;
    }

    public function statuses(): array
    {
        return [
            1 => ['label' => trans('task_status_1', '', 'Non iniziata'), 'class' => 'draft', 'href' => 'tasks/status/1'],
            2 => ['label' => trans('task_status_2', '', 'In corso'), 'class' => 'sent', 'href' => 'tasks/status/2'],
            3 => ['label' => trans('task_status_3', '', 'Completata'), 'class' => 'paid', 'href' => 'tasks/status/3'],
            4 => ['label' => trans('task_status_4', '', 'In attesa'), 'class' => 'canceled', 'href' => 'tasks/status/4'],
        ];
    }

    /**
     * Ultime attivita' dell'azienda attiva.
     *
     * ip_tasks non ha company_id: senza il passaggio da progetto e cliente
     * (lo stesso di getAllWithProject()) la Panoramica mostrava le attivita'
     * di tutte le aziende.
     */
    public function getLatest(int $limit = 5): array
    {
        return $this->select('ip_tasks.*')
            ->join('ip_projects', 'ip_projects.project_id = ip_tasks.project_id', 'left')
            ->join('ip_clients', 'ip_clients.client_id = ip_projects.client_id', 'left')
            ->scopeToCompany(null, 'ip_clients')
            ->orderBy('ip_tasks.task_id', 'DESC')
            ->limit($limit)
            ->findAll();
    }

    /**
     * Attivita' non ancora fatturate dell'azienda attiva (badge della
     * Panoramica): stesso perimetro di getLatest().
     */
    public function countOpen(): int
    {
        $builder = $this->db->table('ip_tasks')
            ->join('ip_projects', 'ip_projects.project_id = ip_tasks.project_id', 'left')
            ->join('ip_clients', 'ip_clients.client_id = ip_projects.client_id', 'left')
            ->where('ip_tasks.task_status !=', 4);

        return scope_builder_to_company($builder, 'ip_clients')->countAllResults();
    }
}