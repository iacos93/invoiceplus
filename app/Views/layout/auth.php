<!DOCTYPE html>
<?php
/* La lingua e' per utente (session user_language -> impostazione
   default_language -> it, vedi BaseController::initController). Era fissa a
   "it": con l'interfaccia in inglese la pagina si dichiarava comunque
   italiana, e sintesi vocale e traduzione automatica sbagliavano lingua. */
?>
<html lang="<?= esc(service('request')->getLocale(), 'attr') ?>">
<head>
    <title>
        <?php
        $app_title  = get_setting('custom_title', '', true) ?: 'InvoicePlane';
        $page_title = trim($pageTitle ?? '');
        echo esc($page_title === '' ? $app_title : $page_title . ' · ' . $app_title);
        ?>
    </title>

    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="NOINDEX,NOFOLLOW">
    <?php
    /* Token CSRF per il JavaScript. Il cookie csrf_cookie_name è HttpOnly,
       quindi document.cookie non lo vede: senza questa meta webauthn.js
       partiva con l'header X-CSRF-TOKEN vuoto e il login con passkey veniva
       respinto dal filtro (stessa meta già presente in layout/guest.php). */
    ?>
    <meta name="_csrf" content="<?= csrf_hash() ?>">

    <link rel="icon" type="image/png" href="<?= base_url('assets/core/img/favicon.png') ?>">

    <?= $this->include('layout/theme_head') ?>

    <!-- Caratteri del design system (vedi layout/fonts.php) -->
    <?= $this->include('layout/fonts') ?>

    <!-- Bootstrap 5 + vendor -->
    <link rel="stylesheet" href="<?php _core_asset('vendor/bootstrap.min.css'); ?>">
    <!-- Font Awesome ridotto alle icone in uso (tools/subset_fontawesome.php) -->
    <link rel="stylesheet" href="<?php _core_asset('vendor/fontawesome6/css/app.min.css'); ?>">
    <?php
    /* Il tema caricava anche css/style.css, che dopo il passaggio a Bootstrap 5
       conteneva solo un commento: una richiesta a vuoto su ogni pagina. Il
       foglio dell'app e' custom.css, unico e in core/. */
    ?>
    <!-- Tema -->
    <link rel="stylesheet" href="<?php _core_asset('css/custom.css'); ?>">
</head>
<body class="auth-page">

<noscript>
    <div class="alert alert-danger no-margin"><?= trans('please_enable_js') ?></div>
</noscript>

<?php $app_title = get_setting('custom_title', '', true) ?: 'InvoicePlane'; ?>

