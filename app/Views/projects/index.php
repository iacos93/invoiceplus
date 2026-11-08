<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('projects')); ?>
<?php
$actions = '<a class="btn btn-sm btn-primary" href="' . site_url('projects/form') . '">'
         . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new', '', 'Nuovo progetto') . '</a>';
$summary = $summary ?? ['projects' => 0, 'open_tasks' => 0, 'completed_tasks' => 0, 'overdue_tasks' => 0];
?>
<?= $this->include('layout/headerbar', ['title' => trans('projects'), 'actions' => $actions]) ?>

<div id="content" class="table-content">

    <?php /* Riepilogo in testa: quanto lavoro è aperto, finito, in ritardo.
             Stessa striscia di liste documenti e scadenzario — layout/summary_strip. */ ?>
    <?= view('layout/summary_strip', ['items' => [
        ['label' => trans('projects'), 'value' => (string) (int) $summary['projects']],
        [
            'label' => trans('tasks'),
            'value' => (string) ((int) $summary['open_tasks'] + (int) $summary['completed_tasks'] + (int) ($summary['invoiced_tasks'] ?? 0)),
        ],
        ['label' => trans('in_progress', '', 'Aperte'),   'value' => (string) (int) $summary['open_tasks']],
        ['label' => trans('complete', '', 'Completate'),  'value' => (string) (int) $summary['completed_tasks']],
        [
            'label' => trans('overdue', '', 'Scadute'),
            'value' => $summary['overdue_tasks'] > 0 ? (string) (int) $summary['overdue_tasks'] : null,
            'tone'  => 'danger',
        ],
    ]]) ?>

    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-briefcase" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('projects')) ?></span>
                <span class="badge text-bg-secondary"><?= count($projects) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search" data-target="#projects-list-table">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca progetto, cliente…')) ?>" autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover ip-table-card-collapse" id="projects-list-table">

            <thead>
            <tr>
                <th scope="col"><?= trans('project_name') ?></th>
                <th scope="col"><?= trans('client_name') ?></th>
                <th scope="col" class="ip-strip-col"><?= trans('tasks') ?></th>
                <th scope="col"><?= trans('project_last_activity', '', 'Ultima attività') ?></th>
                <th scope="col"><?= trans('options') ?></th>
            </tr>
            </thead>

            <tbody>
            <?php
            // Tinte della striscia: i quattro stati dell'attività, le stesse
            // dei timbri di stato e della barra di composizione nella scheda
            // progetto. Il commento diceva già "gli stessi dei badge", ma tre
            // tinte su quattro non lo erano: "in corso" era blu invece che
            // ambra e "completata" verde invece che inchiostro — cioè il verde,
            // che nell'app vuol dire denaro, marcava un lavoro non ancora
            // fatturato.
            $stripSegments = [
                ['key' => 'task_not_started_count', 'tint' => 'is-not-started', 'label' => trans('not_started')],
                ['key' => 'task_in_progress_count', 'tint' => 'is-in-progress', 'label' => trans('in_progress')],
                ['key' => 'task_completed_count',   'tint' => 'is-complete',    'label' => trans('complete')],
                ['key' => 'task_invoiced_count',    'tint' => 'is-invoiced',    'label' => trans('invoiced')],
            ];
            ?>
            <?php foreach ($projects as $project): ?>
                <?php $taskCount = (int) ($project['task_count'] ?? 0);
                      $done      = (int) ($project['task_completed_count'] ?? 0)
                                 + (int) ($project['task_invoiced_count'] ?? 0);
                      $overdue   = (int) ($project['task_overdue_count'] ?? 0);
                ?>
                <tr>
                    <td data-label="<?= esc(trans('project_name')) ?>">
                        <a class="ip-project-name" href="<?= site_url('projects/view/' . $project['project_id']) ?>">
                            <?= esc($project['project_name']) ?>
                        </a>
                    </td>
                    <td data-label="<?= esc(trans('client_name')) ?>">
                        <?php if (!empty($project['client_id'])): ?>
                            <a class="ip-client-link" href="<?= site_url('clients/view/' . $project['client_id']) ?>">
                                <?= esc($project['client_name']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-muted"><?= trans('none') ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= esc(trans('tasks')) ?>">
                        <?php if ($taskCount > 0): ?>
                            <div class="ip-task-strip-wrap">
                                <div class="ip-task-strip" role="img"
                                     aria-label="<?= esc($done . ' di ' . $taskCount . ' attività chiuse') ?>">
                                    <?php foreach ($stripSegments as $seg): ?>
                                        <?php $count = (int) ($project[$seg['key']] ?? 0); ?>
                                        <?php if ($count > 0): ?>
                                            <span class="ip-task-strip-seg <?= $seg['tint'] ?>"
                                                  style="width: <?= round($count / $taskCount * 100, 2) ?>%"
                                                  title="<?= esc($seg['label'] . ': ' . $count) ?>"></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <span class="ip-task-strip-meta">
                                    <?= $done ?> / <?= $taskCount ?>
                                    <?php if ($overdue > 0): ?>
                                        <span class="ip-overdue-flag" title="<?= esc(trans('overdue', '', 'Scadute')) ?>">
                                            <i class="fa fa-solid fa-exclamation-circle" aria-hidden="true"></i> <?= $overdue ?>
                                        </span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php else: ?>
                            <span class="text-muted"><?= trans('none') ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= esc(trans('project_last_activity', '', 'Ultima attività')) ?>">
                        <span class="ip-date-cell"><?= date_from_mysql($project['project_last_task_date'] ?? '') ?></span>
                    </td>
                    <td data-label="<?= esc(trans('options')) ?>">
                        <div class="options btn-group ip-row-options">
                            <button type="button" class="btn btn-secondary btn-sm dropdown-toggle"
                               data-bs-toggle="dropdown">
                                <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options') ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="<?= site_url('projects/view/' . $project['project_id']) ?>">
                                        <i class="fa fa-solid fa-eye fa-margin" aria-hidden="true"></i> <?= trans('view') ?>
                                    </a>
                                </li>
                                <li>
                                    <a href="<?= site_url('projects/form/' . $project['project_id']) ?>">
                                        <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit') ?>
                                    </a>
                                </li>
                                <li>
                                    <form action="<?= site_url('projects/delete/' . $project['project_id']) ?>"
                                          method="POST">
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
            <?php if (empty($projects)): ?>
                <tr class="empty-row">
                    <td colspan="5">
                        <i class="fa fa-solid fa-briefcase empty-icon" aria-hidden="true"></i>
                        <?= trans('alert_no_projects_found', '', 'Nessun progetto presente.') ?>
                        <a href="<?= site_url('projects/form') ?>" class="empty-cta">
                            <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('new', '', 'Nuovo progetto') ?>
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