<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('import_purchase_xml', '', 'Importa fatture ricevute')); ?>
<?php
// Niente pastiglia "Importazione / XML" in testata, come in Fatture: è la
// forma dei totali usata per un totale che non c'è.
$actions = '<a class="btn btn-sm btn-secondary" href="' . site_url('expenses') . '">' . trans('cancel', '', 'Annulla') . '</a>'
    . '<button type="submit" form="expenses_import_form" class="btn btn-sm btn-success"><i class="fa fa-solid fa-upload" aria-hidden="true"></i> ' . trans('import', '', 'Importa') . '</button>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('import_purchase_xml', '', 'Importa fatture ricevute'), 'actions' => $actions]) ?>

<div id="content" class="ip-doc-form ip-import-layout">
    <form method="POST" action="<?= site_url('expenses/run_import') ?>" enctype="multipart/form-data" id="expenses_import_form">
        <?= csrf_field() ?>

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">1</span>
                <h2 class="ip-doc-section__title"><?= trans('select_files', '', 'File da importare') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <?php /* Tratteggio sul controllo, non su un contenitore intorno:
                         il drop lo ascolta .ip-file__control. */ ?>
                <div class="ip-doc-field ip-file-drop">
                    <label class="ip-doc-label" for="xml_files"><?= trans('files', '', 'File XML, .p7m o ZIP') ?></label>
                    <input type="file" name="xml_files[]" id="xml_files" class="ip-file-input" multiple
                           accept=".xml,.p7m,.zip,application/xml,text/xml,application/zip"
                           aria-describedby="xml_files_help">
                    <p class="ip-field-help" id="xml_files_help"><?= trans('import_drop_hint', '', 'Trascina qui i file o scegli dal computer.') ?></p>
                </div>

                <div class="ip-doc-field ip-import-category">
                    <label class="ip-doc-label" for="expense_category_id"><?= trans('category', '', 'Categoria da assegnare') ?></label>
                    <select name="expense_category_id" id="expense_category_id" class="form-control simple-select">
                        <option value=""><?= trans('none', '', 'Nessuna') ?></option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category['expense_category_id'] ?>">
                                <?= esc($category['expense_category_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </section>

        <?php
        // Cosa entra e cosa viene saltato: prima era un paragrafo unico da
        // cinque righe sotto il campo, che allungava la card del gesto e si
        // leggeva a fatica. Qui è un elenco accanto, la stessa frase spezzata
        // sui suoi periodi.
        ?>
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step"><i class="fa fa-solid fa-circle-info" aria-hidden="true"></i></span>
                <h2 class="ip-doc-section__title"><?= trans('import_what_happens', '', 'Cosa succede all\'importazione') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <ul class="ip-import-notes">
                    <li><i class="fa fa-solid fa-check" aria-hidden="true"></i> <?= trans('import_xml_note_types', '', 'XML di fatture ricevute, file firmati .p7m e lo ZIP del portale Fatture e Corrispettivi (anche lotti).') ?></li>
                    <li><i class="fa fa-solid fa-check" aria-hidden="true"></i> <?= trans('import_xml_note_dupes', '', 'I documenti già importati vengono saltati.') ?></li>
                </ul>
            </div>
        </section>
    </form>
</div>

<?= $this->endSection() ?>
