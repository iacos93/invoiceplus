<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('add_unit')); ?>

<form method="post" action="<?= site_url('units/form/' . ($unit_id ?? '')) ?>">

    <?= csrf_field() ?>

    <?= $this->include('layout/headerbar', ['title' => trans('add_unit'), 'actions' => header_actions()]) ?>

    <div id="content">

        <div class="ip-form-pane">

            <div class="card"><div class="card-body">

            <div class="row">
                <div class="col-12 col-md-6">

                <div class="ip-doc-field">
                    <label class="ip-doc-label" for="unit_name">
                        <?= trans('unit_name') ?>
                    </label>
                    <input type="text" name="unit_name" id="unit_name" class="form-control"
                            value="<?= esc(old('unit_name', $model->formValue('unit_name'))) ?>">
                    <?php if (!empty($errors['unit_name'])): ?>
                        <span class="ip-field-error"><?= esc($errors['unit_name']) ?></span>
                    <?php endif; ?>
                </div>

                </div>
                <div class="col-12 col-md-6">

                <div class="ip-doc-field">
                    <label class="ip-doc-label" for="unit_name_plrl">
                        <?= trans('unit_name_plrl') ?>
                    </label>
                    <input type="text" name="unit_name_plrl" id="unit_name_plrl" class="form-control"
                            value="<?= esc(old('unit_name_plrl', $model->formValue('unit_name_plrl'))) ?>">
                    <?php if (!empty($errors['unit_name_plrl'])): ?>
                        <span class="ip-field-error"><?= esc($errors['unit_name_plrl']) ?></span>
                    <?php endif; ?>
                </div>

                </div>
            </div>

            <div class="row">
                <div class="col-12 col-md-6">

                <div class="ip-doc-field">
                    <label class="ip-doc-label" for="unit_type">
                        <?= trans('unit_type', '', 'Tipo') ?>
                    </label>
                    <select name="unit_type" id="unit_type" class="form-control simple-select"
                            data-placeholder="<?= esc(trans('select', '', 'Seleziona'), 'attr') ?>">
                        <option value=""><?= trans('none', '', 'Nessuno') ?></option>
                        <?php foreach ($unit_types as $code => $label): ?>
                            <option value="<?= esc($code, 'attr') ?>"<?= old('unit_type', $model->formValue('unit_type')) === $code ? ' selected' : '' ?>>
                                <?= esc($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                </div>
            </div>

            </div></div>

        </div>

    </div>

</form>

<?= $this->endSection() ?>
