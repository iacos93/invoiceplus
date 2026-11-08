<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('company', '', 'Azienda')); ?>

<form method="post" action="<?= site_url('companies/form/' . ($company_id ?? '')) ?>" class="ip-doc-form">
    <?= csrf_field() ?>
    <?= $this->include('layout/headerbar', ['title' => trans('company', '', 'Azienda'), 'actions' => header_actions()]) ?>

    <div id="content">
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">1</span>
                <h2 class="ip-doc-section__title"><?= trans('fiscal_identity', '', 'Identità fiscale') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-grid">
                    <?php
                    $fields = [
                        'company_name'      => trans('company', '', 'Ragione sociale'),
                        'company_vat_id'    => trans('vat_id', '', 'P. IVA'),
                        'company_tax_code'  => trans('tax_code', '', 'Codice fiscale'),
                        'company_address_1' => trans('address', '', 'Indirizzo'),
                        'company_city'      => trans('city', '', 'Città'),
                        'company_state'     => trans('state', '', 'Provincia'),
                        'company_zip'       => trans('zip', '', 'CAP'),
                        'company_country'   => trans('country', '', 'Paese'),
                        'company_phone'     => trans('phone', '', 'Telefono'),
                        'company_email'     => trans('email', '', 'Email'),
                    ];
                    ?>
                    <?php foreach ($fields as $name => $label): ?>
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="<?= $name ?>"><?= esc($label) ?></label>
                            <?php if ($name === 'company_country'): ?>
                                <?php $selected_country = $model->formValue('company_country') ?: get_setting('default_country'); ?>
                                <select name="company_country" id="company_country" class="form-control simple-select"
                                        data-placeholder="<?= esc(trans('country', '', 'Paese'), 'attr') ?>" data-allow-clear>
                                    <option value=""><?= trans('none', '', 'Nessuno') ?></option>
                                    <?php foreach ($countries as $cldr => $country): ?>
                                        <option value="<?= esc($cldr) ?>" <?= check_select($selected_country, $cldr) ?>>
                                            <?= esc($country) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                            <input type="text" name="<?= $name ?>" id="<?= $name ?>" class="form-control"
                                   value="<?= esc(old($name, $model->formValue($name))) ?>">
                            <?php endif; ?>
                            <?php if (!empty($errors[$name])): ?>
                                <span class="ip-field-error"><?= esc($errors[$name]) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">2</span>
                <h2 class="ip-doc-section__title"><?= trans('tax_settings', '', 'Regime e imposte') ?></h2>
                <span class="ip-doc-section__hint"><?= trans('used_by_finance', '', 'Usati dal report Utile e imposte') ?></span>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-grid">
                    <?php
                    // Il regime cambia il resto della sezione: una select
                    // nasconderebbe dietro un clic proprio le differenze che
                    // servono per scegliere, e ogni opzione porta con sé una
                    // riga di spiegazione. Stesso schema della tipologia
                    // cliente (.ip-type-choice).
                    $companyForm = $model->formValue('company_form');
                    // Vuoto = quello che il report usa come ripiego
                    // (FinanceReport::forYear): la scheda non deve mostrarne
                    // uno diverso da quello con cui la stima viene calcolata.
                    $companyForm = in_array($companyForm, ['forfettario', 'semplificato', 'societa_capitali'], true)
                        ? $companyForm
                        : 'semplificato';

                    $companyForms = [
                        'forfettario' => [
                            'fa-solid fa-seedling',
                            trans('form_forfettario', '', 'Ditta individuale — forfettario'),
                            trans('regime_note_forfettario', '', 'Imposta sostitutiva su una quota forfettaria dei ricavi. I costi effettivi non si deducono.'),
                        ],
                        'semplificato' => [
                            'fa-solid fa-user-tie',
                            trans('form_semplificato', '', 'Ditta individuale o professionista — ordinario'),
                            trans('regime_note_semplificato', '', 'IRPEF a scaglioni sull\'utile, più addizionali e contributi previdenziali.'),
                        ],
                        'societa_capitali' => [
                            'fa-solid fa-building',
                            trans('form_societa', '', 'Società di capitali (SRL, SPA)'),
                            trans('regime_note_societa', '', 'IRES sull\'utile e IRAP sul valore della produzione: gli altri campi qui sotto non si applicano.'),
                        ],
                    ];
                    ?>
                    <div class="ip-doc-field ip-doc-field--full">
                        <span class="ip-doc-label" id="ip-company-form-label"><?= trans('company_form', '', 'Forma aziendale') ?></span>
                        <div class="ip-type-choice" id="ip-company-form"
                             role="radiogroup" aria-labelledby="ip-company-form-label">
                            <?php foreach ($companyForms as $key => [$icon, $label, $hint]): ?>
                                <label class="ip-type-choice__option <?= $companyForm === $key ? 'is-active' : '' ?>">
                                    <input type="radio" name="company_form" value="<?= $key ?>"
                                        <?= $companyForm === $key ? 'checked' : '' ?>>
                                    <i class="fa <?= $icon ?>" aria-hidden="true"></i>
                                    <span class="ip-type-choice__label"><?= esc($label) ?></span>
                                    <span class="ip-type-choice__hint"><?= esc($hint) ?></span>
                                </label>
                    <?php endforeach; ?>

                    <div class="ip-doc-field ip-doc-field--full">
                        <label class="ip-doc-label" for="company_carta_docente_code">
                            <?= trans('carta_docente_code', '', 'Codice esercente Carta del Docente') ?>
                        </label>
                        <input type="text" name="company_carta_docente_code" id="company_carta_docente_code"
                               class="form-control"
                               value="<?= esc(old('company_carta_docente_code', $model->formValue('company_carta_docente_code'))) ?>">
                        <p class="ip-number-help">
                            <?= trans('carta_docente_code_help', '', 'Codice (case-sensitive) assegnato dalla piattaforma Carta del Docente all\'iscrizione: l\'app lo inserisce nelle fatture elettroniche dei buoni come riferimento amministrazione.') ?>
                        </p>
                        <?php if (!empty($errors['company_carta_docente_code'])): ?>
                            <span class="ip-field-error"><?= esc($errors['company_carta_docente_code']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                    </div>

                    <div class="ip-doc-subgroup" data-regime-heading="forfettario">
                        <?= trans('regime_group_forfettario', '', 'Solo forfettario') ?>
                    </div>
                    <div class="ip-doc-field ip-regime-field" data-forms="forfettario">
                        <label class="ip-doc-label" for="company_coefficient"><?= trans('coefficient', '', 'Coefficiente di redditività') ?></label>
                        <div class="ip-input-suffix">
                            <input type="number" step="1" min="0" max="100" name="company_coefficient" id="company_coefficient"
                                   class="form-control" value="<?= esc($model->formValue('company_coefficient') ?: 78) ?>">
                            <span class="ip-input-suffix__mark">%</span>
                        </div>
                        <p class="ip-number-help"><?= trans('coefficient_help', '', 'Dipende dal codice ATECO: lo trovi nella tabella dei coefficienti del regime forfettario.') ?></p>
                    </div>
                    <div class="ip-doc-field ip-regime-field" data-forms="forfettario">
                        <label class="ip-doc-label" for="company_forfettario_startup"><?= trans('forfettario_startup', '', 'Forfettario agevolato') ?></label>
                        <?php $startup = (int) ($model->formValue('company_forfettario_startup') ?: 0); ?>
                        <select name="company_forfettario_startup" id="company_forfettario_startup" class="form-control simple-select">
                            <option value="0" <?= $startup === 0 ? 'selected' : '' ?>>
                                <?= trans('forfettario_ordinary', '', 'No — aliquota ordinaria 15%') ?>
                            </option>
                            <option value="1" <?= $startup === 1 ? 'selected' : '' ?>>
                                <?= trans('forfettario_reduced', '', 'Sì — aliquota startup 5%') ?>
                            </option>
                        </select>
                        <p class="ip-number-help"><?= trans('forfettario_startup_help', '', 'L\'aliquota ridotta vale per i primi cinque anni di attività, se ne ricorrono i requisiti.') ?></p>
                    </div>

                    <div class="ip-doc-subgroup" data-regime-heading="forfettario,semplificato">
                        <?= trans('regime_group_contributi', '', 'Contributi previdenziali') ?>
                        <span class="ip-doc-subgroup__hint"><?= trans('regime_group_contributi_hint', '', 'Non si applicano alle società di capitali') ?></span>
                    </div>
                    <div class="ip-doc-field ip-regime-field" data-forms="forfettario,semplificato">
                        <label class="ip-doc-label" for="company_contributions"><?= trans('contributions', '', 'Aliquota contributi') ?></label>
                        <div class="ip-input-suffix">
                            <input type="number" step="0.01" min="0" name="company_contributions" id="company_contributions"
                                   class="form-control" placeholder="<?= esc(trans('rate_of_year', '', 'aliquota dell\'anno'), 'attr') ?>"
                                   value="<?= esc($model->formValue('company_contributions')) ?>">
                            <span class="ip-input-suffix__mark">%</span>
                        </div>
                        <p class="ip-number-help"><?= trans('contributions_help', '', 'Lascia vuoto per usare l\'aliquota dello schema scelto dai parametri fiscali dell\'anno.') ?></p>
                    </div>
                    <div class="ip-doc-field ip-regime-field" data-forms="forfettario,semplificato">
                        <label class="ip-doc-label" for="company_inps_scheme"><?= trans('inps_scheme', '', 'Schema contributivo INPS') ?></label>
                        <?php $scheme = $model->formValue('company_inps_scheme') ?: 'separata'; ?>
                        <select name="company_inps_scheme" id="company_inps_scheme" class="form-control simple-select">
                            <option value="separata" <?= $scheme === 'separata' ? 'selected' : '' ?>>
                                <?= trans('inps_separata', '', 'Gestione separata — percentuale sul reddito') ?>
                            </option>
                            <option value="artigiani" <?= $scheme === 'artigiani' ? 'selected' : '' ?>>
                                <?= trans('inps_artigiani', '', 'Artigiani e commercianti — fisso + eccedenza') ?>
                            </option>
                        </select>
                        <p class="ip-number-help"><?= trans('inps_scheme_help', '', 'Artigiani e commercianti pagano un contributo fisso fino al minimale e una quota sull\'eccedenza fino al massimale.') ?></p>
                    </div>

                    <?php /* Le addizionali dei parametri per anno sono medie
                             nazionali: qui si mette la propria, se la si
                             conosce. Vuoto = usa la media. Si applicano solo
                             all'IRPEF del regime ordinario (FinanceService). */ ?>
                    <div class="ip-doc-subgroup" data-regime-heading="semplificato">
                        <?= trans('regime_group_addizionali', '', 'Solo regime ordinario') ?>
                        <span class="ip-doc-subgroup__hint"><?= trans('regime_group_addizionali_hint', '', 'Addizionali IRPEF') ?></span>
                    </div>
                    <div class="ip-doc-field ip-regime-field" data-forms="semplificato">
                        <label class="ip-doc-label" for="company_add_regionale"><?= trans('add_regionale', '', 'Addizionale regionale') ?></label>
                        <div class="ip-input-suffix">
                            <input type="number" step="0.01" min="0" name="company_add_regionale" id="company_add_regionale"
                                   class="form-control" placeholder="<?= esc(trans('national_average', '', 'media nazionale'), 'attr') ?>"
                                   value="<?= esc($model->formValue('company_add_regionale')) ?>">
                            <span class="ip-input-suffix__mark">%</span>
                        </div>
                        <p class="ip-number-help"><?= trans('company_surcharge_help', '', 'Lascia vuoto per usare la media dei parametri fiscali dell\'anno.') ?></p>
                    </div>
                    <div class="ip-doc-field ip-regime-field" data-forms="semplificato">
                        <label class="ip-doc-label" for="company_add_comunale"><?= trans('add_comunale', '', 'Addizionale comunale') ?></label>
                        <div class="ip-input-suffix">
                            <input type="number" step="0.01" min="0" name="company_add_comunale" id="company_add_comunale"
                                   class="form-control" placeholder="<?= esc(trans('national_average', '', 'media nazionale'), 'attr') ?>"
                                   value="<?= esc($model->formValue('company_add_comunale')) ?>">
                            <span class="ip-input-suffix__mark">%</span>
                        </div>
                    </div>

                    <div class="ip-doc-subgroup">
                        <?= trans('regime_group_comune', '', 'Comune a tutti i regimi') ?>
                    </div>
                    <?php
                    // Voci contributive aggiuntive: dal DB arrivano come JSON
                    // (dopo un errore di validazione il controller ne salva la
                    // serializzazione in formValues, quindi qui il formato è
                    // sempre lo stesso).
                    $taxExtras = [];
                    $_extrasRaw = $model->formValue('company_tax_extras');
                    if (is_string($_extrasRaw) && $_extrasRaw !== '') {
                        $_decoded = json_decode($_extrasRaw, true);
                        $taxExtras = is_array($_decoded) ? $_decoded : [];
                    }
                    ?>
                    <div class="ip-doc-field ip-doc-field--full">
                        <span class="ip-doc-label"><?= trans('tax_extras', '', 'Voci contributive aggiuntive') ?></span>
                        <p class="ip-number-help ip-tax-extras-help">
                            <?= trans('tax_extras_help', '', 'Percentuali calcolate su imponibile, utile lordo o ricavi. Se deducibili riducono la base su cui si calcolano imposta sostitutiva e IRPEF.') ?>
                        </p>
                        <div class="ip-tax-extras-card">
                            <div class="ip-tax-extra-row ip-tax-extra-row--head" aria-hidden="true">
                                <span><?= trans('tax_extra_name_col', '', 'Nome') ?></span>
                                <span><?= trans('tax_extra_rate_col', '', 'Aliquota') ?></span>
                                <span><?= trans('tax_extra_base_col', '', 'Base') ?></span>
                                <span><?= trans('tax_extra_deductible_col', '', 'Deducibile') ?></span>
                                <span></span>
                            </div>
                            <?php /* Ogni riga porta il proprio indice nel nome dei campi.
                                      Con i nomi aperti (tax_extra_name[]) le caselle
                                      "Deducibile" non allineano: quelle non spuntate non
                                      vengono inviate, quindi la prima spunta finiva
                                      all'indice 0 e la deducibilità si spostava di riga. */ ?>
                            <div id="tax-extras-rows">
                                <?php foreach (array_values($taxExtras) as $i => $extra): ?>
                                    <div class="ip-tax-extra-row">
                                        <input type="text" name="tax_extra_name[<?= $i ?>]" class="form-control" aria-label="<?= esc(trans('tax_extra_name', '', 'Nome della voce'), 'attr') ?>"
                                               placeholder="<?= esc(trans('tax_extra_name_placeholder', '', 'INAIL, fondo pensione…'), 'attr') ?>"
                                               value="<?= esc($extra['name'] ?? '') ?>">
                                        <div class="ip-input-suffix">
                                            <input type="number" step="0.01" min="0" name="tax_extra_rate[<?= $i ?>]" class="form-control" aria-label="<?= esc(trans('tax_extra_rate', '', 'Aliquota della voce'), 'attr') ?>"
                                                   value="<?= esc($extra['rate'] ?? '') ?>">
                                            <span class="ip-input-suffix__mark">%</span>
                                        </div>
                                        <select name="tax_extra_base[<?= $i ?>]" class="form-control simple-select"
                                                aria-label="<?= esc(trans('tax_extra_base', '', 'Base di calcolo')) ?>">
                                            <?php $_extraBase = $extra['base'] ?? 'taxable'; ?>
                                            <option value="taxable" <?= $_extraBase === 'taxable' ? 'selected' : '' ?>>
                                                <?= trans('tax_extra_base_taxable', '', 'Imponibile') ?>
                                            </option>
                                            <option value="profit" <?= $_extraBase === 'profit' ? 'selected' : '' ?>>
                                                <?= trans('tax_extra_base_profit', '', 'Utile lordo') ?>
                                            </option>
                                            <option value="revenue" <?= $_extraBase === 'revenue' ? 'selected' : '' ?>>
                                                <?= trans('tax_extra_base_revenue', '', 'Ricavi') ?>
                                            </option>
                                        </select>
                                        <label class="form-check ip-tax-extra-check">
                                            <input type="checkbox" class="form-check-input" name="tax_extra_deductible[<?= $i ?>]" value="1"
                                                   <?= !empty($extra['deductible']) ? 'checked' : '' ?>>
                                            <span class="form-check-label"><?= trans('tax_extra_deductible', '', 'Deducibile') ?></span>
                                        </label>
                                        <button type="button" class="btn btn-outline-danger btn-sm tax-extra-remove"
                                                title="<?= esc(trans('tax_extra_remove', '', 'Rimuovi voce'), 'attr') ?>" aria-label="<?= esc(trans('tax_extra_remove', '', 'Rimuovi voce'), 'attr') ?>">
                                            <i class="fa fa-solid fa-times" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" id="tax-extra-add" class="btn btn-sm btn-outline-primary ip-tax-extra-add">
                                <i class="fa fa-solid fa-plus" aria-hidden="true"></i>
                                <?= trans('tax_extra_add', '', 'Aggiungi voce') ?>
                            </button>
                        </div>
                    </div>
                    <template id="tax-extra-row-template">
                        <div class="ip-tax-extra-row">
                            <input type="text" name="tax_extra_name[__i__]" class="form-control" aria-label="<?= esc(trans('tax_extra_name', '', 'Nome della voce'), 'attr') ?>"
                                   placeholder="<?= esc(trans('tax_extra_name_placeholder', '', 'INAIL, fondo pensione…'), 'attr') ?>">
                            <div class="ip-input-suffix">
                                <input type="number" step="0.01" min="0" name="tax_extra_rate[__i__]" class="form-control" aria-label="<?= esc(trans('tax_extra_rate', '', 'Aliquota della voce'), 'attr') ?>">
                                <span class="ip-input-suffix__mark">%</span>
                            </div>
                            <select name="tax_extra_base[__i__]" class="form-control simple-select"
                                            aria-label="<?= esc(trans('tax_extra_base', '', 'Base di calcolo')) ?>">
                                <option value="taxable" selected><?= trans('tax_extra_base_taxable', '', 'Imponibile') ?></option>
                                <option value="profit"><?= trans('tax_extra_base_profit', '', 'Utile lordo') ?></option>
                                <option value="revenue"><?= trans('tax_extra_base_revenue', '', 'Ricavi') ?></option>
                            </select>
                            <label class="form-check ip-tax-extra-check">
                                <input type="checkbox" class="form-check-input" name="tax_extra_deductible[__i__]" value="1">
                                <span class="form-check-label"><?= trans('tax_extra_deductible', '', 'Deducibile') ?></span>
                            </label>
                            <button type="button" class="btn btn-outline-danger btn-sm tax-extra-remove"
                                    title="<?= esc(trans('tax_extra_remove', '', 'Rimuovi voce'), 'attr') ?>" aria-label="<?= esc(trans('tax_extra_remove', '', 'Rimuovi voce'), 'attr') ?>">
                                <i class="fa fa-solid fa-times" aria-hidden="true"></i>
                            </button>
                        </div>
                    </template>
                </div>

                <div class="form-check" style="margin-top:12px">
                    <input type="hidden" name="company_is_default" value="0">
                    <input type="checkbox" class="form-check-input" name="company_is_default" id="company_is_default" value="1"
                           <?= db_bool($model->formValue('company_is_default')) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="company_is_default"><?= trans('is_default_company', '', 'Azienda predefinita all\'accesso') ?></label>
                </div>
                <div class="form-check">
                    <input type="hidden" name="company_is_active" value="0">
                    <input type="checkbox" class="form-check-input" name="company_is_active" id="company_is_active" value="1"
                           <?= ($company_id === null || db_bool($model->formValue('company_is_active'))) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="company_is_active"><?= trans('is_active_company', '', 'Attiva (selezionabile)') ?></label>
                </div>

                <?php if ($company_id === null): ?>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" name="copy_setup" id="copy_setup" value="1" checked>
                        <label class="form-check-label" for="copy_setup">
                            <?= trans('copy_setup', '', 'Crea la configurazione iniziale (serie fatture, serie bozze, categorie di spesa)') ?>
                        </label>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <?php if (!empty($invoice_groups)): ?>
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">3</span>
                <h2 class="ip-doc-section__title"><?= trans('numbering', '', 'Numerazione predefinita') ?></h2>
                <span class="ip-doc-section__hint"><?= trans('numbering_company_hint', '', 'Serie proposte ai nuovi documenti di questa azienda') ?></span>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-grid">
                    <?php foreach ([
                        'default_invoice_group' => trans('default_invoice_group', '', 'Serie fatture'),
                        'draft_invoice_group'   => trans('draft_invoice_group', '', 'Serie bozze fatture'),
                        'default_quote_group'   => trans('default_quote_group', '', 'Serie preventivi'),
                        'draft_quote_group'     => trans('draft_quote_group', '', 'Serie bozze preventivi'),
                    ] as $name => $label): ?>
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="<?= $name ?>"><?= esc($label) ?></label>
                            <select name="<?= $name ?>" id="<?= $name ?>" class="form-control simple-select">
                                <option value=""><?= trans('none', '', 'Nessuna') ?></option>
                                <?php foreach ($invoice_groups as $group): ?>
                                    <option value="<?= (int) $group['invoice_group_id'] ?>"
                                        <?= (int) $model->formValue($name) === (int) $group['invoice_group_id'] ? 'selected' : '' ?>>
                                        <?= esc($group['invoice_group_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="ip-number-help">
                    <?= trans('draft_group_help', '', 'Le bozze ricevono subito un numero provvisorio dalla serie bozze (es. T-1), sostituito da quello definitivo quando il documento esce dalla bozza: il progressivo ufficiale non viene consumato in anticipo.') ?>
                </p>
            </div>
        </section>
        <?php endif; ?>
    </div>
</form>
<script>
    (function () {
        // Mostra solo i campi del regime scelto. I valori nascosti restano
        // nel DOM e vengono inviati (il calcolo lato server ignora quelli che
        // non c'entrano), ma tenerli a vista farebbe pensare che si applichino
        // tutti insieme.
        var group   = document.getElementById('ip-company-form');
        var fields  = document.querySelectorAll('.ip-regime-field');
        var heads   = document.querySelectorAll('.ip-doc-subgroup[data-regime-heading]');

        if (!group) { return; }

        function currentForm() {
            var checked = group.querySelector('input[name="company_form"]:checked');
            return checked ? checked.value : '';
        }

        function toggle(el, current) {
            var forms = (el.dataset.forms || el.dataset.regimeHeading || '').split(',');
            el.hidden = forms.indexOf(current) === -1;
        }

        function applyRegime() {
            var current = currentForm();

            group.querySelectorAll('.ip-type-choice__option').forEach(function (opt) {
                var input = opt.querySelector('input[type="radio"]');
                opt.classList.toggle('is-active', !!input && input.value === current);
            });

            fields.forEach(function (field) { toggle(field, current); });
            heads.forEach(function (head) { toggle(head, current); });
        }

        group.addEventListener('change', applyRegime);
        applyRegime();
    })();

    (function () {
        var wrap   = document.getElementById('tax-extras-rows');
        var tpl    = document.getElementById('tax-extra-row-template');
        var addBtn = document.getElementById('tax-extra-add');

        if (!wrap || !tpl || !addBtn) { return; }

        // Indice progressivo delle righe: i nomi dei campi lo portano esplicito
        // perche' le caselle non spuntate non vengono inviate e senza indice
        // la deducibilita' finirebbe sulla riga sbagliata.
        var nextIndex = wrap.querySelectorAll('.ip-tax-extra-row').length;

        function addRow() {
            var row  = tpl.content.cloneNode(true);
            var i    = nextIndex++;

            row.querySelectorAll('[name]').forEach(function (el) {
                el.name = el.name.replace('__i__', i);
            });

            wrap.appendChild(row);
        }

        addBtn.addEventListener('click', addRow);

        // Delega: funziona anche per le righe aggiunte dopo il load.
        wrap.addEventListener('click', function (e) {
            var btn = e.target.closest('.tax-extra-remove');
            if (btn && btn.closest('.ip-tax-extra-row')) {
                btn.closest('.ip-tax-extra-row').remove();
            }
        });

        // Una riga vuota pronta da compilare: salvata cosi' com'e' viene
        // scartata dalla normalizzazione, quindi e' innocua.
        if (wrap.children.length === 0) {
            addRow();
        }
    })();
</script>
<?= $this->endSection() ?>
