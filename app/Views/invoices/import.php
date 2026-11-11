<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('import_sales_xml', '', 'Importa fatture emesse')); ?>
<?php
// Niente pastiglia "Importazione / XML" in testata: .headerbar-total è la
// forma dei totali (etichetta sopra, valore sotto) e qui mostrava una
// "Importazione" in maiuscoletto sopra la parola XML, cioe' un totale che
// non esiste, per di piu' ripetendo il titolo della pagina.
$actions = '<a class="btn btn-sm btn-secondary" href="' . site_url('invoices') . '">' . trans('cancel', '', 'Annulla') . '</a>'
    . '<button type="submit" form="invoices_import_form" class="btn btn-sm btn-success"><i class="fa fa-solid fa-upload" aria-hidden="true"></i> ' . trans('import', '', 'Importa') . '</button>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('import_sales_xml', '', 'Importa fatture emesse'), 'actions' => $actions]) ?>

<div id="content" class="ip-doc-form ip-import-layout">
    <form method="POST" action="<?= site_url('invoices/run_import') ?>" enctype="multipart/form-data" id="invoices_import_form">
        <?= csrf_field() ?>

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">1</span>
                <h2 class="ip-doc-section__title"><?= trans('select_files', '', 'File da importare') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <?php /* Tratteggio sul controllo e non su un contenitore intorno:
                         il trascinamento lo gestisce .ip-file__control, e il
                         riquadro esterno sembrava una zona di rilascio che
                         non accettava nulla. */ ?>
                <div class="ip-doc-field ip-file-drop">
                    <label class="ip-doc-label" for="xml_files"><?= trans('files', '', 'File XML, .p7m o ZIP') ?></label>
                    <input type="file" name="xml_files[]" id="xml_files" class="ip-file-input" multiple
                           accept=".xml,.p7m,.zip,application/xml,text/xml,application/zip"
                           aria-describedby="xml_files_help">
                    <p class="ip-field-help" id="xml_files_help"><?= trans('import_drop_help', '', 'Trascina qui i file o scegli dal computer. Anche ZIP e lotti con più fatture.') ?></p>
                </div>
            </div>
        </section>

        <?php
        // Cosa succede ai documenti, detto prima del caricamento: chi arriva
        // da un altro gestionale deve sapere che le fatture nascono già
        // "Inviate" e bloccate, e che la numerazione riparte dopo l'ultima
        // importata. Sta nella card a fianco e non sotto il campo: sotto
        // restava una colonna di quattro righe a sinistra di una pagina larga
        // il doppio, che nessuno leggeva come istruzioni.
        ?>
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step"><i class="fa fa-solid fa-circle-info" aria-hidden="true"></i></span>
                <h2 class="ip-doc-section__title"><?= trans('import_what_happens', '', 'Cosa succede all\'importazione') ?></h2>
            </div>
            <div class="ip-doc-section__body">
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
