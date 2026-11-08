<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
$number = $invoice['invoice_number'] ?: ('#' . (int) $invoice['invoice_id']);
$this->setVar('pageTitle', trans('validate_xml', '', 'Verifica tracciato') . ' ' . $number);

$actions = '<div class="ip-doc-actions">'
    . '<a class="btn btn-sm btn-primary" href="' . site_url('invoices/view/' . (int) $invoice['invoice_id']) . '">'
    . '<i class="fa fa-solid fa-eye" aria-hidden="true"></i> ' . trans('view', '', 'Anteprima') . '</a>'
    . '<a class="btn btn-sm btn-secondary" href="' . site_url('invoices/generate_xml/' . (int) $invoice['invoice_id']) . '">'
    . '<i class="fa fa-solid fa-download" aria-hidden="true"></i> ' . trans('download', '', 'Scarica XML') . '</a>'
    . '</div>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('validate_xml', '', 'Verifica tracciato') . ' ' . esc($number), 'actions' => $actions]) ?>

<div id="content">

    <?php if ($result['valid']): ?>
        <div class="alert alert-success">
            <i class="fa fa-solid fa-check-circle" aria-hidden="true"></i>
            <strong><?= trans('xml_valid', '', 'Tracciato conforme.') ?></strong>
            <?= trans('xml_valid_hint', '', 'Il file rispetta lo schema FatturaPA 1.2.1: lo SdI non lo scarterà per errori di formato. Restano possibili scarti per motivi di merito (codice destinatario inesistente, partita IVA cessata, numero duplicato).') ?>
        </div>
    <?php else: ?>
        <div class="alert alert-danger">
            <i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i>
            <strong><?= trans('xml_invalid', '', 'Tracciato non conforme.') ?></strong>
            <?= trans('xml_invalid_hint', '', 'Inviato così verrebbe scartato dallo SdI. Ogni riga indica il campo che non rispetta lo schema.') ?>
        </div>

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step"><i class="fa fa-solid fa-list" aria-hidden="true"></i></span>
                <h2 class="ip-doc-section__title"><?= trans('errors', '', 'Errori') ?></h2>
                <span class="ip-doc-section__hint"><?= count($result['errors']) ?></span>
            </div>
            <div class="ip-doc-section__body">
                <ul class="ip-xml-errors">
                    <?php foreach ($result['errors'] as $error): ?>
                        <li><?= esc($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    <?php endif; ?>

    <section class="ip-doc-section">
        <div class="ip-doc-section__head">
            <span class="ip-doc-step"><i class="fa fa-regular fa-file-code" aria-hidden="true"></i></span>
            <h2 class="ip-doc-section__title"><?= trans('xml_content', '', 'Contenuto XML') ?></h2>
        </div>
        <div class="ip-doc-section__body">
            <pre class="ip-xml-preview"><?= esc($xml) ?></pre>
        </div>
    </section>
</div>

<?= $this->endSection() ?>
