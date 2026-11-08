<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
$userType = $model->formValue('user_type');
if ($userType === '' && !$id) {
    $userType = '2';
}

$language = $model->formValue('user_language');
if ($language === '') {
    $language = session('user_language') ?: 'system';
}

$active = db_bool($model->formValue('user_active'));
if ($model->formValue('user_active') === '') {
    $active = true;
}

// IBAN "storico" del profilo. Le coordinate di pagamento vivono in
// Impostazioni > Conti di saldo; questo campo resta solo come ripiego per le
// installazioni che non hanno ancora creato un conto (Invoices lo usa per
// l'XML quando la fattura non ha un conto associato). Lo mostriamo soltanto
// se valorizzato: svuotandolo sparisce dal form.
// Nota: dbArrayForExisting() salva solo le chiavi presenti nel POST, quindi
// non renderizzare il campo non azzera il valore a database.
$legacyIban = trim((string) old('user_iban', $model->formValue('user_iban')));
?>
<?php $this->setVar('pageTitle', trans('user_form')); ?>

<script>
    $(function () {
        show_fields();

        $('#user_type').change(function () {
            show_fields();
        });

        // I campi fiscali valgono solo per l'amministratore: l'utente
        // ospite e' in sola lettura sui documenti dei clienti assegnati.
        // (Il ramo #guest_fields e' stato tolto: non esiste nessun elemento
        // con quell'id nella pagina, era codice morto.)
        function show_fields() {
            $('#administrator_fields').toggle($('#user_type').val() === '1');
        }
    });
</script>

<?php /* Barra e #content fuori dal form: sotto il form c'e' il pannello
         Sicurezza, con form propri che non possono stare dentro form_user.
         Un solo #content li contiene entrambi, cosi' hanno gli stessi margini;
         Salva resta legato al form con l'attributo form=. */ ?>
<?= $this->include('layout/headerbar', ['title' => trans('user_form'), 'actions' => header_actions(['form' => 'form_user'])]) ?>

