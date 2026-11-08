<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('assigned_clients')); ?>
<?php $actions = '<div class="btn-group btn-group-sm"><a class="btn btn-secondary" href="' . site_url('users') . '"><i class="fa fa-solid fa-arrow-left" aria-hidden="true"></i> ' . trans('back') . '</a><a class="btn btn-primary" href="' . site_url('user_clients/create') . '"><i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new') . '</a></div>'; ?>
<?= $this->include('layout/headerbar', ['title' => trans('assigned_clients'), 'actions' => $actions]) ?>

<div id="content">

    <div class="ip-form-pane">

            <div class="card">
                <div class="card-header">
                    <?= trans('user') ?>: <?= esc($user['user_name']) ?>
                </div>

                <div class="card-body table-content">
                    <div class="table-responsive no-margin">
                        <table class="table table-hover no-margin">

                            <thead>
                            <tr>
                                <th scope="col"><?= trans('client') ?></th>
                                <th scope="col"><?= trans('options') ?></th>
                            </tr>
                            </thead>

                            <tbody>
                            <?php foreach ($user_clients as $user_client): ?>
                                <tr>
                                    <td>
                                        <a href="<?= site_url('clients/view/' . $user_client['client_id']) ?>">
                                            <?= esc(($user_client['client_name'] ?? '') . ' ' . ($user_client['client_surname'] ?? '')) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <form
                                            action="<?= site_url('user_clients/delete/' . $user_client['user_client_id']) ?>"
                                            method="POST">
                                            <?= csrf_field() ?>
                                            <button type="button" class="btn btn-secondary btn-sm"
                                                    data-bs-toggle="confirm" data-confirm-title="<?= esc(trans('confirm', '', 'Conferma')) ?>" data-confirm-body="<?= esc(trans('delete_record_warning')) ?>" data-confirm-ok="<?= esc(trans('delete', '', 'Elimina')) ?>" data-confirm-danger="1">
                                                <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('remove') ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($user_clients)): ?>
                                <tr>
                                    <td colspan="2"><?= trans('no_clients_assigned') ?></td>
                                </tr>
                            <?php endif; ?>
                            </tbody>

                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <?= trans('add_client') ?>
                </div>

                <div class="card-body">
                    <form method="POST" action="<?= site_url('user_clients/create') ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= esc($id) ?>">

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="client_id"><?= trans('client') ?></label>
                            <select name="client_id" id="client_id" class="form-control simple-select">
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?= esc($client['client_id']) ?>">
                                        <?= esc(($client['client_name'] ?? '') . ' ' . ($client['client_surname'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-success">
                            <i class="fa fa-solid fa-check" aria-hidden="true"></i> <?= trans('submit') ?>
                        </button>
                    </form>
                </div>
            </div>

    </div>

</div>

<?= $this->endSection() ?>