<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('invoice_group_form')); ?>

<?php
// Il valore salvato resta il "formato identificatore" con i segnaposto
// ({{{id}}}/PA), perché è quello che il model sa interpretare. Qui però non lo
// si scrive a mano: i tre campi semplici (testo, posizione, separatore) lo
// compongono, e il formato grezzo resta disponibile nel pannello avanzato per
// i casi che i tre campi non coprono (anno, mese, giorno).
$ip_format = old('invoice_group_identifier_format', $model->formValue('invoice_group_identifier_format'));
?>

<form method="post" action="<?= ip_resource_url('invoice_groups', 'form', $invoice_group_id ?? null) ?>">

    <?= csrf_field() ?>

    <?= $this->include('layout/headerbar', ['title' => trans('invoice_group_form'), 'actions' => header_actions()]) ?>

    <div id="content">

        <div class="ip-form-pane">

                <div class="card mb-3">
                    <div class="card-header"><?= trans('invoice_group_form', '', 'Serie di numerazione') ?></div>
                    <div class="card-body">

                        <?php // A tutta riga il nome diventerebbe un input lunghissimo per una
                              // sigla di poche parole: mezza colonna, come nelle impostazioni. ?>
                        <div class="row">
                            <div class="col-12 col-md-6">
                                <div class="ip-doc-field">
                                    <label class="form-badge" for="invoice_group_name">
                                        <?= trans('name') ?>
                                    </label>
                                    <input type="text" name="invoice_group_name" id="invoice_group_name" class="form-control"
                                           value="<?= esc(old('invoice_group_name', $model->formValue('invoice_group_name'))) ?>"
                                           placeholder="<?= esc(trans('invoice_group_name_placeholder', '', 'Es. Fatture, Fatture PA, Preventivi'), 'attr') ?>">
                                    <?php if (!empty($errors['invoice_group_name'])): ?>
                                        <span class="ip-field-error"><?= esc($errors['invoice_group_name']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="row" id="simple_builder">
                            <div class="col-12 col-sm-5">
                                <div class="ip-doc-field">
                                    <label class="form-badge" for="ip_number_text">
                                        <?= trans('number_text', '', 'Testo (sigla)') ?>
                                    </label>
                                    <input type="text" id="ip_number_text" class="form-control"
                                           placeholder="<?= esc(trans('number_text_placeholder', '', 'PA, PRI, TEMP…'), 'attr') ?>"
                                           autocomplete="off">
                                </div>
                            </div>
                            <div class="col-12 col-sm-4">
                                <div class="ip-doc-field">
                                    <label class="form-badge" for="ip_number_position">
                                        <?= trans('number_position', '', 'Posizione') ?>
                                    </label>
                                    <select id="ip_number_position" class="form-control simple-select">
                                        <option value="after"><?= trans('after_number', '', 'Dopo il numero') ?></option>
                                        <option value="before"><?= trans('before_number', '', 'Prima del numero') ?></option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-12 col-sm-3">
                                <div class="ip-doc-field">
                                    <label class="form-badge" for="ip_number_separator">
                                        <?= trans('separator', '', 'Separatore') ?>
                                    </label>
                                    <select id="ip_number_separator" class="form-control simple-select">
                                        <option value="/">/</option>
                                        <option value="-">-</option>
                                        <option value=".">.</option>
                                        <option value="_">_</option>
                                        <option value=" "><?= trans('space', '', 'Spazio') ?></option>
                                        <option value=""><?= trans('none', '', 'Nessuno') ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-12 col-sm-6">
                                <div class="ip-doc-field">
                                    <label class="form-badge" for="invoice_group_next_id">
                                        <?= trans('next_id') ?>
                                    </label>
                                    <input type="number" name="invoice_group_next_id" id="invoice_group_next_id"
                                           class="form-control" min="1"
                                           value="<?= esc(old('invoice_group_next_id', $model->formValue('invoice_group_next_id'))) ?>">
                                    <?php if (!empty($errors['invoice_group_next_id'])): ?>
                                        <span class="ip-field-error"><?= esc($errors['invoice_group_next_id']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <div class="ip-doc-field">
                                    <label class="form-badge" for="invoice_group_left_pad">
                                        <?= trans('left_pad') ?>
                                    </label>
                                    <input type="number" name="invoice_group_left_pad" id="invoice_group_left_pad"
                                           class="form-control" min="0"
                                           value="<?= esc(old('invoice_group_left_pad', $model->formValue('invoice_group_left_pad'))) ?>">
                                    <p class="ip-field-help">
                                        <?= trans('left_pad_help', '', 'Zeri davanti al numero: 4 produce 0001.') ?>
                                    </p>
                                    <?php if (!empty($errors['invoice_group_left_pad'])): ?>
                                        <span class="ip-field-error"><?= esc($errors['invoice_group_left_pad']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Anteprima: senza, per capire cosa produce una serie
                             bisogna salvare e creare un documento di prova. -->
                        <div class="ip-number-preview">
                            <span class="ip-number-preview__label"><?= trans('preview', '', 'Anteprima') ?></span>
                            <span class="ip-number-preview__value" id="number_preview">—</span>
                        </div>

                        <?php if (!empty($errors['invoice_group_identifier_format'])): ?>
                            <span class="ip-field-error"><?= esc($errors['invoice_group_identifier_format']) ?></span>
                        <?php endif; ?>

                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <button type="button" class="ip-disclosure" id="advanced_toggle"
                                aria-expanded="false" aria-controls="advanced_panel">
                            <i class="fa fa-solid fa-chevron-right" aria-hidden="true"></i>
                            <?= trans('advanced_format', '', 'Formato avanzato') ?>
                        </button>
                    </div>
                    <div class="card-body" id="advanced_panel" hidden>

                        <p class="ip-number-help">
                            <?= trans('advanced_format_help', '', 'Serve per inserire anno, mese o giorno nel numero. Il testo che scrivi resta com\'è; i segnaposto vengono sostituiti alla creazione del documento.') ?>
                        </p>

                        <div class="ip-doc-field">
                            <label class="form-badge" for="invoice_group_identifier_format">
                                <?= trans('identifier_format') ?>
                            </label>
                            <input type="text" class="form-control"
                                   name="invoice_group_identifier_format" id="invoice_group_identifier_format"
                                   value="<?= esc($ip_format) ?>"
                                   placeholder="{{{id}}}/PA">
                        </div>

                        <div class="ip-tag-buttons" id="tag_buttons">
                            <button type="button" class="ip-tag-button" data-tag="{{{id}}}">
                                <code>{{{id}}}</code> <?= trans('id', '', 'Numero progressivo') ?>
                            </button>
                            <button type="button" class="ip-tag-button" data-tag="{{{year}}}">
                                <code>{{{year}}}</code> <?= trans('current_year') ?>
                            </button>
                            <button type="button" class="ip-tag-button" data-tag="{{{yy}}}">
                                <code>{{{yy}}}</code> <?= trans('current_yy') ?>
                            </button>
                            <button type="button" class="ip-tag-button" data-tag="{{{month}}}">
                                <code>{{{month}}}</code> <?= trans('current_month') ?>
                            </button>
                            <button type="button" class="ip-tag-button" data-tag="{{{day}}}">
                                <code>{{{day}}}</code> <?= trans('current_day') ?>
                            </button>
                        </div>

                    </div>
                </div>

        </div>

    </div>

</form>

<script>
(function () {
    var format    = document.getElementById('invoice_group_identifier_format');
    var text      = document.getElementById('ip_number_text');
    var position  = document.getElementById('ip_number_position');
    var separator = document.getElementById('ip_number_separator');
    var nextId    = document.getElementById('invoice_group_next_id');
    var pad       = document.getElementById('invoice_group_left_pad');
    var preview   = document.getElementById('number_preview');
    var builder   = document.getElementById('simple_builder');

    if (!format || !preview) { return; }

    var ID  = '{{{id}}}';
    var now = new Date();

    function pad2(n) { return (n < 10 ? '0' : '') + n; }

    /* ---- anteprima: stessa logica di InvoiceGroupModel::parseIdentifierFormat ---- */

    function buildPreview() {
        var raw    = format.value;
        var id     = parseInt(nextId && nextId.value, 10);
        var length = parseInt(pad && pad.value, 10);

        if (isNaN(id) || id < 1) { id = 1; }
        if (isNaN(length) || length < 0) { length = 0; }

        var padded = String(id);
        while (padded.length < length) { padded = '0' + padded; }

        if (!raw.trim()) { return padded; }
        if (raw.indexOf(ID) === -1) { return raw + padded; }

        return raw
            .replace(/\{\{\{id\}\}\}/g, padded)
            .replace(/\{\{\{year\}\}\}/g, String(now.getFullYear()))
            .replace(/\{\{\{yy\}\}\}/g, String(now.getFullYear()).slice(-2))
            .replace(/\{\{\{month\}\}\}/g, pad2(now.getMonth() + 1))
            .replace(/\{\{\{day\}\}\}/g, pad2(now.getDate()))
            .replace(/\{\{\{[^{}]*\}\}\}/g, '');
    }

    function render() { preview.textContent = buildPreview(); }

    /* ---- campi semplici → formato ---- */

    function compose() {
        var value = text.value.trim();

        if (!value) { return ID; }

        return position.value === 'before'
            ? value + separator.value + ID
            : ID + separator.value + value;
    }

    function fromSimple() {
        format.value = compose();
        render();
    }

    [text, position, separator].forEach(function (el) {
        el.addEventListener('input', fromSimple);
        el.addEventListener('change', fromSimple);
    });

    [nextId, pad].forEach(function (el) {
        if (el) { el.addEventListener('input', render); }
    });

    /* ---- formato → campi semplici ---- */

    var SEPARATORS = ['/', '-', '.', '_', ' '];

    // Ritorna null se il formato non è esprimibile con i tre campi semplici
    // (contiene anno/mese/giorno, o {{{id}}} in mezzo ad altro testo): in quel
    // caso i campi semplici resterebbero disallineati dal valore reale, quindi
    // si passa al pannello avanzato invece di mostrare dati falsi.
    function parseSimple(raw) {
        raw = String(raw || '');

        if (raw.trim() === '') { return { text: '', position: 'after', separator: '/' }; }
        if (raw.indexOf(ID) === -1) { return null; }
        if (/\{\{\{(?!id\}\}\})/.test(raw)) { return null; }
        if (raw.split(ID).length > 2) { return null; }

        var before = raw.slice(0, raw.indexOf(ID));
        var after  = raw.slice(raw.indexOf(ID) + ID.length);

        if (before && after) { return null; }

        if (!before && !after) { return { text: '', position: 'after', separator: '/' }; }

        var part = before || after;
        var pos  = before ? 'before' : 'after';
        var sep  = '';

        // Il separatore sta fra numero e testo: in prefisso è l'ultimo
        // carattere, in suffisso il primo.
        var edge = before ? part.charAt(part.length - 1) : part.charAt(0);

        if (SEPARATORS.indexOf(edge) !== -1) {
            sep  = edge;
            part = before ? part.slice(0, -1) : part.slice(1);
        }

        return { text: part, position: pos, separator: sep };
    }

    function showAdvanced() {
        panel.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
        toggle.querySelector('i').className = 'fa fa-chevron-down';
    }

    /* ---- pannello avanzato ---- */

    var toggle = document.getElementById('advanced_toggle');
    var panel  = document.getElementById('advanced_panel');

    toggle.addEventListener('click', function () {
        if (panel.hidden) {
            showAdvanced();
        } else {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            toggle.querySelector('i').className = 'fa fa-chevron-right';
        }
    });

    // Modifica diretta del formato: si riallineano i campi semplici, oppure si
    // disattivano se il formato è diventato troppo articolato per loro.
    format.addEventListener('input', function () {
        var parsed = parseSimple(format.value);

        builder.classList.toggle('is-disabled', !parsed);
        [text, position, separator].forEach(function (el) { el.disabled = !parsed; });

        if (parsed) {
            text.value      = parsed.text;
            position.value  = parsed.position;
            separator.value = parsed.separator;
        }

        render();
    });

    document.getElementById('tag_buttons').addEventListener('click', function (e) {
        var btn = e.target.closest('[data-tag]');
        if (!btn) { return; }

        var tag   = btn.dataset.tag;
        var start = format.selectionStart;
        var end   = format.selectionEnd;

        if (typeof start !== 'number') {
            format.value += tag;
        } else {
            format.value = format.value.slice(0, start) + tag + format.value.slice(end);
            var caret = start + tag.length;
            format.setSelectionRange(caret, caret);
        }

        format.focus();
        format.dispatchEvent(new Event('input'));
    });

    /* ---- avvio ---- */

    var initial = parseSimple(format.value);

    if (initial) {
        text.value      = initial.text;
        position.value  = initial.position;
        separator.value = initial.separator;
        // Un gruppo senza formato salvato produrrebbe numeri senza progressivo:
        // si parte dal solo {{{id}}}, che è la serie minima sensata.
        if (!format.value.trim()) { format.value = ID; }
    } else {
        builder.classList.add('is-disabled');
        [text, position, separator].forEach(function (el) { el.disabled = true; });
        showAdvanced();
    }

    render();
})();
</script>

<?= $this->endSection() ?>