<div id="content">
<form id="form_user" method="POST" action="<?= site_url('users/form/' . ($id ?? '')) ?>">

    <?= csrf_field() ?>

        <div class="row">
            <div class="col-12">

                <div id="userInfo">

                    <div class="card">
                        <div class="card-header"><?= trans('account_information') ?></div>

                        <div class="card-body">
                            <?php /* Campi appaiati: un nome o una partita IVA non hanno
                                     bisogno di una riga intera, e la colonna e' la stessa
                                     di bank_accounts/form. Prima ogni campo occupava una
                                     riga da sola e la scheda era lunga il doppio. */ ?>
                            <div class="row">
                                <div class="col-12 col-sm-6">
                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="user_name">
                                            <?= trans('name') ?>
                                        </label>
                                        <input type="text" name="user_name" id="user_name" class="form-control"
                                               value="<?= esc(old('user_name', $model->formValue('user_name'))) ?>">
                                        <?php if (!empty($errors['user_name'])): ?>
                                            <span class="ip-field-error"><?= esc($errors['user_name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="user_company">
                                            <?= trans('company') ?>
                                        </label>
                                        <input type="text" name="user_company" id="user_company" class="form-control"
                                               value="<?= esc(old('user_company', $model->formValue('user_company'))) ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="ip-doc-field">
                                <label class="ip-doc-label" for="user_email">
                                    <?= trans('email_address') ?>
                                </label>
                                <input type="email" name="user_email" id="user_email" class="form-control"
                                       autocomplete="email"
                                       value="<?= esc(old('user_email', $model->formValue('user_email'))) ?>">
                                <?php if (!empty($errors['user_email'])): ?>
                                    <span class="ip-field-error"><?= esc($errors['user_email']) ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if (!$id): ?>
                                <div class="row">
                                    <div class="col-12 col-sm-6">
                                        <div class="ip-doc-field">
                                            <label class="ip-doc-label" for="user_password">
                                                <?= trans('password') ?>
                                            </label>
                                            <input type="password" name="user_password" id="user_password"
                                                   class="form-control" autocomplete="new-password">
                                            <?php if (!empty($errors['user_password'])): ?>
                                                <span class="ip-field-error"><?= esc($errors['user_password']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-12 col-sm-6">
                                        <div class="ip-doc-field">
                                            <label class="ip-doc-label" for="user_passwordv">
                                                <?= trans('verify_password') ?>
                                            </label>
                                            <input type="password" name="user_passwordv" id="user_passwordv"
                                                   class="form-control" autocomplete="new-password">
                                            <?php if (!empty($errors['user_passwordv'])): ?>
                                                <span class="ip-field-error"><?= esc($errors['user_passwordv']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php /* Il bottone senza etichetta spezzava il ritmo label/campo
                                         delle righe vicine: ora ha la sua, come gli altri controlli. */ ?>
                                <div class="ip-doc-field">
                                    <span class="ip-doc-label"><?= trans('password') ?></span>
                                    <a href="<?= site_url('users/change_password/' . $id) ?>" class="btn btn-secondary">
                                        <i class="fa fa-solid fa-key fa-margin" aria-hidden="true"></i> <?= trans('change_password') ?>
                                    </a>
                                </div>
                            <?php endif; ?>

                            <div class="row">
                                <div class="col-12 col-sm-6">
                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="user_language">
                                            <?= trans('language') ?>
                                        </label>
                                        <select name="user_language" id="user_language" class="form-control simple-select">
                                            <option value="system" <?= check_select($language, 'system') ?>>
                                                <?= trans('use_system_language') ?>
                                            </option>
                                            <?php foreach ($languages as $langValue => $langLabel): ?>
                                                <?php if ($langValue === 'system') {
                                                    continue;
                                                } ?>
                                                <option value="<?= esc($langValue) ?>" <?= check_select($language, $langValue) ?>>
                                                    <?= esc($langLabel) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (!empty($errors['user_language'])): ?>
                                            <span class="ip-field-error"><?= esc($errors['user_language']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <div class="ip-doc-field">
                                        <label class="ip-doc-label" for="user_type">
                                            <?= trans('user_type') ?>
                                        </label>
                                        <select name="user_type" id="user_type" class="form-control simple-select">
                                            <?php foreach ($user_types as $key => $type): ?>
                                                <option value="<?= esc($key) ?>" <?= check_select($userType, $key) ?>>
                                                    <?= esc(trans($type)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if (!empty($errors['user_type'])): ?>
                                            <span class="ip-field-error"><?= esc($errors['user_type']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="user_active" name="user_active" value="1"
                                    <?= $active ? 'checked' : '' ?>>
                                <label class="form-check-label" for="user_active"><?= trans('active') ?></label>
                            </div>
                        </div>

                    </div>

                </div>

                <div id="administrator_fields">

                    <div class="card">
                        <div class="card-header"><?= trans('tax_information') ?></div>

                        <div class="card-body">

                            <?php /* I dati fiscali non stanno qui: hanno un'anagrafica
                                     propria (Impostazioni > Aziende) con ragione sociale,
                                     P. IVA, codice fiscale e forma aziendale. Una fattura
                                     emessa da una delle aziende deve portare i dati di
                                     quella azienda, non quelli del profilo: il profilo ne
                                     descrive uno solo, e i due dati finivano per
                                     contraddirsi.

                                     I campi P. IVA/codice fiscale del profilo (gia' rimossi
                                     da qui) restano nel database come sola lettura: nessuna
                                     fattura puo' piu' averli come ripiego, perche' ogni
                                     documento ha sempre un'azienda (company_id non e' mai
                                     NULL — lo assegna withCompany() alla creazione, e la
                                     migrazione CreateCompanies ha gia' riassegnato quelli
                                     preesistenti). Non azzerarli via UPDATE diretto senza
                                     verificare prima che non divergano dal dato
                                     sull'azienda: qui coincidevano, ma potrebbero non
                                     coincidere su ogni installazione. */ ?>
                            <p class="ip-number-help">
                                <i class="fa fa-solid fa-building fa-margin" aria-hidden="true"></i>
                                <?= trans('user_fiscal_moved_help', '', 'P. IVA, codice fiscale e forma aziendale si gestiscono nella scheda dell\'azienda: sono i dati che finiscono su fatture, preventivi e XML.') ?>
                                <a href="<?= site_url('companies') ?>"><?= trans('companies', '', 'Aziende') ?></a>.
                            </p>

                            <?php /* Le coordinate di pagamento non stanno qui: hanno un'anagrafica
                                     propria (Impostazioni > Conti di saldo) con banca, intestatario,
                                     IBAN, BIC e conto predefinito, selezionabile sulla singola
                                     fattura. Un IBAN nel profilo utente ne era la versione a conto
                                     unico e restava a chiedere due volte lo stesso dato. */ ?>
                            <p class="ip-number-help mb-0">
                                <i class="fa fa-solid fa-university fa-margin" aria-hidden="true"></i>
                                <?= trans('user_bank_moved_help', '', 'Le coordinate di pagamento (IBAN, BIC, intestatario) si gestiscono in') ?>
                                <a href="<?= site_url('bank_accounts') ?>"><?= trans('bank_accounts', '', 'Conti di saldo') ?></a>.
                            </p>

                            <?php if ($legacyIban !== ''): ?>
                                <?php /* Campo di sola compatibilita': resta visibile solo alle
                                         installazioni che hanno ancora un IBAN sul profilo e non
                                         hanno creato i conti. La generazione XML lo usa come
                                         ripiego (Invoices::…MP05). Svuotandolo sparisce. */ ?>
                                <div class="ip-doc-field mt-3">
                                    <label class="ip-doc-label" for="user_iban">
                                        <?= trans('user_iban') ?>
                                        <span class="ip-legacy-tag"><?= trans('deprecated', '', 'obsoleto') ?></span>
                                    </label>
                                    <input type="text" name="user_iban" id="user_iban" class="form-control"
                                           value="<?= esc($legacyIban) ?>"
                                           autocomplete="off" spellcheck="false">
                                    <p class="ip-field-help">
                                        <?= trans('user_iban_legacy_help', '', 'Usato solo se la fattura non ha un conto di saldo associato. Sposta il dato in Conti di saldo e svuota questo campo.') ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>

                </div>

            </div>
        </div>

</form>

<?php /* Sicurezza: DOPO la chiusura di form_user. Stava dentro, e un <form>
         annidato viene ignorato dal browser: il pulsante "Resetta la verifica
         in due passaggi" inviava il modulo utente invece del reset. */ ?>
<?php if (!empty($security_panel)): ?>
    <?php /* Proprio account: solo lo stato e il rimando. La gestione (attivare
             la verifica, registrare o togliere passkey) sta nella pagina
             dedicata Sicurezza account: un pannello intero in fondo al
             profilo allungava la pagina e mescolava due cose diverse. */ ?>
    <?php
        $spMfa      = !empty($security_panel['mfa_enabled']);
        $spPasskeys = count($security_panel['passkeys'] ?? []);
    ?>
    <div id="ip-security-panel" class="ip-settings-pane ip-user-security">
        <div class="card">
            <div class="card-header">
                <i class="fa fa-solid fa-shield fa-fw" aria-hidden="true"></i>
                <?= trans('security_title', '', 'Sicurezza account') ?>
            </div>
            <div class="card-body ip-user-security__body">
                <div class="ip-user-security__status">
                    <span class="ip-status-pill <?= $spMfa ? 'is-success' : 'is-muted' ?>">
                        <i class="fa fa-solid fa-mobile-screen-button" aria-hidden="true"></i>
                        <?= trans('mfa_title', '', 'Verifica in due passaggi') ?>:
                        <?= $spMfa ? trans('mfa_state_on', '', 'attiva') : trans('mfa_state_off', '', 'non attiva') ?>
                    </span>
                    <span class="ip-status-pill <?= $spPasskeys ? 'is-success' : 'is-muted' ?>">
                        <i class="fa fa-solid fa-fingerprint" aria-hidden="true"></i>
                        <?= trans('passkeys_title', '', 'Passkey') ?>: <?= $spPasskeys ?>
                    </span>
                </div>
                <a class="btn btn-sm btn-primary" href="<?= site_url('security') ?>">
                    <i class="fa fa-solid fa-shield" aria-hidden="true"></i>
                    <?= trans('security_manage', '', 'Gestisci sicurezza') ?>
                </a>
            </div>
        </div>
    </div>
<?php elseif ($mfa_enabled !== null): ?>
    <?php /* Un altro utente: solo stato e reset. Le passkey e la MFA si
             registrano sul dispositivo dell'interessato, non per conto suo;
             qui un admin sblocca chi ha perso telefono e codici di recupero. */ ?>
    <div class="ip-settings-pane ip-user-security">
        <div class="card">
            <div class="card-header">
                <i class="fa fa-solid fa-mobile-screen-button fa-fw" aria-hidden="true"></i>
                <?= trans('mfa_title', '', 'Verifica in due passaggi') ?>
            </div>
            <div class="card-body">
                <?php if ($mfa_enabled): ?>
                    <p class="mb-2">
                        <span class="badge bg-success"><?= trans('active', '', 'Attivo') ?></span>
                        <?= trans('mfa_enabled_help', '', 'L\'accesso richiede un codice dall\'app authenticator o una passkey.') ?>
                    </p>
                    <form method="post" action="<?= site_url('users/reset_mfa/' . $id) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-outline-danger btn-sm"
                                data-bs-toggle="confirm"
                                data-confirm-title="<?= esc(trans('confirm', '', 'Conferma')) ?>"
                                data-confirm-body="<?= esc(trans('mfa_reset_warning', '', 'Resettare la verifica in due passaggi di questo utente? Potrà riattivarla dalla sua pagina Sicurezza account.')) ?>"
                                data-confirm-ok="<?= esc(trans('reset', '', 'Resetta')) ?>"
                                data-confirm-danger="1">
                            <i class="fa fa-solid fa-undo fa-margin" aria-hidden="true"></i> <?= trans('mfa_reset', '', 'Resetta la verifica in due passaggi') ?>
                        </button>
                    </form>
                <?php else: ?>
                    <p class="text-muted mb-0"><?= trans('mfa_user_off', '', 'Verifica in due passaggi non attiva.') ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>

<?= $this->endSection() ?>