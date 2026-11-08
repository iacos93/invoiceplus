<?php $this->setVar('pageTitle', trans('setup_first_user', '', 'Primo avvio')); ?>
<?= $this->extend('layout/auth') ?>
<?= $this->section('content') ?>

<div class="ip-auth-head">
    <h2><?= trans('setup_first_user', '', 'Benvenuto') ?></h2>
    <p><?= trans('setup_first_user_help', '', 'Questa installazione non ha ancora utenti: crea il primo account amministratore per iniziare.') ?></p>
</div>

<form method="post" action="<?= site_url('sessions/setup') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="btn_setup" value="true">

    <div class="ip-doc-field">
        <label for="user_name" class="form-badge"><?= trans('name', '', 'Nome e cognome') ?></label>
        <input type="text" name="user_name" id="user_name" class="form-control"
               autocomplete="name" required autofocus
               value="<?= esc($values['user_name'] ?? '') ?>">
        <?php if (!empty($errors['user_name'])): ?>
            <span class="ip-field-error"><?= esc($errors['user_name']) ?></span>
        <?php endif; ?>
    </div>

    <div class="ip-doc-field">
        <label for="user_email" class="form-badge"><?= trans('email') ?></label>
        <input type="email" name="user_email" id="user_email" class="form-control"
               placeholder="<?= esc(trans('login_email_placeholder', '', 'nome@studio.it')) ?>"
               autocomplete="email" required
               value="<?= esc($values['user_email'] ?? '') ?>">
        <?php if (!empty($errors['user_email'])): ?>
            <span class="ip-field-error"><?= esc($errors['user_email']) ?></span>
        <?php endif; ?>
    </div>

    <div class="ip-doc-field">
        <label for="user_password" class="form-badge"><?= trans('password') ?></label>
        <input type="password" name="user_password" id="user_password" class="form-control"
               autocomplete="new-password" required minlength="8">
        <?php if (!empty($errors['user_password'])): ?>
            <span class="ip-field-error"><?= esc($errors['user_password']) ?></span>
        <?php endif; ?>
    </div>

    <div class="ip-doc-field">
        <label for="user_passwordv" class="form-badge"><?= trans('verify_password', '', 'Conferma password') ?></label>
        <input type="password" name="user_passwordv" id="user_passwordv" class="form-control"
               autocomplete="new-password" required minlength="8">
        <?php if (!empty($errors['user_passwordv'])): ?>
            <span class="ip-field-error"><?= esc($errors['user_passwordv']) ?></span>
        <?php endif; ?>
    </div>

    <button type="submit" class="btn btn-primary">
        <i class="fa fa-solid fa-user-plus fa-margin" aria-hidden="true"></i>
        <?= trans('setup_create_account', '', 'Crea account') ?>
    </button>
</form>

<p class="ip-webauthn-status">
    <?= trans('setup_only_first_run', '', 'Questa pagina è disponibile solo finché non esiste nessun utente.') ?>
</p>

<?= $this->endSection() ?>
