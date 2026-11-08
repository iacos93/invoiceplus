<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $title = $email_template_id ? trans('edit') . ' ' . trans('email_template') : trans('new') . ' ' . trans('email_template'); ?>
<?php $this->setVar('pageTitle', $title); ?>

<form method="POST" action="<?= site_url('email_templates/form/' . ($email_template_id ?? '')) ?>">
    <?= csrf_field() ?>

    <?= $this->include('layout/headerbar', ['title' => $title, 'actions' => header_actions()]) ?>

    <div id="content">
        <div class="ip-form-pane">
            <div class="card">
                    <div class="card-header"><?= trans('email_template') ?></div>
                    <div class="card-body">
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="email_template_title"><?= trans('title') ?></label>
                            <input type="text" name="email_template_title" id="email_template_title" class="form-control" required
                                   value="<?= esc(old('email_template_title', $model->formValue('email_template_title'))) ?>">
                            <?php if (!empty($errors['email_template_title'])): ?>
                                <span class="ip-field-error"><?= esc($errors['email_template_title']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="email_template_subject"><?= trans('subject') ?></label>
                            <input type="text" name="email_template_subject" id="email_template_subject" class="form-control"
                                   value="<?= esc(old('email_template_subject', $model->formValue('email_template_subject'))) ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="email_template_from_name"><?= trans('from_name') ?></label>
                            <input type="text" name="email_template_from_name" id="email_template_from_name" class="form-control"
                                   value="<?= esc(old('email_template_from_name', $model->formValue('email_template_from_name'))) ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="email_template_from_email"><?= trans('from_email') ?></label>
                            <input type="email" name="email_template_from_email" id="email_template_from_email" class="form-control"
                                   value="<?= esc(old('email_template_from_email', $model->formValue('email_template_from_email'))) ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="email_template_cc"><?= trans('cc') ?></label>
                            <input type="text" name="email_template_cc" id="email_template_cc" class="form-control"
                                   value="<?= esc(old('email_template_cc', $model->formValue('email_template_cc'))) ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="email_template_bcc"><?= trans('bcc') ?></label>
                            <input type="text" name="email_template_bcc" id="email_template_bcc" class="form-control"
                                   value="<?= esc(old('email_template_bcc', $model->formValue('email_template_bcc'))) ?>">
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="email_template_pdf_template"><?= trans('pdf_template') ?></label>
                            <select name="email_template_pdf_template" id="email_template_pdf_template" class="form-control simple-select">
                                <option value=""><?= trans('none') ?></option>
                                <optgroup label="<?= trans('invoices') ?>">
                                    <?php foreach ($pdf_templates['invoices'] as $template): ?>
                                        <option value="<?= esc($template) ?>"
                                            <?= (string) $model->formValue('email_template_pdf_template') === (string) $template ? 'selected' : '' ?>>
                                            <?= esc($template) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <optgroup label="<?= trans('quotes') ?>">
                                    <?php foreach ($pdf_templates['quotes'] as $template): ?>
                                        <option value="<?= esc($template) ?>"
                                            <?= (string) $model->formValue('email_template_pdf_template') === (string) $template ? 'selected' : '' ?>>
                                            <?= esc($template) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            </select>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="email_template_body"><?= trans('body') ?></label>
                            <textarea name="email_template_body" id="email_template_body" rows="12" class="form-control"><?= esc(old('email_template_body', $model->formValue('email_template_body'))) ?></textarea>
                        </div>

                        <?= view('email_templates/template_tags') ?>
                    </div>
                </div>
        </div>
    </div>
</form>

<?= $this->endSection() ?>