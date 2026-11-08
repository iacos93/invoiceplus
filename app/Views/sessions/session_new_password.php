<?= $this->extend('layout/auth') ?>
<?php $this->setVar('pageTitle', trans('new_password')); ?>
<?= $this->section('content') ?>

<div class="ip-auth-head">
    <h2><?= trans('new_password') ?></h2>
    <p><?= trans('new_password_help', '', 'Scegli una nuova password per l\'accesso al registro.') ?></p>
</div>

<form method="post" action="<?= site_url('sessions/passwordreset') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= esc($token) ?>">
    <input type="hidden" name="user_id" value="<?= (int) $user_id ?>">

    <div class="ip-doc-field">
        <label for="new_password" class="form-badge"><?= trans('new_password') ?></label>
        <input type="password" name="new_password" id="new_password" class="form-control" required>
    </div>

    <button type="submit" name="btn_new_password" value="1" class="btn btn-primary">
        <i class="fa fa-solid fa-check fa-margin" aria-hidden="true"></i> <?= trans('save') ?>
    </button>
    <a href="<?= site_url('sessions/login') ?>" class="btn-link-secondary"><?= trans('cancel') ?></a>
</form>

<?= $this->endSection() ?>