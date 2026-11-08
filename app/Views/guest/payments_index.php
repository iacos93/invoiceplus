<?= $this->extend('layout/guest') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('payments', '', 'Pagamenti')); ?>
<?= $this->include('layout/headerbar', ['title' => trans('payments', '', 'Pagamenti')]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-money-bill-1" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('payments', '', 'Pagamenti')) ?></span>
                <span class="badge text-bg-secondary"><?= count($payments) ?></span>
            </div>
        </div>
    <div class="table-responsive">
        <table class="table table-hover ip-table-card-collapse">

            <thead>
            <tr>
                <th scope="col"><?= trans('date', '', 'Data') ?></th>
                <th scope="col"><?= trans('invoice', '', 'Fattura') ?></th>
                <th scope="col" class="amount"><?= trans('amount', '', 'Importo') ?></th>
                <th scope="col"><?= trans('payment_method', '', 'Metodo di pagamento') ?></th>
                <th scope="col"><?= trans('note', '', 'Nota') ?></th>
            </tr>
            </thead>

            <tbody>
            <?php foreach ($payments as $payment): ?>
                <tr>
                    <?php // Scheda mobile: fattura e importo in alto, data sotto,
                          // metodo e nota solo nella vista tabellare. ?>
                    <td data-cell="due"><?= date_from_mysql($payment['payment_date']) ?></td>
                    <td data-cell="number">
                        <a href="<?= site_url('guest/invoices/view/' . (int) $payment['invoice_id']) ?>">
                            <span class="ip-num"><?= esc($payment['invoice_number'] ?: ('#' . $payment['invoice_id'])) ?></span>
                        </a>
                    </td>
                    <td class="amount" data-cell="amount"><?= format_currency($payment['payment_amount']) ?></td>
                    <td class="ip-hide-on-mobile"><?= esc($payment['payment_method_name'] ?? '') ?></td>
                    <td class="ip-hide-on-mobile"><?= esc($payment['payment_note'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($payments)): ?>
                <tr>
                    <td colspan="5"><?= trans('no_payments') ?></td>
                </tr>
            <?php endif; ?>
            </tbody>

        </table>
    </div>
    </div>
</div>

<?= $this->endSection() ?>
