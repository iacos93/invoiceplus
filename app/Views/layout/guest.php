<!DOCTYPE html>
<?php
/* La lingua e' per utente (session user_language -> impostazione
   default_language -> it, vedi BaseController::initController). Era fissa a
   "it": con l'interfaccia in inglese la pagina si dichiarava comunque
   italiana, e sintesi vocale e traduzione automatica sbagliavano lingua. */

// Visitatore arrivato dal link pubblico (url key di fattura o preventivo):
// stessi CSS e stessa modale di conferma, ma senza navigazione dell'area
// guest e senza logout — sono comandi che a un cliente non servono e che
// portano solo alla pagina di login.
$is_public_view = !empty($public_view);
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
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="NOINDEX,NOFOLLOW">
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

    <?php if (get_setting('monospace_amounts') == 1) { ?>
        <link rel="stylesheet" href="<?php _theme_asset('css/monospace.css'); ?>">
    <?php } ?>

    <!-- jQuery (usato dal codice legacy delle view) -->
    <script src="<?php _core_asset('vendor/jquery.min.js'); ?>"></script>
    <!-- Bootstrap 5 bundle (include Popper) -->
    <script src="<?php _core_asset('vendor/bootstrap.bundle.min.js'); ?>"></script>

    <script>
        // Impostazioni lette da IPDatepicker (ip-components.js)
        window.IP_DATE_FORMAT = '<?= date_format_datepicker() ?>';
        window.IP_WEEK_START  = <?= (int) get_setting('first_day_of_week', 1) ?>;
    </script>
    <!-- Componenti di input propri (select con ricerca + calendario) -->
    <script src="<?php _core_asset('js/ip-components.min.js'); ?>"></script>
    <?php
    /* Lo skeleton sta fuori da #main-content: il partial layout/skeleton.php non
       include più lo script (vedi il suo docblock). Insieme agli altri moduli
       arriva a fondo pagina, dentro ip-app.min.js (tools/bundle_js.php). */
    ?>
</head>
<body>

<a class="ip-skip-link" href="#main-content"><?= esc(trans('skip_to_content', '', 'Vai al contenuto')) ?></a>

<header class="ip-topbar" role="banner">
    <div class="ip-topbar-inner">
        <div class="ip-topbar-left">
            <a class="ip-brand" href="<?= $is_public_view ? site_url('/') : site_url('guest') ?>">
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
            </a>

            <?php if (!$is_public_view): ?>
            <nav class="ip-guest-nav" aria-label="<?= esc(trans('menu')) ?>">
                <?= anchor('guest', trans('dashboard'), 'class="ip-guest-link ' . nav_active_class('guest', true) . '"') ?>
                <?= anchor('guest/invoices', trans('invoices'), 'class="ip-guest-link ' . nav_active_class('guest/invoices') . '"') ?>
                <?= anchor('guest/quotes', trans('quotes'), 'class="ip-guest-link ' . nav_active_class('guest/quotes') . '"') ?>
                <?= anchor('guest/payments', trans('payments'), 'class="ip-guest-link ' . nav_active_class('guest/payments') . '"') ?>
            </nav>
            <?php endif; ?>
        </div>

        <div class="ip-topbar-right">
            <button type="button" class="ip-theme-toggle" id="ip-theme-toggle"
                    title="<?= esc(trans('theme_toggle', '', 'Tema chiaro/scuro')) ?>"
                    aria-label="<?= esc(trans('theme_toggle', '', 'Tema chiaro/scuro')) ?>">
                <i class="fa fa-solid fa-moon" aria-hidden="true"></i>
                <i class="fa fa-solid fa-sun" aria-hidden="true"></i>
            </button>
            <?php if (!$is_public_view): ?>
            <ul class="navbar-nav ms-auto align-items-lg-center flex-row gap-1">
                <li class="nav-item">
                    <a href="<?= site_url('sessions/logout') ?>" class="ip-topbar-link" title="<?= trans('logout') ?>">
                        <i class="fa fa-solid fa-power-off" aria-hidden="true"></i>
                        <span class="d-none d-lg-inline"><?= trans('logout') ?></span>
                    </a>
                </li>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</header>

<script>
    // Altezza reale della topbar — vedi layout/base.php
    (function () {
        var topbar = document.querySelector('.ip-topbar');
        if (!topbar) { return; }
        function syncHeight() {
            var h = topbar.offsetHeight;
            if (h > 0) { document.documentElement.style.setProperty('--ip-navbar-height', h + 'px'); }
        }
        syncHeight();
        window.addEventListener('load', syncHeight);
        window.addEventListener('resize', syncHeight);
        if (document.fonts && document.fonts.ready) { document.fonts.ready.then(syncHeight); }
        if (window.ResizeObserver) { new ResizeObserver(syncHeight).observe(topbar); }
    })();
</script>

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

<div id="main-area">
    <main id="main-content" tabindex="-1">
        <?= $this->include('layout/skeleton') ?>
        <?= $this->include('layout/alerts') ?>
        <?= $this->renderSection('content') ?>
    </main>
</div>

<div id="modal-placeholder"></div>

<?= $this->include('layout/confirm_modal') ?>

<script>
    // Delegato conferma — vedi layout/base.php per la versione commentata
    (function () {
        var modalEl = document.getElementById('ip-confirm-modal');
        if (!modalEl || typeof bootstrap === 'undefined' || !bootstrap.Modal) return;
        var modal = new bootstrap.Modal(modalEl);
        var pendingEl = null;
        document.getElementById('ip-confirm-ok').addEventListener('click', function () {
            if (!pendingEl) return;
            var el = pendingEl; pendingEl = null;
            var cb = el.dataset.confirmCallback;
            if (cb && typeof window[cb] === 'function') { window[cb](el); return; }
            if (el.tagName === 'A' && el.getAttribute('href') && el.getAttribute('href') !== '#') {
                window.location.href = el.getAttribute('href'); return;
            }
            // el.form copre anche i bottoni con l'attributo HTML5 form="..."
            // che puntano a un form non antenato — vedi layout/base.php.
            var form = el.form || el.closest('form');
            if (form) {
                if (form.requestSubmit) { form.requestSubmit(el); } else { form.submit(); }
            }
        });
        document.addEventListener('click', function (e) {
            var el = e.target.closest('[data-bs-toggle="confirm"]');
            if (!el) return;
            e.preventDefault(); e.stopPropagation();
            pendingEl = el;
            var danger = (el.dataset.confirmDanger === '1' || el.dataset.confirmDanger === 'true');
            document.getElementById('ip-confirm-modal-title').textContent = el.dataset.confirmTitle || 'Conferma';
            document.getElementById('ip-confirm-modal-body').textContent = el.dataset.confirmBody || '';
            var okBtn = document.getElementById('ip-confirm-ok');
            okBtn.querySelector('.ip-confirm-ok-label').textContent = el.dataset.confirmOk || 'Conferma';
            okBtn.className = danger ? 'btn btn-danger' : 'btn btn-primary';
            okBtn.querySelector('i').className = danger ? 'fa fa-exclamation-triangle' : 'fa fa-check';
            var modalIcon = document.querySelector('#ip-confirm-modal .confirm-modal-icon');
            if (modalIcon) {
                modalIcon.className = 'fa ' + (danger ? 'fa-exclamation-triangle confirm-modal-icon confirm-modal-icon-danger'
                                                        : 'fa-question-circle confirm-modal-icon');
            }
            modal.show();
        });
    })();
</script>

<script defer src="<?php _core_asset('js/ip-app.min.js'); ?>"></script>
</body>
</html>
