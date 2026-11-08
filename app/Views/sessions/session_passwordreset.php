<?= $this->extend('layout/auth') ?>
<?php $this->setVar('pageTitle', trans('forgot_password')); ?>
<?= $this->section('content') ?>

<div class="ip-auth-head">
    <h2><?= trans('forgot_password') ?></h2>
    <p><?= trans('password_reset_info', '', 'Riceverai un\'email con un link per reimpostare la password.') ?></p>
</div>

<form method="post" action="<?= site_url('sessions/passwordreset') ?>">
    <?= csrf_field() ?>

    <div class="ip-doc-field">
        <label for="email" class="form-badge"><?= trans('email') ?></label>
        <input type="email" name="email" id="email" class="form-control"
               placeholder="<?= esc(trans('login_email_placeholder', '', 'nome@studio.it')) ?>"
               autocomplete="email" required autofocus>
    </div>

    <button type="submit" name="btn_reset" value="1" class="btn btn-primary">
        <i class="fa fa-solid fa-envelope fa-margin" aria-hidden="true"></i> <?= trans('send') ?>
    </button>
    <a href="<?= site_url('sessions/login') ?>" class="btn-link-secondary"><?= trans('cancel') ?></a>
</form>

<?= $this->endSection() ?>