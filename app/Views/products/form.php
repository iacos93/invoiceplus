<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('products_form')); ?>

<form method="post" action="<?= site_url('products/form/' . ($product_id ?? '')) ?>">

    <?= csrf_field() ?>

    <?= $this->include('layout/headerbar', ['title' => trans('products_form'), 'actions' => header_actions()]) ?>

    <div id="content">

        <div class="row">
            <div class="col-12 col-md-6">

                <div class="card">
                    <div class="card-header">

                        <?php if ($model->formValue('product_id')): ?>
                            #<?= esc($model->formValue('product_id')) ?>&nbsp;
                            <?= esc($model->formValue('product_name')) ?>
                        <?php else: ?>
                            <?= trans('new_product') ?>
                        <?php endif; ?>

                    </div>
                    <div class="card-body">

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="family_id">
                                <?= trans('family') ?>
                            </label>

                            <select name="family_id" id="family_id" class="form-control simple-select">
                                <option value="0"><?= trans('select_family') ?></option>
                                <?php foreach ($families as $family): ?>
                                    <option value="<?= esc($family['family_id']) ?>"
                                        <?php check_select($model->formValue('family_id'), $family['family_id']) ?>>
                                        <?= esc($family['family_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="product_sku">
                                <?= trans('product_sku') ?>
                            </label>

                            <input type="text" name="product_sku" id="product_sku" class="form-control"
                                   value="<?= esc(old('product_sku', $model->formValue('product_sku'))) ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="product_name">
                                <?= trans('product_name') ?>
                            </label>

                            <input type="text" name="product_name" id="product_name" class="form-control" required
                                   value="<?= esc(old('product_name', $model->formValue('product_name'))) ?>">
                            <?php if (!empty($errors['product_name'])): ?>
                                <span class="ip-field-error"><?= esc($errors['product_name']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="product_description">
                                <?= trans('product_description') ?>
                            </label>

                            <textarea name="product_description" id="product_description" class="form-control"
                                      rows="3"><?= esc(old('product_description', $model->formValue('product_description'))) ?></textarea>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="product_price">
                                <?= trans('product_price') ?>
                            </label>

                            <div class="ip-input-suffix">
                                <input type="text" name="product_price" id="product_price" class="form-control amount"
                                       value="<?= esc(old('product_price', format_amount($model->formValue('product_price')))) ?>">
                                <span class="ip-input-suffix__mark"><?= htmlsc(get_setting('currency_symbol')) ?></span>
                            </div>
                            <?php if (!empty($errors['product_price'])): ?>
                                <span class="ip-field-error"><?= esc($errors['product_price']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="unit_id">
                                <?= trans('product_unit') ?>
                            </label>

                            <select name="unit_id" id="unit_id" class="form-control simple-select">
                                <option value="0"><?= trans('select_unit') ?></option>
                                <?= view('layout/unit_options', ['unit_groups' => $unit_groups, 'selected' => $model->formValue('unit_id')]) ?>
                            </select>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="tax_rate_id">
                                <?= trans('tax_rate') ?>
                            </label>

                            <select name="tax_rate_id" id="tax_rate_id" class="form-control simple-select">
                                <option value="0"><?= trans('none') ?></option>
                                <?php foreach ($tax_rates as $tax_rate): ?>
                                    <option value="<?= esc($tax_rate['tax_rate_id']) ?>"
                                        <?php check_select($model->formValue('tax_rate_id'), $tax_rate['tax_rate_id']) ?>>
                                        <?= esc($tax_rate['tax_rate_name']
                                            . ' (' . format_amount($tax_rate['tax_rate_percent']) . '%)') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6">

                <div class="card">
                    <div class="card-header">
                        <?= trans('extra_information') ?>
                    </div>
                    <div class="card-body">

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="provider_name">
                                <?= trans('provider_name') ?>
                            </label>

                            <input type="text" name="provider_name" id="provider_name" class="form-control"
                                   value="<?= esc(old('provider_name', $model->formValue('provider_name'))) ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="purchase_price">
                                <?= trans('purchase_price') ?>
                            </label>

                            <div class="ip-input-suffix">
                                <input type="text" name="purchase_price" id="purchase_price" class="form-control amount"
                                       value="<?= esc(old('purchase_price', format_amount($model->formValue('purchase_price')))) ?>">
                                <span class="ip-input-suffix__mark"><?= htmlsc(get_setting('currency_symbol')) ?></span>
                            </div>
                        </div>

                    </div>
                </div>

            </div>
        </div>

    </div>

</form>

<?= $this->endSection() ?>