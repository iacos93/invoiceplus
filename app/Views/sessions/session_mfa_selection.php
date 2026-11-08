<?php $this->setVar('pageTitle', trans('mfa_title', '', 'Verifica in due passaggi')); ?>
<?= $this->extend('layout/auth') ?>
<?= $this->section('content') ?>

<div class="ip-auth-head">
    <h2><?= trans('mfa_title', '', 'Verifica l\'identità') ?></h2>
    <p><?= trans('mfa_select_method', '', 'Scegli il metodo di autenticazione') ?></p>
</div>

<div>
    <!-- Passkey: voce primaria, parte subito la richiesta al dispositivo -->
    <?php if ($has_passkeys): ?>
        <button type="button" class="ip-mfa-method-btn ip-mfa-method-btn--primary"
                data-webauthn="login" data-status="ip-webauthn-status"
                data-busy="<?= esc(trans('passkey_busy', '', 'Attendi la richiesta sul dispositivo…'), 'attr') ?>">
            <span class="ip-mfa-method-btn__icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <path d="M8.2 7.1c-1.6 1.3-2.7 3.1-2.7 5.1 0 1.4.3 2.7.7 3.9"/>
                    <path d="M11.9 5.6c.4 0 .9.1 1.3.2 1.8.4 3.4 1.3 4.5 2.7"/>
                    <path d="M9.5 9.5c-.7 1-1.1 2.1-1.1 3.3 0 .9.2 1.9.4 2.7"/>
                    <path d="M12.9 8.3c.9.3 1.7.8 2.3 1.4.7.8 1.1 1.7 1.1 2.7 0 .8-.1 1.6-.4 2.3"/>
                    <path d="M10.2 11.8c0-1.1.8-2 2-2 .5 0 1 .2 1.4.6"/>
                    <path d="M13.1 12.2c.1.6.1 1.2 0 1.8"/>
                    <path d="M11.9 15.3c-.5-.4-.8-.9-.8-1.5"/>
                </svg>
            </span>
            <span class="ip-mfa-method-btn__text">
                <span class="ip-mfa-method-btn__title"><?= trans('mfa_method_passkey', '', 'Passkey') ?></span>
                <span class="ip-mfa-method-btn__desc"><?= trans('mfa_method_passkey_desc', '', 'Usa il tuo dispositivo') ?></span>
            </span>
            <span class="ip-mfa-method-btn__chevron">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </span>
        </button>
    <?php endif; ?>

    <!-- App autenticatore -->
    <form method="post" action="<?= site_url('sessions/mfa') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="mfa_method" value="totp">
        <button type="submit" class="ip-mfa-method-btn">
            <span class="ip-mfa-method-btn__icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <circle cx="12" cy="12" r="9"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
            </span>
            <span class="ip-mfa-method-btn__text">
                <span class="ip-mfa-method-btn__title"><?= trans('mfa_method_totp', '', 'App autenticatore') ?></span>
                <span class="ip-mfa-method-btn__desc"><?= trans('mfa_method_totp_desc', '', 'Google Authenticator, Authy, ecc.') ?></span>
            </span>
            <span class="ip-mfa-method-btn__chevron">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </span>
        </button>
    </form>

    <!-- Codici di recupero -->
    <form method="post" action="<?= site_url('sessions/mfa') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="mfa_method" value="recovery">
        <button type="submit" class="ip-mfa-method-btn">
            <span class="ip-mfa-method-btn__icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <path d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </span>
            <span class="ip-mfa-method-btn__text">
                <span class="ip-mfa-method-btn__title"><?= trans('mfa_method_recovery', '', 'Codice di recupero') ?></span>
                <span class="ip-mfa-method-btn__desc"><?= trans('mfa_method_recovery_desc', '', 'Usa uno dei codici salvati') ?></span>
            </span>
            <span class="ip-mfa-method-btn__chevron">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                    <polyline points="9 18 15 12 9 6"/>
                </svg>
            </span>
        </button>
    </form>
</div>

<p class="ip-webauthn-status" id="ip-webauthn-status" role="status" aria-live="polite"></p>

<div class="ip-auth-foot">
    <a href="<?= site_url('sessions/login') ?>" class="btn-link-secondary">
        <?= trans('back_to_login', '', 'Torna al login') ?>
    </a>
</div>

<script src="<?php _core_asset('js/webauthn.js'); ?>"></script>
<?= $this->endSection() ?>