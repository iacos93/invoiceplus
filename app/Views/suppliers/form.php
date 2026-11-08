<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', $id ? trans('edit_supplier', '', 'Modifica fornitore') : trans('add_supplier', '', 'Nuovo fornitore')); ?>

<?php
$active = db_bool($model->formValue('supplier_active'));
if ($model->formValue('supplier_active') === '' && !$id) {
    $active = true;
}

/**
 * Campo di testo standard: riduce la ripetizione label + input + errore.
 * Stessa firma usata nel form clienti per coerenza visiva.
 */
$field = function (string $name, string $label, array $opts = []) use ($model, $errors) {
    $type     = $opts['type'] ?? 'text';
    $ph       = $opts['placeholder'] ?? '';
    $maxlen   = $opts['maxlength'] ?? '';
    $noSpell  = !empty($opts['nospell']);
    $required = !empty($opts['required']) ? 'required' : '';
    ob_start(); ?>
    <div class="ip-doc-field">
        <label class="ip-doc-label" for="<?= $name ?>"><?= esc($label) ?></label>
        <input type="<?= $type ?>" name="<?= $name ?>" id="<?= $name ?>" class="form-control"
               <?= $ph !== '' ? 'placeholder="' . esc($ph, 'attr') . '"' : '' ?>
               <?= $maxlen !== '' ? 'maxlength="' . (int) $maxlen . '"' : '' ?>
               <?= $noSpell ? 'autocomplete="off" spellcheck="false"' : '' ?>
               <?= $required ?>
               value="<?= esc(old($name, $model->formValue($name))) ?>">
        <?php if (!empty($errors[$name])): ?>
            <span class="ip-field-error"><?= esc($errors[$name]) ?></span>
        <?php endif; ?>
    </div>
    <?php return ob_get_clean();
};
?>

