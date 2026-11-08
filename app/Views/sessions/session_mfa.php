<?php $this->setVar('pageTitle', trans('mfa_title', '', 'Verifica in due passaggi')); ?>
<?= $this->extend('layout/auth') ?>
<?= $this->section('content') ?>

<div class="ip-auth-head">
    <h2><?= trans('mfa_code', '', 'Codice autenticatore') ?></h2>
    <p>
        <?= trans('mfa_code_help', '', 'Inserisci il codice dell\'app authenticator per l\'account') ?>
        <strong><?= esc($user_email) ?></strong>
    </p>
</div>

<?php if ($attempts_left < 5): ?>
    <div class="ip-auth-callout ip-auth-callout--warning">
        <i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i>
        <?= trans('mfa_attempts_left', (string) $attempts_left, 'Tentativi rimasti: %s') ?>
    </div>
<?php endif; ?>

<form method="post" action="<?= site_url('sessions/mfa') ?>" id="ip-mfa-form" novalidate>
    <?= csrf_field() ?>

    <div class="ip-code-slots" id="ip-code-slots" role="group"
         aria-label="<?= esc(trans('mfa_code', '', 'Codice di verifica')) ?>">
        <input type="text" class="ip-code-slot" data-slot="1" aria-label="1" maxlength="1"
               inputmode="numeric" pattern="[0-9]" autocomplete="one-time-code" required>
        <input type="text" class="ip-code-slot" data-slot="2" aria-label="2" maxlength="1"
               inputmode="numeric" pattern="[0-9]" required>
        <input type="text" class="ip-code-slot" data-slot="3" aria-label="3" maxlength="1"
               inputmode="numeric" pattern="[0-9]" required>
        <input type="text" class="ip-code-slot" data-slot="4" aria-label="4" maxlength="1"
               inputmode="numeric" pattern="[0-9]" required>
        <input type="text" class="ip-code-slot" data-slot="5" aria-label="5" maxlength="1"
               inputmode="numeric" pattern="[0-9]" required>
        <input type="text" class="ip-code-slot" data-slot="6" aria-label="6" maxlength="1"
               inputmode="numeric" pattern="[0-9]" required>
    </div>

    <p class="ip-auth-timer">
        <?= trans('mfa_code_expires', '', 'Il codice scade tra') ?>
        <strong id="mfa-timer">30</strong> <?= trans('seconds', '', 'secondi') ?>
    </p>

    <input type="hidden" name="mfa_code" id="mfa_code" value="">
    <input type="hidden" name="mfa_method" value="totp">
    <input type="hidden" name="btn_mfa" value="true">

    <button type="submit" class="btn btn-primary">
        <i class="fa fa-solid fa-check fa-margin" aria-hidden="true"></i> <?= trans('mfa_verify', '', 'Verifica') ?>
    </button>
</form>

<div class="ip-auth-foot">
    <a href="<?= site_url('sessions/mfa') ?>?method=recovery" class="btn-link-secondary">
        <?= trans('mfa_use_recovery_code', '', 'Usa un codice di recupero') ?>
    </a>
</div>

<?php if ($has_passkeys): ?>
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
        <?= trans('mfa_use_passkey', '', 'Usa una passkey') ?>
    </button>
    <p class="ip-webauthn-status" id="ip-webauthn-status" role="status" aria-live="polite"></p>
<?php endif; ?>

<script>
    /* Le sei caselle del codice sono input veri; qui vivono solo la
       coordinazione fra celle (avanzamento, backspace, incolla), l'invio
       automatico alla sesta cifra e il conto alla rovescia.
       Il timer non parte da 30 al caricamento: il codice TOTP cambia ai
       confini della finestra di 30 secondi (intdiv(time, 30) del server),
       quindi il conto parte dal prossimo confine e si riallinea da solo.
       Allo scadere non fa nulla di drammatico: l'app dell'utente genera
       un codice nuovo, e il conto riparte. */
    (function () {
        var form     = document.getElementById('ip-mfa-form');
        var slots    = Array.prototype.slice.call(form.querySelectorAll('.ip-code-slot'));
        var hidden   = document.getElementById('mfa_code');
        var group    = document.getElementById('ip-code-slots');
        var timerEl  = document.getElementById('mfa-timer');

        function sync() {
            hidden.value = slots.map(function (s) { return s.value; }).join('');
            group.classList.remove('is-invalid');
        }

        slots.forEach(function (slot, i) {
            slot.addEventListener('input', function () {
                var d = slot.value.replace(/\D/g, '').slice(-1);
                slot.value = d;
                slot.classList.toggle('is-filled', !!d);
                sync();
                if (d && i < slots.length - 1) {
                    slots[i + 1].focus();
                } else if (d && i === slots.length - 1) {
                    setTimeout(function () { form.submit(); }, 120);
                }
            });

            slot.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && !slot.value && i > 0) {
                    slots[i - 1].focus();
                    slots[i - 1].value = '';
                    slots[i - 1].classList.remove('is-filled');
                    sync();
                } else if (e.key === 'ArrowLeft' && i > 0) {
                    slots[i - 1].focus();
                } else if (e.key === 'ArrowRight' && i < slots.length - 1) {
                    slots[i + 1].focus();
                }
            });

            slot.addEventListener('paste', function (e) {
                e.preventDefault();
                var text = (e.clipboardData.getData('text') || '').replace(/\D/g, '');
                var n = Math.min(text.length, slots.length - i);
                for (var j = 0; j < n; j++) {
                    slots[i + j].value = text[j];
                    slots[i + j].classList.add('is-filled');
                }
                sync();
                slots[Math.min(i + n, slots.length - 1)].focus();
            });
        });

        form.addEventListener('submit', function (e) {
            if (hidden.value.length !== slots.length) {
                e.preventDefault();
                group.classList.add('is-invalid');
                slots[hidden.value.length].focus();
            }
        });

        if (timerEl) {
            var tick = function () {
                var left = 30 - (Math.floor(Date.now() / 1000) % 30);
                timerEl.textContent = left;
            };
            tick();
            setInterval(tick, 1000);
        }

        slots[0].focus();
    })();
</script>

<script src="<?php _core_asset('js/webauthn.js'); ?>"></script>
<?= $this->endSection() ?>