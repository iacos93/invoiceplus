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
     * fatturato e saldo. LEFT JOIN: un fornitore appena creato, senza spese,
     * deve comunque comparire.
     */
    public function getAllWithTotals(string $status = 'all'): array
    {
        $this->select("
                ip_suppliers.*,
                COALESCE(SUM(ip_expenses.expense_total), 0) AS expense_total,
                COALESCE(SUM(ip_expenses.expense_total - ip_expenses.expense_paid), 0) AS expense_balance,
                COUNT(ip_expenses.expense_id) AS expense_count", false)
            ->join('ip_expenses', 'ip_expenses.supplier_id = ip_suppliers.supplier_id', 'left');

        if ($status === 'active') {
            $this->where('ip_suppliers.supplier_active', true);
        } elseif ($status === 'inactive') {
            $this->where('ip_suppliers.supplier_active', false);
        }

        $this->scopeToCompany();

        return $this->groupBy('ip_suppliers.supplier_id')
                    ->orderBy('ip_suppliers.supplier_name', 'ASC')
                    ->findAll();
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

    public function saveRecord(?int $id, array $data): int
    {
        $now = date('Y-m-d H:i:s');
        $data['supplier_date_modified'] = $now;

        if ($id) {
            $this->update($id, $data);

            return $id;
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
