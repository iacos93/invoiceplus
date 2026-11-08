<?php

namespace App\Models;

/**
 * CustomValueModel
 *
 * Porting CI4 di Mdl_Custom_Values (CI3): valori predefiniti dei campi
 * a scelta singola/multipla (tabella ip_custom_values).
 *
 * NB: nello schema reale (migrazione 023_1.5.0.sql) la colonna che
 * punta al campo e' custom_values_field e non esiste custom_values_order:
 * quest'ultima viene salvata solo se la colonna e' presente nel DB.
 */
class CustomValueModel extends IpBaseModel
{
    protected $table            = 'ip_custom_values';
    protected $primaryKey       = 'custom_values_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'custom_values_field',
        'custom_values_value',
        'custom_values_order',
    ];

    protected $validationRules = [
        'custom_values_value' => 'required',
        'custom_values_order' => 'permit_empty|is_natural',
    ];

    protected $validationMessages = [
        'custom_values_value' => [
            'required' => 'Il valore è obbligatorio',
        ],
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * Valori predefiniti di un campo (equivalente di get_by_fid() CI3).
     */
    public function byField(int $fieldId): array
    {
        return $this->where('custom_values_field', $fieldId)
            ->orderBy('custom_values_id', 'ASC')
            ->findAll();
    }

    /**
     * Campi con conteggio dei valori, raggruppati per custom_field_id
     * (equivalente di get_grouped() CI3).
     */
    public function getGrouped(): array
    {
        return $this->select(
            'ip_custom_fields.custom_field_id, ip_custom_fields.custom_field_label, '
            . 'ip_custom_fields.custom_field_type, ip_custom_fields.custom_field_table, '
            . 'COUNT(ip_custom_values.custom_values_id) AS count'
        )
            ->join('ip_custom_fields', 'ip_custom_values.custom_values_field = ip_custom_fields.custom_field_id', 'inner')
            ->groupBy('ip_custom_fields.custom_field_id')
            ->orderBy('ip_custom_fields.custom_field_table', 'ASC')
            ->orderBy('ip_custom_fields.custom_field_order', 'ASC')
            ->orderBy('ip_custom_fields.custom_field_label', 'ASC')
            ->findAll();
    }

    /**
     * Crea un nuovo valore per il campo (equivalente di save_custom() CI3).
     */
    public function saveCustom(int $fieldId, array $post): int
    {
        $dbArray = $this->dbArrayFromPost($post);
        $dbArray['custom_values_field'] = $fieldId;

        return $this->saveRecord(null, $dbArray);
    }

    /**
     * La colonna custom_values_order non esiste nello schema reale:
     * salvata solo se presente nel DB.
     */
    public function hasOrderColumn(): bool
    {
        return $this->db->fieldExists('custom_values_order', $this->table);
    }

    public function dbArrayFromPost(array $post): array
    {
        $dbArray = parent::dbArrayFromPost($post);

        if (isset($dbArray['custom_values_order']) && $dbArray['custom_values_order'] === '') {
            $dbArray['custom_values_order'] = null;
        }

        if (!$this->hasOrderColumn()) {
            unset($dbArray['custom_values_order']);
        }

        return $dbArray;
    }
}