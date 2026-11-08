<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('custom_values')); ?>
<?php $actions = '<div class="btn-group btn-group-sm"><a class="btn btn-secondary" href="' . site_url('custom_values') . '"><i class="fa fa-solid fa-arrow-left" aria-hidden="true"></i> ' . trans('back') . '</a><a class="btn btn-primary" href="' . site_url('custom_values/create/' . $field['custom_field_id']) . '"><i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new') . '</a></div>'; ?>
<?= $this->include('layout/headerbar', ['title' => trans('custom_values'), 'actions' => $actions]) ?>

<div id="content">

    <div class="ip-form-pane">

            <div class="card">
                <div class="card-header">
                    <?= esc($field['custom_field_label']) ?>
                </div>

                <div class="card-body">
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="custom_values_label"><?= trans('field') ?>: </label>
                        <input type="text" name="custom_values_label" id="custom_values_label" class="form-control"
                               value="<?= esc($field['custom_field_label']) ?>" disabled="disabled">
                    </div>

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="custom_field_types"><?= trans('type') ?>: </label>
                        <input type="text" id="custom_field_types" class="form-control"
                               value="<?= esc(trans(str_replace('-', '_', strtolower($field['custom_field_type'])))) ?>"
                               disabled="disabled">
                    </div>

                    <div class="mb-3">
                        <div class="table-responsive">
                            <table class="table table-bordered no-margin">

                                <thead>
                                <tr>
                                    <th scope="col"><?= trans('id') ?></th>
                                    <th scope="col"><?= trans('label') ?></th>
                                    <th scope="col"><?= trans('options') ?></th>
                                </tr>
                                </thead>

                                <tbody>
                                <?php foreach ($elements as $element): ?>
                                    <tr>
                                        <td><?= esc($element['custom_values_id']) ?></td>
                                        <td><?= esc($element['custom_values_value']) ?></td>
                                        <td>
                                            <div class="options btn-group">
                                                <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown"
                                                  >
                                                    <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options') ?>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li>
                                                        <a href="<?= site_url('custom_values/edit/' . $element['custom_values_id']) ?>">
                                                            <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit') ?>
                                                        </a>
                                                    </li>
                                                    <li>
                                                        <form action="<?= site_url('custom_values/delete/' . $element['custom_values_id']) ?>"
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
                                <?php if (empty($elements)): ?>
                                    <tr class="empty-row">
                                        <td colspan="3">
                                            <span class="empty-text"><?= trans('no_values', '', 'Nessun valore presente.') ?></span>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>

                            </table>
                        </div>
                    </div>
                </div>

            </div>

    </div>

</div>

<?= $this->endSection() ?>