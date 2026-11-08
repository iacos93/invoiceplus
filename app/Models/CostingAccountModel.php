<?php

namespace App\Models;

/**
 * CostingAccountModel — piano dei conti del controllo di gestione.
 *
 * Non è il piano dei conti civilistico: è la riclassificazione che serve a
 * calcolare margine di contribuzione ed EBITDA. Per questo il tipo è una
 * scelta chiusa fra tre valori (ricavo, costo variabile, costo fisso) e non
 * un campo libero: un conto senza tipo non saprebbe dove finire nel report.
 *
 * L'albero è ricorsivo tramite parent_id. Il padre non porta importi propri:
 * nel report somma i figli, così non esiste il caso in cui il totale del
 * padre e la somma dei figli si contraddicono (era il difetto di un piano
 * con totali ridigitati a mano).
 */
class CostingAccountModel extends IpBaseModel
{
    protected $table            = 'ip_costing_accounts';
    protected $primaryKey       = 'account_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    public const TYPE_REVENUE    = 'ricavo';
    public const TYPE_VARIABLE   = 'costo_variabile';
    public const TYPE_FIXED      = 'costo_fisso';

    public const TYPES = [
        self::TYPE_REVENUE  => 'Ricavo',
        self::TYPE_VARIABLE => 'Costo variabile',
        self::TYPE_FIXED    => 'Costo fisso',
    ];

    protected $allowedFields = [
        'company_id',
        'parent_id',
        'account_code',
        'account_name',
        'account_type',
        'account_active',
        'account_order',
        'account_note',
        'account_created',
    ];

    protected $validationRules = [
        'account_code' => 'required|max_length[20]',
        'account_name' => 'required|max_length[150]',
        'account_type' => 'required|in_list[ricavo,costo_variabile,costo_fisso]',
        'parent_id'    => 'permit_empty|is_natural_no_zero',
        'account_order' => 'permit_empty|integer',
    ];

    protected $validationMessages = [
        'account_code' => [
            'required'   => 'Il codice della voce è obbligatorio',
            'max_length' => 'Il codice può avere al massimo 20 caratteri',
        ],
        'account_name' => [
            'required'   => 'Il nome della voce è obbligatorio',
            'max_length' => 'Il nome può avere al massimo 150 caratteri',
        ],
        'account_type' => [
            'required' => 'Scegli il tipo della voce',
            'in_list'  => 'Tipo non riconosciuto',
        ],
    ];

    /**
     * Tutte le voci dell'azienda attiva, ordinate come vanno lette.
     *
     * @return list<array<string, mixed>>
     */
    public function allForCompany(bool $activeOnly = false): array
    {
        $this->scopeToCompany();

        if ($activeOnly) {
            $this->where('account_active', 1);
        }

        return $this->orderBy('account_order', 'ASC')
            ->orderBy('account_code', 'ASC')
            ->findAll();
    }

    /**
     * Voci raggruppate per tipo, ciascuna già in ordine di albero.
     *
     * @param list<array<string, mixed>>|null $accounts
     *
     * @return array<string, list<array<string, mixed>>> tipo => righe (con 'level' e 'has_children')
     */
    public function groupedTree(?array $accounts = null): array
    {
        $accounts ??= $this->allForCompany();
        $tree      = $this->tree($accounts);
        $grouped   = array_fill_keys(array_keys(self::TYPES), []);

        foreach ($tree as $node) {
            $this->flattenNode($node, 0, $grouped);
        }

        return $grouped;
    }

    /**
     * @param list<array<string, mixed>> $accounts
     *
     * @return list<array<string, mixed>>
     */
    public function tree(array $accounts): array
    {
        $byParent = [];

        foreach ($accounts as $account) {
            $byParent[(int) ($account['parent_id'] ?? 0)][] = $account;
        }

        return $this->buildBranch($byParent, 0);
    }

    /**
     * @param array<int, list<array<string, mixed>>> $byParent
     *
     * @return list<array<string, mixed>>
     */
    private function buildBranch(array $byParent, int $parentId): array
    {
        $branch = [];

        foreach ($byParent[$parentId] ?? [] as $account) {
            $children = $this->buildBranch($byParent, (int) $account['account_id']);
            $account['children'] = $children;
            $branch[]            = $account;
        }

        return $branch;
    }

