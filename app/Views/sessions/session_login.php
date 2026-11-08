<?php $this->setVar('pageTitle', trans('login')); ?>
<?= $this->extend('layout/auth') ?>
<?= $this->section('content') ?>

<div class="ip-auth-head">
    <h2><?= trans('login') ?></h2>
    <p><?= trans('auth_welcome_back', '', 'Accedi al tuo registro di fatturazione.') ?></p>
</div>

<form method="post" action="<?= site_url('sessions/login') ?>">
    <?= csrf_field() ?>

    <div class="ip-doc-field">
        <label for="email" class="form-badge"><?= trans('email') ?></label>
        <input type="email" name="email" id="email" class="form-control"
               placeholder="<?= esc(trans('login_email_placeholder', '', 'nome@studio.it')) ?>"
               autocomplete="email" required autofocus
               value="<?= esc(old('email')) ?>">
    </div>

    <div class="ip-doc-field">
        <label for="password" class="form-badge"><?= trans('password') ?></label>
        <input type="password" name="password" id="password" class="form-control"
               autocomplete="current-password" required>
    </div>

    <input type="hidden" name="btn_login" value="true">

    <button type="submit" class="btn btn-primary">
        <i class="fa fa-solid fa-right-to-bracket fa-margin" aria-hidden="true"></i> <?= trans('login') ?>
    </button>
</form>

<div class="auth-separator">
    <span><?= trans('auth_or', '', 'oppure') ?></span>
</div>

<button type="button" class="btn btn-secondary ip-passkey-btn" data-webauthn="login"
        data-status="ip-webauthn-status"
        data-busy="<?= esc(trans('passkey_busy', '', 'Attendi la richiesta sul dispositivo…'), 'attr') ?>">
    <svg class="ip-passkey-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path d="M8.2 7.1c-1.6 1.3-2.7 3.1-2.7 5.1 0 1.4.3 2.7.7 3.9"/>
        <path d="M11.9 5.6c.4 0 .9.1 1.3.2 1.8.4 3.4 1.3 4.5 2.7"/>
        <path d="M9.5 9.5c-.7 1-1.1 2.1-1.1 3.3 0 .9.2 1.9.4 2.7"/>
        <path d="M12.9 8.3c.9.3 1.7.8 2.3 1.4.7.8 1.1 1.7 1.1 2.7 0 .8-.1 1.6-.4 2.3"/>
        <path d="M10.2 11.8c0-1.1.8-2 2-2 .5 0 1 .2 1.4.6"/>
        <path d="M13.1 12.2c.1.6.1 1.2 0 1.8"/>
        <path d="M11.9 15.3c-.5-.4-.8-.9-.8-1.5"/>
    </svg>
    <?= trans('login_passkey', '', 'Accedi con passkey') ?>
</button>

<p class="ip-webauthn-status" id="ip-webauthn-status" role="status" aria-live="polite"></p>

<div class="ip-auth-foot">
    <a href="<?= site_url('sessions/passwordreset') ?>" class="btn-link-secondary">
        <?= trans('forgot_your_password') ?>
    </a>
</div>

<script src="<?php _core_asset('js/webauthn.js'); ?>"></script>
<?= $this->endSection() ?>