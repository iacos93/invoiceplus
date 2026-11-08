<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('import_purchase_xml', '', 'Importa fatture ricevute')); ?>
<?php
$actions = '<div class="headerbar-total">'
    . '<span class="headerbar-total__label">' . trans('import', '', 'Importazione') . '</span>'
    . '<span class="headerbar-total__amount">XML</span>'
    . '</div>'
    . '<a class="btn btn-sm btn-secondary" href="' . site_url('expenses') . '">' . trans('cancel', '', 'Annulla') . '</a>'
    . '<button type="submit" form="expenses_import_form" class="btn btn-sm btn-success"><i class="fa fa-solid fa-upload" aria-hidden="true"></i> ' . trans('import', '', 'Importa') . '</button>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('import_purchase_xml', '', 'Importa fatture ricevute'), 'actions' => $actions]) ?>

<div id="content" class="ip-doc-form">
    <form method="POST" action="<?= site_url('expenses/run_import') ?>" enctype="multipart/form-data" id="expenses_import_form">
        <?= csrf_field() ?>

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">1</span>
                <h2 class="ip-doc-section__title"><?= trans('select_files', '', 'File da importare') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-field ip-import-drop">
                    <label class="ip-doc-label" for="xml_files"><?= trans('files', '', 'File XML, .p7m o ZIP') ?></label>
                    <input type="file" name="xml_files[]" id="xml_files" class="ip-file-input" multiple
                           accept=".xml,.p7m,.zip,application/xml,text/xml,application/zip"
                           aria-describedby="xml_files_help">
                    <p class="ip-field-help" id="xml_files_help"><?= trans('import_xml_help', '', 'Trascina qui i file o scegli dal computer: XML di fatture ricevute, file firmati .p7m e lo ZIP del portale Fatture e Corrispettivi (anche lotti). I documenti già importati vengono saltati.') ?></p>
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
    </form>
</div>

<?= $this->endSection() ?>
