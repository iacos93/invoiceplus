<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
$this->setVar('pageTitle', trans('units'));
$actions = '<a class="btn btn-sm btn-primary" href="' . site_url('units/form') . '">'
         . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new') . '</a>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('units'), 'actions' => $actions]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-cubes" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('units')) ?></span>
                <span class="badge text-bg-secondary"><?= isset($pager) ? $pager->getTotal('ip_units') : count($units) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search" data-target="#units-list-table">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca unità…')) ?>" autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover ip-table-card-collapse" id="units-list-table">

            <thead>
            <tr>
                <th scope="col"><?= trans('unit_name') ?></th>
                <th scope="col"><?= trans('unit_name_plrl') ?></th>
                <th scope="col"><?= trans('unit_type', '', 'Tipo') ?></th>
                <th scope="col"><?= trans('options') ?></th>
            </tr>
            </thead>

            <tbody>
            <?php foreach ($units as $unit): ?>
                <tr>
                    <td data-label="<?= esc(trans('unit_name')) ?>"><?= esc($unit['unit_name']) ?></td>
                    <td data-label="<?= esc(trans('unit_name_plrl')) ?>"><?= esc($unit['unit_name_plrl']) ?></td>
                    <td data-label="<?= esc(trans('unit_type', '', 'Tipo')) ?>"><?= esc(\App\Models\UnitModel::typeLabel($unit['unit_type'] ?? null)) ?></td>
                    <td data-label="<?= esc(trans('options')) ?>">
                        <div class="options btn-group">
                            <button type="button" class="btn btn-secondary btn-sm dropdown-toggle"
                               data-bs-toggle="dropdown">
                                <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options') ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="<?= site_url('units/form/' . $unit['unit_id']) ?>">
                                        <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit') ?>
                                    </a>
                                </li>
                                <li>
                                    <form action="<?= site_url('units/delete/' . $unit['unit_id']) ?>"
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
            <?php if (empty($units)): ?>
                <?= $this->include('layout/empty_row', [
                    'colspan'   => 3,
                    'icon'      => 'fa-solid fa-cubes',
                    'message'   => trans('no_units'),
                    'cta_url'   => site_url('units/form'),
                    'cta_label' => trans('new'),
                ]) ?>
            <?php endif; ?>
            </tbody>

        </table>

    </div>
    <?= $this->include('layout/pager_footer', ['group' => 'ip_units']) ?>
    </div>
</div>

<?= $this->endSection() ?>