<?php

namespace App\Models;

use App\Traits\HasCustomFieldValues;
/**
 * InvoiceCustomModel
 *
 * Porting CI4 di Mdl_Invoice_Custom (CI3): valori dei campi personalizzati
 * della fattura (tabella ip_invoice_custom).
 */
class InvoiceCustomModel extends IpBaseModel
{
    use HasCustomFieldValues;

    protected $table            = 'ip_invoice_custom';
    protected $primaryKey       = 'invoice_custom_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'invoice_id',
        'invoice_custom_fieldid',
        'invoice_custom_fieldvalue',
    ];

    /**
     * Salva i valori custom di una fattura (equivalente di save_custom() CI3).
     * $custom = [custom_field_id => valore] da POST custom[fieldId] o
     * custom[fieldId][] (array per MULTIPLE-CHOICE).
     *
     * @param array<int, mixed> $custom
     */
    public function saveCustom(int $invoiceId, array $custom): void
    {
        foreach ($custom as $fieldId => $value) {
            $value = $this->normalizeValue((int) $fieldId, $value);

            $existing = $this->where('invoice_id', $invoiceId)
                ->where('invoice_custom_fieldid', $fieldId)
                ->first();

            $data = [
                'invoice_id'                => $invoiceId,
                'invoice_custom_fieldid'    => $fieldId,
                'invoice_custom_fieldvalue' => $value,
            ];

            if ($existing) {
                $this->update($existing['invoice_custom_id'], $data);
            } else {
                $this->saveRecord(null, $data);
            }
        }
    }

    /**
     * Righe custom di una fattura (equivalente di by_id() + get() CI3).
     */
    public function getByInvoice(int $invoiceId): array
    {
        return $this->where('invoice_id', $invoiceId)->findAll();
    }

    /**
     * Valori custom di una fattura con etichetta e tipo del campo
     * (join ip_custom_fields, come default_join() CI3).
     */
    public function getValues(int $invoiceId): array
    {
        return $this->customValues('ip_invoice_custom', 'invoice_id', $invoiceId);
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