<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('tasks')); ?>
<?php
// Filtri per stato, come nelle liste fatture/preventivi: ogni pulsante
// conserva il taglio della lista corrente nella propria URL.
$status = (string) ($status ?? 'all');
$btn    = function ($key, $label, $count = null) use ($status) {
    $cls = $status === $key ? 'btn-primary' : 'btn-secondary';
    return '<a href="' . site_url('tasks/status/' . $key) . '" class="btn ' . $cls . '">'
         . $label . ($count !== null ? ' <span class="ip-btn-count">' . (int) $count . '</span>' : '')
         . '</a>';
};

$summary = $summary ?? ['counts' => [], 'total' => 0, 'overdue' => 0];

$filters = '<div class="btn-group btn-group-sm index-options">'
    . $btn('all',     trans('all', '', 'Tutte'), $summary['total'])
    . $btn('1',       trans('not_started'),      $summary['counts'][1] ?? 0)
    . $btn('2',       trans('in_progress'),      $summary['counts'][2] ?? 0)
    . $btn('3',       trans('complete'),         $summary['counts'][3] ?? 0)
    . $btn('4',       trans('invoiced'),         $summary['counts'][4] ?? 0)
    . $btn('overdue', trans('overdue', '', 'Scadute'), $summary['overdue'])
    . '</div>';

$actions = '<button type="button" class="btn btn-secondary btn-sm submenu-toggle d-lg-none"'
         . ' data-bs-toggle="collapse" data-bs-target="#ip-tasks-submenu-collapse">'
         . '<i class="fa fa-solid fa-filter" aria-hidden="true"></i> ' . trans('filter', '', 'Filtra') . '</button>'
         . '<a class="btn btn-sm btn-primary" href="' . site_url('tasks/form') . '">'
         . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new', '', "Nuova attivita'") . '</a>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('tasks'), 'filters' => $filters, 'actions' => $actions]) ?>

<div id="submenu">
    <div class="collapse clearfix" id="ip-tasks-submenu-collapse">
        <div class="submenu-row">
            <div class="btn-group btn-group-sm index-options">
                <?= $filters ?>
            </div>
        </div>
    </div>
</div>

<div id="content" class="table-content">

    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-regular fa-check-square" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('tasks')) ?></span>
                <span class="badge text-bg-secondary"><?= count($tasks) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search" data-target="#tasks-list-table">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca attività, progetto…')) ?>" autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover ip-table-card-collapse" id="tasks-list-table">

            <thead>
            <tr>
                <th scope="col"><?= trans('status') ?></th>
                <th scope="col"><?= trans('task_name') ?></th>
                <th scope="col"><?= trans('task_finish_date') ?></th>
                <th scope="col"><?= trans('project') ?></th>
                <th scope="col" class="amount"><?= trans('task_price') ?></th>
                <th scope="col"><?= trans('options') ?></th>
            </tr>
            </thead>

            <tbody>
            <?php
            // Stessa mappa di invoices/index.php, quotes/index.php e del
            // widget attivita' della bacheca: "in corso" e' sempre ambra,
            // "fatturata" e' sempre verde, in ogni punto dell'app. Prima
            // questa lista ne aveva una propria (blu per "in corso", azzurro
            // per "fatturata") che non corrispondeva a nessun'altra vista.
            $status_label_class = [
                'draft'   => 'text-bg-secondary',
                'viewed'  => 'text-bg-warning',
                'sent'    => 'text-bg-primary',
                'paid'    => 'text-bg-success',
                'overdue' => 'text-bg-danger',
            ];
            ?>
            <?php foreach ($tasks as $task): ?>
                <?php
                $taskStatusInfo = $task_statuses[(string) $task['task_status']] ?? ['class' => 'draft'];
                $statusClass    = (!empty($task['is_overdue']) && (int) $task['task_status'] !== 3)
                    ? $status_label_class['overdue']
                    : ($status_label_class[$taskStatusInfo['class']] ?? 'text-bg-secondary');
                ?>
                <tr class="<?= !empty($task['is_overdue']) ? 'ip-row-overdue' : '' ?>">
                    <td data-label="<?= esc(trans('status')) ?>">
                        <span class="badge ip-status <?= $statusClass ?>">
                            <?= esc($task_statuses[$task['task_status']]['label'] ?? $task['task_status']) ?>
                        </span>
                    </td>
                    <td data-label="<?= esc(trans('task_name')) ?>">
                        <a href="<?= site_url('tasks/form/' . $task['task_id']) ?>">
                            <?= esc($task['task_name']) ?>
                        </a>
                    </td>
                    <td data-label="<?= esc(trans('task_finish_date')) ?>">
                        <span class="ip-due-cell <?= !empty($task['is_overdue']) ? 'text-danger' : '' ?>">
                            <?= date_from_mysql($task['task_finish_date'] ?? '') ?>
                            <?php if (!empty($task['is_overdue'])): ?>
                                <i class="fa fa-solid fa-exclamation-circle ip-overdue-flag"
                                   title="<?= esc(trans('overdue', '', 'Scaduta')) ?>"></i>
                            <?php endif; ?>
                        </span>
                    </td>
                    <td data-label="<?= esc(trans('project')) ?>">
                        <?php if (!empty($task['project_id'])): ?>
                            <a href="<?= site_url('projects/view/' . $task['project_id']) ?>">
                                <?= esc($task['project_name']) ?>
                            </a>
                        <?php endif; ?>
                    </td>
                    <td class="amount" data-label="<?= esc(trans('task_price')) ?>">
                        <?= format_currency($task['task_price']) ?>
                    </td>
                    <td data-label="<?= esc(trans('options')) ?>">
                        <div class="options btn-group">
                            <button type="button" class="btn btn-secondary btn-sm dropdown-toggle"
                               data-bs-toggle="dropdown">
                                <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options') ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="<?= site_url('tasks/form/' . $task['task_id']) ?>"
                                       title="<?= trans('edit') ?>">
                                        <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit') ?>
                                    </a>
                                </li>
                                <?php if ((int) $task['task_status'] === 3): ?>
                                    <li>
                                        <a href="<?= site_url('invoices/form_create?task_id=' . $task['task_id']) ?>"
                                           title="<?= trans('invoice_this_task', '', 'Fattura questa attività') ?>">
                                            <i class="fa fa-regular fa-file-text fa-margin" aria-hidden="true"></i> <?= trans('invoice_this_task', '', 'Fattura questa attività') ?>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                <?php if ((int) $task['task_status'] !== 4): ?>
                                    <li>
                                        <form action="<?= site_url('tasks/delete/' . $task['task_id']) ?>"
                                              method="POST">
                                            <?= csrf_field() ?>
                                            <button type="button" class="dropdown-button"
                                                    data-bs-toggle="confirm" data-confirm-title="<?= esc(trans('confirm', '', 'Conferma')) ?>" data-confirm-body="<?= esc((int) $task['task_status'] === 4 ? trans('alert_task_delete') : trans('delete_record_warning')) ?>" data-confirm-ok="<?= esc(trans('delete', '', 'Elimina')) ?>" data-confirm-danger="1">
                                                <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete') ?>
                                            </button>
                                        </form>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($tasks)): ?>
                <tr class="empty-row">
                    <td colspan="6">
                        <i class="fa fa-regular fa-check-square empty-icon" aria-hidden="true"></i>
                        <?= trans('no_tasks_found') ?>
                        <a href="<?= site_url('tasks/form') ?>" class="empty-cta">
                            <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('new_task') ?>
                        </a>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>

        </table>

    </div>

    </div>
</div>

<?= $this->endSection() ?>