<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('xml_preview', '', 'Anteprima fattura elettronica')); ?>

<?php
$actions = '<a class="btn btn-sm btn-secondary" href="' . site_url('expenses') . '">'
    . '<i class="fa fa-solid fa-arrow-left" aria-hidden="true"></i> ' . trans('back_to_expenses', '', 'Torna alle spese') . '</a>';

$title = trans('xml_preview', '', 'Anteprima fattura elettronica')
    . ' <span class="ip-num">' . esc($expense['expense_number'] ?: $expense['expense_id']) . '</span>'
    . ' <span class="ip-preview-supplier">' . esc($expense['supplier_name']) . '</span>';
?>

<?= $this->include('layout/headerbar', ['title' => $title, 'actions' => $actions]) ?>

<div id="content">
    <?= $this->include('layout/xml_preview', [
        'result'  => $result,
        'styles'  => $styles,
        'style'   => $style,
        'summary' => $summary,
        'rawXml'  => $rawXml,
        'baseUrl' => site_url('expenses/preview_xml/' . $expense['expense_id']),
    ]) ?>
</div>

<?= $this->endSection() ?>
