<?= $this->extend('layout/guest') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('quotes', '', 'Preventivi')); ?>

<?php
// Mappa classi Bootstrap 3 per i badge di stato (stile CI3)
$status_label_class = [
    'draft' => 'text-bg-secondary',
    'sent' => 'text-bg-info',
    'viewed' => 'text-bg-warning',
    'approved' => 'text-bg-success',
    'rejected' => 'text-bg-danger',
    'canceled' => 'text-bg-dark',
];
?>

<?php $actions = '<button type="button" class="btn btn-secondary btn-sm submenu-toggle d-lg-none" data-bs-toggle="collapse" data-bs-target="#ip-status-submenu-collapse"><i class="fa fa-solid fa-filter" aria-hidden="true"></i> ' . trans('filter', '', 'Filtra') . '</button>'; ?>
<?php $filters = '<div class="btn-group btn-group-sm index-options"><a href="' . site_url('guest/quotes/status/open') . '" class="btn ' . ($status === 'open' ? 'btn-primary' : 'btn-secondary') . '">' . trans('open', '', 'Aperti') . '</a><a href="' . site_url('guest/quotes/status/approved') . '" class="btn ' . ($status === 'approved' ? 'btn-primary' : 'btn-secondary') . '">' . trans('approved', '', 'Approvati') . '</a><a href="' . site_url('guest/quotes/status/rejected') . '" class="btn ' . ($status === 'rejected' ? 'btn-primary' : 'btn-secondary') . '">' . trans('rejected', '', 'Rifiutati') . '</a></div>'; ?>
<?= $this->include('layout/headerbar', ['title' => trans('quotes', '', 'Preventivi'), 'actions' => $actions, 'filters' => $filters]) ?>

<div id="submenu">
    <div class="collapse clearfix" id="ip-status-submenu-collapse">
        <div class="submenu-row">
            <div class="btn-group btn-group-sm index-options">
                <a href="<?= site_url('guest/quotes/status/open') ?>"
                   class="btn <?= $status === 'open' ? 'btn-primary' : 'btn-secondary' ?>">
                    <?= trans('open', '', 'Aperti') ?>
                </a>
                <a href="<?= site_url('guest/quotes/status/approved') ?>"
                   class="btn <?= $status === 'approved' ? 'btn-primary' : 'btn-secondary' ?>">
                    <?= trans('approved', '', 'Approvati') ?>
                </a>
                <a href="<?= site_url('guest/quotes/status/rejected') ?>"
                   class="btn <?= $status === 'rejected' ? 'btn-primary' : 'btn-secondary' ?>">
                    <?= trans('rejected', '', 'Rifiutati') ?>
                </a>
            </div>
        </div>
    </div>
</div>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-regular fa-file" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('quotes', '', 'Preventivi')) ?></span>
                <span class="badge text-bg-secondary"><?= count($quotes) ?></span>
            </div>
        </div>
    <div class="table-responsive">
        <table class="table table-hover ip-clickable-rows ip-table-card-collapse">

            <thead>
            <tr>
                <th scope="col"><?= trans('quote', '', 'Preventivo') ?></th>
                <th scope="col"><?= trans('created', '', 'Creato') ?></th>
                <th scope="col"><?= trans('expires', '', 'Scade') ?></th>
                <th scope="col"><?= trans('client_name', '', 'Cliente') ?></th>
                <th scope="col" class="amount"><?= trans('amount', '', 'Importo') ?></th>
                <th scope="col"><?= trans('status', '', 'Stato') ?></th>
                <th scope="col"><?= trans('options', '', 'Opzioni') ?></th>
            </tr>
            </thead>

            <tbody>
            <?php foreach ($quotes as $quote): ?>
                <?php
                $statusInfo          = $quote_statuses[(string) $quote['quote_status_id']] ?? ['label' => '', 'class' => 'draft'];
                $status_class_label  = $status_label_class[$statusInfo['class']] ?? 'text-bg-secondary';
                ?>
                <tr>
                    <td data-cell="number">
                        <a href="<?= site_url('guest/quotes/view/' . (int) $quote['quote_id']) ?>">
                            <span class="ip-num"><?= esc($quote['quote_number'] ?: $quote['quote_id']) ?></span>
                        </a>
                    </td>
                    <?php // Scheda mobile come nei preventivi dell'area riservata. ?>
                    <td class="ip-hide-on-mobile"><?= date_from_mysql($quote['quote_date_created']) ?></td>
                    <td data-cell="due"><?= date_from_mysql($quote['quote_date_expires']) ?></td>
                    <td data-cell="client"><?= esc(format_client($quote)) ?></td>
                    <td class="amount" data-cell="amount"><?= format_currency($quote['quote_total'] ?? 0) ?></td>
                    <td data-cell="status">
                        <span class="badge ip-status <?= $status_class_label ?>"><?= esc($statusInfo['label']) ?></span>
                    </td>
                    <td class="ip-hide-on-mobile">
                        <div class="options btn-group">
                            <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options', '', 'Opzioni') ?>
                            </button>
                            <ul class="dropdown-menu">
                                <?php if (in_array((int) $quote['quote_status_id'], [2, 3], true)): ?>
                                    <?php /* Mutazione di stato: POST con CSRF, non link (vedi guest/quotes_view) */ ?>
                                    <li>
                                        <form method="post" action="<?= site_url('guest/quotes/approve/' . (int) $quote['quote_id']) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="dropdown-item">
                                                <i class="fa fa-solid fa-check fa-margin" aria-hidden="true"></i> <?= trans('approve', '', 'Approva') ?>
                                            </button>
                                        </form>
                                    </li>
                                    <li>
                                        <form method="post" action="<?= site_url('guest/quotes/reject/' . (int) $quote['quote_id']) ?>">
                                            <?= csrf_field() ?>
                                            <button type="submit" class="dropdown-item">
                                                <i class="fa fa-solid fa-times fa-margin" aria-hidden="true"></i> <?= trans('reject', '', 'Rifiuta') ?>
                                            </button>
                                        </form>
                                    </li>
                                <?php endif; ?>
                                <li>
                                    <a href="<?= site_url('guest/quotes/view/' . (int) $quote['quote_id']) ?>">
                                        <i class="fa fa-solid fa-eye fa-margin" aria-hidden="true"></i> <?= trans('view', '', 'Vista') ?>
                                    </a>
                                </li>
                                <li>
                                    <a href="<?= site_url('guest/quotes/generate_pdf/' . (int) $quote['quote_id']) ?>"
                                       target="_blank">
                                        <i class="fa fa-regular fa-file-pdf fa-margin" aria-hidden="true"></i> <?= trans('pdf', '', 'PDF') ?>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($quotes)): ?>
                <tr>
                    <td colspan="7"><?= trans('no_quotes') ?></td>
                </tr>
            <?php endif; ?>
            </tbody>

        </table>
    </div>
    </div>
</div>

<?= $this->endSection() ?>
