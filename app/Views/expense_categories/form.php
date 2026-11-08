<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('expense_category', '', 'Categoria di spesa')); ?>
<form method="post" action="<?= ip_resource_url('expense_categories', 'form', $expense_category_id ?? null) ?>">
    <?= csrf_field() ?>
    <?= $this->include('layout/headerbar', ['title' => trans('expense_category', '', 'Categoria di spesa'), 'actions' => header_actions()]) ?>
    <div id="content">
        <div class="ip-form-pane">
            <div class="card"><div class="card-body">
                <div class="row">
                    <div class="col-12 col-md-6">
                <div class="ip-doc-field">
                    <label class="form-badge" for="expense_category_name"><?= trans('name', '', 'Nome') ?></label>
                    <input type="text" name="expense_category_name" id="expense_category_name" class="form-control"
                           value="<?= esc(old('expense_category_name', $model->formValue('expense_category_name'))) ?>">
                    <?php if (!empty($errors['expense_category_name'])): ?>
                        <span class="ip-field-error"><?= esc($errors['expense_category_name']) ?></span>
                    <?php endif; ?>
                </div>
                    </div>
                </div>
                <div class="form-check">
                    <input type="hidden" name="expense_category_deductible" value="0">
                    <input type="checkbox" class="form-check-input" name="expense_category_deductible"
                           id="expense_category_deductible" value="1"
                           <?= ($expense_category_id === null || db_bool($model->formValue('expense_category_deductible'))) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="expense_category_deductible">
                        <?= trans('expense_deductible_help', '', 'Costo deducibile: incide sull\'utile imponibile nella stima delle imposte') ?>
                    </label>
                </div>
            </div></div>
        </div>
    </div>
</form>
<?= $this->endSection() ?>
