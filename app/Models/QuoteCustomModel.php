<?php

namespace App\Models;

use App\Traits\HasCustomFieldValues;
/**
 * QuoteCustomModel
 *
 * Porting CI4 di Mdl_Quote_Custom (CI3): valori dei campi personalizzati
 * del preventivo (tabella ip_quote_custom).
 */
class QuoteCustomModel extends IpBaseModel
{
    use HasCustomFieldValues;

    protected $table            = 'ip_quote_custom';
    protected $primaryKey       = 'quote_custom_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'quote_id',
        'quote_custom_fieldid',
        'quote_custom_fieldvalue',
    ];

    /**
     * Salva i valori custom di un preventivo (equivalente di save_custom() CI3).
     * $custom = [custom_field_id => valore] da POST custom[fieldId] o
     * custom[fieldId][] (array per MULTIPLE-CHOICE).
     *
     * @param array<int, mixed> $custom
     */
    public function saveCustom(int $quoteId, array $custom): void
    {
        foreach ($custom as $fieldId => $value) {
            $value = $this->normalizeValue((int) $fieldId, $value);

            $existing = $this->where('quote_id', $quoteId)
                ->where('quote_custom_fieldid', $fieldId)
                ->first();

            $data = [
                'quote_id'                => $quoteId,
                'quote_custom_fieldid'    => $fieldId,
                'quote_custom_fieldvalue' => $value,
            ];

            if ($existing) {
                $this->update($existing['quote_custom_id'], $data);
            } else {
                $this->saveRecord(null, $data);
            }
        }
    }

    /**
     * Righe custom di un preventivo (equivalente di by_id() + get() CI3).
     */
    public function getByQuote(int $quoteId): array
    {
        return $this->where('quote_id', $quoteId)->findAll();
    }

    /**
     * Valori custom di un preventivo con etichetta e tipo del campo
     * (join ip_custom_fields, come default_join() CI3).
     */
    public function getValues(int $quoteId): array
    {
        return $this->customValues('ip_quote_custom', 'quote_id', $quoteId);
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