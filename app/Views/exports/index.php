<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('exports', '', 'Esportazioni')); ?>
<?php
$actions = '<a class="btn btn-secondary btn-sm" href="' . site_url('imports_exports') . '"><i class="fa fa-solid fa-arrow-left" aria-hidden="true"></i> ' . esc(trans('imports_exports', '', 'Importa / Esporta')) . '</a> '
    . '<div class="headerbar-total">'
    . '<span class="headerbar-total__label">' . trans('format', '', 'Formato') . '</span>'
    . '<span class="headerbar-total__amount" id="export_summary">CSV</span>'
    . '</div>'
    . '<button type="submit" form="export_form" class="btn btn-sm btn-success"><i class="fa fa-solid fa-download" aria-hidden="true"></i> ' . trans('export_download', '', 'Scarica') . '</button>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('exports', '', 'Esportazioni'), 'actions' => $actions]) ?>

<div id="content" class="ip-doc-form">

    <div class="ip-export-intro">
        <p class="ip-export-intro__eyebrow">Esportazione · CSV · ZIP</p>
        <h2 class="ip-export-intro__title">Scegli cosa, quando e come.</h2>
        <p class="ip-export-intro__desc">Tre scelte in ordine: il contenuto, il periodo, il formato. I prospetti annuali usano l’anno della data iniziale; PDF e XML esistono solo per le fatture.</p>
    </div>

    <form method="POST" action="<?= site_url('exports/run') ?>" id="export_form">
        <?= csrf_field() ?>

        <!-- ① Cosa esportare -->
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">1</span>
                <h2 class="ip-doc-section__title"><?= trans('export_what', '', 'Cosa esportare') ?></h2>
                <span class="ip-doc-section__hint" id="export_types_hint"></span>
            </div>
            <div class="ip-doc-section__body">
                <?php
                $groups = [
                    [
                        'title' => trans('documents', '', 'Documenti'),
                        'icon'  => 'fa-regular fa-file-lines',
                        'class' => '',
                        'types' => ['invoices','invoice_items','quotes','payments','expenses'],
                    ],
                    [
                        'title' => trans('anagrafiche', '', 'Anagrafiche'),
                        'icon'  => 'fa-regular fa-address-book',
                        'class' => '',
                        'types' => ['clients','suppliers'],
                    ],
                    [
                        'title' => trans('finance', '', 'Utile e imposte'),
                        'icon'  => 'fa-solid fa-scale-balanced',
                        'class' => '',
                        'types' => ['finance','finance_months','finance_categories'],
                    ],
                ];
                $defs = [
                    'invoices'      => ['fa-regular fa-file-lines', trans('invoices', '', 'Fatture'), trans('export_invoices_hint', '', 'Una riga per fattura: numero, cliente, imponibile, IVA, totale, saldo.')],
                    'invoice_items' => ['fa-solid fa-list', trans('invoice_items', '', 'Righe fatture'), trans('export_items_hint', '', 'Una riga per articolo fatturato: serve per analizzare cosa è stato venduto.')],
                    'quotes'        => ['fa-regular fa-file', trans('quotes', '', 'Preventivi'), trans('export_quotes_hint', '', 'Preventivi con stato ed eventuale fattura collegata.')],
                    'payments'      => ['fa-solid fa-money-bill', trans('payments', '', 'Pagamenti'), trans('export_payments_hint', '', 'Incassi registrati, con metodo e fattura di riferimento.')],
                    'expenses'      => ['fa-solid fa-shopping-cart', trans('expenses', '', 'Spese'), trans('export_expenses_hint', '', 'Una riga per spesa: fornitore, categoria, imponibile, IVA, totale e saldo aperto.')],
                    'clients'       => ['fa-solid fa-users', trans('clients', '', 'Clienti'), trans('export_clients_hint', '', 'Anagrafica completa: P. IVA, codice fiscale, indirizzi, contatti.')],
                    'suppliers'     => ['fa-solid fa-basket-shopping', trans('suppliers', '', 'Fornitori'), trans('export_suppliers_hint', '', 'Anagrafica fornitori: P. IVA, codice fiscale, indirizzo, contatti bancari.')],
                    'finance'            => ['fa-solid fa-scale-balanced', trans('statement', '', 'Prospetto utile e imposte'), trans('export_finance_hint', '', 'Ricavi, costi, utile e imposte stimate con il dettaglio delle voci. È il prospetto da girare al commercialista.')],
                    'finance_months'     => ['fa-solid fa-chart-line', trans('income_vs_expenses', '', 'Entrate e uscite per mese'), trans('export_finance_months_hint', '', 'Dodici righe con ricavi, costi e saldo di ogni mese, più il totale d\'anno.')],
                    'finance_categories' => ['fa-solid fa-chart-pie', trans('expenses_by_category', '', 'Costi per categoria'), trans('export_finance_categories_hint', '', 'Quanto è stato speso per ogni categoria e che quota rappresenta sul totale.')],
                ];
                $annualTypes = ['finance','finance_months','finance_categories'];
                $first = true;
                ?>
                <div class="ip-export-groups">
                <?php foreach ($groups as $g): ?>
                    <?php $gridCols = count($g['types']) < 4 ? ' ip-export-group__grid--cols-' . count($g['types']) : ''; ?>
                    <div class="ip-export-group <?= esc($g['class']) ?>">
                        <h3 class="ip-export-group__title"><i class="fa <?= esc($g['icon']) ?>" aria-hidden="true"></i> <?= esc($g['title']) ?></h3>
                        <div class="ip-export-group__grid<?= $gridCols ?>">
                        <?php foreach ($g['types'] as $value):
                            [$icon,$label,$hint] = $defs[$value];
                            $isAnnual = in_array($value, $annualTypes, true);
                        ?>
                            <label class="ip-export-type">
                                <input type="radio" name="export_type" value="<?= esc($value, 'attr') ?>"
                                       <?= $first ? 'checked' : '' ?>
                                       data-annual="<?= $isAnnual ? '1' : '0' ?>"
                                       data-nodate="<?= in_array($value, ['clients','suppliers'], true) ? '1' : '0' ?>"
                                       data-documents="<?= $value==='invoices' ? '1' : '0' ?>">
                                <span class="ip-export-type__body">
                                    <span class="ip-export-type__title"><i class="fa <?= esc($icon, 'attr') ?>" aria-hidden="true"></i> <?= esc($label) ?></span>
                                    <span class="ip-export-type__hint"><?= esc($hint) ?></span>
                                    <?php if ($isAnnual): ?><span class="ip-export-badge ip-export-badge--annual"><i class="fa fa-regular fa-calendar" aria-hidden="true"></i> <?= esc(trans('annual', '', 'Annuale')) ?></span><?php endif; ?>
                                </span>
                            </label>
                            <?php $first = false; endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- ② Periodo -->
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">2</span>
                <h2 class="ip-doc-section__title"><?= trans('period', '', 'Periodo') ?></h2>
                <span class="ip-doc-section__hint"><?= trans('export_period_hint', '', 'Lascia vuoto per esportare tutto.') ?></span>
            </div>
            <div class="ip-doc-section__body">
                <div class="alert small" id="export_nodate_note" hidden>
                    <i class="fa fa-solid fa-info-circle" aria-hidden="true"></i>
                    <?= trans('export_nodate_note', '', 'Le anagrafiche sono una fotografia di adesso, non un flusso di documenti: il periodo non le filtra e viene ignorato.') ?>
                </div>
                <div class="alert small" id="export_annual_note" hidden>
                    <i class="fa fa-solid fa-info-circle" aria-hidden="true"></i>
                    <?= trans('export_annual_note', '', 'Il prospetto è annuale: conta l\'anno della data iniziale, non l\'intervallo. Usa i periodi rapidi qui sotto per sceglierlo; senza data si esporta l\'anno su cui stai lavorando.') ?>
                </div>
                <div class="ip-doc-grid ip-doc-grid--narrow">
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="date_from"><?= trans('date_from', '', 'Dal') ?> <span style="font-weight:400; color:var(--ip-text-muted)">— gg/mm/aaaa</span></label>
                        <input type="text" name="date_from" id="date_from" class="form-control datepicker"
                               autocomplete="off" placeholder="01/01/<?= (int)date('Y') ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="date_to"><?= trans('date_to', '', 'Al') ?> <span style="font-weight:400; color:var(--ip-text-muted)">— gg/mm/aaaa</span></label>
                        <input type="text" name="date_to" id="date_to" class="form-control datepicker"
                               autocomplete="off" placeholder="31/12/<?= (int)date('Y') ?>">
                    </div>
                </div>

                <div class="ip-export-shortcuts">
                    <span class="ip-doc-label"><?= trans('export_quick_period', '', 'Periodi rapidi') ?></span>
                    <?php foreach ($years as $y): ?>
                        <button type="button" class="ip-tag-button" data-from="01/01/<?= (int) $y ?>" data-to="31/12/<?= (int) $y ?>">
                            <?= (int) $y ?>
                        </button>
                    <?php endforeach; ?>
                    <?php
                    $currentYear = (int) date('Y');
                    $quarters = [
                        'T1' => ['01/01/', '31/03/'],
                        'T2' => ['01/04/', '30/06/'],
                        'T3' => ['01/07/', '30/09/'],
                        'T4' => ['01/10/', '31/12/'],
                    ];
                    ?>
                    <?php foreach ($quarters as $label => [$qFrom, $qTo]): ?>
                        <button type="button" class="ip-tag-button"
                                data-from="<?= $qFrom . $currentYear ?>" data-to="<?= $qTo . $currentYear ?>">
                            <?= esc($label) ?> <?= $currentYear ?>
                        </button>
                    <?php endforeach; ?>
                    <button type="button" class="ip-tag-button" data-from="" data-to="">
                        <?= trans('all', '', 'Tutto') ?>
                    </button>
                </div>
            </div>
        </section>

        <!-- ③ Formato -->
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">3</span>
                <h2 class="ip-doc-section__title"><?= trans('format', '', 'Formato') ?></h2>
                <span class="ip-doc-section__hint">CSV per Excel · ZIP per archivio</span>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-format-grid">
                    <label class="ip-export-type">
                        <input type="radio" name="export_format" value="csv" checked>
                        <span class="ip-export-type__body">
                            <span class="ip-export-type__title"><i class="fa fa-solid fa-table" aria-hidden="true"></i> CSV</span>
                            <span class="ip-export-type__hint">
                                <?= trans('export_csv_hint', '', 'Tabella apribile in Excel, separata da punto e virgola. Per controlli e invio al commercialista.') ?>
                            </span>
                            <span class="ip-export-badge">; · UTF-8 BOM</span>
                        </span>
                    </label>
                    <label class="ip-export-type" data-only-documents>
                        <input type="radio" name="export_format" value="pdf">
                        <span class="ip-export-type__body">
                            <span class="ip-export-type__title"><i class="fa fa-regular fa-file-pdf" aria-hidden="true"></i> <?= trans('export_pdf_zip', '', 'PDF (ZIP)') ?></span>
                            <span class="ip-export-type__hint">
                                <?= trans('export_pdf_hint', '', 'Archivio dei PDF come sono stati inviati al cliente. Solo fatture.') ?>
                            </span>
                            <span class="ip-export-badge"><?= esc(trans('invoices', '', 'Solo fatture')) ?></span>
                        </span>
                    </label>
                    <label class="ip-export-type" data-only-documents>
                        <input type="radio" name="export_format" value="xml">
                        <span class="ip-export-type__body">
                            <span class="ip-export-type__title"><i class="fa fa-regular fa-file-code" aria-hidden="true"></i> <?= trans('export_xml_zip', '', 'XML FatturaPA (ZIP)') ?></span>
                            <span class="ip-export-type__hint">
                                <?= trans('export_xml_hint', '', 'I file che fanno fede fiscalmente. Solo fatture; non consuma il progressivo di trasmissione.') ?>
                            </span>
                            <span class="ip-export-badge"><?= esc(trans('invoices', '', 'Solo fatture')) ?></span>
                        </span>
                    </label>
                </div>
            </div>
        </section>
    </form>
