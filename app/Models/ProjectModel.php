<?php

namespace App\Models;

/**
 * ProjectModel
 *
 * Porting CI4 del modulo projects (CI3: Mdl_Projects).
 * Include join con ip_clients (nome cliente), conteggio attività
 * per i progetti recenti e attività del progetto.
 */
class ProjectModel extends IpBaseModel
{
    protected $table            = 'ip_projects';
    protected $primaryKey       = 'project_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'client_id',
        'project_name',
    ];

    protected $validationRules = [
        'project_name' => 'required',
        'client_id'    => 'permit_empty|numeric',
    ];

    protected $validationMessages = [
        'project_name' => [
            'required' => "Il nome del progetto e' obbligatorio",
        ],
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * Lista progetti con nome cliente (join left, come default_join() CI3),
     * data dell'ultima attività e conteggi per la striscia di composizione
     * (attività per stato, completate e scadute).
     */
    public function getAllWithClient(): array
    {
        return $this->select("ip_projects.*, ip_clients.client_name, MAX(ip_tasks.task_finish_date) AS project_last_task_date, "
                . 'COUNT(ip_tasks.task_id) AS task_count, '
                . "SUM(CASE WHEN ip_tasks.task_status = 1 THEN 1 ELSE 0 END) AS task_not_started_count, "
                . "SUM(CASE WHEN ip_tasks.task_status = 2 THEN 1 ELSE 0 END) AS task_in_progress_count, "
                . "SUM(CASE WHEN ip_tasks.task_status = 3 THEN 1 ELSE 0 END) AS task_completed_count, "
                . "SUM(CASE WHEN ip_tasks.task_status = 4 THEN 1 ELSE 0 END) AS task_invoiced_count, "
                . "SUM(CASE WHEN ip_tasks.task_finish_date < CURRENT_DATE AND ip_tasks.task_status NOT IN (3, 4) THEN 1 ELSE 0 END) AS task_overdue_count", false)
            ->join('ip_clients', 'ip_clients.client_id = ip_projects.client_id', 'left')
            ->join('ip_tasks', 'ip_tasks.project_id = ip_projects.project_id', 'left')
            // ip_projects non ha company_id: il progetto appartiene
            // all'azienda del cliente a cui e' intestato.
            ->scopeToCompany(null, 'ip_clients')
            // PostgreSQL esige nel GROUP BY ogni colonna non aggregata:
            // ip_projects.project_id basta per le colonne di ip_projects (è
            // chiave primaria), ma client_name arriva da un'altra tabella e
            // va elencato, altrimenti la lista progetti va in errore 500.
            ->groupBy(['ip_projects.project_id', 'ip_clients.client_name'])
            ->orderBy('ip_projects.project_name', 'ASC')
            ->findAll();
    }

    /**
     * Riepilogo per la lista progetti: numero progetti, attività aperte,
     * completate, fatturate e attività scadute.
     */
    public function getSummary(): array
    {
        $projects = $this->select('COUNT(DISTINCT ip_projects.project_id) AS project_count, '
                . "SUM(CASE WHEN ip_tasks.task_status IN (1, 2) THEN 1 ELSE 0 END) AS open_task_count, "
                . "SUM(CASE WHEN ip_tasks.task_status = 3 THEN 1 ELSE 0 END) AS completed_task_count, "
                . "SUM(CASE WHEN ip_tasks.task_status = 4 THEN 1 ELSE 0 END) AS invoiced_task_count, "
                . "SUM(CASE WHEN ip_tasks.task_finish_date < CURRENT_DATE AND ip_tasks.task_status NOT IN (3, 4) THEN 1 ELSE 0 END) AS overdue_task_count", false)
            ->join('ip_tasks', 'ip_tasks.project_id = ip_projects.project_id', 'left')
            ->join('ip_clients', 'ip_clients.client_id = ip_projects.client_id', 'left')
            // ip_projects non ha company_id: il progetto appartiene
            // all'azienda del cliente a cui e' intestato.
            ->scopeToCompany(null, 'ip_clients')
            ->get()
            ->getRowArray();

        return [
            'projects'           => (int) ($projects['project_count'] ?? 0),
            'open_tasks'         => (int) ($projects['open_task_count'] ?? 0),
            'completed_tasks'    => (int) ($projects['completed_task_count'] ?? 0),
            'invoiced_tasks'     => (int) ($projects['invoiced_task_count'] ?? 0),
            'overdue_tasks'      => (int) ($projects['overdue_task_count'] ?? 0),
        ];
    }

    /**
     * Ultimi N progetti con conteggio attività (equivalente di
     * get_latest() + default_join() in CI3).
     */
    public function getLatest(int $limit = 5): array
    {
        return $this->select('ip_projects.*, ip_clients.client_name, COUNT(ip_tasks.task_id) AS task_count')
            ->join('ip_clients', 'ip_clients.client_id = ip_projects.client_id', 'left')
            ->join('ip_tasks', 'ip_tasks.project_id = ip_projects.project_id', 'left')
            // ip_projects non ha company_id: il progetto appartiene
            // all'azienda del cliente a cui e' intestato.
            ->scopeToCompany(null, 'ip_clients')
            // Stessa ragione di getAllWithClient(): client_name non è
            // coperto dalla chiave primaria di ip_projects.
            ->groupBy(['ip_projects.project_id', 'ip_clients.client_name'])
            ->orderBy('ip_projects.project_id', 'DESC')
            ->limit($limit)
            ->findAll();
    }

    /**
     * Progetto singolo con nome cliente (per la vista dettaglio).
     */
    /**
     * Progetto dell'azienda attiva, o null se e' di un'altra.
     *
     * Override obbligatorio: ip_projects non ha company_id, quindi il
     * findScoped() generico di IpBaseModel qui sarebbe un no-op silenzioso e
     * tornerebbe i progetti di tutte le aziende. Il perimetro passa dal cliente
     * collegato, come in tutte le altre query di questo model.
     */
    public function findScoped(int $id): ?array
    {
        $row = $this->select('ip_projects.*')
            ->join('ip_clients', 'ip_clients.client_id = ip_projects.client_id', 'left')
            ->scopeToCompany(null, 'ip_clients')
            ->where('ip_projects.project_id', $id)
            ->first();

        return $row ? (array) $row : null;
    }

    public function getByIdWithClient(int $projectId): ?array
    {
        // Email e telefono, non solo il nome: la scheda progetto serve anche
        // a capire chi contattare per un aggiornamento, e prima li si doveva
        // cercare aprendo la scheda cliente in un'altra scheda.
        // ip_projects non ha company_id: il perimetro passa dal cliente
        // collegato, come gia' fanno getAllWithClient(), getSummary() e
        // getLatest(). Questo metodo era l'unico senza, e alimenta
        // /projects/view/{id}: cambiando l'id si leggeva la scheda con nome,
        // email e telefono del cliente di un'altra azienda.
        return $this->select('ip_projects.*, ip_clients.client_name, ip_clients.client_email, ip_clients.client_phone')
            ->join('ip_clients', 'ip_clients.client_id = ip_projects.client_id', 'left')
            ->scopeToCompany(null, 'ip_clients')
            ->where('ip_projects.project_id', $projectId)
            ->first();
    }

    /**
     * Attività di un progetto (equivalente di get_tasks() in CI3),
     * con flag is_overdue come nel default_select() di Mdl_Tasks.
     */
    /**
     * Statistiche di un progetto a partire dalle sue attivita'.
     *
     * Statica e senza database: e' regola di conteggio, e va verificata da
     * sola (tests/unit/ProjectTaskStatsTest.php).
     *
     * Uno stato "fatturata" e' la conclusione piu' definitiva che un'attivita'
     * possa avere. Prima finiva nel ramo "else" insieme a "non iniziata" e "in
     * corso", con tre conseguenze: risultava aperta, poteva risultare in
     * ritardo, e soprattutto l'avanzamento contava le sole completate — quindi
     * fatturare il lavoro faceva *scendere* la percentuale di avanzamento del
     * progetto.
     *
     * @param list<array<string, mixed>> $tasks
     *
     * @return array{
     *     total: int, not_started: int, in_progress: int, completed: int,
     *     invoiced: int, open: int, overdue: int, amount: float, progress: int
     * }
     */
    public static function taskStats(array $tasks): array
    {
        $stats = [
            'total' => 0, 'not_started' => 0, 'in_progress' => 0,
            'completed' => 0, 'invoiced' => 0,
            'open' => 0, 'overdue' => 0, 'amount' => 0.0, 'progress' => 0,
        ];

        foreach ($tasks as $task) {
            $status = (int) ($task['task_status'] ?? 1);

            $stats['total']++;
            $stats['amount'] += (float) ($task['task_price'] ?? 0);

            $bucket = [1 => 'not_started', 2 => 'in_progress', 3 => 'completed', 4 => 'invoiced'][$status] ?? 'not_started';
            $stats[$bucket]++;

            // Aperta = lavoro ancora da chiudere. Solo un'attivita' aperta puo'
            // essere in ritardo: su una gia' fatturata la scadenza non vuol
            // piu' dire niente.
            if ($status !== 3 && $status !== 4) {
                $stats['open']++;

                if (!empty($task['is_overdue'])) {
                    $stats['overdue']++;
                }
            }
        }

        // Avanzamento = lavoro concluso, cioe' completato *o* fatturato.
        $closed = $stats['completed'] + $stats['invoiced'];
        $stats['progress'] = $stats['total'] > 0
            ? (int) round($closed / $stats['total'] * 100)
            : 0;

        return $stats;
    }

    public function getTasks(int $projectId): array
    {
        if (!$projectId) {
            return [];
        }

        return $this->db->table('ip_tasks')
            ->select("ip_tasks.*, ip_projects.project_name, CASE WHEN ip_tasks.task_finish_date < CURRENT_DATE AND ip_tasks.task_status NOT IN (3, 4) THEN 1 ELSE 0 END AS is_overdue", false)
            ->join('ip_projects', 'ip_projects.project_id = ip_tasks.project_id', 'left')
            ->where('ip_tasks.project_id', $projectId)
            ->orderBy('ip_tasks.task_finish_date', 'ASC')
            ->orderBy('ip_tasks.task_name', 'ASC')
            ->get()
            ->getResultArray();
    }
}