    /**
     * @param array<string, mixed>                 $node
     * @param array<string, list<array<string, mixed>>> $out
     */
    private function flattenNode(array $node, int $level, array &$out): void
    {
        $children = (array) ($node['children'] ?? []);
        $type     = (string) $node['account_type'];

        unset($node['children']);
        $node['level']        = $level;
        $node['has_children'] = $children !== [];

        if (isset($out[$type])) {
            $out[$type][] = $node;
        }

        foreach ($children as $child) {
            $this->flattenNode($child, $level + 1, $out);
        }
    }

    /**
     * Opzioni per una select, indicando la gerarchia con un rientro.
     *
     * @return array<int, string> id => "100 · Vendite" (con "— " per i figli)
     */
    public function options(?string $type = null, bool $activeOnly = true): array
    {
        $accounts = $this->allForCompany($activeOnly);

        if ($type !== null) {
            $accounts = array_values(array_filter(
                $accounts,
                static fn(array $a): bool => $a['account_type'] === $type
            ));
        }

        $options = [];
        $walk    = static function (array $nodes, int $level) use (&$walk, &$options): void {
            foreach ($nodes as $node) {
                $prefix = str_repeat('— ', $level);
                $options[(int) $node['account_id']] = $prefix . $node['account_code'] . ' · ' . $node['account_name'];
                $walk((array) ($node['children'] ?? []), $level + 1);
            }
        };
        $walk($this->tree($accounts), 0);

        return $options;
    }

