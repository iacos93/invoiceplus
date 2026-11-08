<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('add_family')); ?>

<form method="post" action="<?= site_url('families/form/' . ($family_id ?? '')) ?>">

    <?= csrf_field() ?>

    <?= $this->include('layout/headerbar', ['title' => trans('add_family'), 'actions' => header_actions()]) ?>

    <div id="content">

        <div class="ip-form-pane">

            <div class="card"><div class="card-body">

            <div class="row">
                <div class="col-12 col-md-6">

                <div class="ip-doc-field">
                    <label class="ip-doc-label" for="family_name">
                        <?= trans('family_name') ?>
                    </label>
                    <input type="text" name="family_name" id="family_name" class="form-control"
                           value="<?= esc(old('family_name', $model->formValue('family_name'))) ?>">
                    <?php if (!empty($errors['family_name'])): ?>
                        <span class="ip-field-error"><?= esc($errors['family_name']) ?></span>
                    <?php endif; ?>
                </div>

                </div>
            </div>

            </div></div>

        </div>

    </div>

</form>

<?= $this->endSection() ?>