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
        if (typeof jQuery === 'undefined') return;
        var $modal = $('#modal_add_payment');

        // I select con ricerca e i datepicker dentro la modale sono
        // inizializzati da ip-components.js, che li aggancia da solo (i
        // pannelli vengono montati dentro .modal per non finire fuori dal
        // focus trap di Bootstrap).
        $modal.on('shown.bs.modal', function () {
            if (window.IPComponents) { window.IPComponents.init($modal[0]); }
        });

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