<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', $project['project_name']); ?>
<?php $title = esc($project['project_name']); ?>
<?php $actions = '<div class="btn-group btn-group-sm"><a href="' . site_url('tasks/form?project_id=' . $project['project_id']) . '" class="btn btn-secondary"><i class="fa fa-regular fa-check-square fa-margin" aria-hidden="true"></i>' . trans('new_task') . '</a><a href="' . site_url('projects/form/' . $project['project_id']) . '" class="btn btn-secondary"><i class="fa fa-regular fa-edit" aria-hidden="true"></i> ' . trans('edit') . '</a><a class="btn btn-danger" href="' . site_url('projects/delete/' . $project['project_id']) . '" data-bs-toggle="confirm" data-confirm-title="' . esc(trans('confirm', '', 'Conferma')) . '" data-confirm-body="' . esc(trans('delete_record_warning')) . '" data-confirm-ok="' . esc(trans('delete', '', 'Elimina')) . '" data-confirm-danger="1"><i class="fa fa-solid fa-trash-can" aria-hidden="true"></i> ' . trans('delete') . '</a></div>'; ?>
<?= $this->include('layout/headerbar', ['title' => $title, 'actions' => $actions]) ?>

<div id="content">

    <?php /* In testa i fatti d'insieme: quante attività, quante ancora aperte,
             quante in ritardo, quanto valgono. La distribuzione fra i quattro
             stati la racconta la barra qui sotto, quindi non si ripete qui. */ ?>
    <?= view('layout/summary_strip', ['items' => [
        ['label' => trans('tasks'),                     'value' => (string) (int) $stats['total']],
        ['label' => trans('open_tasks', '', 'Aperte'),  'value' => (string) (int) $stats['open']],
        [
            'label' => trans('overdue', '', 'Scadute'),
            'value' => $stats['overdue'] > 0 ? (string) (int) $stats['overdue'] : null,
            'tone'  => 'danger',
        ],
        [
            'label' => trans('task_price', '', 'Totale attività'),
            'value' => $stats['total'] > 0 ? format_currency($stats['amount']) : null,
        ],
    ]]) ?>

    <?php if ($stats['total'] > 0): ?>
        <?php
        // Barra di composizione: la stessa del prospetto "Utile e imposte",
        // con le tinte dei timbri di stato. Prima qui c'era una barra a tinta
        // unita che diceva solo la percentuale — meno di quanto la lista
        // progetti mostrasse gia' per ogni riga.
        $splitSegments = [
            ['key' => 'not_started', 'tint' => 'is-not-started', 'label' => trans('not_started')],
            ['key' => 'in_progress', 'tint' => 'is-in-progress', 'label' => trans('in_progress')],
            ['key' => 'completed',   'tint' => 'is-complete',    'label' => trans('complete')],
            ['key' => 'invoiced',    'tint' => 'is-invoiced',    'label' => trans('invoiced')],
        ];

        $splitAria = [];
        foreach ($splitSegments as $seg) {
            if ((int) $stats[$seg['key']] > 0) {
                $splitAria[] = $seg['label'] . ': ' . (int) $stats[$seg['key']];
            }
        }
        ?>
        <div class="ip-split ip-project-split">
            <div class="ip-split__head">
                <p class="ip-doc-label"><?= trans('progress', '', 'Avanzamento') ?></p>
                <span class="ip-split__progress">
                    <?= (int) $stats['progress'] ?>%&nbsp;<?= trans('closed_work', '', 'concluso') ?>
                </span>
            </div>

            <div class="ip-split__bar" role="img" aria-label="<?= esc(implode(' · ', $splitAria)) ?>">
                <?php foreach ($splitSegments as $seg): ?>
                    <?php $count = (int) $stats[$seg['key']]; ?>
                    <?php if ($count > 0): ?>
                        <span class="ip-split__seg <?= $seg['tint'] ?>"
                              style="width: <?= round($count / $stats['total'] * 100, 2) ?>%"></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <ul class="ip-split__legend">
                <?php foreach ($splitSegments as $seg): ?>
                    <?php $count = (int) $stats[$seg['key']]; ?>
                    <?php if ($count > 0): ?>
                        <li>
                            <span class="ip-split__key <?= $seg['tint'] ?>"></span>
                            <?= esc($seg['label']) ?> <b><?= $count ?></b>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12 col-md-4">

            <?php if (!empty($project['client_name'])): ?>

                <div class="card">
                    <div class="card-header">
                        <i class="fa fa-solid fa-user fa-margin" aria-hidden="true"></i>
                        <a href="<?= site_url('clients/view/' . $project['client_id']) ?>">
                            <strong><?= esc($project['client_name']) ?></strong>
                        </a>
                    </div>
                    <?php if (!empty($project['client_email']) || !empty($project['client_phone'])): ?>
                        <div class="card-body ip-project-client-contacts">
                            <?php if (!empty($project['client_email'])): ?>
                                <a href="mailto:<?= esc($project['client_email'], 'attr') ?>">
                                    <i class="fa fa-solid fa-envelope fa-margin" aria-hidden="true"></i><?= esc($project['client_email']) ?>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($project['client_phone'])): ?>
                                <a href="tel:<?= esc($project['client_phone'], 'attr') ?>">
                                    <i class="fa fa-solid fa-phone fa-margin" aria-hidden="true"></i><?= esc($project['client_phone']) ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

            <?php else: ?>

                <div class="alert alert-info"><?= trans('alert_no_client_assigned') ?></div>

            <?php endif; ?>

        </div>
        <div class="col-12 col-md-8">

            <div class="card">
                <div class="card-header">
                    <?= trans('tasks') ?>
                    <span class="badge text-bg-secondary"><?= (int) $stats['total'] ?></span>
                </div>
                <div class="card-body no-padding">

                    <div class="table-responsive">
                        <table class="table table-hover no-margin ip-project-tasks">

                            <thead>
                            <tr>
                                <th scope="col"><?= trans('task_name') ?></th>
                                <th scope="col"><?= trans('status') ?></th>
                                <th scope="col"><?= trans('task_finish_date') ?></th>
                                <th scope="col" class="amount"><?= trans('task_price') ?></th>
                                <th scope="col"><?= trans('options') ?></th>
                            </tr>
                            </thead>

                            <tbody>
                            <?php
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
                                    <td data-label="<?= esc(trans('task_name')) ?>">
                                        <a href="<?= site_url('tasks/form/' . $task['task_id']) ?>">
                                            <?= esc($task['task_name']) ?>
                                        </a>
                                    </td>
                                    <td data-label="<?= esc(trans('status')) ?>">
                                        <span class="badge ip-status <?= $statusClass ?>">
                                            <?= esc($task_statuses[$task['task_status']]['label'] ?? $task['task_status']) ?>
                                        </span>
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
                                                    <a href="<?= site_url('tasks/form/' . $task['task_id']) ?>">
                                                        <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit') ?>
                                                    </a>
                                                </li>
                                                <?php if ((int) $task['task_status'] === 3): ?>
                                                    <li>
                                                        <a href="<?= site_url('invoices/form_create?task_id=' . $task['task_id']) ?>">
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
                                                                    data-bs-toggle="confirm" data-confirm-title="<?= esc(trans('confirm', '', 'Conferma')) ?>" data-confirm-body="<?= esc(trans('delete_record_warning')) ?>" data-confirm-ok="<?= esc(trans('delete', '', 'Elimina')) ?>" data-confirm-danger="1">
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
                            </tbody>

                        </table>

                    </div>
                </div>

                <?php if (empty($tasks)): ?>
                    <div class="card-body">
                        <div class="alert alert-info no-margin">
                            <?= trans('alert_no_tasks_found') ?>
                            <a href="<?= site_url('tasks/form?project_id=' . $project['project_id']) ?>" class="empty-cta">
                                <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('new_task') ?>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

            </div>

        </div>
    </div>

</div>

<?= $this->endSection() ?>