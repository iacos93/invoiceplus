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
        $app_title  = get_setting('custom_title', '', true) ?: 'Invoice+';
        $page_title = trim($pageTitle ?? '');
        echo esc($page_title === '' ? $app_title : $page_title . ' · ' . $app_title);
        ?>
    </title>

    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="NOINDEX,NOFOLLOW">
    <?php
    /* Token CSRF per il JavaScript (registrazione passkey dalla pagina
       Sicurezza): il cookie omonimo è HttpOnly, quindi il JS non può
       leggerlo. Stessa meta già presente in layout/guest.php. */
    ?>
    <meta name="_csrf" content="<?= csrf_hash() ?>">

    <link rel="icon" type="image/svg+xml" href="<?php _core_asset('img/favicon.svg'); ?>">


    <link rel="icon" type="image/png" href="<?php _core_asset('img/favicon.png'); ?>">
    <?= $this->include('layout/pwa_head') ?>

    <?= $this->include('layout/theme_head') ?>

    <!-- Bootstrap 5 + vendor -->
    <link rel="stylesheet" href="<?php _core_asset('vendor/bootstrap.subset.min.css'); ?>">
    <?php
    /* Font Awesome ridotto alle icone che l'app usa davvero: all.min.css sono
       100 KB, di cui 86 KB di regole di code point per 2.477 nomi, mentre il
       codice ne cita 152. app.min.css e' generato da tools/subset_fontawesome.php
       (php tools/subset_fontawesome.php) e un test unitario verifica che non
       manchi nulla: un'icona nuova senza rigenerare fa fallire phpunit, non
       sparisce in silenzio in produzione. Qui e' stato tolto anche il foglio
       di compatibilita' con i nomi v4 (27 KB): serviva solo alle icone scritte
       senza classe di famiglia, e nei sorgenti quei nomi sono stati portati
       sulle famiglie FA6 (vedi app/Controllers/Search.php). */
    ?>
    <link rel="stylesheet" href="<?php _core_asset('vendor/fontawesome6/css/app.min.css'); ?>">
    <?php
    /* Il tema caricava anche css/style.css, che dopo il passaggio a Bootstrap 5
       conteneva solo un commento: una richiesta a vuoto su ogni pagina. Il
       foglio dell'app e' custom.css, unico e in core/. */
    ?>
    <!-- Tema. custom.css e' la sorgente (commenti "perche'" inclusi); in
         produzione si serve custom.min.css (generato da tools/minify_css.php:
         php tools/minify_css.php). In sviluppo resta la sorgente cosi' le
         modifiche si vedono senza ricompilare. -->
    <?php $ip_css = (defined('ENVIRONMENT') && ENVIRONMENT === 'production') ? 'css/custom.min.css' : 'css/custom.css'; ?>
    <link rel="stylesheet" href="<?php _core_asset($ip_css); ?>">
    <!-- Gli stili dei report (css/reports.css) non stanno qui: li carica
         reports/partial_style.php, solo nelle cinque pagine che li usano. -->

    <?php if (get_setting('monospace_amounts') == 1) { ?>
        <link rel="stylesheet" href="<?php _theme_asset('css/monospace.css'); ?>">
    <?php } ?>

    <!-- Bootstrap 5 bundle (include Popper) -->
    <script src="<?php _core_asset('vendor/bootstrap.bundle.min.js'); ?>"></script>

    <?php
    /* Qui c'erano due <script> verso cdnjs (Dropzone 5.5.1 e clipboard.js 2.0.4).
       Nessuna view li usava piu': gli upload sono <input type="file"> normali e
       la copia negli appunti passa da navigator.clipboard (settings/index.php).
       Oltre al peso inutile su ogni pagina, `Dropzone.autoDiscover = false`
       apriva lo script inline qui sotto: senza rete verso cdnjs sollevava
       ReferenceError e il blocco moriva prima di assegnare IP_DATE_FORMAT e
       IP_WEEK_START, lasciando i datepicker con il formato sbagliato. */
    ?>
    <script>
        // Impostazioni lette da IPDatepicker (ip-components.js)
        window.IP_DATE_FORMAT = '<?= date_format_datepicker() ?>';
        window.IP_WEEK_START  = <?= (int) get_setting('first_day_of_week', 1) ?>;

        // Tooltip e rientro delle schede stanno in ip-app.min.js (ip-layout.js).
        // Qui resta solo la configurazione che dipende dal server.
    </script>
    <!-- Componenti di input propri (select con ricerca + calendario) -->
    <script src="<?php _core_asset('js/ip-components.min.js'); ?>"></script>
    <!-- Skeleton e navigazione parziale. Stanno fuori da #main-content: quel
         contenuto viene sostituito a ogni click, e uno script dentro verrebbe
         rieseguito ogni volta (vedi layout/skeleton.php). I file stanno tutti
         in fondo alla pagina, dentro ip-app.min.js: sono IIFE indipendenti e
         l'unica cosa che conta e' l'ordine, che il bundle conserva. -->
    <script>
        /* Stato per la navigazione parziale (ip-pjax.js). Le pagine sono
           documenti completi: lo script intercetta i link interni e sostituisce
           solo #main-content. I listener di pagina su document/window non
           muoiono con il contenuto rimosso; ipOn() li lega a un AbortSignal
           che ip-pjax.js annulla prima di montare la pagina nuova. */
        window.ipBeforeNavigate = null;
        window.__ipPageAbort = window.AbortController ? new AbortController() : null;
        window.ipPageSignal = window.__ipPageAbort ? window.__ipPageAbort.signal : undefined;
        window.ipOn = function (target, type, handler, opts) {
            // addEventListener accetta anche un booleano (capture): qui va
            // normalizzato, altrimenti non ci si può aggiungere signal.
            if (typeof opts === 'boolean') { opts = { capture: opts }; }
            opts = opts || {};
            if (window.ipPageSignal && !('signal' in opts)) {
                opts.signal = window.ipPageSignal;
            }
            target.addEventListener(type, handler, opts);
        };

        /* Avvio di uno script di pagina. `DOMContentLoaded` scatta una volta
           sola, al primo caricamento: con la navigazione parziale gli script del
           contenuto nuovo girano a documento già pronto e il listener non
           verrebbe mai chiamato (i pulsanti della pagina non rispondono finché
           non si ricarica). Qui si esegue subito se il documento è pronto, e si
           aspetta l'evento solo se è ancora in caricamento. È la forma di
           `$(function () {…})` di jQuery, che gli script avevano prima. */
        window.ipReady = function (fn) {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fn, { once: true });
            } else {
                fn();
            }
        };
    </script>
