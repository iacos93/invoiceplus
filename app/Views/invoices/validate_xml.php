<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
$number = $invoice['invoice_number'] ?: ('#' . (int) $invoice['invoice_id']);
$this->setVar('pageTitle', trans('validate_xml', '', 'Verifica tracciato') . ' ' . $number);

$actions = '<div class="ip-doc-actions">'
    . '<a class="btn btn-sm btn-primary" href="' . site_url('invoices/view/' . (int) $invoice['invoice_id']) . '">'
    . '<i class="fa fa-solid fa-eye" aria-hidden="true"></i> ' . trans('preview', '', 'Anteprima') . '</a>'
    . '<a class="btn btn-sm btn-secondary" href="' . site_url('invoices/generate_xml/' . (int) $invoice['invoice_id']) . '">'
    . '<i class="fa fa-solid fa-download" aria-hidden="true"></i> ' . trans('download', '', 'Scarica XML') . '</a>'
    . '</div>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('validate_xml', '', 'Verifica tracciato') . ' ' . esc($number), 'actions' => $actions]) ?>

<div id="content">

    <?php if (!empty($preflight)): ?>
        <?php
        /* Prima gli errori di schema: quando manca un dato anagrafico il file
           non può passare qualunque altra cosa si sistemi, e il messaggio di
           libxml ("Elemento 'Nazione' non atteso") non dice quale scheda
           aprire. Qui sì. */
        ?>
        <div class="alert alert-warning">
            <i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i>
            <strong><?= trans('sdi_missing_title', '', 'Manca un dato obbligatorio del tracciato.') ?></strong>
            <?= trans('sdi_missing_hint', '', 'Indirizzo, CAP e comune di cedente e cessionario sono obbligatori: senza, lo SdI scarta il file per errore di schema.') ?>
            <ul class="mb-0">
                <?php foreach ($preflight as $problem): ?>
                    <?php $isCompany = $problem['party'] === 'company'; ?>
                    <li>
                        <?= esc($isCompany ? trans('company', '', 'Azienda') : trans('client', '', 'Cliente')) ?>
                        <strong><?= esc($problem['name']) ?></strong>:
                        <?= esc(implode(', ', $problem['fields'])) ?>
                        —
                        <a href="<?= site_url(($isCompany ? 'companies/form/' : 'clients/form/') . (int) ($isCompany ? $invoice['company_id'] : $invoice['client_id'])) ?>"><?= trans('sdi_missing_fix', '', 'Completa la scheda') ?></a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

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
            <?php /* I numeri di riga degli errori si contano su questo blocco:
                     la validazione gira sullo stesso testo indentato. */ ?>
            <div class="ip-xml-block" data-ip-xml-block>
                <div class="ip-xml-block__tools">
                    <button type="button" class="ip-xml-tool-btn"
                            data-ip-xml-copy
                            data-copied-label="<?= esc(trans('xml_copied', '', 'Copiato'), 'attr') ?>"><?= trans('xml_copy', '', 'Copia') ?></button>
                    <button type="button" class="ip-xml-tool-btn"
                            data-ip-xml-wrap aria-pressed="false"><?= trans('xml_wrap', '', 'A capo') ?></button>
                </div>
                <pre class="ip-xml-preview" tabindex="0"><code><?= esc($xml) ?></code></pre>
            </div>
        </div>
    </section>
</div>

<?= $this->include('layout/xml_highlight') ?>

<?= $this->endSection() ?>