<form method="POST" action="<?= site_url('suppliers/form/' . ($id ?? '')) ?>">

    <?= csrf_field() ?>

    <?= $this->include('layout/headerbar', [
        'title'   => $id ? trans('edit_supplier', '', 'Modifica fornitore') : trans('add_supplier', '', 'Nuovo fornitore'),
        'actions' => header_actions(),
    ]) ?>

    <div id="content">

        <!-- ═══════════════════════════════════════════════════════════════
             Card principale: Dati generali fornitore
             Toggle "Attivo" in header — stesso pattern della form cliente.
        ════════════════════════════════════════════════════════════════════ -->
        <div class="row">
            <div class="col-12">
                <div class="card mb-3">
                    <div class="card-header ip-table-card-header">
                        <div class="ip-table-card-title">
                            <span class="card-header-icon"><i class="fa fa-solid fa-shopping-basket" aria-hidden="true"></i></span>
                            <span class="ip-table-card-label"><?= trans('supplier_details', '', 'Dati fornitore') ?></span>
                        </div>

                        <label for="supplier_active" class="form-badge ip-card-header-badge">
                            <?= trans('active', '', 'Attivo') ?>
                            <input type="hidden" name="supplier_active" value="0">
                            <input id="supplier_active" name="supplier_active" type="checkbox" value="1"
                                <?= $active ? 'checked="checked"' : '' ?>>
                        </label>
                    </div>

                    <div class="card-body">
                        <div class="ip-doc-duo">
                            <!-- Denominazione: mezza larghezza come nel form cliente -->
                            <div class="ip-doc-field">
                                <label class="ip-doc-label" for="supplier_name">
                                    <?= trans('supplier_name', '', 'Ragione sociale / Denominazione') ?>
                                </label>
                                <input type="text" name="supplier_name" id="supplier_name" class="form-control"
                                       autofocus required
                                       value="<?= esc(old('supplier_name', $model->formValue('supplier_name'))) ?>">
                                <?php if (!empty($errors['supplier_name'])): ?>
                                    <span class="ip-field-error"><?= esc($errors['supplier_name']) ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Colonna destra vuota — simmetria visiva con la form cliente -->
                            <div class="ip-doc-field" style="visibility:hidden;" aria-hidden="true"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             Riga a tre colonne: Indirizzo | Contatti | Dati fiscali
             Stesso schema 3×col-md-4 della form cliente.
        ════════════════════════════════════════════════════════════════════ -->
        <div class="row">

            <!-- ── 1. Indirizzo ── -->
            <div class="col-12 col-md-4">
                <div class="card mb-3">
                    <div class="card-header ip-table-card-header">
                        <div class="ip-table-card-title">
                            <span class="card-header-icon"><i class="fa fa-solid fa-location-dot" aria-hidden="true"></i></span>
                            <span class="ip-table-card-label"><?= trans('address', '', 'Indirizzo') ?></span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?= $field('supplier_address_1', trans('street_address', '', 'Indirizzo (1ª riga)')) ?>
                        <?= $field('supplier_address_2', trans('street_address_2', '', 'Indirizzo (2ª riga)')) ?>
                        <?= $field('supplier_city',      trans('city',            '', 'Città')) ?>
                        <?= $field('supplier_state',     trans('state',           '', 'Provincia')) ?>
                        <?= $field('supplier_zip',       trans('zip_code',        '', 'CAP')) ?>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="supplier_country"><?= trans('country', '', 'Paese') ?></label>
                            <?php
                            // Stesso select del form cliente: il paese è un codice
                            // a due lettere e va scelto, non digitato. Se però la
                            // scheda arriva da un dato vecchio scritto a mano
                            // ("Italia", "ITA"), quella voce resta in cima
                            // all'elenco: riscriverla in silenzio con il primo
                            // paese della lista sarebbe peggio di mostrarla.
                            $selected_country = (string) old('supplier_country', $model->formValue('supplier_country') ?: get_setting('default_country'));
                            $countryOptions   = $countries;
                            if ($selected_country !== '' && !isset($countryOptions[$selected_country])) {
                                $countryOptions = [$selected_country => $selected_country] + $countryOptions;
                            }
                            ?>
                            <select name="supplier_country" id="supplier_country" class="form-control simple-select"
                                    data-placeholder="<?= esc(trans('country', '', 'Paese')) ?>" data-allow-clear>
                                <option value=""><?= trans('none') ?></option>
                                <?php foreach ($countryOptions as $cldr => $country): ?>
                                    <option value="<?= esc($cldr) ?>"
                                        <?php check_select($selected_country, $cldr) ?>>
                                        <?= esc($country) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── 2. Contatti ── -->
            <div class="col-12 col-md-4">
                <div class="card mb-3">
                    <div class="card-header ip-table-card-header">
                        <div class="ip-table-card-title">
                            <span class="card-header-icon"><i class="fa fa-regular fa-address-book" aria-hidden="true"></i></span>
                            <span class="ip-table-card-label"><?= trans('contact_information', '', 'Contatti') ?></span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?= $field('supplier_phone', trans('phone_number', '', 'Telefono')) ?>
                        <?= $field('supplier_email', trans('email_address', '', 'Email'), ['type' => 'email', 'required' => true]) ?>
                        <?= $field('supplier_web',   trans('web_address',  '', 'Sito web')) ?>
                    </div>
                </div>
            </div>

            <!-- ── 3. Dati fiscali e fattura elettronica ── -->
            <div class="col-12 col-md-4">
                <div class="card mb-3">
                    <div class="card-header ip-table-card-header">
                        <div class="ip-table-card-title">
                            <span class="card-header-icon"><i class="fa fa-regular fa-id-card" aria-hidden="true"></i></span>
                            <span class="ip-table-card-label"><?= trans('tax_information', '', 'Dati fiscali') ?></span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?= $field('supplier_vat_id',   trans('vat_id',   '', 'Partita IVA'),     ['nospell' => true, 'required' => true]) ?>
                        <?= $field('supplier_tax_code', trans('tax_code', '', 'Codice fiscale'),   ['nospell' => true, 'required' => true]) ?>
                        <?= $field('supplier_sdi_code', trans('sdi_code', '', 'Codice dest. SdI'), ['nospell' => true, 'maxlength' => 7]) ?>
                        <?= $field('supplier_sdi_pec',  trans('sdi_pec',  '', 'PEC FatturaPA'),    ['type' => 'email']) ?>
                        <?php if (!empty($errors['supplier_sdi_pec'])): ?>
                            <span class="ip-field-error"><?= esc($errors['supplier_sdi_pec']) ?></span>
                        <?php endif; ?>
                        <?= $field('supplier_iban', trans('iban', '', 'IBAN'), ['nospell' => true]) ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- ═══════════════════════════════════════════════════════════════
             Card note: a piena larghezza, come i campi custom dei clienti.
        ════════════════════════════════════════════════════════════════════ -->
        <div class="row">
            <div class="col-12">
                <div class="card mb-3">
                    <div class="card-header ip-table-card-header">
                        <div class="ip-table-card-title">
                            <span class="card-header-icon"><i class="fa fa-regular fa-sticky-note" aria-hidden="true"></i></span>
                            <span class="ip-table-card-label"><?= trans('notes', '', 'Note') ?></span>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="supplier_note"><?= trans('note', '', 'Nota interna') ?></label>
                            <textarea name="supplier_note" id="supplier_note" rows="4"
                                      class="form-control"><?= esc(old('supplier_note', $model->formValue('supplier_note'))) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /#content -->

</form>

<?= $this->endSection() ?>
