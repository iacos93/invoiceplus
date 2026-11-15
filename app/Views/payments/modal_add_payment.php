<?php
// Modal per l'inserimento rapido di un pagamento per una fattura specifica
// (equivalente di modal_add_payment.php CI3). E' una partial AJAX: viene
// renderizzata da PaymentsAjax::modalAddPayment e mostrata dentro la pagina
// della fattura; il POST va a payments/ajax/add.
?>

<div class="modal fade" tabindex="-1" role="dialog"
     aria-labelledby="modal_add_payment_title" aria-hidden="true" id="modal_add_payment">
    <div class="modal-dialog modal-lg" role="document">
        <form class="modal-content" id="modal-payment-form">
            <?= csrf_field() ?>
            <input type="hidden" name="invoice_id" value="<?= esc($invoice_id) ?>">
            <input type="hidden" name="payment_cf_exist" id="payment_cf_exist" value="<?= esc($payment_cf_exist ?? 'no') ?>">

            <div class="modal-header">
                <h5 class="modal-title" id="modal_add_payment_title"><?= trans('enter_payment') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= trans('cancel') ?>"></button>
            </div>

            <div class="modal-body">
                <div class="ip-doc-field">
                    <label class="ip-doc-label" for="modal_payment_amount"><?= trans('amount') ?></label>
                    <input type="text" name="payment_amount" id="modal_payment_amount" class="form-control"
                           value="<?= isset($invoice_balance) && $invoice_balance !== '' ? esc(format_amount($invoice_balance)) : '' ?>">
                </div>

                <div class="ip-doc-field">
                    <label class="ip-doc-label" for="modal_payment_date"><?= trans('payment_date') ?></label>
                    <input type="text" name="payment_date" id="modal_payment_date" class="form-control datepicker"
                           value="<?= esc(date(date_format_setting())) ?>">
                </div>

                <div class="ip-doc-field">
                    <label class="ip-doc-label" for="modal_payment_method_id"><?= trans('payment_method') ?></label>
                    <select name="payment_method_id" id="modal_payment_method_id" class="form-control simple-select">
                        <option value="0"><?= trans('none') ?></option>
                        <?php foreach ($payment_methods as $payment_method): ?>
                            <option value="<?= esc($payment_method['payment_method_id']) ?>"
                                <?= ! empty($invoice_payment_method) && (string) $invoice_payment_method === (string) $payment_method['payment_method_id'] ? 'selected' : '' ?>>
                                <?= esc($payment_method['payment_method_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php
                // Conto di saldo: proposto dal metodo della fattura, così
                // l'incasso rapido finisce su un conto anche senza aprire il
                // form completo (da qui la prima nota lo vede).
                $modalAccount = default_bank_account_for_payment((int) ($invoice_payment_method ?? 0));
                ?>
                <div class="ip-doc-field">
                    <label class="ip-doc-label" for="modal_bank_account_id"><?= trans('bank_account', '', 'Conto di saldo') ?></label>
                    <select name="bank_account_id" id="modal_bank_account_id" class="form-control simple-select">
                        <option value="0"><?= trans('no_bank_account', '', 'Nessun conto') ?></option>
                        <?php foreach (($bank_accounts ?? []) as $bank_account): ?>
                            <option value="<?= (int) $bank_account['bank_account_id'] ?>"
                                <?= (string) $modalAccount === (string) $bank_account['bank_account_id'] ? 'selected' : '' ?>>
                                <?= esc($bank_account['bank_account_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ip-doc-field">
                    <label class="ip-doc-label" for="modal_payment_note"><?= trans('note') ?></label>
                    <textarea name="payment_note" id="modal_payment_note" rows="3" class="form-control"></textarea>
                </div>

                <div id="modal-payment-errors" class="ip-field-error" role="alert" aria-live="polite"></div>
            </div>

            <div class="modal-footer">
                <div class="btn-group">
                    <button type="button" class="btn btn-success" id="btn_modal_payment_submit">
                        <i class="fa fa-solid fa-check" aria-hidden="true"></i> <?= trans('submit') ?>
                    </button>
                    <button type="button" class="btn btn-danger" data-bs-dismiss="modal">
                        <i class="fa fa-solid fa-times" aria-hidden="true"></i> <?= trans('cancel') ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        var modalEl = document.getElementById('modal_add_payment');
        if (!modalEl) return;

        // I select con ricerca e i datepicker dentro la modale sono
        // inizializzati da ip-components.js, che li aggancia da solo (i
        // pannelli vengono montati dentro .modal per non finire fuori dal
        // focus trap di Bootstrap).
        modalEl.addEventListener('shown.bs.modal', function () {
            if (window.IPComponents) { window.IPComponents.init(modalEl); }
        });

        // Il conto segue il metodo scelto, finché l'utente non lo cambia: la
        // modale parte dal metodo della fattura, e questo copre i casi in cui
        // lo si cambia qui (parte in contanti e parte con bonifico).
        var modalMethod  = document.getElementById('modal_payment_method_id');
        var modalAccount = document.getElementById('modal_bank_account_id');
        var modalAccounts = <?= $method_accounts ?>;
        var modalTouched  = false;

        if (modalMethod && modalAccount) {
            modalAccount.addEventListener('change', function () { modalTouched = true; });
            modalMethod.addEventListener('change', function () {
                if (modalTouched) { return; }
                var proposta = modalAccounts[this.value];
                if (proposta) { modalAccount.value = String(proposta); }
            });
        }

        document.getElementById('btn_modal_payment_submit').addEventListener('click', function () {
            var form = document.getElementById('modal-payment-form');
            var data = new FormData(form);
            data.append('<?= csrf_token() ?>', '<?= csrf_hash() ?>');

            fetch('<?= site_url('payments/ajax/add') ?>', {
                method: 'POST',
                body: data,
                headers: {'X-Requested-With': 'XMLHttpRequest'}
            })
                .then(function (r) { return r.json(); })
                .then(function (response) {
                    if (response.success === 1) {
                        window.location = '<?= site_url('payments') ?>';
                    } else if (response.validation_errors) {
                        document.getElementById('modal-payment-errors').textContent =
                            Object.values(response.validation_errors).join(' ');
                    }
                });
        });
    })();
</script>