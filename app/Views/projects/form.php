<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $title = $project_id ? trans('edit') . ' ' . trans('project_name') : trans('new', '', 'Nuovo progetto'); ?>
<?php $this->setVar('pageTitle', $title); ?>

<form method="POST" action="<?= site_url('projects/form/' . ($project_id ?? '')) ?>">
    <?= csrf_field() ?>

    <?= $this->include('layout/headerbar', ['title' => $title, 'actions' => header_actions()]) ?>

    <div id="content">
        <div class="row">
            <div class="col-12 col-md-6">
                <div class="card">
                    <div class="card-header"><?= trans('project') ?></div>
                    <div class="card-body">
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="project_name"><?= trans('project_name') ?></label>
                            <input type="text" name="project_name" id="project_name" class="form-control" required
                                   value="<?= esc(old('project_name', $model->formValue('project_name'))) ?>">
                            <?php if (!empty($errors['project_name'])): ?>
                                <span class="ip-field-error"><?= esc($errors['project_name']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="client_id"><?= trans('client') ?></label>
                            <select name="client_id" id="client_id" class="form-control simple-select">
                                <option value=""><?= trans('none') ?></option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?= esc($client['client_id']) ?>"
                                        <?= (string) $model->formValue('client_id') === (string) $client['client_id'] ? 'selected' : '' ?>>
                                        <?php /* format_client() compone nome e cognome; la
                                                 chiave client_fullname non esiste su tutte le
                                                 sorgenti cliente (qui arriva da getAllOrdered())
                                                 e la pagina andava in errore. */ ?>
                                        <?= esc(format_client($client)) ?>
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