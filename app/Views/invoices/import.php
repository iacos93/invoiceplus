<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('import_sales_xml', '', 'Importa fatture emesse')); ?>
<?php
$actions = '<div class="headerbar-total">'
    . '<span class="headerbar-total__label">' . trans('import', '', 'Importazione') . '</span>'
    . '<span class="headerbar-total__amount">XML</span>'
    . '</div>'
    . '<a class="btn btn-sm btn-secondary" href="' . site_url('invoices') . '">' . trans('cancel', '', 'Annulla') . '</a>'
    . '<button type="submit" form="invoices_import_form" class="btn btn-sm btn-success"><i class="fa fa-solid fa-upload" aria-hidden="true"></i> ' . trans('import', '', 'Importa') . '</button>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('import_sales_xml', '', 'Importa fatture emesse'), 'actions' => $actions]) ?>

<div id="content" class="ip-doc-form">
    <form method="POST" action="<?= site_url('invoices/run_import') ?>" enctype="multipart/form-data" id="invoices_import_form">
        <?= csrf_field() ?>

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">1</span>
                <h2 class="ip-doc-section__title"><?= trans('select_files', '', 'File da importare') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <?php
                // Cosa succede ai documenti, detto prima del caricamento: chi
                // arriva da un altro gestionale deve sapere che le fatture
                // nascono già "Inviate" e bloccate, e che la numerazione
                // riparte dopo l'ultima importata. Era un paragrafo unico da
                // 150 caratteri per riga; in punti brevi si legge a colpo
                // d'occhio.
                ?>
                <div class="ip-doc-field ip-import-drop">
                    <label class="ip-doc-label" for="xml_files"><?= trans('files', '', 'File XML, .p7m o ZIP') ?></label>
                    <input type="file" name="xml_files[]" id="xml_files" class="ip-file-input" multiple
                           accept=".xml,.p7m,.zip,application/xml,text/xml,application/zip"
                           aria-describedby="xml_files_help">
                    <p class="ip-field-help" id="xml_files_help"><?= trans('import_drop_help', '', 'Trascina qui i file o scegli dal computer. Anche ZIP e lotti con più fatture.') ?></p>
                </div>

                <ul class="ip-import-notes">
                    <li><i class="fa fa-solid fa-check" aria-hidden="true"></i> <?= trans('import_sales_note_1', '', 'Solo fatture emesse dall\'azienda attiva: le altre vengono rifiutate.') ?></li>
                    <li><i class="fa fa-solid fa-check" aria-hidden="true"></i> <?= trans('import_sales_note_2', '', 'Numero, data, cliente, righe e totali originali; i clienti mancanti vengono aggiunti.') ?></li>
                    <li><i class="fa fa-solid fa-check" aria-hidden="true"></i> <?= trans('import_sales_note_3', '', 'Stato "Inviata" e sola lettura: sono già state trasmesse allo SdI.') ?></li>
                    <li><i class="fa fa-solid fa-check" aria-hidden="true"></i> <?= trans('import_sales_note_4', '', 'I documenti già presenti vengono saltati; la numerazione riprende dopo l\'ultimo numero.') ?></li>
                </ul>
            </div>
        </section>
    </form>
</div>

<?= $this->endSection() ?>
