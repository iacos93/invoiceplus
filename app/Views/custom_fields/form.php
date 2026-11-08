<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('custom_field_form')); ?>

<form method="POST" action="<?= site_url('custom_fields/form/' . ($custom_field_id ?? '')) ?>">

    <?= csrf_field() ?>

    <?= $this->include('layout/headerbar', ['title' => trans('custom_field_form'), 'actions' => header_actions()]) ?>

    <div id="content">

        <div class="ip-form-pane">

            <div class="card">
                <div class="card-header">
                    <?= trans('custom_field_form') ?>
                </div>

                <div class="card-body">
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="custom_field_table"><?= trans('table') ?></label>
                        <select name="custom_field_table" id="custom_field_table" class="form-control simple-select">
                            <?php foreach ($custom_field_tables as $table => $label): ?>
                                <option value="<?= esc($table) ?>" <?= check_select($model->formValue('custom_field_table'), $table) ?>>
                                    <?= esc(trans($label)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($errors['custom_field_table'])): ?>
                            <span class="ip-field-error"><?= esc($errors['custom_field_table']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="custom_field_label"><?= trans('label') ?></label>
                        <input type="text" name="custom_field_label" id="custom_field_label" class="form-control"
                               value="<?= esc(old('custom_field_label', $model->formValue('custom_field_label'))) ?>">
                        <?php if (!empty($errors['custom_field_label'])): ?>
                            <span class="ip-field-error"><?= esc($errors['custom_field_label']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="custom_field_type"><?= trans('type') ?></label>
                        <select name="custom_field_type" id="custom_field_type" class="form-control simple-select">
                            <?php foreach ($custom_field_types as $type): ?>
                                <?php $alpha = str_replace('-', '_', strtolower($type)); ?>
                                <option value="<?= esc($type) ?>" <?= check_select($model->formValue('custom_field_type'), $type) ?>>
                                    <?= esc(trans($alpha)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($errors['custom_field_type'])): ?>
                            <span class="ip-field-error"><?= esc($errors['custom_field_type']) ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($has_required_column): ?>
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="custom_field_required" name="custom_field_required" value="1"
                                    <?= $model->formValue('custom_field_required') == '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="custom_field_required"><?= trans('required') ?></label>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="custom_field_order"><?= trans('order') ?></label>
                        <input type="number" name="custom_field_order" id="custom_field_order" class="form-control"
                               value="<?= esc(old('custom_field_order', $model->formValue('custom_field_order'))) ?>">
                        <?php if (!empty($errors['custom_field_order'])): ?>
                            <span class="ip-field-error"><?= esc($errors['custom_field_order']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

        </div>

    </div>

</form>

<?= $this->endSection() ?>