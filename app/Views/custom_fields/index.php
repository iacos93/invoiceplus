<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
$this->setVar('pageTitle', trans('custom_fields'));
$actions = '<a class="btn btn-sm btn-primary" href="' . site_url('custom_fields/form') . '">'
         . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new') . '</a>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('custom_fields'), 'actions' => $actions]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-puzzle-piece" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('custom_fields')) ?></span>
                <span class="badge text-bg-secondary"><?= isset($pager) ? $pager->getTotal('ip_custom_fields') : count($custom_fields) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search" data-target="#custom-fields-list-table">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca campo personalizzato…')) ?>" autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover ip-table-card-collapse" id="custom-fields-list-table">

            <thead>
            <tr>
                <th scope="col"><?= trans('table') ?></th>
                <th scope="col"><?= trans('label') ?></th>
                <th scope="col"><?= trans('type') ?></th>
                <th scope="col"><?= trans('order') ?></th>
                <th scope="col"><?= trans('options') ?></th>
            </tr>
            </thead>

            <tbody>
            <?php foreach ($custom_fields as $custom_field): ?>
                <?php $alpha = str_replace('-', '_', strtolower($custom_field['custom_field_type'])); ?>
                <tr>
                    <td data-label="<?= esc(trans('table')) ?>"><?= esc(trans($custom_tables[$custom_field['custom_field_table']] ?? $custom_field['custom_field_table'])) ?></td>
                    <td data-label="<?= esc(trans('label')) ?>"><?= esc($custom_field['custom_field_label']) ?></td>
                    <td data-label="<?= esc(trans('type')) ?>"><?= esc(trans($alpha)) ?></td>
                    <td data-label="<?= esc(trans('order')) ?>"><?= esc($custom_field['custom_field_order']) ?></td>
                    <td data-label="<?= esc(trans('options')) ?>">
                        <div class="options btn-group btn-group-sm">
                            <?php if (in_array($custom_field['custom_field_type'], $custom_value_types)): ?>
                                <a href="<?= site_url('custom_values/field/' . $custom_field['custom_field_id']) ?>"
                                   class="btn btn-secondary btn-sm">
                                    <i class="fa fa-solid fa-list fa-margin" aria-hidden="true"></i> <?= trans('values') ?>
                                </a>
                            <?php endif; ?>
                            <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options') ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="<?= site_url('custom_fields/form/' . $custom_field['custom_field_id']) ?>">
                                        <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit') ?>
                                    </a>
                                </li>
                                <li>
                                    <form action="<?= site_url('custom_fields/delete/' . $custom_field['custom_field_id']) ?>"
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
            <?php if (empty($custom_fields)): ?>
                <tr class="empty-row">
                    <td colspan="5">
                        <i class="fa fa-solid fa-tags empty-icon" aria-hidden="true"></i>
                        Nessun campo personalizzato presente.
                        <a href="<?= site_url('custom_fields/form') ?>" class="empty-cta">
                            <i class="fa fa-solid fa-plus" aria-hidden="true"></i> Nuovo
                        </a>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>

        </table>
    </div>
    <?= $this->include('layout/pager_footer', ['group' => 'ip_custom_fields']) ?>
    </div>
</div>

<?= $this->endSection() ?>