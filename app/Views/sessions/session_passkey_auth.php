<?php $this->setVar('pageTitle', trans('passkey_auth', '', 'Accesso rapido')); ?>
<?= $this->extend('layout/auth') ?>
<?= $this->section('content') ?>

<div class="ip-auth-head">
    <h2><?= trans('passkey_verify', '', 'Verifica il tuo dispositivo') ?></h2>
    <p><?= trans('passkey_verify_desc', '', 'Usa il metodo di sblocco del tuo telefono, tablet o computer') ?></p>
</div>

<div class="ip-auth-info">
    <p class="ip-auth-info__title"><?= trans('passkey_what_to_do', '', 'Che cosa fare?') ?></p>
    <p>
        <?= trans('passkey_platforms', '', 'Se il tuo dispositivo supporta:') ?><br>
        • <strong><?= trans('passkey_ios', '', 'iPhone/iPad:') ?></strong> <?= trans('passkey_ios_methods', '', 'impronta o Face ID') ?><br>
        • <strong><?= trans('passkey_android', '', 'Android:') ?></strong> <?= trans('passkey_android_methods', '', 'impronta o riconoscimento del viso') ?><br>
        • <strong><?= trans('passkey_desktop', '', 'Windows/Mac:') ?></strong> <?= trans('passkey_desktop_methods', '', 'PIN, impronta o sensore') ?>
    </p>
</div>

<button type="button" class="btn btn-secondary ip-passkey-btn" data-webauthn="login"
        data-webauthn-auto="1" data-status="ip-webauthn-status"
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
    <?= trans('passkey_retry', '', 'Non accade nulla? Riprova') ?>
</button>
<p class="ip-webauthn-status" id="ip-webauthn-status" role="status" aria-live="polite"></p>

<div class="ip-auth-foot">
    <a href="<?= site_url('sessions/mfa') ?>" class="btn-link-secondary">
        <?= trans('mfa_use_different_method', '', 'Usa un metodo diverso') ?>
    </a>
</div>

<script src="<?php _core_asset('js/webauthn.js'); ?>"></script>
<?= $this->endSection() ?>