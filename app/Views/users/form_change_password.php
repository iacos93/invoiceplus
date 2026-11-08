<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('change_password')); ?>

<script src="<?= base_url('assets/core/js/zxcvbn.js') ?>"></script>

<form method="POST" action="<?= site_url('users/change_password/' . $user_id) ?>">

    <?= csrf_field() ?>

    <?= $this->include('layout/headerbar', ['title' => trans('change_password'), 'actions' => header_actions()]) ?>

    <div id="content">

        <div class="ip-form-pane">

                <div class="card">
                    <div class="card-header">
                        <?= trans('change_password') ?>
                    </div>

                    <div class="card-body">
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="user_password">
                                <?= trans('password') ?>
                            </label>
                            <input type="password" name="user_password" id="user_password"
                                   class="form-control passwordmeter-input">
                            <div class="progress" style="height:3px;">
                                <div class="progress-bar progress-bar-danger passmeter passmeter-1"
                                     style="width: 33%"></div>
                                <div class="progress-bar progress-bar-warning passmeter passmeter-2"
                                     style="display: none; width: 33%"></div>
                                <div class="progress-bar progress-bar-success passmeter passmeter-3"
                                     style="display: none; width: 34%"></div>
                            </div>
                            <?php if (!empty($errors['user_password'])): ?>
                                <span class="ip-field-error"><?= esc($errors['user_password']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="user_passwordv">
                                <?= trans('verify_password') ?>
                            </label>
                            <input type="password" name="user_passwordv" id="user_passwordv"
                                   class="form-control">
                            <?php if (!empty($errors['user_passwordv'])): ?>
                                <span class="ip-field-error"><?= esc($errors['user_passwordv']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>

        </div>

    </div>

</form>

<?= $this->endSection() ?>