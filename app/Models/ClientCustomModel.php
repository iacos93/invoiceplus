<?php

namespace App\Models;

/**
 * ClientCustomModel
 *
 * Porting CI4 di Mdl_Client_Custom (CI3): valori dei campi personalizzati
 * del cliente (tabella ip_client_custom).
 */
class ClientCustomModel extends IpBaseModel
{
    protected $table            = 'ip_client_custom';
    protected $primaryKey       = 'client_custom_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'client_id',
        'client_custom_fieldid',
        'client_custom_fieldvalue',
    ];

    /**
     * Salva i valori custom di un cliente (equivalente di save_custom() CI3).
     * $custom = [custom_field_id => valore] da POST custom[fieldId] o
     * custom[fieldId][] (array per MULTIPLE-CHOICE).
     *
     * @param array<int, mixed> $custom
     */
    public function saveCustom(int $clientId, array $custom): void
    {
        foreach ($custom as $fieldId => $value) {
            $value = $this->normalizeValue((int) $fieldId, $value);

            $existing = $this->where('client_id', $clientId)
                ->where('client_custom_fieldid', $fieldId)
                ->first();

            $data = [
                'client_id'                => $clientId,
                'client_custom_fieldid'    => $fieldId,
                'client_custom_fieldvalue' => $value,
            ];

            if ($existing) {
                $this->update($existing['client_custom_id'], $data);
            } else {
                $this->saveRecord(null, $data);
            }
        }
    }

    /**
     * Righe custom di un cliente (equivalente di get_by_client() CI3).
     */
    public function getByClient(int $clientId): array
    {
        return $this->where('client_id', $clientId)->findAll();
    }

    /**
     * Valori custom di un cliente con etichetta e tipo del campo
     * (join ip_custom_fields, come default_join() CI3).
     */
    public function getValues(int $clientId): array
    {
        return $this->select(
            'ip_client_custom.*, ip_custom_fields.custom_field_label, ip_custom_fields.custom_field_type'
        )
            ->join('ip_custom_fields', 'ip_custom_fields.custom_field_id = ip_client_custom.client_custom_fieldid', 'left')
            ->where('ip_client_custom.client_id', $clientId)
            ->orderBy('ip_custom_fields.custom_field_order', 'ASC')
            ->orderBy('ip_custom_fields.custom_field_label', 'ASC')
            ->findAll();
    }

    /**
     * Valori custom di un cliente pronti da stampare (usato da clients/view).
     *
     * Per SINGLE-CHOICE e MULTIPLE-CHOICE la tabella conserva l'id dell'opzione
     * scelta (custom_values_id), non il testo: la scheda cliente stampava quindi
     * un numero — "7", o "3,5" per le scelte multiple — al posto dell'opzione.
     * Qui l'id viene risolto nella sua etichetta, senza toccare cosa c'e'
     * scritto a database.
     */
    public function getValuesByClient(int $clientId): array
    {
        $rows = $this->getValues($clientId);

        if ($rows === []) {
            return $rows;
        }

        $labels = array_column(
            (new CustomValueModel())->findAll(),
            'custom_values_value',
            'custom_values_id'
        );

        return self::resolveOptionLabels($rows, $labels);
    }

    /**
     * Sostituisce gli id opzione con la loro etichetta.
     *
     * Separata e statica perche' e' l'unico pezzo con una logica da verificare
     * (le scelte multiple arrivano come CSV di id) e cosi' si prova senza
     * database. Un id senza corrispondenza resta com'e': meglio mostrare il
     * dato grezzo che perderlo se l'opzione e' stata cancellata.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int|string, string>        $labels  custom_values_id => testo
     *
     * @return array<int, array<string, mixed>>
     */
    public static function resolveOptionLabels(array $rows, array $labels): array
    {
        $choiceTypes = ['SINGLE-CHOICE', 'MULTIPLE-CHOICE'];

        foreach ($rows as &$row) {
            $value = (string) ($row['client_custom_fieldvalue'] ?? '');

            if ($value === '' || !in_array($row['custom_field_type'] ?? '', $choiceTypes, true)) {
                continue;
            }

            $resolved = [];

            foreach (explode(',', $value) as $id) {
                $id = trim($id);

                if ($id === '') {
                    continue;
                }

                $resolved[] = $labels[$id] ?? $id;
            }

            $row['client_custom_fieldvalue'] = implode(', ', $resolved);
        }

        return $rows;
    }

    /**
     * Campi custom della tabella client (usato dal form clienti).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFieldsForClient(): array
    {
        return (new CustomFieldModel())->byTable('ip_client_custom');
    }

    /**
     * Valori predefiniti raggruppati per campo (usato dal form clienti).
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function getCustomValues(): array
    {
        $grouped = [];

        foreach ((new CustomValueModel())->findAll() as $value) {
            $grouped[(int) $value['custom_values_field']][] = $value;
        }

        return $grouped;
    }

    /**
     * Normalizza il valore come fixinput() CI3: array -> CSV,
     * DATE -> formato MySQL, TEXT vuoto -> NULL.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalizeValue(int $fieldId, $value)
    {
        if (is_array($value)) {
            return implode(',', $value);
        }

        $field = (new CustomFieldModel())->find($fieldId);

        if ($field) {
            if ($field['custom_field_type'] === 'DATE' && $value !== '') {
                return date_to_mysql($value);
            }
            if ($field['custom_field_type'] === 'TEXT' && $value === '') {
                return null;
            }
        }

        return $value;
    }
}