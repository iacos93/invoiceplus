<?php $this->setVar('pageTitle', trans('recovery_code_title', '', 'Codice di recupero')); ?>
<?= $this->extend('layout/auth') ?>
<?= $this->section('content') ?>

<div class="ip-auth-head">
    <h2><?= trans('recovery_code_title', '', 'Codice di recupero') ?></h2>
    <p><?= trans('recovery_code_desc', '', 'Inserisci uno dei tuoi codici di backup') ?></p>
</div>

<div class="ip-auth-callout ip-auth-callout--danger">
    <strong><i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i><?= trans('recovery_code_warning', '', 'Uso limitato') ?></strong><br>
    <?= trans('recovery_code_warning_desc', '', 'Ogni codice può essere usato una sola volta. Dopo l\'uso, sarà invalidato.') ?>
</div>

<form method="post" action="<?= site_url('sessions/mfa') ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="mfa_method" value="recovery">

    <div class="ip-doc-field">
        <label for="recovery_code" class="form-badge">
            <?= trans('recovery_code_label', '', 'Codice di recupero') ?>
        </label>
        <input type="text" name="recovery_code" id="recovery_code" class="form-control is-mono"
               placeholder="XXXX-XXXX-XXXX-XXXX" required autofocus>
    </div>

    <button type="submit" class="btn btn-primary">
        <i class="fa fa-solid fa-check fa-margin" aria-hidden="true"></i> <?= trans('verify', '', 'Verifica') ?>
    </button>
</form>

<div class="ip-auth-foot">
    <a href="<?= site_url('sessions/mfa') ?>" class="btn-link-secondary">
        <?= trans('mfa_back_methods', '', 'Torna ai metodi disponibili') ?>
    </a>
</div>

<?= $this->endSection() ?>