<?php

namespace App\Models;

use App\Traits\HasCustomFieldValues;
/**
 * PaymentCustomModel
 *
 * Porting CI4 di Mdl_Payment_Custom (CI3): valori dei campi personalizzati
 * del pagamento (tabella ip_payment_custom).
 */
class PaymentCustomModel extends IpBaseModel
{
    use HasCustomFieldValues;

    protected $table            = 'ip_payment_custom';
    protected $primaryKey       = 'payment_custom_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'payment_id',
        'payment_custom_fieldid',
        'payment_custom_fieldvalue',
    ];

    /**
     * Salva i valori custom di un pagamento (equivalente di save_custom() CI3).
     * $custom = [custom_field_id => valore] da POST custom[fieldId] o
     * custom[fieldId][] (array per MULTIPLE-CHOICE).
     *
     * @param array<int, mixed> $custom
     */
    public function saveCustom(int $paymentId, array $custom): void
    {
        foreach ($custom as $fieldId => $value) {
            $value = $this->normalizeValue((int) $fieldId, $value);

            $existing = $this->where('payment_id', $paymentId)
                ->where('payment_custom_fieldid', $fieldId)
                ->first();

            $data = [
                'payment_id'                => $paymentId,
                'payment_custom_fieldid'    => $fieldId,
                'payment_custom_fieldvalue' => $value,
            ];

            if ($existing) {
                $this->update($existing['payment_custom_id'], $data);
            } else {
                $this->saveRecord(null, $data);
            }
        }
    }

    /**
     * Righe custom di un pagamento (equivalente di get_by_payid() CI3).
     */
    public function getByPayment(int $paymentId): array
    {
        return $this->where('payment_id', $paymentId)->findAll();
    }

    /**
     * Valori custom di un pagamento con etichetta e tipo del campo
     * (join ip_custom_fields, come default_join() CI3).
     */
    public function getValues(int $paymentId): array
    {
        return $this->customValues('ip_payment_custom', 'payment_id', $paymentId);
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