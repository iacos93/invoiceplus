<!DOCTYPE html>
<html lang="it">
<head>
    <title>
        <?php
        $page_title = trim($pageTitle ?? '');
        if ($page_title === '') { $page_title = get_setting('custom_title', '', true) ?: 'InvoicePlane'; }
        echo esc($page_title);
        ?>
    </title>

    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="NOINDEX,NOFOLLOW">

    <link rel="icon" type="image/png" href="<?= base_url('assets/core/img/favicon.png') ?>">

    <?= $this->include('layout/theme_head') ?>

    <link rel="stylesheet" href="<?= base_url('assets/invoiceplane/css/welcome.css') ?>">
    <link rel="stylesheet" href="<?php _core_asset('vendor/bootstrap.min.css'); ?>">
    <link rel="stylesheet" href="<?php _core_asset('vendor/fontawesome6/css/app.min.css'); ?>">
    <link rel="stylesheet" href="<?php _core_asset('css/custom.css'); ?>"></head>
<body>

<div class="container">

    <div id="content">

        <div class="row">
            <div class="col-12 col-md-6 offset-md-3">

                <div class="card text-center">
                    <div class="card-body">

                        <img src="<?= base_url('assets/core/img/logo_400x200.png') ?>"
                             alt="InvoicePlane" class="img-fluid d-block mx-auto"
                             style="max-width:300px;margin-bottom:20px">

                        <h1><?= trans('welcome', '', 'Benvenuto in InvoicePlane CI4') ?></h1>

                        <p class="text-muted">
                            <?= trans('welcome_message', '', 'Sistema di fatturazione portato da CodeIgniter 3 a CodeIgniter 4.') ?>
                        </p>

                        <a class="btn btn-primary" href="<?= site_url('sessions/login') ?>">
                            <i class="fa fa-solid fa-right-to-bracket" aria-hidden="true"></i>
                            <?= trans('login', '', 'Accedi') ?>
                        </a>

                    </div>
                </div>

            </div>
        </div>

    </div>

</div>

</body>
</html>
