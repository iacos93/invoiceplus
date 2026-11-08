<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('create_quote')); ?>

<?php
// QuotesAjax::create() salva anche le righe passate nel campo "items" (JSON),
// nello stesso formato usato da InvoicesAjax::save().
$doc_title            = trans('create_quote', '', 'Nuovo preventivo');
$doc_action           = 'quotes/ajax/create';
$doc_cancel_url       = 'quotes';
$doc_submit_label     = trans('create_quote', '', 'Crea preventivo');
$doc_date_field       = 'quote_date_created';
$doc_date_label       = trans('quote_date');
$doc_password_field   = 'quote_password';
$doc_password_label   = trans('quote_password');
$doc_password_default = decrypt_setting_password((string) get_setting('quote_pre_password', ''));
$doc_group_label      = trans('quote_group');
$doc_group_default    = company_setting('default_quote_group');
$doc_number_field     = 'quote_number';

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
