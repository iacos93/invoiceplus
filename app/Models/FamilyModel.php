<?php

namespace App\Models;

/**
 * FamilyModel
 *
 * Porting CI4 del modulo families (CI3: Mdl_Families).
 * Tabella e struttura invariate rispetto all'originale, per compatibilita'
 * con il database esistente e con l'app CI3 durante la transizione.
 */
class FamilyModel extends IpBaseModel
{
    protected $table            = 'ip_families';
    protected $primaryKey       = 'family_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'family_name',
    ];

    protected $validationRules = [
        'family_name' => 'required',
    ];

    protected $validationMessages = [
        'family_name' => [
            'required' => 'Il nome della famiglia e\' obbligatorio',
        ],
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * Lista ordinata come nell'originale CI3 (default_order_by).
     */
    public function getAllOrdered(?int $perPage = null, int $page = 1): array
    {
        $this->orderBy('family_name', 'ASC');

        return $this->listFetch($perPage, $page);
    }

    /**
     * Verifica se esiste gia' una famiglia con lo stesso nome
     * (equivalente al check anti-duplicato nel controller CI3).
     */
    public function nameExists(string $familyName): bool
    {
        return $this->where('family_name', $familyName)->countAllResults() > 0;
    }
}
