<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
$this->setVar('pageTitle', trans('users'));
$actions = '<a class="btn btn-sm btn-primary" href="' . site_url('users/form') . '">'
         . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new') . '</a>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('users'), 'actions' => $actions]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-user" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('users')) ?></span>
                <span class="badge text-bg-secondary"><?= count($users) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search" data-target="#users-list-table">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca utente…')) ?>" autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover ip-clickable-rows ip-table-card-collapse" id="users-list-table">

            <thead>
            <tr>
                <th scope="col"><?= trans('name') ?></th>
                <th scope="col"><?= trans('user_type') ?></th>
                <th scope="col"><?= trans('email_address') ?></th>
                <th scope="col"><?= trans('options') ?></th>
            </tr>
            </thead>

            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td data-cell="number"><?= esc($user['user_name']) ?></td>
                    <td data-cell="status"><?= esc(trans($user_types[$user['user_type']] ?? $user['user_type'])) ?></td>
                    <?php // Email e azioni restano nella vista tabellare. ?>
                    <td class="ip-hide-on-mobile"><?= esc($user['user_email']) ?></td>
                    <td class="ip-hide-on-mobile">
                        <div class="options btn-group btn-group-sm">
                            <?php if ((int) $user['user_type'] === 2): ?>
                                <a href="<?= site_url('user_clients/user/' . $user['user_id']) ?>"
                                   class="btn btn-secondary">
                                    <i class="fa fa-solid fa-list fa-margin" aria-hidden="true"></i> <?= trans('assigned_clients') ?>
                                </a>
                            <?php endif; ?>
                            <button type="button" class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options') ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="<?= site_url('users/form/' . $user['user_id']) ?>">
                                        <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit') ?>
                                    </a>
                                </li>
                                <li>
                                    <a href="<?= site_url('users/change_password/' . $user['user_id']) ?>">
                                        <i class="fa fa-solid fa-key fa-margin" aria-hidden="true"></i> <?= trans('change_password') ?>
                                    </a>
                                </li>
                                <?php if ((int) $user['user_id'] !== 1): ?>
                                    <li>
                                        <form action="<?= site_url('users/delete/' . $user['user_id']) ?>"
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
            <?php if (empty($users)): ?>
                <tr class="empty-row">
                    <td colspan="4">
                        <i class="fa fa-solid fa-users empty-icon" aria-hidden="true"></i>
                        Nessun utente presente.
                        <a href="<?= site_url('users/form') ?>" class="empty-cta">
                            <i class="fa fa-solid fa-plus" aria-hidden="true"></i> Nuovo
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