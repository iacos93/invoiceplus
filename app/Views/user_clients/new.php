<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('assign_client')); ?>

<script>
    $(function () {
        $('#user_all_clients').click(function () {
            all_client_check();
        });

        function all_client_check() {
            if ($('#user_all_clients').is(':checked')) {
                $('#list_client').hide();
            } else {
                $('#list_client').show();
            }
        }

        all_client_check();
    });
</script>

<form method="POST" action="<?= site_url('user_clients/create') ?>">

    <?= csrf_field() ?>

    <?= $this->include('layout/headerbar', ['title' => trans('assign_client'), 'actions' => header_actions()]) ?>

    <div id="content">

        <div class="ip-form-pane">

                <div class="card">
                    <div class="card-header">
                        <?= trans('assign_client') ?>
                    </div>
                    <div class="card-body">

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="user_id"><?= trans('user') ?></label>
                            <select name="user_id" id="user_id" class="form-control simple-select" required>
                                <option value=""><?= trans('user') ?>...</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= esc($user['user_id']) ?>"><?= esc($user['user_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="alert alert-info">
                            <label>
                                <input type="checkbox" name="user_all_clients" id="user_all_clients" value="1">
                                <?= trans('user_all_clients') ?>
                            </label>

                            <div>
                                <?= trans('user_all_clients_text') ?>
                            </div>
                        </div>

                        <div id="list_client" class="ip-doc-field">
                            <label class="ip-doc-label" for="client_id"><?= trans('client') ?></label>
                            <select name="client_id" id="client_id" class="form-control simple-select"
                                    autofocus="autofocus">
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?= esc($client['client_id']) ?>">
                                        <?= esc(($client['client_name'] ?? '') . ' ' . ($client['client_surname'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                    </div>
                </div>

        </div>

    </div>

</form>

<?= $this->endSection() ?>