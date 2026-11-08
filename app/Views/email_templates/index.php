<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
$this->setVar('pageTitle', trans('email_templates'));
$actions = '<a class="btn btn-sm btn-primary" href="' . site_url('email_templates/form') . '">'
         . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new', '', 'Nuovo template') . '</a>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('email_templates'), 'actions' => $actions]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-envelope" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('email_templates')) ?></span>
                <span class="badge text-bg-secondary"><?= isset($pager) ? $pager->getTotal('ip_email_templates') : count($email_templates) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search" data-target="#email-templates-list-table">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca template…')) ?>" autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover ip-table-card-collapse" id="email-templates-list-table">

            <thead>
            <tr>
                <th scope="col"><?= trans('title') ?></th>
                <th scope="col"><?= trans('subject') ?></th>
                <th scope="col"><?= trans('options') ?></th>
            </tr>
            </thead>

            <tbody>
            <?php foreach ($email_templates as $email_template): ?>
                <tr>
                    <td data-label="<?= esc(trans('title')) ?>"><?= esc($email_template['email_template_title']) ?></td>
                    <td data-label="<?= esc(trans('subject')) ?>"><?= esc($email_template['email_template_subject']) ?></td>
                    <td data-label="<?= esc(trans('options')) ?>">
                        <div class="options btn-group">
                            <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options') ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="<?= site_url('email_templates/form/' . $email_template['email_template_id']) ?>">
                                        <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit') ?>
                                    </a>
                                </li>
                                <li>
                                    <form action="<?= site_url('email_templates/delete/' . $email_template['email_template_id']) ?>"
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
            <?php if (empty($email_templates)): ?>
                <?= $this->include('layout/empty_row', [
                    'colspan'   => 3,
                    'icon'      => 'fa-solid fa-envelope',
                    'message'   => trans('no_email_templates'),
                    'cta_url'   => site_url('email_templates/form'),
                    'cta_label' => trans('new', '', 'Nuovo template'),
                ]) ?>
            <?php endif; ?>
            </tbody>

        </table>
    </div>
    <?= $this->include('layout/pager_footer', ['group' => 'ip_email_templates']) ?>
    </div>
</div>

<?= $this->endSection() ?>