</head>
<body>

<noscript>
    <div class="alert alert-danger no-margin"><?= trans('please_enable_js') ?></div>
</noscript>

<?php $app_title = get_setting('custom_title', '', true) ?: 'Invoice+'; ?>

<!-- Skip link: primo elemento tabulabile, salta topbar e sidebar per chi
     naviga da tastiera o con screen reader. Visibile solo al focus. -->
<a class="ip-skip-link" href="#main-content"><?= esc(trans('skip_to_content', '', 'Vai al contenuto')) ?></a>

<!-- ============================= TOPBAR ============================= -->
<header class="ip-topbar" role="banner">
    <div class="ip-topbar-inner">
        <div class="ip-topbar-left">
            <button type="button" class="ip-sidebar-toggle" id="ip-sidebar-toggle"
                    aria-label="<?= esc(trans('menu')) ?>" aria-expanded="false" aria-controls="ip-sidebar">
                <i class="fa fa-solid fa-bars" aria-hidden="true"></i>
            </button>

            <?php /* Sotto i 576px il nome è nascosto via CSS (non ci sta):
                     l'aria-label tiene il link con un nome accessibile. */ ?>
            <a class="ip-brand" href="<?= site_url('dashboard') ?>" aria-label="<?= esc($app_title, 'attr') ?>">
                <svg class="ip-brand-mark" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <?= $this->include('layout/brand_mark') ?>
                </svg>
                <span class="ip-brand-text">
                    <span class="ip-brand-name"><?= esc($app_title) ?></span>
                    <?php if ($app_subtitle = get_setting('custom_subtitle', '', true)): ?>
                    <span class="ip-brand-subtitle"><?= esc($app_subtitle) ?></span>
                    <?php endif; ?>
                </span>
            </a>
        </div>

        <?php
        // Ricerca globale. Sta fuori da .ip-topbar-left perche' il blocco brand
        // occupa esattamente la larghezza della sidebar: cosi' il campo parte
        // dove parte il contenuto, invece di sovrapporsi alla colonna del menu.
        ?>
        <div class="ip-global-search" id="ip-global-search"
             data-action="<?= site_url('search/global') ?>">
            <i class="fa fa-solid fa-search ip-global-search__icon" aria-hidden="true"></i>
            <input type="text" class="ip-global-search__input" id="ip-global-search-input"
                   placeholder="<?= esc(trans('search_everything', '', 'Cerca fatture, preventivi, clienti, prodotti…')) ?>"
                   aria-label="<?= esc(trans('search', '', 'Cerca')) ?>"
                   autocomplete="off" role="combobox" aria-expanded="false"
                   aria-controls="ip-global-search-results" aria-autocomplete="list">
            <?php /* Una sola combinazione invece di due: "Ctrl + K / Cmd + K"
                     occupava più del doppio dello spazio dentro il campo e
                     spingeva il segnaposto sotto l'etichetta. Lo <span> marca
                     il modificatore che cambia nome per piattaforma: scripts.js
                     lo sostituisce con "⌘" su Mac/iPhone/iPad, così resta "⌘ K"
                     invece di "Ctrl K". Prima quello script non trovava nulla da
                     sostituire e restavano sempre le due forme. */ ?>
            <kbd class="ip-global-search__kbd" aria-hidden="true"><span class="ip-kbd-mod">Ctrl</span> K</kbd>
            <div class="ip-global-search__panel" id="ip-global-search-results" role="listbox" hidden></div>
        </div>

        <div class="ip-topbar-right">

            <?php /* Pulsante di installazione (PWA): nasce nascosto e lo mostra
                     lo script di layout/pwa_head.php solo se il browser offre
                     davvero di installare l'app. Sparisce a installazione fatta
                     e non compare mai nella finestra dell'app installata. */ ?>
            <button type="button" class="ip-install-btn" data-ip-install hidden
                    title="<?= esc(trans('pwa_install_title', '', 'Installa Invoice+ come app'), 'attr') ?>">
                <i class="fa fa-solid fa-download" aria-hidden="true"></i>
                <span><?= esc(trans('pwa_install', '', 'Installa app')) ?></span>
            </button>

            <?php /* Le impostazioni stanno nella sidebar, sotto "Gestione": il
                     gear qui era una seconda porta per la stessa stanza. */ ?>

            <?php $user_name = htmlsc(session('user_name')); $user_initial = mb_substr(trim($user_name), 0, 1) ?: 'U'; ?>
            <div class="dropdown ip-user-menu">
                <button type="button" class="ip-user-button" data-bs-toggle="dropdown" aria-expanded="false"
                        title="<?= $user_name ?>">
                    <span class="ip-nav-avatar" aria-hidden="true"><?= $user_initial ?></span>
                    <span class="ip-topbar-user d-none d-md-inline"><?= $user_name ?></span>
                    <span class="ip-user-button__arrow" aria-hidden="true"><i class="fa fa-solid fa-chevron-down" aria-hidden="true"></i></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li class="dropdown-header"><?= $user_name ?></li>
                    <?php /* Selettore tema chiaro/scuro: preferenza in localStorage
                             (ip-theme), senza scelta esplicita segue il sistema.
                             Icone ed etichetta mostrano l'azione disponibile e si
                             scambiano via CSS su html[data-theme]: in tema chiaro
                             "Tema scuro", in tema scuro "Tema chiaro". */ ?>
                    <li>
                        <button type="button" class="dropdown-item ip-theme-toggle" id="ip-theme-toggle"
                                title="<?= esc(trans('theme_toggle', '', 'Tema chiaro/scuro')) ?>">
                            <i class="fa fa-solid fa-moon" aria-hidden="true"></i>
                            <i class="fa fa-solid fa-sun" aria-hidden="true"></i>
                            <span class="ip-theme-toggle__label" data-action="dark"><?= esc(trans('theme_dark', '', 'Tema scuro')) ?></span>
                            <span class="ip-theme-toggle__label" data-action="light"><?= esc(trans('theme_light', '', 'Tema chiaro')) ?></span>
                        </button>
                    </li>
                    <li><?= anchor('users/form/' . session('user_id'), '<i class="fa fa-solid fa-user fa-margin" aria-hidden="true"></i>' . trans('profile', '', 'Profilo'), 'class="dropdown-item"') ?></li>
                    <li><?= anchor('security', '<i class="fa fa-solid fa-shield fa-margin" aria-hidden="true"></i>' . trans('security_title', '', 'Sicurezza account'), 'class="dropdown-item"') ?></li>
                    <li class="dropdown-divider"></li>
                    <li>
                        <?php /* Uscita in POST: una GET la poteva far partire un
                                 link di terzi (o un prefetch) senza un clic vero. */ ?>
                        <form method="post" action="<?= site_url('sessions/logout') ?>" class="m-0">
                            <?= csrf_field() ?>
                            <?php /* dropdown-button: il <button> sta dentro <form>, non
                                     e' figlio diretto del <li>, e senza la classe restava
                                     con il padding di Bootstrap (4px 16px, nessun gap,
                                     niente hover arrotondato) disallineato dalle voci
                                     sopra. Vedi la nota sui menu in 02-base-componenti. */ ?>
                            <button type="submit" class="dropdown-item dropdown-button text-danger">
                                <i class="fa fa-solid fa-power-off fa-margin" aria-hidden="true"></i><?= trans('logout') ?>
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</header>

