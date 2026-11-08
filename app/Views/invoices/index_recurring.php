<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('recurring_invoices', '', 'Fatture ricorrenti')); ?>

<?php
// Frequenze ricorrenti (chiave => chiave lingua), come Mdl_Invoices_Recurring::recur_frequencies()
$recur_frequencies = [
    '1D'  => 'calendar_day_1',  '2D'  => 'calendar_day_2',  '3D'  => 'calendar_day_3',
    '4D'  => 'calendar_day_4',  '5D'  => 'calendar_day_5',  '6D'  => 'calendar_day_6',
    '15D' => 'calendar_day_15', '30D' => 'calendar_day_30',
    '7D'  => 'calendar_week_1', '14D' => 'calendar_week_2', '21D' => 'calendar_week_3',
    '28D' => 'calendar_week_4',
    '1M'  => 'calendar_month_1', '2M'  => 'calendar_month_2', '3M'  => 'calendar_month_3',
    '4M'  => 'calendar_month_4', '5M'  => 'calendar_month_5', '6M'  => 'calendar_month_6',
    '7M'  => 'calendar_month_7', '8M'  => 'calendar_month_8', '9M'  => 'calendar_month_9',
    '10M' => 'calendar_month_10', '11M' => 'calendar_month_11',
    '1Y'  => 'calendar_year_1', '2Y'  => 'calendar_year_2', '3Y'  => 'calendar_year_3',
    '4Y'  => 'calendar_year_4', '5Y'  => 'calendar_year_5',
];
?>

<?php $actions = '<a class="btn btn-sm btn-secondary" href="' . site_url('invoices') . '"><i class="fa fa-solid fa-arrow-left" aria-hidden="true"></i> ' . trans('back_to_invoices', '', 'Torna alle fatture') . '</a>'; ?>
<?= $this->include('layout/headerbar', ['title' => trans('recurring_invoices', '', 'Fatture ricorrenti'), 'actions' => $actions]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-refresh" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('recurring_invoices', '', 'Fatture ricorrenti')) ?></span>
                <span class="badge text-bg-secondary"><?= count($recurring_invoices) ?></span>
            </div>
        </div>
    <div id="filter_results">
        <div class="table-responsive">
            <table class="table table-hover ip-table-card-collapse">

                <thead>
                <tr>
                    <th scope="col"><?= trans('status', '', 'Stato') ?></th>
                    <th scope="col"><?= trans('base_invoice', '', 'Fattura base') ?></th>
                    <th scope="col"><?= trans('client', '', 'Cliente') ?></th>
                    <th scope="col"><?= trans('start_date', '', 'Data inizio') ?></th>
                    <th scope="col"><?= trans('end_date', '', 'Data fine') ?></th>
                    <th scope="col"><?= trans('every', '', 'Ogni') ?></th>
                    <th scope="col"><?= trans('next_date', '', 'Prossima data') ?></th>
                    <th scope="col"><?= trans('options', '', 'Opzioni') ?></th>
                </tr>
                </thead>

                <tbody>
                <?php foreach ($recurring_invoices as $invoice): ?>
                    <?php $is_active = $invoice['recur_status'] === 'active'; ?>
                    <tr>
                        <td data-label="<?= esc(trans('status', '', 'Stato')) ?>">
                            <span class="badge ip-status <?= $is_active ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                <?= $is_active
                                    ? trans('active', '', 'Attiva')
                                    : trans('inactive', '', 'Inattiva') ?>
                            </span>
                        </td>
                        <td data-label="<?= esc(trans('base_invoice', '', 'Fattura base')) ?>">
                            <a href="<?= site_url('invoices/view/' . (int) $invoice['invoice_id']) ?>">
                                <span class="ip-num"><?= esc($invoice['invoice_number'] ?: ('#' . $invoice['invoice_id'])) ?></span>
                            </a>
                        </td>
                        <td data-label="<?= esc(trans('client', '', 'Cliente')) ?>">
                            <a href="<?= site_url('clients/view/' . (int) $invoice['client_id']) ?>">
                                <?= esc(format_client($invoice)) ?>
                            </a>
                        </td>
                        <td data-label="<?= esc(trans('start_date', '', 'Data inizio')) ?>"><?= date_from_mysql($invoice['recur_start_date']) ?></td>
                        <td data-label="<?= esc(trans('end_date', '', 'Data fine')) ?>"><?= date_from_mysql($invoice['recur_end_date']) ?></td>
                        <td data-label="<?= esc(trans('every', '', 'Ogni')) ?>">
                            <?php
                            $frequency_lang_key = $recur_frequencies[$invoice['recur_frequency']] ?? '';
                            echo trans($frequency_lang_key, '', $invoice['recur_frequency']);
                            ?>
                        </td>
                        <td data-label="<?= esc(trans('next_date', '', 'Prossima data')) ?>"><?= date_from_mysql($invoice['recur_next_date']) ?></td>
                        <td data-label="<?= esc(trans('options', '', 'Opzioni')) ?>">
                            <div class="options btn-group">
                                <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options', '', 'Opzioni') ?>
                                </button>
                                <ul class="dropdown-menu">
                                    <?php if ($is_active): ?>
                                        <li>
                                            <form action="<?= site_url('invoices/recurring/stop/' . (int) $invoice['invoice_id']) ?>"
                                                  method="POST">
                                                <?= csrf_field() ?>
                                                <button type="submit" class="dropdown-button">
                                                    <i class="fa fa-solid fa-ban fa-margin" aria-hidden="true"></i> <?= trans('stop', '', 'Interrompi') ?>
                                                </button>
                                            </form>
                                        </li>
                                    <?php endif; ?>
                                    <li>
                                        <form action="<?= site_url('invoices/recurring/delete/' . (int) $invoice['invoice_id']) ?>"
                                              method="POST">
                                            <?= csrf_field() ?>
                                            <button type="button" class="dropdown-button"
                                                    data-bs-toggle="confirm" data-confirm-title="<?= esc(trans('confirm', '', 'Conferma')) ?>" data-confirm-body="<?= esc(trans('delete_invoice_warning', '', 'Eliminare la ricorrenza?')) ?>" data-confirm-ok="<?= esc(trans('delete', '', 'Elimina')) ?>" data-confirm-danger="1">
                                                <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete', '', 'Elimina') ?>
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recurring_invoices)): ?>
                    <tr class="empty-row">
                        <td colspan="8">
                            <i class="fa fa-solid fa-refresh empty-icon" aria-hidden="true"></i>
                            <?= trans('no_recurring_invoices') ?>
                            <a href="<?= site_url('invoices/form_create') ?>" class="empty-cta">
                                <i class="fa fa-solid fa-plus" aria-hidden="true"></i> Nuova
                            </a>
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