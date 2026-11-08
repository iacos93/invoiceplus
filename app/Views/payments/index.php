<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('payments')); ?>
<?php
$actions = '<a class="btn btn-sm btn-primary" href="' . site_url('payments/form') . '">'
         . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('enter_payment') . '</a>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('payments'), 'actions' => $actions]) ?>

<div id="content" class="table-content">
    <?php
    /* Azioni di gruppo, come nelle fatture e nelle spese. Il modulo sta fuori
       dalla card e contiene solo la barra: avvolgere la tabella avrebbe
       annidato i moduli di riga già presenti (elimina) e, con la ricerca live,
       la barra verrebbe ridisegnata a ogni filtro perdendo la selezione. */
    ?>
    <form method="POST" action="<?= site_url('payments/bulk') ?>" id="ip-bulk-form" class="ip-bulk">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= esc(current_url(true), 'attr') ?>">

        <div class="ip-bulk-bar" id="ip-bulk-bar" hidden>
            <span class="ip-bulk-bar__count" id="ip-bulk-count"
                  data-template="<?= esc(trans('bulk_selected', '', '%s selezionati'), 'attr') ?>"></span>
            <div class="ip-bulk-bar__actions">
                <button type="submit" name="bulk_action" value="delete" class="btn btn-sm btn-secondary ip-bulk-bar__danger"
                        data-bs-toggle="confirm"
                        data-confirm-title="<?= esc(trans('confirm', '', 'Conferma'), 'attr') ?>"
                        data-confirm-body="<?= esc(trans('bulk_delete_confirm_payments', '', 'Eliminare i pagamenti selezionati? Il saldo delle fatture viene ricalcolato e, se un incasso era l\'ultimo, la fattura torna "inviata". Il pagamento non si recupera.'), 'attr') ?>"
                        data-confirm-ok="<?= esc(trans('delete', '', 'Elimina'), 'attr') ?>" data-confirm-danger="1">
                    <i class="fa fa-solid fa-trash-can" aria-hidden="true"></i> <?= trans('delete', '', 'Elimina') ?>
                </button>
                <button type="button" class="btn btn-sm btn-secondary" id="ip-bulk-clear">
                    <?= trans('bulk_clear', '', 'Annulla selezione') ?>
                </button>
            </div>
        </div>
    </form>

    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-money-bill-1" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('payments')) ?></span>
                <span class="badge text-bg-secondary"><?= count($payments) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search"
                 data-target="#payments-table-wrap"
                 data-action="<?= site_url('filter/filter_payments') ?>">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca pagamento, fattura, cliente…')) ?>"
                       autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>
    <div id="payments-table-wrap">
    <div class="table-responsive">
        <table class="table table-hover ip-table-card-collapse">
            <thead>
            <tr>
                <th class="ip-bulk-col ip-hide-on-mobile" scope="col">
                    <?php /* La casella sta dentro una <label>: da sola è 16x16,
                             sotto il minimo di 24px per un bersaglio (WCAG 2.5.8).
                             L'etichetta porta l'area cliccabile a 24x24 senza
                             ingrandire il quadratino. */ ?>
                    <label class="ip-bulk-hit">
                        <input type="checkbox" class="form-check-input" id="ip-bulk-all"
                               aria-label="<?= esc(trans('bulk_select_all_payments', '', 'Seleziona tutti i pagamenti in pagina'), 'attr') ?>">
                    </label>
                </th>
                <th scope="col"><?= trans('status', '', 'Stato') ?></th>
                <th scope="col"><?= trans('date') ?></th>
                <th scope="col"><?= trans('invoice') ?></th>
                <th scope="col"><?= trans('client') ?></th>
                <th scope="col" class="amount"><?= trans('amount') ?></th>
                <th scope="col"><?= trans('payment_method') ?></th>
                <th scope="col"><?= trans('options') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($payments as $payment): ?>
                <?php $paymentStatus = payment_status_badge($payment); ?>
                <tr>
                    <td class="ip-bulk-col ip-hide-on-mobile">
                        <label class="ip-bulk-hit">
                            <input type="checkbox" class="form-check-input ip-bulk-check" form="ip-bulk-form"
                                   name="ids[]" value="<?= (int) $payment['payment_id'] ?>"
                                   aria-label="<?= esc(trans('bulk_select_row_payments', '', 'Seleziona il pagamento') . ' #' . (int) $payment['payment_id'], 'attr') ?>">
                        </label>
                    </td>
                    <td data-label="<?= esc(trans('status', '', 'Stato')) ?>">
                        <span class="badge ip-status <?= esc($paymentStatus['class'], 'attr') ?>"><?= esc($paymentStatus['label']) ?></span>
                    </td>
                    <td data-label="<?= esc(trans('date')) ?>"><?= esc(date_from_mysql($payment['payment_date'])) ?></td>
                    <td data-label="<?= esc(trans('invoice')) ?>">
                        <a href="<?= site_url('invoices/view/' . $payment['invoice_id']) ?>">
                            <span class="ip-num"><?= esc($payment['invoice_number']) ?></span>
                        </a>
                    </td>
                    <td data-label="<?= esc(trans('client')) ?>">
                        <a href="<?= site_url('clients/view/' . $payment['client_id']) ?>">
                            <?= esc(format_client($payment)) ?>
                        </a>
                    </td>
                    <td class="amount" data-label="<?= esc(trans('amount')) ?>"><?= format_currency($payment['payment_amount']) ?></td>
                    <td data-label="<?= esc(trans('payment_method')) ?>"><?= esc($payment['payment_method_name']) ?></td>
                    <td data-label="<?= esc(trans('options')) ?>">
                        <div class="options btn-group">
                            <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options') ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="<?= site_url('invoices/view/' . $payment['invoice_id']) ?>">
                                        <i class="fa fa-solid fa-eye fa-margin" aria-hidden="true"></i> <?= trans('view') ?>
                                    </a>
                                </li>
                                <li>
                                    <form action="<?= site_url('payments/delete/' . $payment['payment_id']) ?>" method="POST">
                                        <?= csrf_field() ?>
                                        <button type="button" class="dropdown-button"
                                                data-bs-toggle="confirm" data-confirm-title="<?= esc(trans('confirm', '', 'Conferma')) ?>" data-confirm-body="<?= esc(trans('delete_record_warning')) ?>" data-confirm-ok="<?= esc(trans('delete', '', 'Elimina')) ?>" data-confirm-danger="1">
                                            <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete') ?>
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($payments)): ?>
                <tr class="empty-row">
                    <td colspan="8">
                        <i class="fa fa-solid fa-money-bill-1 empty-icon" aria-hidden="true"></i>
                        <?= trans('no_records') ?>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    </div>
    </div>
</div>

<?= $this->endSection() ?>