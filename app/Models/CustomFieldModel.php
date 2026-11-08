<?php

namespace App\Models;

/**
 * CustomFieldModel
 *
 * Porting CI4 di Mdl_Custom_Fields (CI3): definizione dei campi
 * personalizzati (tabella ip_custom_fields) con relativi valori
 * (ip_custom_values) e righe nelle tabelle ip_<tabella>_custom.
 */
class CustomFieldModel extends IpBaseModel
{
    protected $table            = 'ip_custom_fields';
    protected $primaryKey       = 'custom_field_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'custom_field_table',
        'custom_field_label',
        'custom_field_type',
        'custom_field_order',
        'custom_field_location',
        'custom_field_required',
    ];

    protected $validationRules = [
        'custom_field_table'   => 'required',
        'custom_field_label'   => 'required|max_length[50]',
        'custom_field_type'    => 'required',
        'custom_field_order'   => 'permit_empty|is_natural',
        'custom_field_required'=> 'permit_empty',
    ];

    protected $validationMessages = [
        'custom_field_label' => [
            'required' => 'L\'etichetta è obbligatoria',
        ],
        'custom_field_table' => [
            'required' => 'La tabella è obbligatoria',
        ],
        'custom_field_type' => [
            'required' => 'Il tipo è obbligatorio',
        ],
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * Tabelle target dei campi custom (nome tabella => chiave lingua),
     * come in Mdl_Custom_Fields::custom_tables() CI3.
     *
     * @return array<string, string>
     */
    public function customTables(): array
    {
        return [
            'ip_client_custom'  => 'client',
            'ip_invoice_custom' => 'invoice',
            'ip_quote_custom'   => 'quote',
            'ip_payment_custom' => 'payment',
            'ip_user_custom'    => 'user',
        ];
    }

    /**
     * Tipi di campo supportati (vedi Mdl_Custom_Values::custom_types() CI3).
     *
     * @return string[]
     */
    public function customTypes(): array
    {
        return ['TEXT', 'DATE', 'BOOLEAN', 'SINGLE-CHOICE', 'MULTIPLE-CHOICE'];
    }

    /**
     * Tipi di campo che usano valori predefiniti (tabella ip_custom_values).
     *
     * @return string[]
     */
    public function customValueTypes(): array
    {
        return ['SINGLE-CHOICE', 'MULTIPLE-CHOICE'];
    }

    /**
     * Campi custom della tabella target (es. 'ip_client_custom').
     */
    public function byTable(string $table): array
    {
        return $this->where('custom_field_table', $table)->findAll();
    }

    /**
     * Tutti i campi ordinati come default_order_by() CI3.
     */
    public function getAllOrdered(?int $perPage = null, int $page = 1): array
    {
        $this->orderBy('custom_field_table', 'ASC')
             ->orderBy('custom_field_order', 'ASC')
             ->orderBy('custom_field_label', 'ASC');

        return $this->listFetch($perPage, $page);
    }

    /**
     * La colonna custom_field_required non esiste nello schema del DB
     * (vedi migrazioni SQL CI3): salvata solo se presente.
     */
    public function hasRequiredColumn(): bool
    {
        return $this->db->fieldExists('custom_field_required', $this->table);
    }

    public function dbArrayFromPost(array $post): array
    {
        $dbArray = parent::dbArrayFromPost($post);

        if (isset($dbArray['custom_field_order']) && $dbArray['custom_field_order'] === '') {
            $dbArray['custom_field_order'] = null;
        }

        if (!$this->hasRequiredColumn()) {
            unset($dbArray['custom_field_required']);
        }

        return $dbArray;
    }

    /**
     * Elimina il campo e tutti i valori collegati: righe ip_custom_values
     * e righe della tabella ip_<tabella>_custom con quel field id.
     */
    public function deleteWithValues(int $id): void
    {
        $field = $this->find($id);

        if (!$field) {
            return;
        }

        $this->db->table('ip_custom_values')
            ->where('custom_values_field', $id)
            ->delete();

        $customTable = (string) $field['custom_field_table'];

        if ($this->db->tableExists($customTable)) {
            $column = str_replace('ip_', '', $customTable) . '_fieldid';
            $this->db->table($customTable)->where($column, $id)->delete();
        }

        $this->delete($id);
    }
}