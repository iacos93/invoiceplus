<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php
$this->setVar('pageTitle', trans('expense_categories', '', 'Categorie di spesa'));
$actions = '<a class="btn btn-sm btn-primary" href="' . ip_resource_url('expense_categories', 'form') . '"><i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new', '', 'Nuova') . '</a>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('expense_categories', '', 'Categorie di spesa'), 'actions' => $actions]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-tags" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('expense_categories', '', 'Categorie di spesa')) ?></span>
                <span class="badge text-bg-secondary"><?= isset($pager) ? $pager->getTotal('ip_expense_categories') : count($categories) ?></span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover ip-table-card-collapse">
                <thead><tr>
                    <th scope="col"><?= trans('name', '', 'Nome') ?></th>
                    <th scope="col"><?= trans('deductible', '', 'Deducibile') ?></th>
                    <th scope="col"><?= trans('options', '', 'Opzioni') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($categories as $c): ?>
                    <tr>
                        <td data-label="<?= esc(trans('name', '', 'Nome')) ?>"><?= esc($c['expense_category_name']) ?></td>
                        <td data-label="<?= esc(trans('deductible', '', 'Deducibile')) ?>">
                            <?php if (db_bool($c['expense_category_deductible'])): ?>
                                <span class="badge ip-status text-bg-success"><?= trans('yes', '', 'Sì') ?></span>
                            <?php else: ?>
                                <span class="badge ip-status text-bg-secondary"><?= trans('no', '', 'No') ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="<?= esc(trans('options', '', 'Opzioni')) ?>">
                            <div class="options btn-group">
                                <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options', '', 'Opzioni') ?>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a href="<?= ip_resource_url('expense_categories', 'form', (int) $c['expense_category_id']) ?>"><i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit', '', 'Modifica') ?></a></li>
                                    <li><form action="<?= ip_resource_url('expense_categories', 'delete', (int) $c['expense_category_id']) ?>" method="POST"><?= csrf_field() ?>
                                        <button type="button" class="dropdown-button" data-bs-toggle="confirm"
                                                data-confirm-title="<?= esc(trans('confirm', '', 'Conferma'), 'attr') ?>"
                                                data-confirm-body="<?= esc(trans('delete_record_warning', '', 'Eliminare la categoria?'), 'attr') ?>"
                                                data-confirm-ok="<?= esc(trans('delete', '', 'Elimina'), 'attr') ?>" data-confirm-danger="1">
                                            <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete', '', 'Elimina') ?></button></form></li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($categories)): ?>
                    <!-- Lista principale: stessa forma delle altre tabelle
                         (tr.empty-row con icona + azione), non la variante
                         muta delle sottotabelle di modifica. -->
                    <tr class="empty-row">
                        <td colspan="3">
                            <i class="fa fa-solid fa-tags empty-icon" aria-hidden="true"></i>
                            <?= trans('no_expense_categories') ?>
                            <a href="<?= ip_resource_url('expense_categories', 'form') ?>" class="empty-cta">
                                <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('new', '', 'Nuova') ?>
                            </a>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= $this->include('layout/pager_footer', ['group' => 'ip_expense_categories']) ?>
    </div>
</div>
<?= $this->endSection() ?>
