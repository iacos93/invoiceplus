<?php
/**
 * Fattura saldata — verde, con il timbro "Pagato" accanto al numero.
 *
 * Il timbro sostituisce il tag <watermarktext> che questo template portava
 * dietro da mPDF: dompdf lo ignora, quindi la marcatura non compariva.
 */
echo $this->include('invoice_templates/partial_document', [
    'accent' => 'paid',
    'badge'  => trans('paid'),
]);
