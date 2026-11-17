<?php

namespace App\Models;

/**
 * SupplierModel — anagrafica fornitori.
 *
 * Speculare a ClientModel: stesse convenzioni (scope per azienda, controllo
 * anti-duplicato, elenco con totali). I totali arrivano da ip_expenses, come
 * quelli dei clienti arrivano da ip_invoice_amounts.
 */
class SupplierModel extends IpBaseModel
{
    protected $table            = 'ip_suppliers';
    protected $primaryKey       = 'supplier_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'supplier_name', 'supplier_vat_id', 'supplier_tax_code',
        'supplier_address_1', 'supplier_address_2', 'supplier_city',
        'supplier_state', 'supplier_zip', 'supplier_country',
        'supplier_phone', 'supplier_email', 'supplier_web',
        'supplier_iban', 'supplier_sdi_code', 'supplier_sdi_pec',
        'supplier_note', 'supplier_active', 'company_id',
        'supplier_date_created', 'supplier_date_modified',
    ];

    protected $validationRules = [
        'supplier_name'      => 'required',
        'supplier_vat_id'    => 'permit_empty|max_length[30]',
        'supplier_tax_code'  => 'permit_empty|max_length[30]',
        'supplier_email'     => 'permit_empty|valid_email',
        'supplier_sdi_code'  => 'permit_empty|alpha_numeric|max_length[7]',
        'supplier_sdi_pec'   => 'permit_empty|valid_email',
        'supplier_address_1' => 'permit_empty',
        'supplier_address_2' => 'permit_empty',
        'supplier_city'      => 'permit_empty',
        'supplier_state'     => 'permit_empty',
        'supplier_zip'       => 'permit_empty',
        'supplier_country'   => 'permit_empty|max_length[5]',
        'supplier_phone'     => 'permit_empty',
        'supplier_web'       => 'permit_empty',
        'supplier_iban'      => 'permit_empty|max_length[34]',
        'supplier_note'      => 'permit_empty',
        'supplier_active'    => 'permit_empty',
    ];

    protected $validationMessages = [
        'supplier_name'    => ['required' => 'Il nome del fornitore è obbligatorio.'],
        'supplier_email'   => ['valid_email' => 'Indirizzo email non valido.'],
        'supplier_sdi_pec' => ['valid_email' => 'Indirizzo PEC non valido.'],
    ];

    /** dbArrayFromPost() filtra il POST su queste chiavi. */
    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * Elenco con totale speso e numero documenti, come la lista clienti mostra
     * fatturato e saldo.
     *
     * Due passate invece di un JOIN aggregato con GROUP BY: la lista è
     * paginata, e con il join ogni apertura aggregava tutta ip_expenses
     * (che cresce con i dati utente) per un dato che serve solo alle righe
     * visibili. La seconda passata filtra su supplier_id con whereIn e
     * lascia lavorare l'indice. I numeri per fornitore restano identici.
     */
    public function getPage(string $status, ?int $perPage = null, int $page = 1): array
    {
        $this->scopeToCompany();

        if ($status === 'active') {
            $this->where('ip_suppliers.supplier_active', true);
        } elseif ($status === 'inactive') {
            $this->where('ip_suppliers.supplier_active', false);
        }

        $suppliers = $this->orderBy('supplier_name', 'ASC')->listFetch($perPage, $page);

        if ($suppliers === []) {
            return [];
        }

        $totals = $this->totalsFor(array_map(
            static fn (array $supplier): int => (int) $supplier['supplier_id'],
            $suppliers
        ));

        foreach ($suppliers as &$supplier) {
            $supplier += $totals[(int) $supplier['supplier_id']]
                ?? ['expense_total' => 0.0, 'expense_balance' => 0.0, 'expense_count' => 0];
        }

        return $suppliers;
    }

    /**
     * Totale speso, residuo e numero documenti per i soli fornitori indicati.
     *
     * @param list<int> $supplierIds
     *
     * @return array<int, array{expense_total: float, expense_balance: float, expense_count: int}>
     */
    public function totalsFor(array $supplierIds): array
    {
        $supplierIds = array_values(array_unique(array_map('intval', $supplierIds)));

        if ($supplierIds === []) {
            return [];
        }

        $rows = $this->db->table('ip_expenses')
            ->select('supplier_id, COALESCE(SUM(expense_total), 0) AS expense_total, '
                . 'COALESCE(SUM(expense_total - expense_paid), 0) AS expense_balance, '
                . 'COUNT(*) AS expense_count')
            ->whereIn('supplier_id', $supplierIds)
            ->groupBy('supplier_id')
            ->get()->getResultArray();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(int) $row['supplier_id']] = [
                'expense_total'   => (float) $row['expense_total'],
                'expense_balance' => (float) $row['expense_balance'],
                'expense_count'   => (int) $row['expense_count'],
            ];
        }

        return $totals;
    }

    public function getByIdWithTotals(int $id): ?array
    {
        $rows = $this->select("
                ip_suppliers.*,
                COALESCE(SUM(ip_expenses.expense_total), 0) AS expense_total,
                COALESCE(SUM(ip_expenses.expense_total - ip_expenses.expense_paid), 0) AS expense_balance,
                COUNT(ip_expenses.expense_id) AS expense_count", false)
            ->join('ip_expenses', 'ip_expenses.supplier_id = ip_suppliers.supplier_id', 'left')
            ->where('ip_suppliers.supplier_id', $id)
            ->scopeToCompany()
            ->groupBy('ip_suppliers.supplier_id')
            ->findAll();

        return $rows[0] ?? null;
    }

    /** Fornitori selezionabili in un elenco a discesa. */
    public function forSelect(): array
    {
        return $this->scopeToCompany()
                    ->where('supplier_active', true)
                    ->orderBy('supplier_name', 'ASC')
                    ->findAll();
    }

    /**
     * Tutti i fornitori dell'azienda attiva, ordinati per nome.
     * Usato dall'esportazione CSV (Exports::dataFor) — stessa firma di
     * ClientModel::getAllOrdered() senza paginazione perché la lista
     * anagrafica non è paginata nell'export.
     */
    public function getAllOrdered(): array
    {
        return $this->scopeToCompany()
                    ->orderBy('supplier_name', 'ASC')
                    ->findAll();
    }

    /**
     * Fornitore gia' presente con la stessa partita IVA (o, in mancanza, lo
     * stesso nome): serve all'import XML per non creare un doppione a ogni
     * fattura ricevuta dallo stesso soggetto.
     */
    public function findExisting(?string $vat, ?string $name): ?array
    {
        $vat  = trim((string) $vat);
        $name = trim((string) $name);

        if ($vat !== '') {
            $row = $this->scopeToCompany()->where('supplier_vat_id', $vat)->first();

            if ($row) {
                return $row;
            }
        }

        if ($name === '') {
            return null;
        }

        return $this->scopeToCompany()->where('supplier_name', $name)->first();
    }

    /**
     * Campi da riempire su un fornitore a partire dai dati di una fattura.
     *
     * Si scrive solo dove il campo è vuoto: quello già compilato — a mano o da
     * un'importazione precedente — resta com'è. È la differenza che tiene in
     * piedi l'arricchimento automatico, perché un fornitore con più sedi manda
     * la fattura dallo stabilimento e non dalla sede legale: sovrascrivendo,
     * ogni import sposterebbe l'anagrafica, e le fatture emesse con
     * l'indirizzo giusto finirebbero per contraddire l'anagrafica.
     *
     * Puro di proposito: niente database, così la regola si prova senza
     * scrivere niente.
     *
     * @param array<string, mixed> $existing  riga di ip_suppliers (o [] in creazione)
     * @param array<string, mixed> $candidate colonna => valore proposto
     *
     * @return array<string, mixed> solo le colonne da scrivere
     */
    public static function emptyFields(array $existing, array $candidate): array
    {
        $patch = [];

        foreach ($candidate as $field => $value) {
            $value = is_string($value) ? trim($value) : $value;

            if ($value === null || $value === '') {
                continue;
            }

            if (trim((string) ($existing[$field] ?? '')) !== '') {
                continue;
            }

            $patch[$field] = $value;
        }

        return $patch;
    }

    /**
     * Primo indirizzo email valido dentro un valore che ne contiene più di uno.
     *
     * Le fatture elettroniche a volte riportano due indirizzi nello stesso nodo
     * Contatti/Email («support@… invoice@…»), separati da spazio o virgola.
     * L'anagrafica ne tiene uno solo, e la regola valid_email rifiuta il valore
     * intero: il modello allora non salva *nessuno* dei campi della riga, quindi
     * un doppio indirizzo faceva perdere anche l'indirizzo e il CAP dello stesso
     * fornitore, senza un errore visibile.
     */
    public static function firstValidEmail(?string $value): string
    {
        foreach (preg_split('/[\s,;]+/', (string) $value) ?: [] as $candidate) {
            $candidate = trim($candidate, " \t\n\r\0\x0B<>");

            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Salva (insert o update) e ritorna l'id, oppure 0 se non ha scritto nulla.
     *
     * insert()/update() di CI4 non lanciano: quando la validazione fallisce
     * tornano false e il messaggio finisce in errors(). Prima questa classe li
     * ignorava e restituiva comunque l'id, così il chiamante — il form
     * fornitori, l'import delle fatture passive, suppliers:enrich — annunciava
     * un salvataggio che non c'era stato, mentre l'unica traccia dell'errore
     * restava in errors(), che nessuno leggeva. Lo zero è il segnale: nessun id
     * valido è zero, e i chiamanti distinguono i due esiti.
     */
    public function saveRecord(?int $id, array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $data['supplier_date_modified'] = $now;

        if ($id) {
            return $this->update($id, $data) ? $id : 0;
        }

        $data['supplier_date_created'] = $now;
        $data = $this->withCompany($data);

        return (int) $this->insert($data);
    }

    /**
     * Le spese non vengono cancellate insieme al fornitore: sono documenti
     * contabili. Perdono solo il collegamento, e restano leggibili grazie ai
     * campi di testo (supplier_name/vat) che continuiamo a valorizzare.
     */
    public function deleteWithRelations(int $id): void
    {
        $this->db->table('ip_expenses')
                 ->where('supplier_id', $id)
                 ->update(['supplier_id' => null]);

        $this->delete($id);
    }
}
