<?php
/**
 * Sezione "Fattura elettronica" del form di creazione documento
 * (layout/document_create_form.php, attivata da $doc_show_einvoice — solo
 * le fatture la compilano, i preventivi no).
 *
 * La logica stile FattureinCloud (solo i campi essenziali in vista, il resto
 * in gruppi collassabili) vive in invoices/partial_einvoice_fields.php,
 * condiviso con la scheda di modifica: qui si passa solo il resolver dei
 * valori che arrivano da old(), perché il documento non esiste ancora.
 */
$get = static fn(string $name) => old($name, '');
echo view('invoices/partial_einvoice_fields', ['get' => $get]);
