<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('schedule', '', 'Scadenzario')); ?>

<?= $this->include('layout/headerbar', ['title' => trans('schedule', '', 'Scadenzario')]) ?>

<div id="content" class="table-content">

    <?php if ($open_count === 0): ?>
        <div class="empty-state card">
            <div class="card-body empty-body">
                <i class="fa fa-solid fa-check-circle empty-state-icon" aria-hidden="true"></i>
                <p class="empty-state-text">
                    <?= trans('no_open_due', '', 'Nessuna scadenza aperta: tutte le fatture emesse risultano saldate.') ?>
                </p>
            </div>
        </div>
    <?php else: ?>

        <?php /* Riepilogo in testa: quanto c'è da incassare e quanto è già in
                 ritardo. Stessa striscia delle liste documenti — vedi
                 layout/summary_strip. */ ?>
        <?= view('layout/summary_strip', ['items' => [
            [
                'label' => trans('open_invoices', '', 'Fatture aperte'),
                'value' => (string) (int) $open_count,
            ],
            [
                'label' => trans('total_balance', '', 'Da incassare'),
                'value' => format_currency($grand_total),
            ],
            [
                'label' => trans('overdue', '', 'Scaduto'),
                'value' => $overdue_total > 0 ? format_currency($overdue_total) : null,
                'tone'  => 'danger',
            ],
        ]]) ?>

        <?php foreach ($buckets as $key => $bucket): ?>
            <?php if (empty($bucket['rows'])) { continue; } ?>
            <div class="card ip-table-card mb-3 ip-schedule-bucket ip-schedule-bucket--<?= esc($bucket['class']) ?>">
                <div class="card-header ip-table-card-header">
                    <div class="ip-table-card-title">
                        <span class="card-header-icon"><i class="fa fa-solid fa-hourglass-half" aria-hidden="true"></i></span>
                        <span class="ip-table-card-label"><?= esc(trans('schedule_' . $key, '', $bucket['label'])) ?></span>
                        <span class="badge text-bg-secondary"><?= count($bucket['rows']) ?></span>
                    </div>
                    <span class="ip-schedule-bucket-total"><?= format_currency($bucket['total']) ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover ip-table-card-collapse">
                        <thead>
                        <tr>
                            <th scope="col"><?= trans('due_date', '', 'Scadenza') ?></th>
                            <th scope="col"><?= trans('invoice', '', 'Fattura') ?></th>
                            <th scope="col"><?= trans('client', '', 'Cliente') ?></th>
                            <th scope="col" class="amount"><?= trans('total', '', 'Totale') ?></th>
                            <th scope="col" class="amount"><?= trans('paid', '', 'Pagato') ?></th>
                            <th scope="col" class="amount"><?= trans('balance', '', 'Saldo') ?></th>
                            <th scope="col"><?= trans('options', '', 'Opzioni') ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($bucket['rows'] as $row): ?>
                            <?php $days = (int) $row['days_overdue']; ?>
                            <tr>
                                <td data-label="<?= esc(trans('due_date', '', 'Scadenza')) ?>">
                                    <span class="<?= $days > 0 ? 'text-danger fw-semibold' : '' ?>">
                                        <?= esc(date_from_mysql($row['invoice_date_due'])) ?>
                                    </span>
                                    <span class="ip-schedule-days">
                                        <?php if ($days > 0): ?>
                                            <?= sprintf(trans('days_late', '', '%s g di ritardo'), $days) ?>
                                        <?php elseif ($days === 0): ?>
                                            <?= trans('due_today', '', 'oggi') ?>
                                        <?php else: ?>
                                            <?= sprintf(trans('days_left', '', 'fra %s g'), abs($days)) ?>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td data-label="<?= esc(trans('invoice', '', 'Fattura')) ?>">
                                    <a href="<?= site_url('invoices/view/' . (int) $row['invoice_id']) ?>">
                                        <?= esc($row['invoice_number'] ?: '#' . $row['invoice_id']) ?>
                                    </a>
                                </td>
                                <td data-label="<?= esc(trans('client', '', 'Cliente')) ?>">
                                    <a href="<?= site_url('clients/view/' . (int) $row['client_id']) ?>">
                                        <?= esc(format_client($row)) ?>
                                    </a>
                                </td>
                                <td class="amount" data-label="<?= esc(trans('total', '', 'Totale')) ?>"><?= format_currency($row['invoice_total']) ?></td>
                                <td class="amount" data-label="<?= esc(trans('paid', '', 'Pagato')) ?>"><?= format_currency($row['invoice_paid']) ?></td>
                                <td class="amount" data-label="<?= esc(trans('balance', '', 'Saldo')) ?>"><strong><?= format_currency($row['invoice_balance']) ?></strong></td>
                                <td data-label="<?= esc(trans('options', '', 'Opzioni')) ?>">
                                    <div class="btn-group btn-group-sm">
                                        <a class="btn btn-secondary"
                                           href="<?= site_url('payments/form?invoice_id=' . (int) $row['invoice_id']) ?>">
                                            <i class="fa fa-solid fa-money-bill-1" aria-hidden="true"></i> <?= trans('enter_payment', '', 'Incassa') ?>
                                        </a>
                                        <?php if (!empty($row['client_email'])): ?>
                                            <a class="btn btn-secondary"
                                               href="<?= site_url('mailer/invoice/' . (int) $row['invoice_id']) ?>">
                                                <i class="fa fa-solid fa-envelope" aria-hidden="true"></i> <?= trans('send_email', '', 'Sollecita') ?>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

    <?php endif; ?>

</div>

<?= $this->endSection() ?>