    /**
     * Id di una voce e di tutti i suoi discendenti.
     *
     * Serve al report: il padre non ha righe associate, quindi i suoi totali
     * sono la somma dei figli. Restituisce anche se stesso perché un conto
     * può avere righe proprie e sottoconti insieme.
     *
     * @return list<int>
     */
    public function idsWithDescendants(int $accountId, ?array $accounts = null): array
    {
        $accounts ??= $this->allForCompany();

        $byParent = [];
        foreach ($accounts as $account) {
            $byParent[(int) ($account['parent_id'] ?? 0)][] = (int) $account['account_id'];
        }

        $ids  = [];
        $walk = static function (int $id) use (&$walk, $byParent, &$ids): void {
            // Guardia contro un ciclo già presente nei dati (creato a mano nel
            // database): senza, la ricorsione non termina e la pagina va in
            // timeout invece di mostrare quello che c'è.
            if (in_array($id, $ids, true)) {
                return;
            }

            $ids[] = $id;
            foreach ($byParent[$id] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($accountId);

        return $ids;
    }

    /**
     * Impedisce di creare un ciclo (una voce che diventa figlia di un proprio
     * discendente): il report ricorsivo entrerebbe in loop.
     *
     * Il controllo è "il padre scelto è già sotto di me?": se lo è, metterlo
     * sopra di me chiude l'anello. Verificare il contrario (se io sono sotto
     * del padre) non trova il caso, perché è esattamente la relazione che si
     * sta creando.
     */
    public function wouldCreateCycle(int $accountId, ?int $parentId): bool
    {
        if ($parentId === null || $parentId === 0) {
            return false;
        }

        return in_array($parentId, $this->idsWithDescendants($accountId), true);
    }

    /**
     * Quante righe/spese usano la voce. Serve prima di cancellarla: senza
     * questo controllo la cancellazione lascerebbe righe con un id che non
     * esiste più e il report le perderebbe in silenzio.
     *
     * @return array{items: int, expenses: int, children: int}
     */
    public function usage(int $accountId): array
    {
        $items = $this->db->table('ip_invoice_items')
            ->where('costing_account_id', $accountId)
            ->countAllResults();

        $expenses = $this->db->table('ip_expenses')
            ->where('costing_account_id', $accountId)
            ->countAllResults();

        $children = $this->db->table('ip_costing_accounts')
            ->where('parent_id', $accountId)
            ->countAllResults();

        return ['items' => (int) $items, 'expenses' => (int) $expenses, 'children' => (int) $children];
    }

    /**
     * Piano dei conti di partenza, ispirato ai piani "startup" e "standard"
     * di Fattura24 ma ridotto alle voci che un'artigiana o una piccola
     * società usa davvero. Sono modificabili subito dopo l'importazione:
     * è una base, non un modello da subire.
     *
     * @return list<array{code: string, name: string, type: string, parent: string|null}>
     */
    public static function standardPlan(): array
    {
        return [
            ['code' => '100', 'name' => 'Vendite di prodotti',            'type' => self::TYPE_REVENUE,  'parent' => null],
            ['code' => '101', 'name' => 'Prestazioni di servizi',         'type' => self::TYPE_REVENUE,  'parent' => null],
            ['code' => '102', 'name' => 'Corrispettivi',                  'type' => self::TYPE_REVENUE,  'parent' => null],
            ['code' => '109', 'name' => 'Altri ricavi',                   'type' => self::TYPE_REVENUE,  'parent' => null],

            ['code' => '200', 'name' => 'Acquisto merci',                 'type' => self::TYPE_VARIABLE, 'parent' => null],
            ['code' => '201', 'name' => 'Materie prime e materiali',      'type' => self::TYPE_VARIABLE, 'parent' => null],
            ['code' => '202', 'name' => 'Lavorazioni esterne',            'type' => self::TYPE_VARIABLE, 'parent' => null],
            ['code' => '203', 'name' => 'Trasporti e spedizioni',         'type' => self::TYPE_VARIABLE, 'parent' => null],
            ['code' => '204', 'name' => 'Provvigioni',                    'type' => self::TYPE_VARIABLE, 'parent' => null],
            ['code' => '209', 'name' => 'Altri costi variabili',          'type' => self::TYPE_VARIABLE, 'parent' => null],

            ['code' => '300', 'name' => 'Stipendi e collaboratori',       'type' => self::TYPE_FIXED,    'parent' => null],
            ['code' => '301', 'name' => 'Contributi e oneri sociali',     'type' => self::TYPE_FIXED,    'parent' => null],
            ['code' => '302', 'name' => 'Affitto',                        'type' => self::TYPE_FIXED,    'parent' => null],
            ['code' => '303', 'name' => 'Utenze',                         'type' => self::TYPE_FIXED,    'parent' => null],
            ['code' => '304', 'name' => 'Software e servizi digitali',    'type' => self::TYPE_FIXED,    'parent' => null],
            ['code' => '305', 'name' => 'Consulenze',                     'type' => self::TYPE_FIXED,    'parent' => null],
            ['code' => '306', 'name' => 'Assicurazioni',                  'type' => self::TYPE_FIXED,    'parent' => null],
            ['code' => '307', 'name' => 'Leasing e noleggi',              'type' => self::TYPE_FIXED,    'parent' => null],
            ['code' => '308', 'name' => 'Manutenzioni',                   'type' => self::TYPE_FIXED,    'parent' => null],
            ['code' => '309', 'name' => 'Altri costi fissi',              'type' => self::TYPE_FIXED,    'parent' => null],
        ];
    }

    /**
     * Importa il piano standard nell'azienda indicata, saltando i codici già
     * presenti: si può premere il pulsante anche dopo aver personalizzato il
     * piano senza duplicare nulla.
     *
     * @return int numero di voci create
     */
    public function importStandardPlan(int $companyId): int
    {
        $existing = array_column($this->allForCompany(), 'account_code');
        $existing = array_map('strval', $existing);
        $created  = 0;

        foreach (self::standardPlan() as $order => $row) {
            if (in_array($row['code'], $existing, true)) {
                continue;
            }

            $this->insert([
                'company_id'      => $companyId,
                'parent_id'       => null,
                'account_code'    => $row['code'],
                'account_name'    => $row['name'],
                'account_type'    => $row['type'],
                'account_active'  => 1,
                'account_order'   => ($order + 1) * 10,
                'account_created' => date('Y-m-d H:i:s'),
            ]);
            $created++;
        }

        return $created;
    }
}
