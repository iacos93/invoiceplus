<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('payment_logs')); ?>
<?= $this->include('layout/headerbar', ['title' => trans('payment_logs')]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-regular fa-file-text" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('payment_logs')) ?></span>
                <span class="badge text-bg-secondary"><?= count($payment_logs) ?></span>
            </div>
        </div>
    <div class="table-responsive">
        <table class="table table-hover ip-table-card-collapse">
            <thead>
            <tr>
                <th scope="col"><?= trans('id') ?></th>
                <th scope="col"><?= trans('date') ?></th>
                <th scope="col"><?= trans('invoice') ?></th>
                <th scope="col"><?= trans('status') ?></th>
                <th scope="col"><?= trans('type') ?></th>
                <th scope="col"><?= trans('description') ?></th>
                <th scope="col"><?= trans('reference') ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($payment_logs as $log): ?>
                <tr>
                    <td data-label="<?= esc(trans('id')) ?>"><?= esc($log['merchant_response_id']) ?></td>
                    <td data-label="<?= esc(trans('date')) ?>"><?= esc(date_from_mysql($log['merchant_response_date'])) ?></td>
                    <td data-label="<?= esc(trans('invoice')) ?>">
                        <a href="<?= site_url('invoices/view/' . $log['invoice_id']) ?>">
                            <?= esc($log['invoice_number'] ?: $log['invoice_id']) ?>
                        </a>
                    </td>
                    <td data-label="<?= esc(trans('status')) ?>">
                        <?php if ($log['merchant_response_successful']): ?>
                            <span class="badge text-bg-success"><?= trans('success') ?></span>
                        <?php else: ?>
                            <span class="badge text-bg-danger"><?= trans('failure') ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= esc(trans('type')) ?>"><?= esc($log['merchant_response_driver']) ?></td>
                    <td data-label="<?= esc(trans('description')) ?>"><?= esc($log['merchant_response']) ?></td>
                    <td data-label="<?= esc(trans('reference')) ?>"><?= esc($log['merchant_response_reference']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($payment_logs)): ?>
                <tr><td colspan="7" class="text-muted text-center"><?= trans('no_records') ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    </div>
</div>

<?= $this->endSection() ?>