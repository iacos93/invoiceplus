<?php

if (! function_exists('date_format_datepicker')) {
    /**
     * Restituisce il formato data atteso da bootstrap-datepicker, derivato
     * dal setting utente `date_format` (es. 'd/m/Y' -> 'dd/mm/yyyy').
     *
     * Equivalente CI3 di datepicker_date_format().
     */
    function date_format_datepicker(): string
    {
        $format   = get_setting('date_format', 'd/m/Y');
        $map      = date_formats();

        return $map[$format]['datepicker'] ?? 'dd/mm/yyyy';
    }
}
