<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php // Chiave dedicata: trans('new') vale 'Nuova' (accordato sui documenti) e dava "Nuova Pagamento".
$title = $payment_id ? trans('edit') . ' ' . trans('payment') : trans('new_payment', '', 'Nuovo pagamento'); ?>
<?php $this->setVar('pageTitle', $title); ?>

<form method="POST" action="<?= site_url('payments/form/' . ($payment_id ?? '')) ?>" class="ip-doc-form">
    <?= csrf_field() ?>
    <?php if ($payment_id): ?>
        <input type="hidden" name="payment_id" value="<?= $payment_id ?>">
    <?php endif; ?>

    <?= $this->include('layout/headerbar', ['title' => $title, 'actions' => header_actions()]) ?>

    <div id="content">
        <?php /* Sezione canonica con griglia a due colonne, come le altre schede
                 (spese, fattura, preventivo). Prima la pagina era una card
                 Bootstrap su col-md-6: il form stava schiacciato nella metà
                 sinistra con mezza schermata vuota accanto, e ogni campo
                 occupava tutta la larghezza della card anche quando erano due
                 dati brevi (data e importo) che stanno bene appaiati. */ ?>
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">1</span>
                <h2 class="ip-doc-section__title"><?= trans('payment') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-grid">
                    <?php /* Il filtro cliente non è un dato del pagamento: sfoltisce
                             l'elenco delle fatture aperte qui sotto, che altrimenti
                             contiene i documenti di tutti i clienti. */ ?>
                    <div class="ip-doc-field ip-doc-field--full">
                        <label class="ip-doc-label" for="client_filter"><?= trans('client_filter') ?></label>
                        <select id="client_filter" class="form-control simple-select">
                            <option value=""><?= trans('all_clients') ?></option>
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= esc($client['client_id']) ?>"><?= esc(format_client($client)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ip-doc-field ip-doc-field--full">
                        <label class="ip-doc-label" for="invoice_id"><?= trans('invoice') ?></label>
                        <select name="invoice_id" id="invoice_id" class="form-control simple-select" required>
                            <?php if ($payment_id && $payment): ?>
                                <option value="<?= esc($payment['invoice_id']) ?>" data-client-id="<?= esc($payment['client_id'] ?? '') ?>"
                                    data-credit="<?= (float) ($payment['invoice_total'] ?? 0) < 0 ? 1 : 0 ?>" selected>
                                    <?= esc($payment['invoice_number']) ?> - <?= esc(format_client($payment)) ?> - <?= format_currency($payment['invoice_balance']) ?>
                                </option>
                            <?php endif; ?>
                            <?php foreach ($open_invoices as $invoice): ?>
                                <?php if ($payment_id && $payment && (int) $invoice['invoice_id'] === (int) $payment['invoice_id']): ?>
                                    <?php continue; ?>
                                <?php endif; ?>
                                <option value="<?= esc($invoice['invoice_id']) ?>" data-client-id="<?= esc($invoice['client_id']) ?>"
                                    data-credit="<?= (float) ($invoice['invoice_total'] ?? 0) < 0 ? 1 : 0 ?>"
                                    <?= (string) $model->formValue('invoice_id') === (string) $invoice['invoice_id'] ? 'selected' : '' ?>>
                                    <?= esc($invoice['invoice_number']) ?> - <?= esc(format_client($invoice)) ?> - <?= format_currency($invoice['invoice_balance']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (! empty($errors['invoice_id'])): ?>
                            <div class="ip-field-error"><?= esc($errors['invoice_id']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="payment_date"><?= trans('date') ?></label>
                        <input type="text" name="payment_date" id="payment_date" class="form-control datepicker"
                               value="<?= esc(date_from_mysql($model->formValue('payment_date'))) ?>">
                        <?php if (! empty($errors['payment_date'])): ?>
                            <div class="ip-field-error"><?= esc($errors['payment_date']) ?></div>
                        <?php endif; ?>
                    </div>

                    <?php
                    $paymentAmountValue   = $model->formValue('payment_amount');
                    $paymentAmountDisplay = $paymentAmountValue === ''
                        ? ''
                        : (is_numeric($paymentAmountValue) ? format_amount($paymentAmountValue) : $paymentAmountValue);
                    ?>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="payment_amount"><?= trans('amount') ?></label>
                        <input type="text" name="payment_amount" id="payment_amount" class="form-control" value="<?= esc($paymentAmountDisplay) ?>"
                               aria-describedby="payment_refund_hint">
                        <?php // Visibile solo quando la fattura scelta è una nota di credito (script in fondo). ?>
                        <p class="ip-field-help" id="payment_refund_hint" hidden>
                            <?= trans('payment_refund_hint', '', 'Nota di credito: è un rimborso al cliente. Scrivi l\'importo in positivo, viene registrato in negativo.') ?>
                        </p>
                        <?php if (! empty($errors['payment_amount'])): ?>
                            <div class="ip-field-error"><?= esc($errors['payment_amount']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="ip-doc-field ip-doc-field--full">
                        <label class="ip-doc-label" for="payment_method_id"><?= trans('payment_method') ?></label>
                        <select name="payment_method_id" id="payment_method_id" class="form-control simple-select">
                            <option value="0"><?= trans('select_payment_method') ?></option>
                            <?php foreach ($payment_methods as $payment_method): ?>
                                <option value="<?= esc($payment_method['payment_method_id']) ?>"
                                    <?= (string) $model->formValue('payment_method_id') === (string) $payment_method['payment_method_id'] ? 'selected' : '' ?>>
                                    <?= esc($payment_method['payment_method_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php // Ripartizione su più metodi: solo per un nuovo incasso (vedi Payments::saveSplit()). ?>
                    <?php if (! $payment_id): ?>
                        <div class="ip-doc-field ip-doc-field--full" role="group" aria-labelledby="payment_split_label">
                            <span class="ip-doc-label" id="payment_split_label"><?= trans('payment_split', '', 'Altri metodi di pagamento') ?></span>
                            <p class="ip-field-help payment-split-help"><?= trans('payment_split_hint', '', 'Se il cliente ha pagato in parte con un metodo e in parte con un altro, scrivi sopra la prima quota e aggiungi qui le altre: viene registrato un pagamento per ciascun metodo.') ?></p>
                            <div id="payment_split_rows" class="payment-split-rows"></div>
                            <button type="button" class="btn btn-secondary btn-sm" id="payment_split_add">
                                <i class="fa fa-plus" aria-hidden="true"></i> <?= trans('payment_split_add', '', 'Aggiungi metodo') ?>
                            </button>
                            <template id="payment_split_tpl">
                                <div class="payment-split-row">
                                    <input type="text" name="split_amount[]" class="form-control payment-split-amount" aria-label="<?= esc(trans('amount')) ?>" placeholder="<?= esc(trans('amount')) ?>">
                                    <select name="split_method[]" class="form-control simple-select" aria-label="<?= esc(trans('payment_method')) ?>">
                                        <option value="0"><?= trans('select_payment_method') ?></option>
                                        <?php foreach ($payment_methods as $payment_method): ?>
                                            <option value="<?= esc($payment_method['payment_method_id']) ?>"><?= esc($payment_method['payment_method_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" class="btn btn-secondary btn-sm payment-split-remove" aria-label="<?= esc(trans('delete')) ?>"><i class="fa fa-trash-can" aria-hidden="true"></i></button>
                                </div>
                            </template>
                        </div>
                    <?php endif; ?>

                    <div class="ip-doc-field ip-doc-field--full">
                        <label class="ip-doc-label" for="payment_note"><?= trans('notes') ?></label>
                        <textarea name="payment_note" id="payment_note" rows="3" class="form-control"><?= esc($model->formValue('payment_note')) ?></textarea>
                    </div>

                    <?php foreach ($custom_fields as $custom_field): ?>
                        <?php
                        $fieldKey    = 'custom[' . $custom_field['custom_field_id'] . ']';
                        $fieldValue  = $model->formValue($fieldKey);
                        $fieldType   = $custom_field['custom_field_type'];
                        $fieldValues = $custom_values[$custom_field['custom_field_id']] ?? [];
                        // MULTIPLE-CHOICE esce come lista di checkbox (vedi CLAUDE.md): li'
                        // l'etichetta e' il titolo di un gruppo, non la label di un singolo
                        // controllo, quindi diventa <span> + aria-labelledby sul contenitore.
                        $fieldCtl   = 'custom_' . $custom_field['custom_field_id'];
                        $fieldMulti = $fieldType === 'MULTIPLE-CHOICE';
                        ?>
                        <div class="ip-doc-field ip-doc-field--full"<?= $fieldMulti ? ' role="group" aria-labelledby="' . $fieldCtl . '-label"' : '' ?>>
                            <?php if ($fieldMulti): ?>
                                <span class="ip-doc-label" id="<?= $fieldCtl ?>-label"><?= esc($custom_field['custom_field_label']) ?></span>
                            <?php else: ?>
                                <label class="ip-doc-label" for="<?= $fieldCtl ?>"><?= esc($custom_field['custom_field_label']) ?></label>
                            <?php endif; ?>

                            <?php if ($fieldType === 'TEXT'): ?>
                                <input type="text" name="<?= esc($fieldKey) ?>" id="<?= $fieldCtl ?>" value="<?= esc($fieldValue) ?>" class="form-control">

                            <?php elseif ($fieldType === 'DATE'): ?>
                                <input type="text" name="<?= esc($fieldKey) ?>" id="<?= $fieldCtl ?>" value="<?= esc(date_from_mysql($fieldValue)) ?>" class="form-control datepicker">

                            <?php elseif ($fieldType === 'BOOLEAN'): ?>
                                <input type="checkbox" name="<?= esc($fieldKey) ?>" id="<?= $fieldCtl ?>" value="1" <?= $fieldValue ? 'checked' : '' ?>>

                            <?php elseif ($fieldType === 'SINGLE-CHOICE'): ?>
                                <select name="<?= esc($fieldKey) ?>" id="<?= $fieldCtl ?>" class="form-control simple-select">
                                    <option value=""><?= trans('none') ?></option>
                                    <?php foreach ($fieldValues as $cv): ?>
                                        <option value="<?= esc($cv['custom_values_value']) ?>"
                                            <?= (string) $fieldValue === (string) $cv['custom_values_value'] ? 'selected' : '' ?>>
                                            <?= esc($cv['custom_values_value']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                            <?php elseif ($fieldType === 'MULTIPLE-CHOICE'): ?>
                                <?php $selectedValues = $fieldValue !== '' ? explode(',', (string) $fieldValue) : []; ?>
                                <?php foreach ($fieldValues as $cv): ?>
                                    <label class="ip-choice-option">
                                        <input type="checkbox" name="<?= esc($fieldKey) ?>[]"
                                               value="<?= esc($cv['custom_values_value']) ?>"
                                            <?= in_array((string) $cv['custom_values_value'], $selectedValues, true) ? 'checked' : '' ?>>
                                        <?= esc($cv['custom_values_value']) ?>
                                    </label>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>
</form>

<script>
    // IIFE: la pagina può essere rimontata dalla navigazione parziale
    // (ip-pjax.js). Dichiarazioni const al livello dello script
    // sopravviverebbero al documento e alla seconda esecuzione darebbero
    // "Identifier ... has already been declared".
    (function () {
    const amounts = <?= $amounts ?>;
    const invoicePaymentMethods = <?= $invoice_payment_methods ?>;
    const invoiceSelect = document.getElementById('invoice_id');
    const clientFilter = document.getElementById('client_filter');

    clientFilter.addEventListener('change', function () {
        const clientId = this.value;
        for (const option of invoiceSelect.options) {
            option.hidden = clientId !== '' && option.dataset.clientId !== clientId;
        }
    });

    // Il suggerimento sui rimborsi segue la fattura scelta: le note di
    // credito ora compaiono fra i documenti aperti, e l'importo si scrive
    // in positivo anche per loro.
    const refundHint = document.getElementById('payment_refund_hint');
    function toggleRefundHint() {
        const selected = invoiceSelect.options[invoiceSelect.selectedIndex];
        refundHint.hidden = !(selected && selected.dataset.credit === '1');
    }
    toggleRefundHint();

    invoiceSelect.addEventListener('change', function () {
        toggleRefundHint();
        const key = 'invoice' + this.value;
        if (amounts[key] !== undefined) {
            document.getElementById('payment_amount').value = String(amounts[key]).replace('&nbsp;', ' ');
        }
        if (invoicePaymentMethods[key] !== undefined && invoicePaymentMethods[key] != 0) {
            document.getElementById('payment_method_id').value = String(invoicePaymentMethods[key]);
        }
    });

    // Righe di ripartizione: clonate dal <template>, IPSelect le inizializza
    // da solo tramite il MutationObserver di ip-components.js.
    const splitAdd = document.getElementById('payment_split_add');
    if (splitAdd) {
        const splitRows = document.getElementById('payment_split_rows');
        const splitTpl = document.getElementById('payment_split_tpl');
        splitAdd.addEventListener('click', function () {
            splitRows.appendChild(splitTpl.content.cloneNode(true));
            splitRows.lastElementChild.querySelector('input').focus();
        });
        splitRows.addEventListener('click', function (e) {
            const btn = e.target.closest('.payment-split-remove');
            if (btn) btn.closest('.payment-split-row').remove();
        });
    }
    })();
</script>

<?= $this->endSection() ?>
