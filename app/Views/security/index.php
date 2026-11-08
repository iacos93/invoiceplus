<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('security_title', '', 'Sicurezza account')); ?>

<?= $this->include('layout/headerbar', ['title' => trans('security_title', '', 'Sicurezza account'), 'actions' => '']) ?>

<div id="content" class="ip-settings-pane">
    <?= $this->include('security/panel') ?>
</div>
<?= $this->endSection() ?>
