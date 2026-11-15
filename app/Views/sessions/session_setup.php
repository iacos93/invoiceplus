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

<?php if (!empty($checks)): ?>
    <?php /* L'ambiente è a posto (altrimenti si vedrebbe la pagina di verifica):
             si mostra comunque l'esito, chiuso, e gli avvisi in evidenza. */ ?>
    <?php $avvisi = count(array_filter($checks, static fn ($c) => $c['state'] !== 'ok')); ?>
    <details class="ip-setup-checks"<?= $avvisi > 0 ? ' open' : '' ?>>
        <summary>
            <?= esc(trans('setup_env_checks', '', 'Esito dei controlli')) ?>
            <span class="text-muted">— <?= $avvisi > 0
                ? esc($avvisi . ' ' . trans('setup_env_warnings', '', 'da controllare'))
                : esc(trans('setup_env_allok', '', 'tutto regolare')) ?></span>
        </summary>
        <ul>
            <?php foreach ($checks as $c): ?>
                <li><i class="fa fa-solid <?= $c['state'] === 'ok' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>" aria-hidden="true"></i>
                    <strong><?= esc($c['label']) ?></strong> — <?= esc($c['detail']) ?>
                    <?php if ($c['state'] !== 'ok' && $c['hint'] !== ''): ?><br><span class="text-muted"><?= esc($c['hint']) ?></span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </details>
<?php endif; ?>

<p class="ip-webauthn-status">
    <?= trans('setup_only_first_run', '', 'Questa pagina è disponibile solo finché non esiste nessun utente.') ?>
</p>

<?= $this->endSection() ?>
