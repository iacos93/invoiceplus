<div class="table-responsive">
    <table class="table table-hover ip-clickable-rows">

        <thead>
        <tr>
            <th class="ip-bulk-col" scope="col">
                <label class="ip-bulk-hit">
                    <input type="checkbox" class="form-check-input" id="ip-bulk-all"
                           aria-label="<?= esc(trans('bulk_select_all_payments', '', 'Seleziona tutti i pagamenti in pagina'), 'attr') ?>">
                </label>
            </th>
            <th scope="col"><?= trans('status', '', 'Stato') ?></th>
            <th scope="col"><?= trans('payment_date', '', 'Data pagamento') ?></th>
            <th scope="col"><?= trans('invoice_date', '', 'Data fattura') ?></th>
            <th scope="col"><?= trans('invoice', '', 'Fattura') ?></th>
            <th scope="col"><?= trans('client', '', 'Cliente') ?></th>
            <th scope="col" class="amount"><?= trans('amount', '', 'Importo') ?></th>
            <th scope="col"><?= trans('payment_method', '', 'Metodo di pagamento') ?></th>
            <th scope="col"><?= trans('note', '', 'Nota') ?></th>
            <th scope="col"><?= trans('options', '', 'Opzioni') ?></th>
        </tr>
        </thead>

        <tbody>
        <?php foreach ($payments as $payment): ?>
            <?php $paymentStatus = payment_status_badge($payment); ?>
            <tr>
                <td class="ip-bulk-col">
                    <label class="ip-bulk-hit">
                        <input type="checkbox" class="form-check-input ip-bulk-check" form="ip-bulk-form"
                               name="ids[]" value="<?= (int) $payment['payment_id'] ?>"
                               aria-label="<?= esc(trans('bulk_select_row_payments', '', 'Seleziona il pagamento') . ' #' . (int) $payment['payment_id'], 'attr') ?>">
                    </label>
                </td>
                <td>
                    <span class="badge ip-status <?= esc($paymentStatus['class'], 'attr') ?>"><?= esc($paymentStatus['label']) ?></span>
                </td>
                <td><?= date_from_mysql($payment['payment_date']) ?></td>
                <td><?= date_from_mysql($payment['invoice_date_created'] ?? '') ?></td>
                <td>
                    <a href="<?= site_url('invoices/view/' . (int) $payment['invoice_id']) ?>">
                        <?= esc($payment['invoice_number'] ?: ('#' . $payment['invoice_id'])) ?>
                    </a>
                </td>
                <td><?= esc(format_client($payment)) ?></td>
                <td class="amount"><?= format_currency($payment['payment_amount']) ?></td>
                <td><?= esc($payment['payment_method_name'] ?? '') ?></td>
                <td><?= esc($payment['payment_note'] ?? '') ?></td>
                <td>
                    <div class="options btn-group">
                        <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options', '', 'Opzioni') ?>
                        </button>
                        <ul class="dropdown-menu">
                            <li>
                                <a href="<?= site_url('invoices/view/' . (int) $payment['invoice_id']) ?>">
                                    <i class="fa fa-solid fa-eye fa-margin" aria-hidden="true"></i> <?= trans('view', '', 'Vista') ?>
                                </a>
                            </li>
                            <li>
                                <form action="<?= site_url('payments/delete/' . (int) $payment['payment_id']) ?>"
                                      method="POST">
                                    <?= csrf_field() ?>
                                    <button type="button" class="dropdown-button"
                                            data-bs-toggle="confirm" data-confirm-title="<?= esc(trans('confirm', '', 'Conferma')) ?>" data-confirm-body="<?= esc(trans('delete_payment_warning', '', 'Eliminare il pagamento?')) ?>" data-confirm-ok="<?= esc(trans('delete', '', 'Elimina')) ?>" data-confirm-danger="1">
                                        <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete', '', 'Elimina') ?>
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($payments)): ?>
            <tr><td colspan="10"><?= trans('no_payments_found') ?></td></tr>
        <?php endif; ?>
        </tbody>

    </table>
</div>
<input type="hidden" name="csrf_test_name" value="<?= csrf_hash() ?>">
