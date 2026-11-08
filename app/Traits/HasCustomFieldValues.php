<?php

namespace App\Traits;

/**
 * Valori dei campi personalizzati con etichetta e tipo del campo.
 *
 * I quattro model dei campi custom (fatture, preventivi, pagamenti, utenti)
 * avevano la stessa query copiata riga per riga: stesso join su
 * ip_custom_fields, stesso ordinamento per posizione e poi per etichetta.
 * Cambiava solo il nome della tabella. Con la query in un posto solo, un
 * cambio di ordinamento non puo' restare indietro su uno dei quattro.
 */
trait HasCustomFieldValues
{
    /**
     * @param string $table      tabella dei valori (es. ip_invoice_custom)
     * @param string $ownerField colonna che lega il valore al documento
     */
    protected function customValues(string $table, string $ownerField, int $ownerId): array
    {
        // ip_invoice_custom -> invoice: il campo che lega il valore al campo
        // custom ha la stessa radice della tabella.
        $prefix = str_replace(['ip_', '_custom'], '', $table);

        return $this->select($table . '.*, ip_custom_fields.custom_field_label, ip_custom_fields.custom_field_type')
            ->join(
                'ip_custom_fields',
                'ip_custom_fields.custom_field_id = ' . $table . '.' . $prefix . '_custom_fieldid',
                'left'
            )
            ->where($table . '.' . $ownerField, $ownerId)
            ->orderBy('ip_custom_fields.custom_field_order', 'ASC')
            ->orderBy('ip_custom_fields.custom_field_label', 'ASC')
            ->findAll();
    }
}
