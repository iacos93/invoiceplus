<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('custom_values')); ?>
<?php $actions = '<a class="btn btn-sm btn-primary" href="' . site_url('custom_fields/form') . '"><i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new') . '</a>'; ?>
<?= $this->include('layout/headerbar', ['title' => trans('custom_values'), 'actions' => $actions]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-list" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('custom_values')) ?></span>
                <span class="badge text-bg-secondary"><?= count($custom_values) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search" data-target="#custom-values-list-table">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca campo…')) ?>" autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover ip-clickable-rows ip-table-card-collapse" id="custom-values-list-table">

            <thead>
            <tr>
                <th scope="col"><?= trans('id') ?></th>
                <th scope="col"><?= trans('field') ?></th>
                <th scope="col"><?= trans('elements') ?></th>
                <th scope="col"><?= trans('type') ?></th>
                <th scope="col"><?= trans('options') ?></th>
            </tr>
            </thead>

            <tbody>
            <?php foreach ($custom_values as $custom_value): ?>
                <?php $alpha = str_replace('-', '_', strtolower($custom_value['custom_field_type'])); ?>
                <?php /* Il link di modifica punta a custom_values/field/{id}, che il
                         fallback "primo link form/ o view/" di ip-table-rows.js non
                         riconosce: l'URL della riga va dichiarato esplicitamente. */ ?>
                <tr data-row-url="<?= site_url('custom_values/field/' . $custom_value['custom_field_id']) ?>">
                    <?php // L'id è secondario: sulla scheda è solo rumore. ?>
                    <td class="ip-hide-on-mobile"><?= esc($custom_value['custom_field_id']) ?></td>
                    <td data-cell="number"><?= esc($custom_value['custom_field_label']) ?></td>
                    <td class="amount" data-cell="amount"><?= (int) $custom_value['count'] ?></td>
                    <td data-cell="client"><?= esc(trans($alpha)) ?></td>
                    <td class="ip-hide-on-mobile">
                        <div class="options btn-group">
                            <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options') ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="<?= site_url('custom_values/field/' . $custom_value['custom_field_id']) ?>">
                                        <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit') ?>
                                    </a>
                                </li>
                                <li>
                                    <form action="<?= site_url('custom_fields/delete/' . $custom_value['custom_field_id']) ?>"
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
            <?php if (empty($custom_values)): ?>
                <tr class="empty-row">
                    <td colspan="5">
                        <span class="empty-text"><?= trans('no_custom_fields', '', 'Nessun campo personalizzato presente.') ?></span>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>

        </table>
    </div>
    </div>
</div>

<?= $this->endSection() ?>