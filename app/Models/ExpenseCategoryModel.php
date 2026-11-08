<?php

namespace App\Models;

/**
 * ExpenseCategoryModel — voci di costo (categorie di spesa).
 * Il flag "deducibile" alimenta il calcolo dell'utile imponibile.
 */
class ExpenseCategoryModel extends IpBaseModel
{
    protected $table            = 'ip_expense_categories';
    protected $primaryKey       = 'expense_category_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'expense_category_name',
        'expense_category_deductible',
    ];

    protected $validationRules = [
        'expense_category_name'       => 'required',
        'expense_category_deductible' => 'permit_empty',
    ];

    protected $validationMessages = [
        'expense_category_name' => ['required' => 'Il nome della categoria è obbligatorio'],
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    public function getAllOrdered(?int $perPage = null, int $page = 1): array
    {
        $this->orderBy('expense_category_name', 'ASC');

        return $this->listFetch($perPage, $page);
    }
}
