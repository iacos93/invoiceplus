<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php
$this->setVar('pageTitle', trans('delivery_note', '', 'Documento di trasporto'));
$transportDt = $model->formValue('transport_datetime');
?>
<form method="post" action="<?= site_url('delivery_notes/form/' . ($delivery_note_id ?? '')) ?>" id="ddt_form" class="ip-doc-form">
    <?= csrf_field() ?>
    <input type="hidden" name="items" id="ddt_items_json" value="">

    <?= $this->include('layout/headerbar', ['title' => trans('delivery_note', '', 'Documento di trasporto'), 'actions' => header_actions()]) ?>

    <div id="content">
        <!-- ① Destinatario -->
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">1</span>
                <h2 class="ip-doc-section__title"><?= trans('recipient', '', 'Destinatario') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-grid">
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="client_id"><?= trans('client', '', 'Cliente') ?></label>
                        <select name="client_id" id="client_id" class="form-control simple-select" required>
                            <option value=""><?= trans('select_client', '', 'Seleziona cliente') ?></option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?= (int) $c['client_id'] ?>" <?= (int) $model->formValue('client_id') === (int) $c['client_id'] ? 'selected' : '' ?>>
                                    <?= esc(format_client($c)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!empty($errors['client_id'])): ?><span class="ip-field-error"><?= esc($errors['client_id']) ?></span><?php endif; ?>
                    </div>
                    <div class="ip-doc-field" style="grid-column: span 2">
                        <label class="ip-doc-label" for="shipping_address"><?= trans('shipping_address', '', 'Luogo di destinazione (se diverso)') ?></label>
                        <input type="text" name="shipping_address" id="shipping_address" class="form-control"
                               value="<?= esc(old('shipping_address', $model->formValue('shipping_address'))) ?>">
                    </div>
                </div>
            </div>
        </section>

        <!-- ② Documento -->
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">2</span>
                <h2 class="ip-doc-section__title"><?= trans('document_data', '', 'Dati documento') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-grid">
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="delivery_note_date"><?= trans('date', '', 'Data') ?></label>
                        <input type="text" name="delivery_note_date" id="delivery_note_date" class="form-control datepicker"
                               value="<?= esc($model->formValue('delivery_note_date') ? date_from_mysql($model->formValue('delivery_note_date')) : date(date_format_setting())) ?>">
                        <?php if (!empty($errors['delivery_note_date'])): ?><span class="ip-field-error"><?= esc($errors['delivery_note_date']) ?></span><?php endif; ?>
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="delivery_note_number"><?= trans('number', '', 'Numero') ?></label>
                        <input type="text" name="delivery_note_number" id="delivery_note_number" class="form-control"
                               placeholder="<?= esc(trans('auto_from_series', '', 'Dalla serie'), 'attr') ?>"
                               value="<?= esc(old('delivery_note_number', $model->formValue('delivery_note_number'))) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="invoice_group_id"><?= trans('series', '', 'Serie numerazione') ?></label>
                        <select name="invoice_group_id" id="invoice_group_id" class="form-control simple-select">
                            <option value=""><?= trans('none', '', 'Nessuna') ?></option>
                            <?php foreach ($invoice_groups as $g): ?>
                                <option value="<?= (int) $g['invoice_group_id'] ?>" <?= (int) $model->formValue('invoice_group_id') === (int) $g['invoice_group_id'] ? 'selected' : '' ?>>
                                    <?= esc($g['invoice_group_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </section>

        <!-- ③ Merce -->
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">3</span>
                <h2 class="ip-doc-section__title"><?= trans('goods', '', 'Merce') ?></h2>
                <span class="ip-doc-section__hint"><?= trans('ddt_no_prices', '', 'Il DDT descrive cosa viaggia, non il valore') ?></span>
            </div>
            <div class="ip-doc-items">
                <table class="ip-items-table">
                    <thead><tr>
                        <th scope="col" class="ip-items-table__desc"><?= trans('description', '', 'Descrizione') ?></th>
                        <th scope="col" class="ip-items-table__num"><?= trans('quantity', '', 'Qtà') ?></th>
                        <th scope="col" class="ip-items-table__tax"><?= trans('unit', '', 'Unità') ?></th>
                        <th scope="col" class="ip-items-table__actions"></th>
                    </tr></thead>
                    <tbody id="ddt_items_body"></tbody>
                </table>
                <div class="ip-doc-items__footer">
                    <button type="button" class="btn btn-sm btn-secondary" id="ddt_add_row">
                        <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('add_new_row', '', 'Aggiungi riga') ?>
                    </button>
                </div>
            </div>
        </section>

        <!-- ④ Trasporto -->
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">4</span>
                <h2 class="ip-doc-section__title"><?= trans('transport', '', 'Trasporto') ?></h2>
                <span class="ip-doc-section__hint"><?= trans('transport_required', '', 'Dati richiesti sul documento') ?></span>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-grid">
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="transport_reason"><?= trans('transport_reason', '', 'Causale del trasporto') ?></label>
                        <select name="transport_reason" id="transport_reason" class="form-control simple-select">
                            <?php foreach ($reasons as $r): ?>
                                <option value="<?= esc($r, 'attr') ?>" <?= (string) $model->formValue('transport_reason') === $r ? 'selected' : '' ?>><?= esc($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="goods_appearance"><?= trans('goods_appearance', '', 'Aspetto esteriore dei beni') ?></label>
                        <input type="text" name="goods_appearance" id="goods_appearance" class="form-control"
                               placeholder="<?= esc(trans('goods_appearance_hint', '', 'Es. Scatole, Bancali'), 'attr') ?>"
                               value="<?= esc(old('goods_appearance', $model->formValue('goods_appearance'))) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="packages"><?= trans('packages', '', 'Numero colli') ?></label>
                        <input type="text" name="packages" id="packages" class="form-control"
                               value="<?= esc(old('packages', $model->formValue('packages'))) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="weight"><?= trans('weight', '', 'Peso') ?></label>
                        <input type="text" name="weight" id="weight" class="form-control"
                               value="<?= esc(old('weight', $model->formValue('weight'))) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="carrier"><?= trans('carrier', '', 'Vettore') ?></label>
                        <input type="text" name="carrier" id="carrier" class="form-control"
                               value="<?= esc(old('carrier', $model->formValue('carrier'))) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="transport_charge"><?= trans('transport_charge', '', 'Porto') ?></label>
                        <select name="transport_charge" id="transport_charge" class="form-control simple-select">
                            <?php foreach ($charges as $k => $label): ?>
                                <option value="<?= esc($k, 'attr') ?>" <?= (string) $model->formValue('transport_charge') === (string) $k ? 'selected' : '' ?>><?= esc($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="transport_date"><?= trans('transport_date', '', 'Inizio trasporto — data') ?></label>
                        <input type="text" name="transport_date" id="transport_date" class="form-control datepicker"
                               value="<?= esc($transportDt ? date_from_mysql(substr($transportDt, 0, 10)) : date(date_format_setting())) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="transport_time"><?= trans('transport_time', '', 'Inizio trasporto — ora') ?></label>
                        <input type="time" name="transport_time" id="transport_time" class="form-control"
                               value="<?= esc($transportDt ? substr($transportDt, 11, 5) : date('H:i')) ?>">
                    </div>
                </div>

                <div class="ip-doc-field" style="margin-top:16px">
                    <label class="ip-doc-label" for="delivery_note_notes"><?= trans('note', '', 'Note') ?></label>
                    <textarea name="delivery_note_notes" id="delivery_note_notes" rows="2" class="form-control"><?= esc($model->formValue('delivery_note_notes')) ?></textarea>
                </div>
            </div>
        </section>
    </div>
</form>

<template id="ddt_row_template">
    <tr class="ip-item-row">
        <td class="ip-items-table__desc">
            <input type="text" class="form-control input-sm" data-field="item_name"
                   placeholder="<?= esc(trans('item', '', 'Articolo'), 'attr') ?>"
                   aria-label="<?= esc(trans('item', '', 'Articolo'), 'attr') ?>">
            <textarea rows="1" class="form-control input-sm ip-item-row__desc" data-field="item_description"
                      placeholder="<?= esc(trans('description', '', 'Descrizione'), 'attr') ?>"
                      aria-label="<?= esc(trans('description', '', 'Descrizione'), 'attr') ?>"></textarea>
        </td>
        <td class="ip-items-table__num">
            <input type="text" class="form-control input-sm amount" data-field="item_quantity" value="1"
                   aria-label="<?= esc(trans('quantity', '', 'Qtà'), 'attr') ?>">
        </td>
        <td class="ip-items-table__tax">
            <input type="text" class="form-control input-sm" data-field="item_unit"
                   placeholder="<?= esc(trans('unit', '', 'pz'), 'attr') ?>"
                   aria-label="<?= esc(trans('product_unit', '', 'Unità di misura'), 'attr') ?>">
        </td>
        <td class="ip-items-table__actions">
            <button type="button" class="ip-item-row__remove" data-action="remove-row"
                    aria-label="<?= esc(trans('delete', '', 'Elimina'), 'attr') ?>"><i class="fa fa-solid fa-trash-can" aria-hidden="true"></i></button>
        </td>
    </tr>
</template>

<script>
(function () {
    var form = document.getElementById('ddt_form');
    if (!form) { return; }

    var body = document.getElementById('ddt_items_body');
    var tpl  = document.getElementById('ddt_row_template');
    var json = document.getElementById('ddt_items_json');

    function fieldsOf(row) {
        var map = {};
        row.querySelectorAll('[data-field]').forEach(function (el) { map[el.dataset.field] = el; });
        return map;
    }

    function addRow(values) {
        var frag = document.importNode(tpl.content, true);
        var row  = frag.querySelector('.ip-item-row');

        if (values) {
            var f = fieldsOf(row);
            Object.keys(values).forEach(function (k) { if (f[k]) { f[k].value = values[k]; } });
        }

        body.appendChild(frag);
        return body.lastElementChild;
    }

    function ensureRow() { if (!body.querySelector('.ip-item-row')) { addRow(); } }

    document.getElementById('ddt_add_row').addEventListener('click', function () {
        var first = addRow().querySelector('[data-field="item_name"]');
        if (first) { first.focus(); }
    });

    body.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-action="remove-row"]');
        if (!btn) { return; }
        btn.closest('.ip-item-row').remove();
        ensureRow();
    });

    form.addEventListener('submit', function () {
        var items = [];

        body.querySelectorAll('.ip-item-row').forEach(function (row) {
            var f = fieldsOf(row), item = {};
            Object.keys(f).forEach(function (k) { item[k] = f[k].value; });
            // Le righe lasciate vuote non vanno salvate: la tabella parte
            // sempre con una riga pronta, che spesso resta intatta.
            if (item.item_name || item.item_description) { items.push(item); }
        });

        json.value = items.length ? JSON.stringify(items) : '';
    });

    <?php
    $existing = array_map(static fn($i) => [
        'item_name'        => $i['item_name'] ?? '',
        'item_description' => $i['item_description'] ?? '',
        'item_quantity'    => format_amount($i['item_quantity'] ?? 0),
        'item_unit'        => $i['item_unit'] ?? '',
    ], $items ?? []);
    ?>
    var existing = <?= json_encode($existing, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    existing.forEach(function (i) { addRow(i); });
    ensureRow();
})();
</script>
<?= $this->endSection() ?>
