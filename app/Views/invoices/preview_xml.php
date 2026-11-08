<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('xml_preview', '', 'Anteprima fattura elettronica')); ?>

<?php
$number  = $invoice['invoice_number'] ?: $invoice['invoice_id'];
$actions = '<a class="btn btn-sm btn-secondary" href="' . site_url('invoices/validate_xml/' . $invoice['invoice_id']) . '">'
    . '<i class="fa fa-solid fa-check-circle" aria-hidden="true"></i> ' . trans('validate_xml', '', 'Valida') . '</a>'
    . '<a class="btn btn-sm btn-secondary" href="' . site_url('invoices/view/' . $invoice['invoice_id']) . '">'
    . '<i class="fa fa-solid fa-arrow-left" aria-hidden="true"></i> ' . trans('back_to_invoice', '', 'Torna alla fattura') . '</a>';
?>

<?= $this->include('layout/headerbar', [
    'title'   => trans('xml_preview', '', 'Anteprima fattura elettronica') . ' <span class="ip-num">' . esc($number) . '</span>',
    'actions' => $actions,
]) ?>

<div id="content">
    <?= $this->include('layout/xml_preview', [
        'result'  => $result,
        'styles'  => $styles,
        'style'   => $style,
        'summary' => $summary,
        'rawXml'  => $rawXml,
        'baseUrl' => site_url('invoices/preview_xml/' . $invoice['invoice_id']),
    ]) ?>
</div>

<?= $this->endSection() ?>
