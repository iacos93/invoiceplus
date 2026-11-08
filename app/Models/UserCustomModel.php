<?php

namespace App\Models;

use App\Traits\HasCustomFieldValues;
/**
 * UserCustomModel
 *
 * Porting CI4 di Mdl_User_Custom (CI3): valori dei campi personalizzati
 * dell'utente (tabella ip_user_custom).
 */
class UserCustomModel extends IpBaseModel
{
    use HasCustomFieldValues;

    protected $table            = 'ip_user_custom';
    protected $primaryKey       = 'user_custom_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'user_id',
        'user_custom_fieldid',
        'user_custom_fieldvalue',
    ];

    /**
     * Salva i valori dei campi personalizzati per un utente
     * (equivalente di save_custom() in CI3).
     *
     * @param array<int, mixed> $custom [custom_field_id => valore|array]
     */
    public function saveCustom(int $userId, array $custom): void
    {
        foreach ($custom as $fieldId => $value) {
            $value = $this->normalizeValue((int) $fieldId, $value);

            $existing = $this->where('user_id', $userId)
                ->where('user_custom_fieldid', $fieldId)
                ->first();

            $data = [
                'user_id'                => $userId,
                'user_custom_fieldid'    => $fieldId,
                'user_custom_fieldvalue' => $value,
            ];

            if ($existing) {
                $this->update($existing['user_custom_id'], $data);
            } else {
                $this->saveRecord(null, $data);
            }
        }
    }

    /**
     * Valori dei campi personalizzati di un utente.
     */
    public function getByUser(int $userId): array
    {
        return $this->where('user_id', $userId)->findAll();
    }

    /**
     * Valori dei campi personalizzati di un utente con etichetta e tipo
     * del campo (join ip_custom_fields, come default_join() CI3).
     */
    public function getValues(int $userId): array
    {
        return $this->customValues('ip_user_custom', 'user_id', $userId);
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