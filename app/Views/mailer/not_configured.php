<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('email_invoice')); ?>
<?= $this->include('layout/headerbar', ['title' => trans('email_invoice')]) ?>

<div id="content">
    <div class="alert alert-danger">
        <i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i> <?= trans('email_not_configured') ?>
        <a href="<?= site_url('email_senders') ?>"><?= trans('email_settings_section', '', 'Email e mittenti') ?></a>
    </div>
</div>

<?= $this->endSection() ?>