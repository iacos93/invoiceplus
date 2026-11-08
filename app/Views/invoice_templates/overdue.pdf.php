<?php
/**
 * Fattura scaduta — rossa, con il timbro "Scaduto" accanto al numero.
 *
 * E' la copia che si rimanda al cliente per farsi pagare: prima usciva senza
 * estremi di pagamento, perche' questo file era fermo a una revisione in cui
 * il blocco IBAN non esisteva ancora. Ora il corpo e' condiviso e non puo'
 * piu' restare indietro.
 */
echo $this->include('invoice_templates/partial_document', [
    'accent' => 'overdue',
    'badge'  => trans('overdue'),
]);