<div class="ip-auth-shell">

    <!-- Selettore tema chiaro/scuro. Era l'unica pagina senza: il tema si
         poteva scegliere solo dopo l'accesso, e chi apre l'app di sera si
         prendeva comunque il login chiaro in faccia. Stessa preferenza
         (localStorage ip-theme) usata dall'area interna e dal portale, quindi
         la scelta fatta qui e' gia' quella giusta dopo il login. -->
    <button type="button" class="ip-theme-toggle ip-auth-theme-toggle" id="ip-theme-toggle"
            title="<?= esc(trans('theme_toggle', '', 'Tema chiaro/scuro')) ?>"
            aria-label="<?= esc(trans('theme_toggle', '', 'Tema chiaro/scuro')) ?>">
        <i class="fa fa-solid fa-moon" aria-hidden="true"></i>
        <i class="fa fa-solid fa-sun" aria-hidden="true"></i>
        <span class="ip-theme-toggle__label" data-action="dark"><?= esc(trans('theme_dark', '', 'Tema scuro')) ?></span>
        <span class="ip-theme-toggle__label" data-action="light"><?= esc(trans('theme_light', '', 'Tema chiaro')) ?></span>
    </button>

    <!-- Niente skeleton di navigazione (layout/skeleton.php): dalla pagina
         di login il click su "Entra" porta in un mondo completamente
         diverso (dashboard), e la card finta in caricamento confonde più
         di quanto rassicuri. -->

    <!-- Pannello brand: la carta dell'app, a sinistra su desktop -->
    <aside class="ip-auth-brand">
        <div class="ip-auth-brand-mark">
            <svg class="ip-brand-mark" viewBox="240 76 138 108" aria-hidden="true" focusable="false">
                <path class="ip-brand-under" d="M294.392 145.867l11.403 5.462 8.872 3.179-21.734 27.092z"/>
                <path class="ip-brand-fold" d="M273.067 133.027L374.4 78.933l-79.864 67.035-1.59 35.632-11.733-23.794z"/>
                <path class="ip-brand-body" d="M243.067 123.146l4.708-2.857 13.083-9.693L374.4 78.933l-101.383 54.134-17.475-4.526zM294.533 145.992l10.8 6 14.962 5.608 24.332-6.696 29.773-71.97z"/>
            </svg>
            <span class="ip-brand-text">
                <span class="ip-brand-name"><?= esc($app_title) ?></span>
                <?php if ($app_subtitle = get_setting('custom_subtitle', '', true)): ?>
                <span class="ip-brand-subtitle"><?= esc($app_subtitle) ?></span>
                <?php endif; ?>
            </span>
        </div>

        <div class="ip-auth-brand-copy">
            <h1><?= esc(trans('auth_headline', '', 'La tua fatturazione, in ordine.')) ?></h1>
            <p><?= esc(trans('auth_subline', '', 'Fatture, preventivi e incassi su un unico registro. Tutto sotto controllo, senza fogli sparsi.')) ?></p>
            <ul class="ip-auth-points">
                <li><i class="fa fa-regular fa-file-text" aria-hidden="true"></i><?= esc(trans('auth_point_billing', '', 'Fatture e preventivi in pochi passi')) ?></li>
                <li><i class="fa fa-solid fa-money-bill-1" aria-hidden="true"></i><?= esc(trans('auth_point_payments', '', 'Incassi e scadenze sempre sotto controllo')) ?></li>
                <li><i class="fa fa-solid fa-chart-column" aria-hidden="true"></i><?= esc(trans('auth_point_reports', '', 'Report chiari su chi ti deve cosa')) ?></li>
            </ul>
        </div>

        <div class="ip-auth-brand-foot">
            <svg class="ip-brand-mark ip-brand-mark--foot" viewBox="240 76 138 108" aria-hidden="true" focusable="false">
                <path class="ip-brand-under" d="M294.392 145.867l11.403 5.462 8.872 3.179-21.734 27.092z"/>
                <path class="ip-brand-fold" d="M273.067 133.027L374.4 78.933l-79.864 67.035-1.59 35.632-11.733-23.794z"/>
                <path class="ip-brand-body" d="M243.067 123.146l4.708-2.857 13.083-9.693L374.4 78.933l-101.383 54.134-17.475-4.526zM294.533 145.992l10.8 6 14.962 5.608 24.332-6.696 29.773-71.97z"/>
            </svg>
            <?= esc(trans('auth_foot', '', 'Fatturazione per chi lavora bene.')) ?>
        </div>
    </aside>

    <!-- Pannello modulo -->
    <main class="ip-auth-form">
        <div class="ip-auth-card">
            <?php
                // Il logo sta in writable/uploads, non raggiungibile via http: data URI
                // (vedi logo_data_uri).
                //
                // Un marchio monocromatico scuro sparisce sul fondo del tema scuro, per
                // questo la variante chiara è caricabile a parte. Lo scambio è via CSS
                // (html[data-theme], vedi .login-logo--light/--dark in custom.css) e non
                // qui: il tema lo sceglie il browser da localStorage, il server al
                // momento del render non sa quale sia attivo.
                $login_logo      = logo_data_uri(get_setting('login_logo'));
                $login_logo_dark = logo_data_uri(get_setting('login_logo_dark'));

                // Con una sola delle due caricate quella vale per entrambi i temi:
                // meglio un logo poco leggibile che nessun logo.
                if ($login_logo === '') {
                    $login_logo      = $login_logo_dark;
                    $login_logo_dark = '';
                }
            ?>
            <?php if ($login_logo && $login_logo_dark): ?>
                <?php // Niente .d-block sulle varianti: le utility Bootstrap sono !important e vincerebbero sullo scambio per tema. ?>
                <img src="<?= $login_logo ?>" alt="<?= esc($app_title) ?>" class="login-logo login-logo--light img-fluid mx-auto mb-3" style="max-height:64px;">
                <img src="<?= $login_logo_dark ?>" alt="<?= esc($app_title) ?>" class="login-logo login-logo--dark img-fluid mx-auto mb-3" style="max-height:64px;">
            <?php elseif ($login_logo): ?>
                <img src="<?= $login_logo ?>" alt="<?= esc($app_title) ?>" class="login-logo img-fluid d-block mx-auto mb-3" style="max-height:64px;">
            <?php endif; ?>
            <?php
            // Il titolo e il sottotitolo non stanno qui: ogni pagina di accesso
            // porta la propria intestazione (titolo Fraunces + sottotitolo,
            // vedi .ip-auth-head) — il logo resta l'unico elemento
            // che il layout decide per conto di tutte. Prima c'era un h1+p di
            // fallback che, con le intestazioni delle pagine, finiva per
            // raddoppiare il titolo sulla card.
            ?>

            <?= $this->include('layout/alerts') ?>

            <div class="ip-auth-form-box">
                <?= $this->renderSection('content') ?>
            </div>
        </div>
    </main>
</div>

<script>
    // Toggle tema chiaro/scuro — vedi layout/theme_head.php per l'applicazione
    // anticipata e il fallback su prefers-color-scheme.
    (function () {
        var btn = document.getElementById('ip-theme-toggle');
        if (!btn) { return; }
        btn.addEventListener('click', function () {
            var root = document.documentElement;
            var next = root.dataset.theme === 'dark' ? 'light' : 'dark';
            root.dataset.theme = next;
            root.setAttribute('data-bs-theme', next);
            try { localStorage.setItem('ip-theme', next); } catch (e) {}
        });
    })();
</script>

</body>
</html>