<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
$this->setVar('pageTitle', trans('tax_rate_form'));

$currentNatura = old('tax_rate_natura', $model->formValue('tax_rate_natura'));
?>

<form method="post" action="<?= site_url('tax_rates/form/' . ($tax_rate_id ?? '')) ?>" class="">

    <?= csrf_field() ?>

    <?= $this->include('layout/headerbar', ['title' => trans('tax_rate_form'), 'actions' => header_actions()]) ?>

    <div id="content">

        <div class="ip-form-pane">

            <div class="card"><div class="card-body">

            <div class="row">
                <div class="col-12 col-md-6">

                <div class="ip-doc-field">
                    <label class="ip-doc-label" for="tax_rate_name">
                        <?= trans('tax_rate_name') ?>
                    </label>
                    <input type="text" name="tax_rate_name" id="tax_rate_name" class="form-control" maxlength="100"
                           value="<?= esc(old('tax_rate_name', $model->formValue('tax_rate_name'))) ?>">
                    <?php if (!empty($errors['tax_rate_name'])): ?>
                        <span class="ip-field-error"><?= esc($errors['tax_rate_name']) ?></span>
                    <?php endif; ?>
                </div>

                </div>
                <div class="col-12 col-md-3">

                <div class="ip-doc-field">
                    <label class="ip-doc-label" for="tax_rate_percent">
                        <?= trans('tax_rate_percent') ?>
                    </label>
                    <div class="ip-input-suffix">
                        <input type="text" name="tax_rate_percent" id="tax_rate_percent" class="form-control amount"
                               value="<?= esc(old('tax_rate_percent', format_amount($model->formValue('tax_rate_percent')))) ?>">
                        <span class="ip-input-suffix__mark">%</span>
                    </div>
                    <?php if (!empty($errors['tax_rate_percent'])): ?>
                        <span class="ip-field-error"><?= esc($errors['tax_rate_percent']) ?></span>
                    <?php endif; ?>
                </div>

                </div>
            </div>

            <div class="row">
                <div class="col-12">

                <div class="ip-doc-field">
                    <label class="ip-doc-label" for="tax_rate_natura">
                        <?= trans('tax_rate_natura', '', 'Natura IVA (fattura elettronica)') ?>
                    </label>
                    <select name="tax_rate_natura" id="tax_rate_natura" class="form-control simple-select">
                        <option value=""<?= $currentNatura === '' || $currentNatura === null ? ' selected' : '' ?>>
                            <?= esc(trans('tax_rate_natura_none', '', '— Nessuna (aliquota ordinaria con IVA) —')) ?>
                        </option>
                        <?php foreach (\App\Models\TaxRateModel::naturaGroups() as $groupLabel => $codes): ?>
                            <optgroup label="<?= esc($groupLabel) ?><?= $groupLabel === 'Inversione contabile' ? ' (reverse charge)' : '' ?>">
                                <?php foreach ($codes as $code => $label): ?>
                                    <option value="<?= esc($code) ?>"<?= $currentNatura === $code ? ' selected' : '' ?>>
                                        <?= esc($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text text-muted">
                        <?= esc(trans(
                            'tax_rate_natura_help',
                            '',
                            'Da indicare solo per operazioni senza IVA esposta (esenti, non imponibili, escluse) o in inversione contabile. Per un\'aliquota ordinaria (es. 22%, 10%, 4%) lasciare "Nessuna".'
                        )) ?>
                    </small>
                    <?php if (!empty($errors['tax_rate_natura'])): ?>
                        <span class="ip-field-error"><?= esc($errors['tax_rate_natura']) ?></span>
                    <?php endif; ?>
                </div>

                </div>
            </div>

            </div></div>

        </div>

    </div>

</form>

<?= $this->endSection() ?>