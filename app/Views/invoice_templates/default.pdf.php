<?php
/**
 * Fattura — documento neutro (non ancora saldato, non ancora scaduto).
 *
 * Il contenuto sta in partial_document.php: qui si sceglie solo lo stato.
 * Vedi il commento nel partial per il perche' di questa separazione.
 */
echo $this->include('invoice_templates/partial_document', [
    'accent' => 'neutral',
    'badge'  => '',
]);
