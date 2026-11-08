<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $readonly = (bool) $model->formValue('task_id') && (int) $model->formValue('task_status') === 4; ?>
<?php $this->setVar('pageTitle', trans('new_task')); ?>
<?php $disabled = $readonly ? ' disabled' : ''; ?>

<form method="POST" action="<?= site_url('tasks/form/' . ($task_id ?? '')) ?>">
    <?= csrf_field() ?>

    <?php
    $title = $readonly ? '#' . esc($model->formValue('task_id')) . '&nbsp;' . esc($model->formValue('task_name')) : trans('new_task');

    // "Fattura questa attività": solo su un'attività già salvata (task_id) e
    // completata (stato 3) — prima di completarla non ha senso fatturarla,
    // dopo (stato 4) è già fatturata. Vedi Invoices::formCreate().
    $isCompleted = (bool) $model->formValue('task_id') && (int) $model->formValue('task_status') === 3;
    $invoiceAction = $isCompleted
        ? '<a class="btn btn-sm btn-secondary" href="' . site_url('invoices/form_create?task_id=' . $model->formValue('task_id')) . '">'
            . '<i class="fa fa-regular fa-file-text fa-margin" aria-hidden="true"></i>' . trans('invoice_this_task', '', 'Fattura questa attività') . '</a> '
        : '';
    ?>
    <?= $this->include('layout/headerbar', [
        'title'   => $title,
        'actions' => $invoiceAction . header_actions(['hide_submit_button' => $readonly]),
    ]) ?>

    <div id="content">
        <?php if ($readonly): ?>
            <div class="alert alert-danger"><?= trans('info_task_readonly') ?></div>
        <?php endif; ?>

        <div class="row">
            <div class="col-12 col-md-6">
                <div class="card">
                    <div class="card-header"><?= trans('task') ?></div>
                    <div class="card-body">
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="task_name"><?= trans('task_name') ?></label>
                            <input type="text" name="task_name" id="task_name" class="form-control" required
                                   value="<?= esc(old('task_name', $model->formValue('task_name'))) ?>"<?= $disabled ?>>
                            <?php if (!empty($errors['task_name'])): ?>
                                <span class="ip-field-error"><?= esc($errors['task_name']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="task_description"><?= trans('task_description') ?></label>
                            <textarea name="task_description" id="task_description" rows="3" class="form-control"<?= $disabled ?>><?= esc(old('task_description', $model->formValue('task_description'))) ?></textarea>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="task_price"><?= trans('task_price') ?></label>
                            <div class="ip-input-suffix">
                                <input type="text" name="task_price" id="task_price" class="form-control" required
                                       value="<?= esc(old('task_price', format_amount($model->formValue('task_price')))) ?>"<?= $disabled ?>>
                                <span class="ip-input-suffix__mark"><?= esc(get_setting('currency_symbol') ?: '€') ?></span>
                            </div>
                            <?php if (!empty($errors['task_price'])): ?>
                                <span class="ip-field-error"><?= esc($errors['task_price']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="task_finish_date"><?= trans('task_finish_date') ?></label>
                            <input type="text" name="task_finish_date" id="task_finish_date" class="form-control datepicker" required
                                   value="<?= esc(old('task_finish_date', $model->formValue('task_finish_date'))) ?>"<?= $disabled ?>>
                            <?php if (!empty($errors['task_finish_date'])): ?>
                                <span class="ip-field-error"><?= esc($errors['task_finish_date']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6">
                <div class="card">
                    <div class="card-header"><?= trans('project') ?></div>
                    <div class="card-body">
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="task_status"><?= trans('status') ?></label>
                            <select name="task_status" id="task_status" class="form-control simple-select"<?= $disabled ?>>
                                <?php foreach ($task_statuses as $key => $status): ?>
                                    <?php if ($model->formValue('task_status') != 4 && (int) $key === 4) { continue; } ?>
                                    <option value="<?= esc($key) ?>"
                                        <?= (string) $model->formValue('task_status') === (string) $key ? 'selected' : '' ?>>
                                        <?= esc($status['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="project_id"><?= trans('project') ?></label>
                            <select name="project_id" id="project_id" class="form-control simple-select"<?= $disabled ?>>
                                <option value=""><?= trans('select_project') ?></option>
                                <?php foreach ($projects as $project): ?>
                                    <option value="<?= esc($project['project_id']) ?>"
                                        <?= (string) $model->formValue('project_id') === (string) $project['project_id'] ? 'selected' : '' ?>>
                                        <?= esc($project['project_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="tax_rate_id"><?= trans('tax_rate') ?></label>
                            <select name="tax_rate_id" id="tax_rate_id" class="form-control simple-select"<?= $disabled ?>>
                                <option value="0" <?= empty($model->formValue('tax_rate_id')) ? 'selected' : '' ?>><?= trans('none') ?></option>
                                <?php foreach ($tax_rates as $tax_rate): ?>
                                    <option value="<?= esc($tax_rate['tax_rate_id']) ?>"
                                        <?= (string) $model->formValue('tax_rate_id') === (string) $tax_rate['tax_rate_id'] ? 'selected' : '' ?>>
                                        <?= esc($tax_rate['tax_rate_name'] . ' (' . format_amount($tax_rate['tax_rate_percent']) . '%)') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<?= $this->endSection() ?>