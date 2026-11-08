<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('expense', '', 'Spesa')); ?>

<?php
// Dalle spese importate come fattura elettronica si arriva all'anteprima XML
// con il tracciato (XSLT), come per le fatture emesse.
$expense_actions = '';

if (!empty($expense_id) && $model->formValue('expense_source') === 'xml') {
    $expense_actions .= '<a class="btn btn-sm btn-secondary" href="' . site_url('expenses/preview_xml/' . (int) $expense_id) . '">'
        . '<i class="fa fa-solid fa-eye" aria-hidden="true"></i> ' . trans('xml_preview', '', 'Anteprima XML') . '</a>';
}

$expense_actions .= header_actions();
?>

<form method="post" action="<?= site_url('expenses/form/' . ($expense_id ?? '')) ?>" class="ip-doc-form">
    <?= csrf_field() ?>

    <?= $this->include('layout/headerbar', ['title' => trans('expense', '', 'Spesa'), 'actions' => $expense_actions]) ?>

    <div id="content">
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">1</span>
                <h2 class="ip-doc-section__title"><?= trans('supplier', '', 'Fornitore') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-grid">
                    <?php /* Anagrafica fornitori: scegliendone uno i campi sotto si
                             compilano da soli. Restano modificabili, e restano
                             validi anche senza selezione — le spese registrate
                             prima dell'anagrafica hanno solo il testo. */ ?>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="supplier_id"><?= trans('supplier_registry', '', 'Da anagrafica') ?></label>
                        <select name="supplier_id" id="supplier_id" class="form-control simple-select"
                                data-placeholder="<?= esc(trans('supplier_none', '', 'Nessuno — inserisci a mano'), 'attr') ?>" data-allow-clear>
                            <option value=""></option>
                            <?php foreach (($suppliers ?? []) as $sup): ?>
                                <option value="<?= (int) $sup['supplier_id'] ?>"
                                        data-name="<?= esc($sup['supplier_name'], 'attr') ?>"
                                        data-vat="<?= esc($sup['supplier_vat_id'] ?? '', 'attr') ?>"
                                        data-tax="<?= esc($sup['supplier_tax_code'] ?? '', 'attr') ?>"
                                    <?= (string) old('supplier_id', $model->formValue('supplier_id')) === (string) $sup['supplier_id'] ? 'selected' : '' ?>>
                                    <?= esc($sup['supplier_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="supplier_name"><?= trans('supplier', '', 'Fornitore') ?></label>
                        <input type="text" name="supplier_name" id="supplier_name" class="form-control"
                               value="<?= esc(old('supplier_name', $model->formValue('supplier_name'))) ?>">
                        <?php if (!empty($errors['supplier_name'])): ?>
                            <span class="ip-field-error"><?= esc($errors['supplier_name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="supplier_vat"><?= trans('vat_id', '', 'P. IVA') ?></label>
                        <input type="text" name="supplier_vat" id="supplier_vat" class="form-control"
                               value="<?= esc(old('supplier_vat', $model->formValue('supplier_vat'))) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="supplier_tax_code"><?= trans('tax_code', '', 'Cod. fiscale') ?></label>
                        <input type="text" name="supplier_tax_code" id="supplier_tax_code" class="form-control"
                               value="<?= esc(old('supplier_tax_code', $model->formValue('supplier_tax_code'))) ?>">
                    </div>
                </div>
            </div>
        </section>

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">2</span>
                <h2 class="ip-doc-section__title"><?= trans('document_data', '', 'Dati documento') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-grid">
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="expense_date"><?= trans('date', '', 'Data') ?></label>
                        <input type="text" name="expense_date" id="expense_date" class="form-control datepicker"
                               value="<?= esc(old('expense_date', $model->formValue('expense_date') ? date_from_mysql($model->formValue('expense_date')) : date(date_format_setting()))) ?>">
                        <?php if (!empty($errors['expense_date'])): ?>
                            <span class="ip-field-error"><?= esc($errors['expense_date']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="expense_number"><?= trans('number', '', 'Numero') ?></label>
                        <input type="text" name="expense_number" id="expense_number" class="form-control"
                               value="<?= esc(old('expense_number', $model->formValue('expense_number'))) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="expense_category_id"><?= trans('category', '', 'Categoria') ?></label>
                        <select name="expense_category_id" id="expense_category_id" class="form-control simple-select">
                            <option value=""><?= trans('none', '', 'Nessuna') ?></option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['expense_category_id'] ?>"
                                    <?= (int) $model->formValue('expense_category_id') === (int) $category['expense_category_id'] ? 'selected' : '' ?>>
                                    <?= esc($category['expense_category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php /* Voce del Bilancino: la categoria serve a raggruppare le
                             spese nella pagina Spese, questa a leggerle nel conto
                             economico riclassificato. Sono due assi diversi e
                             possono non coincidere (es. "Utenze" è una categoria,
                             "Costi fissi" è il tipo nel Bilancino). */ ?>
                    <?php if (($costing_accounts ?? []) !== []): ?>
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="costing_account_id"><?= trans('management_cost_account', '', 'Voce di costo') ?></label>
                            <select name="costing_account_id" id="costing_account_id" class="form-control simple-select"
                                    data-placeholder="<?= esc(trans('management_no_account', '', 'Nessuna voce'), 'attr') ?>"
                                    data-allow-clear="true">
                                <option value=""><?= trans('management_no_account', '', 'Nessuna voce') ?></option>
                                <?php foreach ($costing_accounts as $accountId => $accountLabel): ?>
                                    <option value="<?= (int) $accountId ?>"
                                        <?= (string) $model->formValue('costing_account_id') === (string) $accountId ? 'selected' : '' ?>>
                                        <?= esc($accountLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">3</span>
                <h2 class="ip-doc-section__title"><?= trans('amounts', '', 'Importi') ?></h2>
                <span class="ip-doc-section__hint"><?= trans('expense_total_hint', '', 'Lascia vuoto il totale per calcolarlo da imponibile e IVA.') ?></span>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-grid">
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="expense_amount"><?= trans('subtotal', '', 'Imponibile') ?></label>
                        <input type="text" name="expense_amount" id="expense_amount" class="form-control amount"
                               value="<?= esc(format_amount($model->formValue('expense_amount'))) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="expense_tax"><?= trans('tax', '', 'IVA') ?></label>
                        <input type="text" name="expense_tax" id="expense_tax" class="form-control amount"
                               value="<?= esc(format_amount($model->formValue('expense_tax'))) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="expense_total"><?= trans('total', '', 'Totale') ?></label>
                        <input type="text" name="expense_total" id="expense_total" class="form-control amount"
                               value="<?= esc(format_amount($model->formValue('expense_total'))) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="expense_paid"><?= trans('paid', '', 'Pagato') ?></label>
                        <input type="text" name="expense_paid" id="expense_paid" class="form-control amount"
                               value="<?= esc(format_amount($model->formValue('expense_paid'))) ?>">
                    </div>
                    <?php if (!empty($bank_accounts)): ?>
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="bank_account_id"><?= trans('bank_account', '', 'Conto di saldo') ?></label>
                            <select name="bank_account_id" id="bank_account_id" class="form-control simple-select">
                                <option value=""><?= trans('none', '', 'Nessuno') ?></option>
                                <?php foreach ($bank_accounts as $account): ?>
                                    <option value="<?= (int) $account['bank_account_id'] ?>"
                                        <?= (int) $model->formValue('bank_account_id') === (int) $account['bank_account_id'] ? 'selected' : '' ?>>
                                        <?= esc($account['bank_account_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="ip-doc-field" style="margin-top:16px">
                    <label class="ip-doc-label" for="expense_note"><?= trans('note', '', 'Nota') ?></label>
                    <textarea name="expense_note" id="expense_note" rows="2" class="form-control"><?= esc($model->formValue('expense_note')) ?></textarea>
                </div>
            </div>
        </section>
    </div>
</form>

<script>
    // Scelto un fornitore, i tre campi di testo si allineano. Non si tocca
    // quello che l'utente ha gia' scritto se sceglie "Nessuno": il testo resta
    // la fonte per le spese non collegate.
    (function () {
        var sel = document.getElementById('supplier_id');
        if (!sel) { return; }

        sel.addEventListener('change', function () {
            var opt = sel.options[sel.selectedIndex];
            if (!opt || !opt.value) { return; }

            var map = {supplier_name: 'name', supplier_vat: 'vat', supplier_tax_code: 'tax'};
            Object.keys(map).forEach(function (id) {
                var input = document.getElementById(id);
                if (input) { input.value = opt.getAttribute('data-' + map[id]) || ''; }
            });
        });
    })();
</script>

<?= $this->endSection() ?>
