<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('create_invoice', '', 'Nuova fattura')); ?>

<?php
// invoices/ajax/save (non /create): quando invoice_id è assente nel POST crea
// il documento E le righe (campo "items" in JSON) in un solo salvataggio —
// vedi InvoicesAjax::save(). /create resta nel routing per compatibilità ma
// non gestisce righe.
$doc_title            = trans('create_invoice', '', 'Nuova fattura');
$doc_action           = 'invoices/ajax/save';
$doc_cancel_url       = 'invoices';
$doc_submit_label     = trans('create_invoice', '', 'Crea fattura');
$doc_date_field       = 'invoice_date_created';
$doc_date_label       = trans('invoice_date');
$doc_password_field   = 'invoice_password';
$doc_password_label   = trans('invoice_password');
$doc_password_default = decrypt_setting_password((string) get_setting('invoice_pre_password', ''));
$doc_group_label      = trans('invoice_group');
$doc_group_default    = company_setting('default_invoice_group', 0);
$doc_number_field     = 'invoice_number';
// La sezione "Fattura elettronica" (CIG/CUP/ritenute/bollo...) appartiene solo
// alle fatture: il flag la mostra nel partial condiviso, e il POST la salva
// già in creazione via dbArrayFromPost(). I preventivi non la ricevono.
$doc_show_einvoice    = true;

// Totale e pulsanti Annulla/Salva vivono nell'headerbar (invece che nella
// barra fissa in fondo alla pagina): il totale è calcolato lato client
// (vedi document_create_form.php), qui parte da "—" e lo aggiorna il JS
// tramite data-doc-total-mirror (lo aggiorna ip-doc-items.js). Il bottone di salvataggio referenzia il form
// con l'attributo "form" perché l'headerbar viene renderizzata prima del
// <form> stesso.
$actions = '<div class="headerbar-total">'
    . '<span class="headerbar-total__label">' . trans('total', '', 'Totale') . '</span>'
    . '<span class="headerbar-total__amount" data-doc-total-mirror>—</span>'
    . '</div>'
    . '<a class="btn btn-sm btn-secondary" href="' . site_url($doc_cancel_url) . '">' . trans('cancel', '', 'Annulla') . '</a>'
    . '<button type="submit" form="document_create_form" class="btn btn-sm btn-success"><i class="fa fa-solid fa-check" aria-hidden="true"></i> ' . esc($doc_submit_label) . '</button>';
?>
<?= $this->include('layout/headerbar', ['title' => $doc_title, 'actions' => $actions]) ?>
<?= view('layout/document_create_form', get_defined_vars()) ?>

<?= $this->endSection() ?>
