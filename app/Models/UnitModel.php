<?php

namespace App\Models;

/**
 * UnitModel
 *
 * Porting CI4 del modulo units (CI3: Mdl_Units).
 * Tabella e struttura invariate rispetto all'originale, per compatibilita'
 * con il database esistente e con l'app CI3 durante la transizione.
 */
class UnitModel extends IpBaseModel
{
    protected $table            = 'ip_units';
    protected $primaryKey       = 'unit_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    /**
     * Categorie di unita' di misura. La chiave e' il valore persistito su
     * unit_type, l'etichetta e' mostrata nel raggruppamento dei <select>.
     * "altro" raccoglie le unita' senza tipo (o con tipo non riconosciuto).
     */
    public const UNIT_TYPES = [
        'pezzi'      => 'Pezzi',
        'peso'       => 'Peso',
        'lunghezza'  => 'Lunghezza',
        'superficie' => 'Superficie',
        'volume'     => 'Volume',
        'tempo'      => 'Tempo',
        'altro'      => 'Altro',
    ];

    protected $allowedFields = [
        'unit_name',
        'unit_name_plrl',
        'unit_type',
    ];

    protected $validationRules = [
        'unit_name'      => 'required|max_length[50]',
        'unit_name_plrl' => 'required|max_length[50]',
        'unit_type'      => 'permit_empty',
    ];

    protected $validationMessages = [
        'unit_name' => [
            'required' => 'Il nome dell\'unita\' e\' obbligatorio',
        ],
        'unit_name_plrl' => [
            'required' => 'Il nome plurale dell\'unita\' e\' obbligatorio',
        ],
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * Etichetta italiana del tipo (codice => etichetta). Per un codice non
     * valido ritorna "Altro", cosi' le unita' importate senza tipo finiscono
     * comunque in un gruppo sensato anziche' sparire.
     */
    public static function typeLabel(?string $code): string
    {
        if ($code === null) {
            return self::UNIT_TYPES['altro'];
        }

        return self::UNIT_TYPES[$code] ?? self::UNIT_TYPES['altro'];
    }

    /**
     * Unita' raggruppate per tipo, nell'ordine di UNIT_TYPES, pronte per
     * essere rese come <optgroup> nei <select> delle righe documento.
     * Ogni gruppo ha 'label' ed 'units' (lista vuota se nessuna unita' di
     * quel tipo). Le unita' senza tipo o con tipo sconosciuto finiscono in
     * "altro".
     *
     * @return array<string, array{label: string, units: array}>
     */
    public function getGroupedByType(): array
    {
        $groups = [];

        foreach (self::UNIT_TYPES as $code => $label) {
            $groups[$code] = ['label' => $label, 'units' => []];
        }

        foreach ($this->orderBy('unit_name', 'ASC')->findAll() as $unit) {
            $type = $unit['unit_type'] ?? '';
            if (!isset($groups[$type])) {
                $type = 'altro';
            }
            $groups[$type]['units'][] = $unit;
        }

        return $groups;
    }

    /**
     * Lista ordinata come nell'originale CI3 (default_order_by).
     */
    public function getAllOrdered(?int $perPage = null, int $page = 1): array
    {
        $this->orderBy('unit_name', 'ASC');

        return $this->listFetch($perPage, $page);
    }

    /**
     * Verifica se esiste gia' un'unita' con lo stesso nome
     * (equivalente al check anti-duplicato nel controller CI3).
     */
    public function nameExists(string $unitName): bool
    {
        return $this->where('unit_name', $unitName)->countAllResults() > 0;
    }
}
