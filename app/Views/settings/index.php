<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('settings')); ?>

<script>
    // Salva l'hash originale prima che Bootstrap lo sovrascriva col tab attivo.
    var _originalHash = location.hash;
    $(function () {
        // Mostra solo i gruppi di campi coerenti col canale SdI scelto:
        // evita di mostrare inutilmente le credenziali PEC, API o IMAP.
        toggle_sdi_settings();

        $('select[name="settings[sdi_channel]"]').change(function () {
            toggle_sdi_settings();
        });

        function toggle_sdi_settings() {
            var channel = $('select[name="settings[sdi_channel]"]').val();

            $('.sdi-group').each(function () {
                var matched = String($(this).data('channels')).split(',').indexOf(channel) !== -1;
                $(this).toggle(matched);
                // I controlli nascosti vanno disabilitati: cosi' il salvataggio
                // non ne azzera i valori gia' memorizzati (es. password).
                $(this).find('input, select, textarea').prop('disabled', !matched);
            });
        }

        // Filtro live delle impostazioni: nasconde i campi il cui testo
        // (etichetta + aiuto) non contiene la query e le sezioni rimaste vuote.
        // Prima si fermava qui: se la scheda aperta non aveva corrispondenze la
        // pagina sembrava vuota, e i risultati restavano in una scheda che
        // l'utente non stava guardando. Ora il filtro apre la prima scheda con
        // un risultato e, se non ce n'è nessuno, lo dice.
        var $search   = $('#settings-search');
        var $empty    = $('#settings-search-empty');
        var $fields   = $('.ip-doc-field');
        var startPane = null;

        // Pannello con almeno un campo corrispondente. Non si usa :visible:
        // i campi dei pannelli chiusi sono display:none anche quando contano.
        function paneWithHits() {
            return $('.tab-pane').filter(function () {
                return $(this).find('.ip-doc-field.ip-search-hit').length > 0;
            }).first();
        }

        function showPane(id) {
            var button = document.getElementById('tab-' + id);
            if (!button || !window.bootstrap) { return; }
            bootstrap.Tab.getOrCreateInstance(button).show();
        }

        $search.on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
            }
        });
        $search.on('input', function () {
            var q = $.trim($(this).val()).toLowerCase();

            if (q === '') {
                $fields.removeClass('ip-search-hit').show();
                $('.ip-doc-section').show();
                $empty.prop('hidden', true);
                toggle_sdi_settings();
                // Si torna alla scheda da cui si era partiti con la ricerca.
                if (startPane) { showPane(startPane); startPane = null; }
                return;
            }

            if (!startPane) {
                var activePane = document.querySelector('.tab-pane.active');
                if (activePane) { startPane = activePane.id; }
            }

            $fields.each(function () {
                var hit = $(this).text().toLowerCase().indexOf(q) !== -1;
                $(this).toggleClass('ip-search-hit', hit).toggle(hit);
            });
            $('.ip-doc-section').each(function () {
                $(this).toggle($(this).find('.ip-doc-field.ip-search-hit').length > 0);
            });

            var $pane = paneWithHits();
            $empty.prop('hidden', $pane.length > 0);
            if ($pane.length) { showPane($pane[0].id); }
        });
    });
</script>