<!-- ============================= SIDEBAR ============================= -->
    <div class="ip-sidebar-backdrop" id="ip-sidebar-backdrop" hidden></div>

    <?php
    /**
     * Voci della sidebar descritte come dati e non come markup ripetuto: prima
     * ogni voce riscriveva le stesse quattro righe di HTML, e aggiungerne o
     * spostarne una voleva dire copiare markup con il rischio di dimenticare un
     * pezzo. Struttura: [rotta, icona, etichetta, sottovoci].
     */
    $ip_nav_sections = [
        [
            'label' => trans('sidebar_work', '', 'Lavoro'),
            'items' => array_values(array_filter([
                ['dashboard', 'fa-solid fa-gauge-high', trans('dashboard'), []],
                ['clients', 'fa-solid fa-address-book', trans('anagrafiche', '', 'Anagrafiche'), [
                    ['clients', trans('clients')],
                    ['clients/form', trans('new_client')],
                    ['suppliers', trans('suppliers', '', 'Fornitori')],
                    ['suppliers/form', trans('add_supplier', '', 'Nuovo fornitore')],
                ]],
                ['quotes', 'fa-regular fa-file', trans('quotes'), [
                    ['quotes/form_create', trans('create_quote')],
                ]],
                ['invoices', 'fa-regular fa-file-lines', trans('invoices'), [
                    ['invoices/form_create', trans('create_invoice')],
                    ['invoices/recurring', trans('view_recurring_invoices')],
                    ['invoices/archive', trans('invoice_archive')],
                    ['invoices/import', trans('import_xml', '', 'Importa XML')],
                ]],
                ['payments', 'fa-solid fa-money-bill', trans('payments'), [
                    ['payments/form', trans('enter_payment')],
                    // Stessa etichetta del titolo della pagina: la frase CI3
                    // ("Visualizzare i registri di pagamento on-line") andava
                    // su due righe nella colonna.
                    ['payments/online_logs', trans('payment_logs', '', 'Registri di pagamento')],
                ]],
                ['expenses', 'fa-solid fa-cart-shopping', trans('expenses', '', 'Spese'), [
                    ['expenses/form', trans('add_expense', '', 'Nuova spesa')],
                    ['expenses/import', trans('import_xml', '', 'Importa XML')],
                ]],
                // In sidebar la sigla: "Documenti di trasporto" non entra nei
                // 236px della colonna e veniva troncato con l'ellissi. Il
                // titolo della pagina resta per esteso.
                ['delivery_notes', 'fa-solid fa-truck', trans('delivery_note', '', 'DDT'), [
                    ['delivery_notes/form', trans('new_delivery_note', '', 'Nuovo DDT')],
                ]],
                ['f24', 'fa-solid fa-building-columns', 'F24', []],
                ['riba', 'fa-solid fa-credit-card', trans('riba', '', 'RiBa'), []],
                ['products', 'fa-solid fa-cubes', trans('products'), [
                    ['products/form', trans('create_product')],
                    ['families', trans('view_product_families')],
                    ['units', trans('view_product_units')],
                ]],
                get_setting('projects_enabled') == 1
                    ? ['tasks', 'fa-regular fa-square-check', trans('tasks'), [
                        ['tasks/form', trans('create_task')],
                        ['projects/form', trans('create_project')],
                        ['projects', trans('view_projects')],
                    ]]
                    : null,
            ])),
        ],
        [
            'label' => trans('sidebar_analysis', '', 'Analisi'),
            'items' => [
                ['schedule', 'fa-solid fa-hourglass-half', trans('schedule', '', 'Scadenzario'), []],
                ['calendar', 'fa-solid fa-calendar', trans('calendar', '', 'Calendario'), []],
                ['reconciliation', 'fa-solid fa-right-left', trans('reconciliation', '', 'Riconciliazione'), []],
                ['finance', 'fa-solid fa-scale-balanced', trans('finance', '', 'Utile e imposte'), []],
                ['management', 'fa-solid fa-chart-line', trans('management', '', 'Controllo di gestione'), [
                    ['management', trans('management', '', 'Bilancino')],
                    ['management/cashflow', trans('management_cashflow', '', 'Flusso di cassa')],
                    ['management/accounts', trans('management_plan', '', 'Piano dei conti')],
                    ['management/budget', trans('management_budget', '', 'Budget')],
                    ['management/assign', trans('management_assign', '', 'Assegna voci')],
                ]],
                ['imports_exports', 'fa-solid fa-right-left', trans('imports_exports', '', 'Importa / Esporta'), [
                    ['import', trans('import_data', '', 'Importa CSV')],
                    ['exports', trans('exports', '', 'Esporta')],
                ]],
                // Punta all'hub /reports, non a un report: con la rotta di
                // "Storico fatture" la sezione non si accendeva proprio
                // sull'elenco dei report, e la voce portava a uno dei quattro.
                ['reports', 'fa-solid fa-chart-column', trans('reports'), [
                    ['reports/invoice_aging', trans('invoice_aging')],
                    ['reports/payment_history', trans('payment_history')],
                    ['reports/sales_by_client', trans('sales_by_client')],
                    ['reports/sales_by_year', trans('sales_by_date')],
                ]],
            ],
        ],
        [
            'label' => trans('sidebar_manage', '', 'Gestione'),
            'items' => [
                ['settings', 'fa-solid fa-gear', trans('settings', '', 'Impostazioni'), [
                    ['settings', trans('system_settings', '', 'Generali')],
                    ['invoice_groups', trans('invoice_groups')],
                    ['tax_rates', trans('tax_rates')],
                    ['fiscal_years', trans('fiscal_years', '', 'Parametri fiscali')],
                    ['bank_accounts', trans('bank_accounts', '', 'Conti di saldo')],
                    ['expense_categories', trans('expense_categories', '', 'Categorie di spesa')],
                    ['payment_methods', trans('payment_methods')],
                    ['custom_fields', trans('custom_fields')],
                    ['email_templates', trans('email_templates')],
                    ['email_senders', trans('email_settings_section', '', 'Email e mittenti')],
                    ['companies', trans('companies', '', 'Aziende')],
                    ['users', trans('user_accounts')],
                    ['security', trans('security_title', '', 'Sicurezza account')],
                ]],
                ['mcp/settings', 'fa-solid fa-robot', trans('mcp_settings', '', 'Integrazione MCP'), []],
                ['system/status', 'fa-solid fa-heart-pulse', trans('system_status', '', 'Stato del sistema'), []],
            ],
        ],
    ];

    $ip_nav_index = 0;
    ?>

    <nav class="ip-sidebar" id="ip-sidebar" aria-label="<?= esc(trans('menu')) ?>">
        <div class="ip-sidebar-scroll">
            <?php
            // Contesto della navigazione — azienda e anno — in testa alla
            // sidebar, sopra le sezioni del menu.
            //
            // Erano separati: l'azienda qui, l'anno in topbar. Sono i due
            // filtri che riscrivono ogni cifra della pagina, e chi leggeva un
            // totale inatteso doveva cercarne la causa in due punti diversi
            // dello schermo. Stanno insieme perché si leggono insieme.
            //
            // Perché qui e non in topbar: su mobile la sidebar è off-canvas e
            // il selettore azienda resta comunque raggiungibile, mentre dalla
            // topbar spariva del tutto (era la ragione per cui era stato
            // spostato qui la prima volta). Spostare l'anno di sotto tiene
            // insieme la coppia senza rimettere in piedi quel problema.
            //
            // L'azienda compare solo se ce n'è più di una: altrimenti sarebbe
            // un comando con una sola scelta possibile.
            $ip_companies = available_companies();

            // L'anno della pagina vince su quello di sessione quando è
            // nell'URL: la barra periodo delle liste porta con sé l'anno dei
            // mesi, e con «Tutti gli anni» sono i mesi dell'anno in corso. Il
            // selettore deve dire l'anno che si sta guardando, non quello
            // salvato, o dopo quel clic mostrerebbe «Tutti gli anni» sopra
            // una lista di un anno solo.
            $ip_year_in_url = service('request')->getGet('year');
            $currentYear    = is_scalar($ip_year_in_url) && $ip_year_in_url !== ''
                ? (int) $ip_year_in_url
                : selected_year();
            // Codice destinatario SdI dell'azienda attiva. Sta sotto il
            // selettore perché è un dato dell'azienda e non un filtro: serve
            // a farsi mandare le fatture, e averlo sempre in vista evita di
            // riaprirlo ogni volta nella scheda azienda.
            $ip_company_sdi = trim((string) (active_company()['company_sdi_code'] ?? ''));
            ?>
            <div class="ip-sidebar-context">
                <?php if (count($ip_companies) > 1): ?>
                    <form method="POST" action="<?= site_url('companies/switch') ?>" class="ip-company-switch">
                        <?= csrf_field() ?>
                        <input type="hidden" name="back" value="<?= esc(current_url(true)) ?>">
                        <label class="visually-hidden" for="ip-context-company"><?= esc(trans('company', '', 'Azienda')) ?></label>
                        <select name="company_id" id="ip-context-company" class="simple-select" onchange="this.form.submit()">
                            <?php $ip_active_company = active_company_id(); ?>
                            <?php foreach ($ip_companies as $ip_company): ?>
                                <option value="<?= (int) $ip_company['company_id'] ?>"
                                    <?= $ip_active_company === (int) $ip_company['company_id'] ? 'selected' : '' ?>>
                                    <?= esc($ip_company['company_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php endif; ?>

                <?php if ($ip_company_sdi !== ''): ?>
                    <?php /* «SDI» e non «codice destinatario»: nella colonna larga
                             236px l'etichetta estesa non ci sta accanto al
                             codice, e l'acronimo è lo stesso in entrambe le
                             lingue. Il lettore di schermo sente comunque il
                             nome completo in aria-label. */ ?>
                    <p class="ip-sidebar-sdi" aria-label="<?= esc(trans('sdi_code', '', 'Codice destinatario SdI'), 'attr') ?>">
                        <span class="ip-sidebar-sdi__label">SDI</span>
                        <span class="ip-sidebar-sdi__value"><?= esc($ip_company_sdi) ?></span>
                    </p>
                <?php endif; ?>

                <?php /* Filtro anno globale: persiste in sessione e in un cookie
                         di un anno (selected_year()/remember_selected_year(),
                         in ip_helpers.php), non nella query string — segue
                         l'utente da una pagina all'altra e ritorna anche dopo
                         logout o chiusura del browser. "back" riporta alla
                         pagina corrente dopo il cambio, che sia bacheca,
                         fatture o scadenzario. */ ?>
                <form method="POST" action="<?= site_url('year_filter/set') ?>" class="ip-year-filter">
                    <?= csrf_field() ?>
                    <input type="hidden" name="back" value="<?= esc(current_url(true)) ?>">
                    <label class="visually-hidden" for="ip-context-year"><?= esc(trans('year', '', 'Anno')) ?></label>
                    <select name="year" id="ip-context-year" class="ip-year-filter-select simple-select" onchange="this.form.submit()">
                        <option value="0" <?= $currentYear === 0 ? 'selected' : '' ?>><?= esc(trans('all_years', '', 'Tutti gli anni')) ?></option>
                        <?php foreach (available_years() as $y): ?>
                            <option value="<?= (int) $y ?>" <?= $currentYear === (int) $y ? 'selected' : '' ?>><?= (int) $y ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php foreach ($ip_nav_sections as $section): ?>
                <p class="ip-nav-eyebrow"><?= esc($section['label']) ?></p>
                <ul class="ip-nav">
                    <?php foreach ($section['items'] as [$route, $icon, $label, $children]): ?>
                        <?php
                        // Regola in nav_item_state() (ip_helpers.php), dove e'
                        // verificabile: quale voce prende l'evidenziazione piena
                        // non e' ovvio quanto sembra, e ha gia' sbagliato piu' volte.
                        $nav = nav_item_state($route, $children);

                        $is_active = $nav['open'];
                        $is_here   = $nav['here'];

                        $panel_id  = 'ip-nav-panel-' . (++$ip_nav_index);
                        $link_attr = 'class="ip-nav-link' . ($is_here ? ' is-current' : '') . '"'
                            . ($nav['aria'] !== '' ? ' aria-current="' . esc($nav['aria'], 'attr') . '"' : '');
                        ?>
                        <li class="ip-nav-item <?= $is_active ? 'active' : '' ?>">
                            <div class="ip-nav-row">
                                <?= anchor($route, '<i class="fa ' . $icon . '" aria-hidden="true"></i><span>' . $label . '</span>', $link_attr) ?>

                                <?php if ($children): ?>
                                    <?php /* Freccia separata dal link: la voce principale
                                             resta cliccabile e porta alla sua pagina,
                                             l'espansione è un gesto a parte. */ ?>
                                    <button type="button" class="ip-nav-toggle"
                                            data-nav-toggle aria-controls="<?= $panel_id ?>"
                                            aria-expanded="<?= $is_active ? 'true' : 'false' ?>"
                                            aria-label="<?= esc(trans('expand', '', 'Espandi')) . ' ' . esc($label) ?>">
                                        <i class="fa fa-solid fa-chevron-down" aria-hidden="true"></i>
                                    </button>
                                <?php endif; ?>
                            </div>

                            <?php if ($children): ?>
                                <ul class="ip-nav-sub" id="<?= $panel_id ?>" <?= $is_active ? '' : 'hidden' ?>>
                                    <?php foreach ($children as [$child_route, $child_label]): ?>
                                        <?php
                                        // La sotto-voce attiva arriva già decisa da
                                        // nav_item_state(): è quella più specifica che
                                        // contiene l'indirizzo (clients/form su clients).
                                        // Con il solo confronto esatto, le pagine di
                                        // elenco (/clients/status/active) e i dettagli
                                        // (/clients/view/5) lasciavano accesa solo la
                                        // madre, senza dire in quale sotto-sezione si era.
                                        $child_active = $nav['child'] === $child_route;
                                        ?>
                                        <li>
                                            <?= anchor(
                                                $child_route,
                                                $child_label,
                                                'class="ip-nav-sub-link' . ($child_active ? ' active' : '') . '"'
                                                . ($child_active && $nav['child_aria'] !== ''
                                                    ? ' aria-current="' . esc($nav['child_aria'], 'attr') . '"'
                                                    : '')
                                            ) ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>

            <p class="ip-sidebar-foot">
                <svg class="ip-brand-mark ip-brand-mark--foot" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <?= $this->include('layout/brand_mark') ?>
                </svg>
                <span><?= esc($app_title) ?> <em><?= esc(trans('sidebar_version', '', 'fatturazione')) ?></em></span>
            </p>
        </div>
    </nav>

<script>
    // Scorrimento del menu tra una pagina e l'altra. Ogni pagina e' un
    // caricamento completo, quindi la colonna ripartiva sempre dall'alto: chi
    // stava lavorando in fondo alla lista (Gestione, Impostazioni) si ritrovava
    // in cima a ogni click. La posizione sta in sessionStorage e si rimette qui,
    // subito dopo il markup e prima del primo disegno, cosi' non c'e' scatto.
    (function () {
        var KEY = 'ip-sidebar-scroll';
        var scroller = document.querySelector('#ip-sidebar .ip-sidebar-scroll');
        if (!scroller) { return; }

        try {
            var saved = parseInt(window.sessionStorage.getItem(KEY), 10);
            if (!isNaN(saved)) { scroller.scrollTop = saved; }
        } catch (e) {
            // Storage non disponibile: il menu riparte dall'alto come prima.
        }

        // Se la voce corrente resta fuori vista (prima visita, o sezione
        // riaperta altrove) la si porta dentro l'area visibile.
        var cur = scroller.querySelector('.ip-nav-link.is-current, .ip-nav-sub-link.active');
        if (cur) {
            var r = cur.getBoundingClientRect();
            var s = scroller.getBoundingClientRect();
            if (r.top < s.top || r.bottom > s.bottom) {
                scroller.scrollTop += r.top - s.top - 80;
            }
        }

        function save() {
            try { window.sessionStorage.setItem(KEY, String(scroller.scrollTop)); } catch (e) {}
        }

        var timer = null;
        scroller.addEventListener('scroll', function () {
            clearTimeout(timer);
            timer = setTimeout(save, 80);
        }, { passive: true });
        window.addEventListener('pagehide', save);
    })();
</script>

<script>
    // Misura l'altezza REALE della topbar (font caricato in ritardo, wrap su
    // viewport strette) e la espone come --ip-navbar-height. body.padding-top,
    // .ip-sidebar e #headerbar la usano per non finire sotto la topbar fixed.
    (function () {
        var topbar = document.querySelector('.ip-topbar');
        if (!topbar) { return; }

        function syncHeight() {
            var h = topbar.offsetHeight;
            if (h > 0) {
                document.documentElement.style.setProperty('--ip-navbar-height', h + 'px');
            }
            // Altezza headerbar: serve ad ancorare l'eventuale thead sticky
            // subito sotto la headerbar stessa (vedi .table--sticky-head in
            // custom.css). Misurata qui perche' dipende da font/avatar/wrap.
            var headerbar = document.getElementById('headerbar');
            if (headerbar) {
                var hh = headerbar.offsetHeight;
                if (hh > 0) {
                    document.documentElement.style.setProperty('--ip-headerbar-height', hh + 'px');
                }
            }
        }

        // La headerbar cambia altezza da una pagina all'altra (headerbar-meta
        // sotto il titolo, azioni che vanno a capo) e la navigazione parziale
        // la sostituisce: l'osservatore va riagganciato al nuovo elemento,
        // altrimenti --ip-headerbar-height resta quella della pagina di prima.
        // La usa anche il blocco del filtro anno in sidebar, che deve chiudere
        // sulla stessa riga della headerbar.
        var hbNode = null;
        var hbObserver = null;
        function observeHeaderbar() {
            var headerbar = document.getElementById('headerbar');
            if (headerbar === hbNode) { return; }
            if (hbObserver) { hbObserver.disconnect(); hbObserver = null; }
            hbNode = headerbar;
            if (headerbar && window.ResizeObserver) {
                hbObserver = new ResizeObserver(syncHeight);
                hbObserver.observe(headerbar);
            }
        }

        syncHeight();
        observeHeaderbar();
        window.addEventListener('load', syncHeight);
        window.addEventListener('resize', syncHeight);
        document.addEventListener('ip:page-ready', function () {
            syncHeight();
            observeHeaderbar();
        });
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(syncHeight);
        }
        if (window.ResizeObserver) {
            new ResizeObserver(syncHeight).observe(topbar);
        }
    })();

    // Sezioni della sidebar: la freccia apre e chiude, il link resta un link.
    (function () {
        var sidebar = document.getElementById('ip-sidebar');
        if (!sidebar) { return; }

        var STORAGE_KEY = 'ip-nav-closed';

        function readClosed() {
            try {
                return JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '[]');
            } catch (e) {
                return [];
            }
        }

        function writeClosed(list) {
            try {
                window.localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
            } catch (e) {
                // Storage non disponibile: le sezioni tornano semplicemente
                // allo stato di partenza a ogni pagina.
            }
        }

        // La chiusura viene ricordata per nome della sezione: gli id dei
        // pannelli sono progressivi e cambierebbero se l'elenco delle voci
        // cambia (es. progetti disattivati), riaprendo sezioni a caso.
        function keyOf(item) {
            var link = item.querySelector('.ip-nav-link span');
            return link ? link.textContent.trim() : '';
        }

        var closed = readClosed();
        var scroller = sidebar.querySelector('.ip-sidebar-scroll');

        sidebar.querySelectorAll('.ip-nav-item').forEach(function (item) {
            var button = item.querySelector('[data-nav-toggle]');
            var panel  = item.querySelector('.ip-nav-sub');
            if (!button || !panel) { return; }

            // Quando la sezione chiusa contiene la pagina corrente, la voce
            // madre resta accesa: la sotto-voce è nascosta e senza questo il
            // menu non direbbe più dove ci si trova.
            function syncClosedCurrent(isClosed) {
                var link  = item.querySelector('.ip-nav-link');
                var child = panel.querySelector('.ip-nav-sub-link.active');
                if (!link || !child) { return; }
                if (isClosed) {
                    link.classList.add('is-current');
                    link.setAttribute('aria-current', 'true');
                } else {
                    link.classList.remove('is-current');
                    link.removeAttribute('aria-current');
                }
            }

            // Una sezione chiusa a mano resta chiusa anche se è quella della
            // pagina corrente: proprio lì le scorciatoie servono meno, ed è la
            // sezione che si chiude più volentieri. Il punto in cui ci si trova
            // resta comunque leggibile dall'evidenziazione della voce.
            if (closed.indexOf(keyOf(item)) !== -1) {
                panel.hidden = true;
                button.setAttribute('aria-expanded', 'false');
                syncClosedCurrent(true);
            }

            button.addEventListener('click', function () {
                var open = panel.hidden;

                // Posizione della freccia prima del cambio: aprendo o chiudendo
                // un pannello l'altezza della colonna cambia e il browser
                // ricalcola lo scroll del menu, che cosi' saltava e la voce
                // premuta si spostava sotto il puntatore. Qui si riporta la
                // freccia esattamente dov'era, sia in apertura sia in chiusura.
                var before = button.getBoundingClientRect().top;

                panel.hidden = !open;
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
                syncClosedCurrent(panel.hidden);

                if (scroller) {
                    scroller.scrollTop += button.getBoundingClientRect().top - before;
                }

                var key  = keyOf(item);
                var list = readClosed().filter(function (k) { return k !== key; });

                if (!open) { list.push(key); }

                writeClosed(list);
            });
        });
    })();
</script>

<div id="main-area">
    <!-- <main>: landmark "main" per screen reader; tabindex=-1 per ricevere
         il focus programmatico dopo la navigazione (vedi ip-skeleton.js) -->
    <main id="main-content" tabindex="-1">
        <?= $this->include('layout/skeleton') ?>
        <?= $this->include('layout/alerts') ?>
        <?= $this->renderSection('content') ?>
    </main>
</div>

<div id="modal-placeholder"></div>

<?= $this->include('layout/confirm_modal') ?>

<script defer src="<?php _core_asset('js/ip-app.min.js'); ?>"></script>
</body>
</html>