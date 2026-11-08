<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('email_sender', '', 'Mittente email')); ?>
<form method="post" action="<?= site_url('email_senders/form/' . ($sender_id ?? '')) ?>" class="ip-doc-form">
    <?= csrf_field() ?>
    <?= $this->include('layout/headerbar', ['title' => trans('email_sender', '', 'Mittente email'), 'actions' => header_actions()]) ?>
    <div id="content">
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">1</span>
                <h2 class="ip-doc-section__title"><?= trans('sender', '', 'Mittente') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-grid">
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="sender_name"><?= trans('name', '', 'Nome visualizzato') ?></label>
                        <input type="text" name="sender_name" id="sender_name" class="form-control"
                               value="<?= esc(old('sender_name', $model->formValue('sender_name'))) ?>">
                        <?php if (!empty($errors['sender_name'])): ?><span class="ip-field-error"><?= esc($errors['sender_name']) ?></span><?php endif; ?>
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="sender_email"><?= trans('email', '', 'Indirizzo') ?></label>
                        <input type="email" name="sender_email" id="sender_email" class="form-control"
                               value="<?= esc(old('sender_email', $model->formValue('sender_email'))) ?>">
                        <?php if (!empty($errors['sender_email'])): ?><span class="ip-field-error"><?= esc($errors['sender_email']) ?></span><?php endif; ?>
                    </div>
                </div>
                <div class="form-check" style="margin-top:12px">
                    <input type="hidden" name="sender_is_default" value="0">
                    <input type="checkbox" class="form-check-input" name="sender_is_default" id="sender_is_default" value="1"
                           <?= db_bool($model->formValue('sender_is_default')) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="sender_is_default"><?= trans('sender_default', '', 'Usa come mittente predefinito per questa azienda') ?></label>
                </div>
            </div>
        </section>

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">2</span>
                <h2 class="ip-doc-section__title"><?= trans('smtp_section', '', 'Server SMTP') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <?php /* Un server "generale" non esiste: l'SMTP vive solo nelle
                         caselle (build_mail_config legge da qui). Il vecchio
                         testo rimandava a Impostazioni → Email, e chi lasciava
                         vuoto credeva di usare quel server mentre l'invio
                         passava da mail() di PHP. */ ?>
                <p class="ip-number-help">
                    <?= trans('smtp_help', '', 'Con il server compilato le email partono autenticate su SMTP (porta 587 con STARTTLS, 465 con SSL). Lasciandolo vuoto l\'app usa la funzione mail() di PHP: nessuna autenticazione, consegna affidata al server locale, e i cron non inviano.') ?>
                </p>

                <?php /* Preset dei servizi più usati: chi configura Gmail o
                         Microsoft 365 sbaglia sempre lo stesso dato (porta 465
                         invece di 587, o il server di un altro servizio). */ ?>
                <div class="ip-doc-grid">
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="sender_smtp_preset"><?= trans('smtp_preset', '', 'Servizio di posta') ?></label>
                        <select id="sender_smtp_preset" class="form-control simple-select">
                            <option value=""><?= trans('smtp_preset_manual', '', 'Configurazione manuale') ?></option>
                            <option value="gmail" data-host="smtp.gmail.com" data-port="587" data-security="tls">Gmail / Google Workspace</option>
                            <option value="m365" data-host="smtp.office365.com" data-port="587" data-security="tls">Microsoft 365</option>
                            <option value="outlook" data-host="smtp-mail.outlook.com" data-port="587" data-security="tls">Outlook.com / Hotmail</option>
                            <?php /* Aruba ha due nomi di server: le caselle dei
                                     domini/hosting escono da smtps.aruba.it, quelle
                                     @aruba.it e @technet.it da smtp.aruba.it. La
                                     porta è la stessa (465, SSL implicito). */ ?>
                            <option value="aruba" data-host="smtps.aruba.it" data-port="465" data-security="ssl">Aruba</option>
                            <option value="aruba_mail" data-host="smtp.aruba.it" data-port="465" data-security="ssl">Aruba (@aruba.it / @technet.it)</option>
                        </select>
                        <p class="ip-field-help"><?= trans('smtp_preset_help', '', 'Gmail: serve la verifica in due passaggi e una password per le app, la password normale dell\'account viene rifiutata. Microsoft 365: l\'autenticazione SMTP va abilitata sulla casella (di norma è spenta) e Microsoft ha annunciato la disattivazione dell\'accesso SMTP con password a fine 2026. Aruba: porta 465 con SSL, utente = indirizzo email completo e password della casella (non serve una password per le app).') ?></p>
                    </div>
                </div>

                <div class="ip-doc-grid">
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="sender_smtp_host"><?= trans('smtp_server', '', 'Server') ?></label>
                        <input type="text" name="sender_smtp_host" id="sender_smtp_host" class="form-control"
                               value="<?= esc(old('sender_smtp_host', $model->formValue('sender_smtp_host'))) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="sender_smtp_port"><?= trans('smtp_port', '', 'Porta') ?></label>
                        <input type="number" name="sender_smtp_port" id="sender_smtp_port" class="form-control"
                               value="<?= esc($model->formValue('sender_smtp_port')) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="sender_smtp_security"><?= trans('smtp_security', '', 'Sicurezza') ?></label>
                        <select name="sender_smtp_security" id="sender_smtp_security" class="form-control simple-select">
                            <?php foreach (['' => trans('none', '', 'Nessuna'), 'tls' => 'TLS', 'ssl' => 'SSL'] as $k => $label): ?>
                                <option value="<?= esc($k, 'attr') ?>" <?= (string) $model->formValue('sender_smtp_security') === (string) $k ? 'selected' : '' ?>>
                                    <?= esc($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="sender_smtp_user"><?= trans('smtp_username', '', 'Utente') ?></label>
                        <input type="text" name="sender_smtp_user" id="sender_smtp_user" class="form-control"
                               value="<?= esc(old('sender_smtp_user', $model->formValue('sender_smtp_user'))) ?>" autocomplete="off">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="sender_smtp_password"><?= trans('smtp_password', '', 'Password') ?></label>
                        <input type="password" name="sender_smtp_password" id="sender_smtp_password" class="form-control"
                               value="" autocomplete="new-password">
                        <?php if ($sender_id && $model->formValue('sender_smtp_password')): ?>
                            <p class="ip-field-help"><?= trans('password_keep', '', 'Lascia vuoto per non modificare la password salvata.') ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    </div>
</form>
<script>
    (function () {
        // IPSelect sostituisce il <select> con un controllo suo: scrivere il
        // valore non basta, serve un change perché quello visibile si riallinei.
        var preset = document.getElementById('sender_smtp_preset');
        var host   = document.getElementById('sender_smtp_host');
        var port   = document.getElementById('sender_smtp_port');
        var secure = document.getElementById('sender_smtp_security');
        var user   = document.getElementById('sender_smtp_user');
        var email  = document.getElementById('sender_email');

        if (!preset || !host || !port || !secure) {
            return;
        }

        preset.addEventListener('change', function () {
            var option = preset.options[preset.selectedIndex];

            if (!option || !option.dataset || !option.dataset.host) {
                return;
            }

            host.value = option.dataset.host;
            port.value = option.dataset.port;
            secure.value = option.dataset.security;
            secure.dispatchEvent(new Event('change', { bubbles: true }));

            // Tutti questi servizi vogliono l'indirizzo email completo come
            // utente (Aruba compresa: non accetta il solo nome prima della
            // chiocciola). Si compila solo se il campo è ancora vuoto, così non
            // si sovrascrive un utente diverso dall'indirizzo del mittente.
            if (user && !user.value && email && email.value) {
                user.value = email.value;
            }
        });
    })();
</script>
<?= $this->endSection() ?>