</div>

<script>
(function () {
    var form = document.getElementById('export_form');
    if (!form) { return; }

    var summary = document.getElementById('export_summary');

    function selected(name) {
        var el = form.querySelector('[name="' + name + '"]:checked');
        return el ? el.value : '';
    }

    function syncFormats() {
        var typeInput   = form.querySelector('[name="export_type"]:checked');
        var isDocuments = typeInput && typeInput.dataset.documents === '1';
        var isAnnual = typeInput && typeInput.dataset.annual === '1';
        var noDate   = typeInput && typeInput.dataset.nodate === '1';
        var note     = document.getElementById('export_annual_note');
        if (note) { note.hidden = !isAnnual; }
        var noDateNote = document.getElementById('export_nodate_note');
        if (noDateNote) { noDateNote.hidden = !noDate; }

        // Il campo "Al" non serve ai prospetti (sono annuali) e nessuno dei due
        // serve alle anagrafiche: lasciarli attivi faceva credere di aver
        // filtrato un periodo che il backend non guarda nemmeno.
        var dateFrom = document.getElementById('date_from');
        if (dateFrom) {
            dateFrom.disabled = noDate;
            dateFrom.closest('.ip-doc-field').classList.toggle('is-disabled', noDate);
        }
        var dateTo = document.getElementById('date_to');
        if (dateTo) {
            dateTo.disabled = isAnnual || noDate;
            dateTo.closest('.ip-doc-field').classList.toggle('is-disabled', isAnnual || noDate);
        }
        form.querySelectorAll('.ip-export-shortcuts .ip-tag-button').forEach(function (btn) {
            btn.disabled = noDate;
        });
        form.querySelectorAll('[data-only-documents]').forEach(function (label) {
            var radio = label.querySelector('input');
            label.classList.toggle('is-disabled', !isDocuments);
            radio.disabled = !isDocuments;
            if (!isDocuments && radio.checked) {
                form.querySelector('[name="export_format"][value="csv"]').checked = true;
            }
        });
        var labels = { csv: 'CSV', pdf: 'PDF (ZIP)', xml: 'XML (ZIP)' };
        if(summary) summary.textContent = labels[selected('export_format')] || 'CSV';

        var hint = document.getElementById('export_types_hint');
        if (hint) {
            hint.textContent = form.querySelectorAll('[name="export_type"]').length + ' tipi · '
                + form.querySelectorAll('.ip-export-group').length + ' gruppi';
        }

        // evidenzia pill attiva per periodo rapido
        var from = document.getElementById('date_from').value.trim();
        var to   = document.getElementById('date_to').value.trim();
        form.querySelectorAll('.ip-export-shortcuts .ip-tag-button').forEach(function(btn){
            var active = btn.dataset.from === from && btn.dataset.to === to;
            btn.classList.toggle('is-active', active);
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }

    form.addEventListener('change', syncFormats);
    form.addEventListener('input', syncFormats);

    form.querySelectorAll('.ip-export-shortcuts .ip-tag-button').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('date_from').value = btn.dataset.from;
            document.getElementById('date_to').value   = btn.dataset.to;
            // rimuovi focus per evitare outline persistente, poi sincronizza
            btn.blur();
            syncFormats();
        });
    });

    syncFormats();
})();
</script>

<?= $this->endSection() ?>
