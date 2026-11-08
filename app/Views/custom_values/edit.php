<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('custom_values_edit')); ?>

<form method="POST" action="<?= site_url('custom_values/edit/' . ($value['custom_values_id'] ?? '')) ?>">

    <?= csrf_field() ?>

    <?= $this->include('layout/headerbar', ['title' => trans('custom_values_edit'), 'actions' => header_actions()]) ?>

    <div id="content">

        <div class="ip-form-pane">


                <div class="card">
                    <div class="card-header">
                        <?= trans('custom_values_edit') ?>
                    </div>

                    <div class="card-body">
                        <div class="row">
                            <div class="col-12 col-md-6">
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="custom_values_label"><?= trans('field') ?></label>
                            <input type="text" class="form-control" id="custom_values_label"
                                   value="<?= esc($field['custom_field_label'] ?? '') ?>" disabled="disabled"/>
                        </div>
                            </div>
                            <div class="col-12 col-md-6">

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="custom_values_value"><?= trans('label') ?></label>
                            <input type="text" name="custom_values_value" id="custom_values_value" class="form-control"
                                   value="<?= esc(old('custom_values_value', $model->formValue('custom_values_value'))) ?>">
                            <?php if (!empty($errors['custom_values_value'])): ?>
                                <span class="ip-field-error"><?= esc($errors['custom_values_value']) ?></span>
                            <?php endif; ?>
                        </div>
                            </div>

                        <?php if ($has_order_column): ?>
                            <div class="col-12 col-md-3">
                            <div class="ip-doc-field">
                                <label class="ip-doc-label" for="custom_values_order"><?= trans('order') ?></label>
                                <input type="number" name="custom_values_order" id="custom_values_order"
                                       class="form-control"
                                       value="<?= esc(old('custom_values_order', $model->formValue('custom_values_order'))) ?>">
                                <?php if (!empty($errors['custom_values_order'])): ?>
                                    <span class="ip-field-error"><?= esc($errors['custom_values_order']) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

            </div>
        </div>

    </div>

</form>

<?= $this->endSection() ?>