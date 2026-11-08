<?= $this->extend('layout/guest') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('invoices', '', 'Fatture')); ?>

<?php
// Mappa classi Bootstrap 3 per i badge di stato (stile CI3)
$status_label_class = [
    'draft' => 'text-bg-secondary',
    'sent' => 'text-bg-info',
    'viewed' => 'text-bg-warning',
    'paid' => 'text-bg-success',
    'overdue' => 'text-bg-danger',
    'approved' => 'text-bg-success',
    'rejected' => 'text-bg-danger',
    'canceled' => 'text-bg-dark',
];
?>

<?php $actions = '<button type="button" class="btn btn-secondary btn-sm submenu-toggle d-lg-none" data-bs-toggle="collapse" data-bs-target="#ip-status-submenu-collapse"><i class="fa fa-solid fa-filter" aria-hidden="true"></i> ' . trans('filter', '', 'Filtra') . '</button>'; ?>
<?php $filters = '<div class="btn-group btn-group-sm index-options"><a href="' . site_url('guest/invoices/status/open') . '" class="btn ' . ($status === 'open' ? 'btn-primary' : 'btn-secondary') . '">' . trans('open', '', 'Aperte') . '</a><a href="' . site_url('guest/invoices/status/paid') . '" class="btn ' . ($status === 'paid' ? 'btn-primary' : 'btn-secondary') . '">' . trans('paid', '', 'Pagate') . '</a><a href="' . site_url('guest/invoices/status/overdue') . '" class="btn ' . ($status === 'overdue' ? 'btn-primary' : 'btn-secondary') . '">' . trans('overdue', '', 'Scadute') . '</a></div>'; ?>
<?= $this->include('layout/headerbar', ['title' => trans('invoices', '', 'Fatture'), 'actions' => $actions, 'filters' => $filters]) ?>

<div id="submenu">
    <div class="collapse clearfix" id="ip-status-submenu-collapse">
        <div class="submenu-row">
            <div class="btn-group btn-group-sm index-options">
                <a href="<?= site_url('guest/invoices/status/open') ?>"
                   class="btn <?= $status === 'open' ? 'btn-primary' : 'btn-secondary' ?>">
                    <?= trans('open', '', 'Aperte') ?>
                </a>
                <a href="<?= site_url('guest/invoices/status/paid') ?>"
                   class="btn <?= $status === 'paid' ? 'btn-primary' : 'btn-secondary' ?>">
                    <?= trans('paid', '', 'Pagate') ?>
                </a>
                <a href="<?= site_url('guest/invoices/status/overdue') ?>"
                   class="btn <?= $status === 'overdue' ? 'btn-primary' : 'btn-secondary' ?>">
                    <?= trans('overdue', '', 'Scadute') ?>
                </a>
            </div>
        </div>
    </div>
</div>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-regular fa-file-text" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('invoices', '', 'Fatture')) ?></span>
                <span class="badge text-bg-secondary"><?= count($invoices) ?></span>
            </div>
        </div>
    <div class="table-responsive">
        <table class="table table-hover ip-clickable-rows ip-table-card-collapse">

            <thead>
            <tr>
                <th scope="col"><?= trans('invoice', '', 'Fattura') ?></th>
                <th scope="col"><?= trans('created', '', 'Creata') ?></th>
                <th scope="col"><?= trans('due_date', '', 'Scadenza') ?></th>
                <th scope="col"><?= trans('client_name', '', 'Cliente') ?></th>
                <th scope="col" class="amount"><?= trans('amount', '', 'Importo') ?></th>
                <th scope="col" class="amount"><?= trans('balance', '', 'Saldo') ?></th>
                <th scope="col"><?= trans('status', '', 'Stato') ?></th>
                <th scope="col"><?= trans('options', '', 'Opzioni') ?></th>
            </tr>
            </thead>

            <tbody>
            <?php foreach ($invoices as $invoice): ?>
                <?php
                $statusInfo         = $invoice_statuses[(string) $invoice['invoice_status_id']] ?? ['label' => '', 'class' => 'draft'];
                $status_class_label = $status_label_class[$statusInfo['class']] ?? 'text-bg-secondary';
                ?>
                <tr>
                    <td data-cell="number">
                        <a href="<?= site_url('guest/invoices/view/' . (int) $invoice['invoice_id']) ?>">
                            <span class="ip-num"><?= esc($invoice['invoice_number'] ?: ('#' . $invoice['invoice_id'])) ?></span>
                        </a>
                    </td>
                    <?php // Scheda mobile come nelle fatture dell'area riservata. ?>
                    <td class="ip-hide-on-mobile"><?= date_from_mysql($invoice['invoice_date_created']) ?></td>
                    <td data-cell="due">
                        <span class="<?= !empty($invoice['is_overdue']) ? 'text-danger fw-semibold' : '' ?>">
                            <?= date_from_mysql($invoice['invoice_date_due']) ?>
                        </span>
                    </td>
                    <td data-cell="client"><?= esc(format_client($invoice)) ?></td>
                    <td class="amount" data-cell="amount"><?= format_currency($invoice['invoice_total'] ?? 0) ?></td>
                    <td class="amount" data-cell="balance"><?= format_currency($invoice['invoice_balance'] ?? 0) ?></td>
                    <td data-cell="status">
                        <span class="badge ip-status <?= $status_class_label ?>"><?= esc($statusInfo['label']) ?></span>
                    </td>
                    <td class="ip-hide-on-mobile">
                        <div class="options btn-group">
                            <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options', '', 'Opzioni') ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="<?= site_url('guest/invoices/view/' . (int) $invoice['invoice_id']) ?>">
                                        <i class="fa fa-solid fa-eye fa-margin" aria-hidden="true"></i> <?= trans('view', '', 'Vista') ?>
                                    </a>
                                </li>
                                <li>
                                    <a href="<?= site_url('guest/invoices/generate_pdf/' . (int) $invoice['invoice_id']) ?>"
                                       target="_blank">
                                        <i class="fa fa-regular fa-file-pdf fa-margin" aria-hidden="true"></i> <?= trans('pdf', '', 'PDF') ?>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($invoices)): ?>
                <tr>
                    <td colspan="8"><?= trans('no_invoices') ?></td>
                </tr>
            <?php endif; ?>
            </tbody>

        </table>
    </div>
    </div>
</div>

<?= $this->endSection() ?>
