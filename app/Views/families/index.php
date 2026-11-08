<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
$this->setVar('pageTitle', trans('families'));
$actions = '<a class="btn btn-sm btn-primary" href="' . site_url('families/form') . '">'
         . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new') . '</a>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('families'), 'actions' => $actions]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-folder" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('families')) ?></span>
                <span class="badge text-bg-secondary"><?= count($families) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search" data-target="#families-list-table">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca famiglia…')) ?>" autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover ip-clickable-rows ip-table-card-collapse" id="families-list-table">

            <thead>
            <tr>
                <th scope="col"><?= trans('family_name') ?></th>
                <th scope="col"><?= trans('options') ?></th>
            </tr>
            </thead>

            <tbody>
            <?php foreach ($families as $family): ?>
                <tr>
                    <td data-cell="number"><?= esc($family['family_name']) ?></td>
                    <td class="ip-hide-on-mobile">
                        <div class="options btn-group">
                            <button type="button" class="btn btn-secondary btn-sm dropdown-toggle"
                               data-bs-toggle="dropdown">
                                <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options') ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="<?= site_url('families/form/' . $family['family_id']) ?>">
                                        <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit') ?>
                                    </a>
                                </li>
                                <li>
                                    <form action="<?= site_url('families/delete/' . $family['family_id']) ?>"
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
            <?php if (empty($families)): ?>
                <?= $this->include('layout/empty_row', [
                    'colspan'   => 2,
                    'icon'      => 'fa-solid fa-folder',
                    'message'   => trans('no_families'),
                    'cta_url'   => site_url('families/form'),
                    'cta_label' => trans('new'),
                ]) ?>
            <?php endif; ?>
            </tbody>

        </table>
    </div>
    <?= $this->include('layout/pager_footer', ['group' => 'ip_families']) ?>
    </div>
</div>

<?= $this->endSection() ?>