<form method="POST" action="<?= site_url('settings') ?>" id="form-settings" enctype="multipart/form-data">

    <?= csrf_field() ?>

    <?= $this->include('layout/headerbar', [
        'title'   => trans('settings'),
        'actions' => header_actions(['hide_cancel_button' => true]),
        'filters' => '<div class="ip-settings-search">'
            . '<i class="fa fa-solid fa-search ip-settings-search__icon" aria-hidden="true"></i>'
            . '<input type="search" id="settings-search" class="form-control form-control-sm"'
            . ' placeholder="' . esc(trans('search_settings', '', 'Filtra impostazioni…')) . '">'
            . '</div>',
    ]) ?>

    <?php /* L'ordine delle schede segue l'ordine dei pannelli piu' sotto: prima
             "Imposte" stava fra Preventivi ed Email nella barra ma il suo
             pannello era penultimo nel markup, quindi con le frecce da tastiera
             l'ordine di lettura non corrispondeva a quello visibile. */ ?>
    <?= $this->include('layout/tabs', [
        'id'   => 'settings-tabs',
        'tabs' => [
            ['id' => 'settings-general',        'label' => trans('general')],
            ['id' => 'settings-invoices',       'label' => trans('invoices')],
            ['id' => 'settings-quotes',         'label' => trans('quotes')],
            ['id' => 'settings-taxes',          'label' => trans('taxes')],
            ['id' => 'settings-projects-tasks', 'label' => trans('projects')],
        ],
    ]) ?>

    <div id="content">
            <div class="tab-content">

                <?php
                $firstDays = ['Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato'];
                ?>

                <div id="settings-general" class="tab-pane active" role="tabpanel" aria-labelledby="tab-settings-general" tabindex="0">
                <div class="ip-settings-pane">

                <p class="ip-settings-intro"><?= trans('settings_general_intro', '', 'Lingua, formato di date e importi, aspetto dell\'interfaccia e impostazioni di sistema.') ?></p>

                <!-- Generali -->
                <section class="ip-doc-section">
                    <div class="ip-doc-section__head">
                        <h2 class="ip-doc-section__title"><i class="fa fa-solid fa-earth" aria-hidden="true"></i> <?= trans('general') ?></h2>
                    </div>
                    <div class="ip-doc-section__body">

                        <div class="row">
                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[default_language]">
                                        <?= trans('language') ?>
                                    </label>
                                    <select name="settings[default_language]" id="settings[default_language]"
                                            class="form-control simple-select">
                                        <option value="it" <?= check_select(get_setting('default_language'), 'it') ?>>Italiano</option>
                                        <option value="en" <?= check_select(get_setting('default_language'), 'en') ?>>English</option>
                                    </select>
                                </div>

                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[first_day_of_week]">
                                        <?= trans('first_day_of_week') ?>
                                    </label>
                                    <select name="settings[first_day_of_week]" id="settings[first_day_of_week]"
                                            class="form-control simple-select" data-minimum-results-for-search="Infinity">
                                        <?php foreach ($firstDays as $dayIndex => $dayName): ?>
                                            <option value="<?= $dayIndex ?>" <?= check_select(get_setting('first_day_of_week'), (string) $dayIndex) ?>>
                                                <?= $dayName ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[default_country]">
                                        <?= trans('default_country') ?>
                                    </label>
                                    <select name="settings[default_country]" id="settings[default_country]"
                                            class="form-control simple-select">
                                        <?php foreach ($countries as $cldr => $country): ?>
                                            <option value="<?= esc($cldr) ?>" <?= check_select(get_setting('default_country'), $cldr) ?>>
                                                <?= esc($country) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[date_format]">
                                        <?= trans('date_format') ?>
                                    </label>
                                    <select name="settings[date_format]" id="settings[date_format]"
                                            class="form-control simple-select">
                                        <?php foreach ($date_formats as $dateFormat): ?>
                                            <option value="<?= esc($dateFormat['setting']) ?>" <?= check_select(get_setting('date_format'), $dateFormat['setting']) ?>>
                                                <?= esc($current_date->format($dateFormat['setting'])) ?>
                                                (<?= esc($dateFormat['setting']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="default_list_limit">
                                        <?= trans('default_list_limit') ?>
                                    </label>
                                    <input type="number" name="settings[default_list_limit]" id="default_list_limit"
                                           class="form-control" minlength="1" min="1" required
                                           value="<?= esc(old('settings[default_list_limit]', get_setting('default_list_limit', '15'))) ?>">
                                </div>
                            </div>
                        </div>

                </div>
                </section>

                <!-- Importi -->
                <section class="ip-doc-section">
                    <div class="ip-doc-section__head">
                        <h2 class="ip-doc-section__title"><i class="fa fa-solid fa-money-bill-1" aria-hidden="true"></i> <?= trans('amount_settings') ?></h2>
                    </div>
                    <div class="ip-doc-section__body">

                        <div class="row">
                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[currency_symbol]">
                                        <?= trans('currency_symbol') ?>
                                    </label>
                                    <input type="text" name="settings[currency_symbol]" id="settings[currency_symbol]"
                                           class="form-control" autocomplete="off"
                                           value="<?= esc(old('settings[currency_symbol]', currency_symbol())) ?>">
                                </div>

                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[currency_code]">
                                        <?= trans('currency_code') ?>
                                    </label>
                                    <select name="settings[currency_code]" id="settings[currency_code]"
                                            class="form-control simple-select">
                                        <?php foreach ($currencies as $currency): ?>
                                            <option value="<?= esc($currency) ?>" <?= check_select(get_setting('currency_code'), $currency) ?>>
                                                <?= esc($currency) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[number_format]">
                                        <?= trans('number_format') ?>
                                    </label>
                                    <select name="settings[number_format]" id="settings[number_format]"
                                            class="form-control simple-select" data-minimum-results-for-search="Infinity">
                                        <?php foreach ($number_formats as $formatKey => $format): ?>
                                            <option value="<?= esc($formatKey) ?>" <?= check_select(get_setting('number_format', 'it_IT'), $formatKey) ?>>
                                                <?= esc($formatKey) ?> (<?= esc($format['decimal_point'] . ' / ' . $format['thousands_separator']) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[currency_symbol_placement]">
                                        <?= trans('currency_symbol_placement') ?>
                                    </label>
                                    <select name="settings[currency_symbol_placement]" id="settings[currency_symbol_placement]"
                                            class="form-control simple-select" data-minimum-results-for-search="Infinity">
                                        <option value="before" <?= check_select(get_setting('currency_symbol_placement'), 'before') ?>>
                                            <?= trans('before_amount') ?>
                                        </option>
                                        <option value="beforespace" <?= check_select(get_setting('currency_symbol_placement'), 'beforespace') ?>>
                                            <?= trans('before_amount_space') ?>
                                        </option>
                                        <option value="after" <?= check_select(get_setting('currency_symbol_placement'), 'after') ?>>
                                            <?= trans('after_amount') ?>
                                        </option>
                                        <option value="afterspace" <?= check_select(get_setting('currency_symbol_placement'), 'afterspace') ?>>
                                            <?= trans('after_amount_space') ?>
                                        </option>
                                    </select>
                                </div>


                            </div>
                        </div>

                </div>
                </section>

                <!-- Aspetto -->
                <section class="ip-doc-section">
                    <div class="ip-doc-section__head">
                        <h2 class="ip-doc-section__title"><i class="fa fa-solid fa-desktop" aria-hidden="true"></i> <?= trans('interface') ?></h2>
                    </div>
                    <div class="ip-doc-section__body">

                        <div class="row">
                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="monospace_amounts">
                                        <?= trans('monospaced_font_for_amounts') ?>
                                    </label>
                                    <div class="form-check">
                                        <input type="hidden" name="settings[monospace_amounts]" value="0">
                                        <input type="checkbox" class="form-check-input" name="settings[monospace_amounts]" value="1"
                                               id="monospace_amounts"
                                            <?= check_select(get_setting('monospace_amounts'), '1', '==', true) ?>>
                                        <label class="form-check-label" for="monospace_amounts"><?= trans('yes') ?></label>
                                    </div>
                                    <p class="ip-field-help">
                                        <?= trans('example') ?>:
                                        <span style="font-family: var(--ip-mono)">
                                            <?= format_currency(123456.78) ?>
                                        </span>
                                    </p>
                                </div>

<div class="ip-doc-field">
                                    <label class="ip-doc-label" for="login_logo">
                                        <?= trans('login_logo') ?>
                                    </label>
                                    <input type="file" name="login_logo" id="login_logo" class="ip-file-input"
                                           accept="image/gif,image/jpeg,image/png,image/svg+xml"
                                           data-help="<?= esc(trans('login_logo_help', '', 'Formati supportati: GIF, JPEG, PNG, SVG. Dimensione massima consigliata: 300x100px.')) ?>">
                                </div>

<div class="ip-doc-field">
                                    <label class="ip-doc-label" for="login_logo_dark">
                                        <?= trans('login_logo_dark', '', 'Logo login (tema scuro)') ?>
                                    </label>
                                    <input type="file" name="login_logo_dark" id="login_logo_dark" class="ip-file-input"
                                           accept="image/gif,image/jpeg,image/png,image/svg+xml"
                                           data-help="<?= esc(trans('login_logo_dark_help', '', 'Versione chiara del marchio, mostrata quando l\'interfaccia è in tema scuro. Se non la carichi vale il logo login per entrambi i temi.')) ?>">
                                </div>
                            </div>

                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[custom_title]">
                                        <?= trans('custom_title') ?>
                                    </label>
                                    <input type="text" name="settings[custom_title]" id="settings[custom_title]"
                                           class="form-control"
                                           value="<?= esc(old('settings[custom_title]', get_setting('custom_title'))) ?>">
                                </div>

                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[custom_subtitle]">
                                        <?= trans('custom_subtitle') ?>
                                    </label>
                                    <input type="text" name="settings[custom_subtitle]" id="settings[custom_subtitle]"
                                           class="form-control"
                                           value="<?= esc(old('settings[custom_subtitle]', get_setting('custom_subtitle'))) ?>">
                                    <p class="ip-field-help">
                                        <?= trans('custom_subtitle_help', '', 'Frase mostrata sotto il titolo nella barra in alto (es. "di Nome Cognome").') ?>
                                    </p>
                                </div>


                            </div>
                        </div>

                </div>
                </section>

                <!-- Sistema -->
                <section class="ip-doc-section">
                    <div class="ip-doc-section__head">
                        <h2 class="ip-doc-section__title"><i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('system_settings') ?></h2>
                    </div>
                    <div class="ip-doc-section__body">

                        <div class="row">
                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="cron_key">
                                        <?= trans('cron_key') ?>
                                    </label>
                                    <div class="input-group">
                                        <input type="text" name="settings[cron_key]" id="cron_key" class="form-control"
                                               readonly value="<?= esc(get_setting('cron_key')) ?>">
                                        <button id="btn_generate_cron_key" type="button" class="btn btn-primary">
                                            <i class="fa fa-solid fa-recycle fa-margin" aria-hidden="true"></i> <?= trans('generate') ?>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="calendar_feed_url">
                                        <?= trans('calendar_feed', '', 'Feed calendario scadenze') ?>
                                    </label>
                                    <div class="input-group">
                                        <input type="text" id="calendar_feed_url" class="form-control" readonly
                                                placeholder="<?= esc(trans('calendar_feed_generate_first', '', 'Genera l\'URL per attivare il feed'), 'attr') ?>"
                                                value="<?= esc($calendar_feed_url) ?>">
                                        <button id="btn_copy_calendar_feed" type="button" class="btn btn-secondary"
                                                title="<?= esc(trans('copy', '', 'Copia'), 'attr') ?>">
                                            <i class="fa fa-solid fa-copy" aria-hidden="true"></i>
                                        </button>
                                        <button id="btn_generate_calendar_feed" type="button" class="btn btn-primary">
                                            <i class="fa fa-solid fa-recycle fa-margin" aria-hidden="true"></i> <?= trans('generate') ?>
                                        </button>
                                    </div>
                                    <p class="ip-field-help">
                                        <?= trans('calendar_feed_help', '', 'URL di sola lettura con fatture in scadenza, F24 e attività. In Google Calendar: "Aggiungi altri calendari" > "Da URL". In un client CalDAV (Apple Calendario, Thunderbird, Nextcloud...): sottoscrivi calendario da URL.') ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                </div>
                </section>

                </div>
                </div>

                <div id="settings-invoices" class="tab-pane" role="tabpanel" aria-labelledby="tab-settings-invoices" tabindex="0">
                <div class="ip-settings-pane">

                <p class="ip-settings-intro"><?= trans('settings_invoices_intro', '', 'Dati predefiniti delle fatture, stampa PDF e modelli.') ?></p>

                <!-- Fatture -->
                <section class="ip-doc-section">
                    <div class="ip-doc-section__head">
                        <h2 class="ip-doc-section__title"><i class="fa fa-regular fa-file-text" aria-hidden="true"></i> <?= trans('invoices') ?></h2>
                    </div>
                    <div class="ip-doc-section__body">

                        <div class="row">
                            <div class="col-12 col-md-6">
                                <div class="alert alert-info">
                                    <?= trans('numbering_moved_to_company', '', 'Le serie di numerazione predefinite (documenti e bozze) sono ora impostate per azienda: le trovi nella scheda dell\'azienda, perche\' ogni azienda ha le proprie serie.') ?>
                                    <a href="<?= site_url('companies') ?>"><?= trans('companies', '', 'Aziende') ?></a>
                                </div>

                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[default_invoice_terms]">
                                        <?= trans('default_terms') ?>
                                    </label>
                                    <textarea name="settings[default_invoice_terms]" id="settings[default_invoice_terms]"
                                              class="form-control" rows="4"><?= esc(old('settings[default_invoice_terms]', get_setting('default_invoice_terms'))) ?></textarea>
                                </div>
                            </div>

                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[invoice_default_payment_method]">
                                        <?= trans('default_payment_method') ?>
                                    </label>
                                    <select name="settings[invoice_default_payment_method]" class="form-control simple-select"
                                            id="settings[invoice_default_payment_method]" data-minimum-results-for-search="Infinity">
                                        <option value=""><?= trans('none') ?></option>
                                        <?php foreach ($payment_methods as $paymentMethod): ?>
                                            <option value="<?= esc($paymentMethod['payment_method_id']) ?>" <?= check_select(get_setting('invoice_default_payment_method'), $paymentMethod['payment_method_id']) ?>>
                                                <?= esc($paymentMethod['payment_method_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[invoices_due_after]">
                                        <?= trans('invoices_due_after') ?>
                                    </label>
                                    <input type="number" name="settings[invoices_due_after]" id="settings[invoices_due_after]"
                                           class="form-control"
                                           value="<?= esc(old('settings[invoices_due_after]', get_setting('invoices_due_after'))) ?>">
                                </div>

                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[generate_invoice_number_for_draft]">
                                        <?= trans('generate_invoice_number_for_draft') ?>
                                    </label>
                                    <div class="form-check">
                                        <input type="hidden" name="settings[generate_invoice_number_for_draft]" value="0">
                                        <input type="checkbox" class="form-check-input" name="settings[generate_invoice_number_for_draft]" value="1"
                                               id="settings[generate_invoice_number_for_draft]"
                                            <?= check_select(get_setting('generate_invoice_number_for_draft'), '1', '==', true) ?>>
                                        <label class="form-check-label" for="settings[generate_invoice_number_for_draft]"><?= trans('yes') ?></label>
                                    </div>
                                </div>

                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[automatic_email_on_recur]">
                                        <?= trans('automatic_email_on_recur') ?>
                                    </label>
                                    <div class="form-check">
                                        <input type="hidden" name="settings[automatic_email_on_recur]" value="0">
                                        <input type="checkbox" class="form-check-input" name="settings[automatic_email_on_recur]" value="1"
                                               id="settings[automatic_email_on_recur]"
                                            <?= check_select(get_setting('automatic_email_on_recur'), '1', '==', true) ?>>
                                        <label class="form-check-label" for="settings[automatic_email_on_recur]"><?= trans('yes') ?></label>
                                    </div>
                                </div>

                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[read_only_toggle]">
                                        <?= trans('set_to_read_only') ?>
                                    </label>
                                    <select name="settings[read_only_toggle]" id="settings[read_only_toggle]"
                                            class="form-control simple-select" data-minimum-results-for-search="Infinity">
                                        <option value="0" <?= check_select(get_setting('read_only_toggle'), '0') ?>>Disattivato</option>
                                        <option value="1" <?= check_select(get_setting('read_only_toggle'), '1') ?>>Bozza</option>
                                        <option value="2" <?= check_select(get_setting('read_only_toggle'), '2') ?>>
                                            <?= trans('sent') ?>
                                        </option>
                                        <option value="3" <?= check_select(get_setting('read_only_toggle'), '3') ?>>
                                            <?= trans('viewed') ?>
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>

                </div>
                </section>

                <section class="ip-doc-section">
                    <div class="ip-doc-section__head">
                        <h2 class="ip-doc-section__title"><i class="fa fa-regular fa-file-pdf" aria-hidden="true"></i> <?= trans('pdf_settings') ?></h2>
                    </div>
                    <div class="ip-doc-section__body">

                        <div class="row">
                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[mark_invoices_sent_pdf]">
                                        <?= trans('mark_invoices_sent_pdf') ?>
                                    </label>
                                    <div class="form-check">
                                        <input type="hidden" name="settings[mark_invoices_sent_pdf]" value="0">
                                        <input type="checkbox" class="form-check-input" name="settings[mark_invoices_sent_pdf]" value="1"
                                               id="settings[mark_invoices_sent_pdf]"
                                            <?= check_select(get_setting('mark_invoices_sent_pdf'), '1', '==', true) ?>>
                                        <label class="form-check-label" for="settings[mark_invoices_sent_pdf]"><?= trans('yes') ?></label>
                                    </div>
                                </div>

                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[enable_invoice_deletion]">
                                        <?= trans('enable_invoice_deletion', '', 'Consenti cancellazione fatture non in bozza') ?>
                                    </label>
                                    <div class="form-check">
                                        <input type="hidden" name="settings[enable_invoice_deletion]" value="0">
                                        <input type="checkbox" class="form-check-input" name="settings[enable_invoice_deletion]" value="1"
                                               id="settings[enable_invoice_deletion]"
                                            <?= check_select(get_setting('enable_invoice_deletion'), '1', '==', true) ?>>
                                        <label class="form-check-label" for="settings[enable_invoice_deletion]"><?= trans('yes') ?></label>
                                    </div>
                                    <p class="ip-field-help"><?= trans('enable_invoice_deletion_hint', '', 'Se disattivato, solo le bozze possono essere eliminate.') ?></p>
                                </div>

                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[invoice_pre_password]">
                                        <?= trans('invoice_pre_password') ?>
                                    </label>
                                    <input type="password" name="settings[invoice_pre_password]"
                                           id="settings[invoice_pre_password]" class="form-control"
                                           autocomplete="new-password" value="">
                                    <input type="hidden" name="settings[invoice_pre_password_field_is_password]" value="1">
                                </div>

                            </div>

                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[pdf_watermark]">
                                        <?= trans('pdf_watermark') ?>
                                    </label>
                                    <input type="text" name="settings[pdf_watermark]" id="settings[pdf_watermark]"
                                           class="form-control"
                                           value="<?= esc(old('settings[pdf_watermark]', get_setting('pdf_watermark'))) ?>">
                                </div>

<div class="ip-doc-field">
                                    <label class="ip-doc-label" for="invoice_logo"><?= trans('invoice_logo', '', 'Logo (intestazione)') ?></label>
                                    <input type="file" name="invoice_logo" id="invoice_logo" class="ip-file-input"
                                           accept="image/gif,image/jpeg,image/png,image/svg+xml"
                                           data-help="<?= esc(trans('invoice_logo_help', '', 'Formati supportati: GIF, JPEG, PNG, SVG. Dimensione massima consigliata: 300x100px.')) ?>">
                                </div>

<div class="ip-doc-field">
                                    <label class="ip-doc-label" for="invoice_logo_footer"><?= trans('invoice_logo_footer', '', 'Logo (piè di pagina)') ?></label>
                                    <input type="file" name="invoice_logo_footer" id="invoice_logo_footer" class="ip-file-input"
                                           accept="image/gif,image/jpeg,image/png,image/svg+xml"
                                           data-help="<?= esc(trans('invoice_logo_footer_help', '', 'Versione del logo stampata in fondo al documento: di solito monocromatica o estesa. Se non caricata, il piè di pagina resta senza logo.')) ?>">
                                </div>
                            </div>
                        </div>

                </div>
                </section>

                <section class="ip-doc-section">
                    <div class="ip-doc-section__head">
                        <h2 class="ip-doc-section__title"><i class="fa fa-regular fa-copy" aria-hidden="true"></i> <?= trans('invoice_templates') ?></h2>
                    </div>
                    <div class="ip-doc-section__body">

                        <?php // Solo i template effettivamente letti da Mailer/Cron/Guest
                            // (gli altri 3 — paid/public — non sono mai get_setting-ati)
                            $invoiceTemplateKeys = [
                            'pdf_invoice_template'           => trans('default_pdf_template'),
                            'pdf_invoice_template_overdue'   => trans('pdf_template_overdue'),
                            'email_invoice_template'         => trans('default_email_template'),
                            'email_invoice_template_overdue' => trans('email_template_overdue'),
                        ]; ?>

                        <div class="row">
                            <?php $half = (int) ceil(count($invoiceTemplateKeys) / 2); ?>
                            <?php foreach (array_chunk($invoiceTemplateKeys, $half, true) as $chunk): ?>
                                <div class="col-12 col-md-6">
                                    <?php foreach ($chunk as $templateKey => $templateLabel): ?>
                                        <div class="ip-doc-field">
                                            <label class="ip-doc-label" for="settings[<?= $templateKey ?>]">
                                                <?= $templateLabel ?>
                                            </label>
                                            <select name="settings[<?= $templateKey ?>]" id="settings[<?= $templateKey ?>]"
                                                    class="form-control simple-select" data-minimum-results-for-search="Infinity">
                                                <option value=""><?= trans('none') ?></option>
                                                <?php foreach ($templates as $templateKeyValue => $templateName): ?>
                                                    <option value="<?= esc($templateKeyValue) ?>" <?= check_select(get_setting($templateKey), $templateKeyValue) ?>>
                                                        <?= esc($templateName) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="row">
                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[pdf_invoice_footer]">
                                        <?= trans('pdf_invoice_footer') ?>
                                    </label>
                                    <textarea name="settings[pdf_invoice_footer]" id="settings[pdf_invoice_footer]"
                                              class="form-control no-margin"><?= esc(old('settings[pdf_invoice_footer]', get_setting('pdf_invoice_footer'))) ?></textarea>
                                    <p class="ip-field-help"><?= trans('pdf_invoice_footer_hint') ?></p>
                                </div>
                            </div>
                        </div>

                    </div>

                </section>

                <?= $this->include('settings/partial_sdi') ?>

                </div>
                </div>

                <div id="settings-quotes" class="tab-pane" role="tabpanel" aria-labelledby="tab-settings-quotes" tabindex="0">
                <div class="ip-settings-pane">

                <p class="ip-settings-intro"><?= trans('settings_quotes_intro', '', 'Dati predefiniti dei preventivi, stampa PDF e modelli.') ?></p>

                <!-- Preventivi -->
                <section class="ip-doc-section">
                    <div class="ip-doc-section__head">
                        <h2 class="ip-doc-section__title"><i class="fa fa-regular fa-file" aria-hidden="true"></i> <?= trans('quote') ?></h2>
                    </div>
                    <div class="ip-doc-section__body">

                        <div class="row">
                            <div class="col-12 col-md-6">
                                <div class="alert alert-info">
                                    <?= trans('numbering_moved_to_company', '', 'Le serie di numerazione predefinite (documenti e bozze) sono ora impostate per azienda: le trovi nella scheda dell\'azienda, perche\' ogni azienda ha le proprie serie.') ?>
                                    <a href="<?= site_url('companies') ?>"><?= trans('companies', '', 'Aziende') ?></a>
                                </div>

                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[default_quote_notes]">
                                        <?= trans('default_notes') ?>
                                    </label>
                                    <textarea name="settings[default_quote_notes]" id="settings[default_quote_notes]" rows="3"
                                              class="form-control"><?= esc(old('settings[default_quote_notes]', get_setting('default_quote_notes'))) ?></textarea>
                                </div>
                            </div>

                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[quotes_expire_after]">
                                        <?= trans('quotes_expire_after') ?>
                                    </label>
                                    <input type="number" name="settings[quotes_expire_after]" id="settings[quotes_expire_after]"
                                           class="form-control"
                                           value="<?= esc(old('settings[quotes_expire_after]', get_setting('quotes_expire_after'))) ?>">
                                </div>

                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[generate_quote_number_for_draft]">
                                        <?= trans('generate_quote_number_for_draft') ?>
                                    </label>
                                    <div class="form-check">
                                        <input type="hidden" name="settings[generate_quote_number_for_draft]" value="0">
                                        <input type="checkbox" class="form-check-input" name="settings[generate_quote_number_for_draft]" value="1"
                                               id="settings[generate_quote_number_for_draft]"
                                            <?= check_select(get_setting('generate_quote_number_for_draft'), '1', '==', true) ?>>
                                        <label class="form-check-label" for="settings[generate_quote_number_for_draft]"><?= trans('yes') ?></label>
</div>
                            </div>
                        </div>

                </div>
                </section>

                <section class="ip-doc-section">
                    <div class="ip-doc-section__head">
                        <h2 class="ip-doc-section__title"><i class="fa fa-regular fa-file-pdf" aria-hidden="true"></i> <?= trans('pdf_settings') ?></h2>
                    </div>
                    <div class="ip-doc-section__body">

                        <div class="row">
                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[mark_quotes_sent_pdf]">
                                        <?= trans('mark_quotes_sent_pdf') ?>
                                    </label>
                                    <div class="form-check">
                                        <input type="hidden" name="settings[mark_quotes_sent_pdf]" value="0">
                                        <input type="checkbox" class="form-check-input" name="settings[mark_quotes_sent_pdf]" value="1"
                                               id="settings[mark_quotes_sent_pdf]"
                                            <?= check_select(get_setting('mark_quotes_sent_pdf'), '1', '==', true) ?>>
                                        <label class="form-check-label" for="settings[mark_quotes_sent_pdf]"><?= trans('yes') ?></label>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[quote_pre_password]">
                                        <?= trans('quote_pre_password') ?>
                                    </label>
                                    <input type="password" name="settings[quote_pre_password]"
                                           id="settings[quote_pre_password]" class="form-control"
                                           autocomplete="new-password" value="">
                                    <input type="hidden" name="settings[quote_pre_password_field_is_password]" value="1">
                                </div>
                            </div>
                        </div>

                </div>
                </section>

                <section class="ip-doc-section">
                    <div class="ip-doc-section__head">
                        <h2 class="ip-doc-section__title"><i class="fa fa-regular fa-copy" aria-hidden="true"></i> <?= trans('quote_templates') ?></h2>
                    </div>
                    <div class="ip-doc-section__body">

                        <?php // public_quote_template mai letto (Guest usa pdf_*) — rimosso
                            $quoteTemplateKeys = [
                            'pdf_quote_template'   => trans('default_pdf_template'),
                            'email_quote_template' => trans('default_email_template'),
                        ]; ?>

                        <div class="row">
                            <?php $half = (int) ceil(count($quoteTemplateKeys) / 2); ?>
                            <?php foreach (array_chunk($quoteTemplateKeys, $half, true) as $chunk): ?>
                                <div class="col-12 col-md-6">
                                    <?php foreach ($chunk as $templateKey => $templateLabel): ?>
                                        <div class="ip-doc-field">
                                            <label class="ip-doc-label" for="settings[<?= $templateKey ?>]">
                                                <?= $templateLabel ?>
                                            </label>
                                            <select name="settings[<?= $templateKey ?>]" id="settings[<?= $templateKey ?>]"
                                                    class="form-control simple-select" data-minimum-results-for-search="Infinity">
                                                <option value=""><?= trans('none') ?></option>
                                                <?php foreach ($templates as $templateKeyValue => $templateName): ?>
                                                    <option value="<?= esc($templateKeyValue) ?>" <?= check_select(get_setting($templateKey), $templateKeyValue) ?>>
                                                        <?= esc($templateName) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="row">
                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[pdf_quote_footer]">
                                        <?= trans('pdf_quote_footer') ?>
                                    </label>
                                    <textarea name="settings[pdf_quote_footer]" id="settings[pdf_quote_footer]"
                                              class="form-control no-margin"><?= esc(old('settings[pdf_quote_footer]', get_setting('pdf_quote_footer'))) ?></textarea>
                                    <p class="ip-field-help"><?= trans('pdf_quote_footer_hint') ?></p>
                                </div>
                            </div>
                        </div>

                </div>
                </section>

                </div>
                </div>

                <div id="settings-taxes" class="tab-pane" role="tabpanel" aria-labelledby="tab-settings-taxes" tabindex="0">
                <div class="ip-settings-pane">

                <p class="ip-settings-intro"><?= trans('settings_taxes_intro', '', 'Aliquote proposte sui nuovi documenti e sulle righe, e ordine con cui si applicano.') ?></p>

                <!-- Tasse -->
                <section class="ip-doc-section">
                    <div class="ip-doc-section__head">
                        <h2 class="ip-doc-section__title"><i class="fa fa-solid fa-percent" aria-hidden="true"></i> <?= trans('taxes') ?></h2>
                    </div>
                    <div class="ip-doc-section__body">

                        <div class="row">
                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[default_invoice_tax_rate]">
                                        <?= trans('default_invoice_tax_rate') ?>
                                    </label>
                                    <select name="settings[default_invoice_tax_rate]" id="settings[default_invoice_tax_rate]"
                                            class="form-control simple-select">
                                        <option value=""><?= trans('none') ?></option>
                                        <?php foreach ($tax_rates as $taxRate): ?>
                                            <option value="<?= esc($taxRate['tax_rate_id']) ?>" <?= check_select(get_setting('default_invoice_tax_rate'), $taxRate['tax_rate_id']) ?>>
                                                <?= esc($taxRate['tax_rate_percent'] . '% - ' . $taxRate['tax_rate_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[default_item_tax_rate]">
                                        <?= trans('default_item_tax_rate') ?>
                                    </label>
                                    <select name="settings[default_item_tax_rate]" id="settings[default_item_tax_rate]"
                                            class="form-control simple-select">
                                        <option value=""><?= trans('none') ?></option>
                                        <?php foreach ($tax_rates as $taxRate): ?>
                                            <option value="<?= esc($taxRate['tax_rate_id']) ?>" <?= check_select(get_setting('default_item_tax_rate'), $taxRate['tax_rate_id']) ?>>
                                                <?= esc($taxRate['tax_rate_percent'] . '% - ' . $taxRate['tax_rate_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[default_include_item_tax]">
                                        <?= trans('default_invoice_tax_rate_placement') ?>
                                    </label>
                                    <select name="settings[default_include_item_tax]" id="settings[default_include_item_tax]"
                                            class="form-control simple-select" data-minimum-results-for-search="Infinity">
                                        <option value="0" <?= check_select(get_setting('default_include_item_tax'), '0') ?>>
                                            <?= trans('apply_before_item_tax') ?>
                                        </option>
                                        <option value="1" <?= check_select(get_setting('default_include_item_tax'), '1') ?>>
                                            <?= trans('apply_after_item_tax') ?>
                                        </option>
                                    </select>
                                </div>
                            </div>
                        </div>

                </div>
                </section>

                </div>
                </div>

                <div id="settings-projects-tasks" class="tab-pane" role="tabpanel" aria-labelledby="tab-settings-projects-tasks" tabindex="0">
                <div class="ip-settings-pane">

                <p class="ip-settings-intro"><?= trans('settings_projects_tasks_intro', '', 'Attivazione dei progetti e tariffa oraria predefinita delle attivita\'.') ?></p>

                <!-- Progetti e task -->
                <section class="ip-doc-section">
                    <div class="ip-doc-section__head">
                        <h2 class="ip-doc-section__title"><i class="fa fa-solid fa-bars-progress" aria-hidden="true"></i> <?= trans('projects') ?></h2>
                    </div>
                    <div class="ip-doc-section__body">

                        <div class="row">
                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[projects_enabled]">
                                        <?= trans('enable_projects') ?>
                                    </label>
                                    <div class="form-check">
                                        <input type="hidden" name="settings[projects_enabled]" value="0">
                                        <input type="checkbox" class="form-check-input" name="settings[projects_enabled]" value="1"
                                               id="settings[projects_enabled]"
                                            <?= check_select(get_setting('projects_enabled'), '1', '==', true) ?>>
                                        <label class="form-check-label" for="settings[projects_enabled]"><?= trans('yes') ?></label>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="ip-doc-label" for="settings[default_hourly_rate]">
                                        <?= trans('default_hourly_rate') ?>
                                    </label>
                                    <div class="ip-input-suffix">
                                        <input type="text" name="settings[default_hourly_rate]"
                                               id="settings[default_hourly_rate]" class="form-control amount"
                                               value="<?= esc(old('settings[default_hourly_rate]', format_amount(get_setting('default_hourly_rate')))) ?>">
                                        <span class="ip-input-suffix__mark"><?= esc(get_setting('currency_symbol')) ?></span>
                                    </div>
                                    <input type="hidden" name="settings[default_hourly_rate_field_is_amount]" value="1">
                                </div>
                            </div>
                        </div>

                </div>
                </section>

                </div>
                </div>


            </div>

            <?php /* Il filtro può non trovare nulla in nessuna scheda: senza un
                     messaggio la pagina resta vuota e sembra rotta. */ ?>
            <p id="settings-search-empty" class="ip-doc-empty" hidden>
                <?= trans('settings_search_empty', '', 'Nessuna impostazione corrisponde al filtro.') ?>
            </p>
    </div>

</form>

<script>
    (function () {
        // Precompila il simbolo di valuta in base alla valuta scelta: quando si
        // seleziona un codice noto, il campo simbolo si riempie da solo (a meno
        // che l'utente non abbia gia' digitato un simbolo personalizzato).
        var symbols = <?= json_encode($currency_symbols) ?>;
        var codeSelect = document.getElementById('settings[currency_code]');
        var symbolInput = document.getElementById('settings[currency_symbol]');

        if (codeSelect && symbolInput) {
            var precompila = function () {
                var sym = symbols[codeSelect.value];
                if (sym && !symbolInput.value.trim()) {
                    symbolInput.value = sym;
                }
            };
            precompila();
            codeSelect.addEventListener('change', function () {
                var sym = symbols[this.value];
                if (sym) {
                    symbolInput.value = sym;
                }
            });
        }
    })();

    (function () {
        var tokenName = '<?= csrf_token() ?>';
        var tokenInput = document.querySelector('input[name="' + tokenName + '"]');
        var cronInput  = document.getElementById('cron_key');
        var generateBtn = document.getElementById('btn_generate_cron_key');

        if (!tokenInput || !cronInput || !generateBtn) {
            return;
        }

        generateBtn.addEventListener('click', function () {
            var fd = new FormData();
            if (!fd.get(tokenName)) {
                fd.append(tokenName, tokenInput.value);
            }

            fetch('<?= site_url('settings/ajax/get_cron_key') ?>', {method: 'POST', body: fd})
                .then(function (r) {
                    return r.json();
                })
                .then(function (data) {
                    if (data && data.token) {
                        tokenInput.value = data.token;
                    }
                    if (data && data.key) {
                        cronInput.value = data.key;
                    }
                });
        });
    })();

    (function () {
        var tokenName    = '<?= csrf_token() ?>';
        var tokenInput   = document.querySelector('input[name="' + tokenName + '"]');
        var feedInput    = document.getElementById('calendar_feed_url');
        var generateBtn  = document.getElementById('btn_generate_calendar_feed');
        var copyBtn      = document.getElementById('btn_copy_calendar_feed');

        if (!tokenInput || !feedInput || !generateBtn) {
            return;
        }

        generateBtn.addEventListener('click', function () {
            var fd = new FormData();
            fd.append(tokenName, tokenInput.value);

            fetch('<?= site_url('settings/ajax/get_calendar_feed_token') ?>', {method: 'POST', body: fd})
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.token) {
                        tokenInput.value = data.token;
                    }
                    if (data && data.feed_url) {
                        feedInput.value = data.feed_url;
                    }
                });
        });

        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                if (!feedInput.value) { return; }
                feedInput.select();
                navigator.clipboard && navigator.clipboard.writeText(feedInput.value);
            });
        }
    })();

    (function () {
        // Modifiche non salvate: il form e' lungo (7 schede), un link toccato
        // per errore nella sidebar manda a monte tutto. L'avviso compare solo
        // se qualcosa e' davvero cambiato, e si azzera alla submit — dopo il
        // salvataggio si puo' uscire senza dialogo.
        var form = document.getElementById('form-settings');
        if (!form) { return; }

        var dirty = false;

        // Corrispettivo pjax di beforeunload: ip-pjax.js chiama questa prima
        // di smontare la pagina. Con la navigazione parziale beforeunload non
        // viene emesso (la pagina non si scarica), quindi senza questo una
        // modifica non salvata sparirebbe senza avviso.
        window.ipBeforeNavigate = function () {
            if (!dirty) { return true; }
            return window.confirm(<?= json_encode(trans('unsaved_changes_confirm', '', 'Ci sono modifiche non salvate. Uscire senza salvare?')) ?>);
        };

        form.addEventListener('input', function () { dirty = true; });
        form.addEventListener('change', function () { dirty = true; });

        ipOn(window, 'beforeunload', function (e) {
            if (!dirty) { return; }
            e.preventDefault();
            e.returnValue = '';
        });

        form.addEventListener('submit', function () { dirty = false; });

        // Salva con Cmd/Ctrl+S: il bottone e' in cima alla pagina, che con
        // sette schede e' spesso lontano da dove si sta lavorando.
        ipOn(document, 'keydown', function (e) {
            if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 's') {
                e.preventDefault();
                form.requestSubmit();
            }
        });
    })();

    (function () {
        // Arrivo con #cron_key / #calendar_feed_url nella URL (link "Gestisci"
        // dalla pagina calendario): lo scroll nativo non tiene conto dello
        // stack sticky e il campo finirebbe sotto navbar + headerbar + schede.
        // Lo scroll esplicito sottrae la stessa altezza usata dal cambio scheda.
        function scrollToHash() {
            var hash = _originalHash || location.hash;
            var el = hash ? document.getElementById(hash.slice(1)) : null;
            if (!el) { return; }
            var y = el.getBoundingClientRect().top + window.scrollY;
            var stack = 0;
            var headerbar = document.getElementById('headerbar');
            if (headerbar) { stack += headerbar.offsetHeight; }
            var tabs = document.querySelector('.ip-tabs');
            if (tabs) { stack += tabs.offsetHeight + 10; }
            var navbar = parseInt(document.documentElement.style.getPropertyValue('--ip-navbar-height'), 10) || 56;
            window.scrollTo({ top: y - navbar - stack - 12, behavior: 'instant' });
        }
        // Al caricamento pieno l'evento è load; con la navigazione parziale lo
        // script viene rieseguito senza load, e ip-pjax.js emette
        // ip:page-ready dopo aver montato il contenuto. In quel caso lo scroll
        // arriva qui, così tiene conto dello stack sticky.
        ipOn(window, 'load', scrollToHash);
        ipOn(document, 'ip:page-ready', scrollToHash);
    })();
</script>

<?= $this->endSection() ?>