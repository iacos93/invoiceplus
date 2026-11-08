<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('products')); ?>
<?php
$actions = '<a class="btn btn-sm btn-primary" href="' . site_url('products/form') . '">'
         . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new') . '</a>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('products'), 'actions' => $actions]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-database" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('products')) ?></span>
                <span class="badge text-bg-secondary"><?= count($products) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search" data-target="#products-list-table">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca prodotto…')) ?>" autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover ip-table-card-collapse" id="products-list-table">

            <thead>
            <tr>
                <th scope="col"><?= trans('family') ?></th>
                <th scope="col"><?= trans('product_sku') ?></th>
                <th scope="col"><?= trans('product_name') ?></th>
                <th scope="col"><?= trans('product_description') ?></th>
                <th scope="col" class="amount"><?= trans('product_price') ?></th>
                <th scope="col"><?= trans('product_unit') ?></th>
                <th scope="col"><?= trans('tax_rate') ?></th>
                <th scope="col"><?= trans('options') ?></th>
            </tr>
            </thead>

            <tbody>
            <?php foreach ($products as $product): ?>
                <tr>
                    <td data-label="<?= esc(trans('family')) ?>"><?= esc($product['family_name']) ?></td>
                    <td data-label="<?= esc(trans('product_sku')) ?>"><?= esc($product['product_sku']) ?></td>
                    <td data-label="<?= esc(trans('product_name')) ?>"><?= esc($product['product_name']) ?></td>
                    <td data-label="<?= esc(trans('product_description')) ?>"><?= nl2br(esc($product['product_description'])) ?></td>
                    <td class="amount" data-label="<?= esc(trans('product_price')) ?>"><?= format_currency($product['product_price']) ?></td>
                    <td data-label="<?= esc(trans('product_unit')) ?>"><?= esc($product['unit_name']) ?></td>
                    <td data-label="<?= esc(trans('tax_rate')) ?>"><?= $product['tax_rate_id'] ? esc($product['tax_rate_percent']) . '%' : trans('none') ?></td>
                    <td data-label="<?= esc(trans('options')) ?>">
                        <div class="options btn-group">
                            <button type="button" class="btn btn-secondary btn-sm dropdown-toggle"
                               data-bs-toggle="dropdown">
                                <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options') ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="<?= site_url('products/form/' . $product['product_id']) ?>">
                                        <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit') ?>
                                    </a>
                                </li>
                                <li>
                                    <form action="<?= site_url('products/delete/' . $product['product_id']) ?>"
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
            <?php if (empty($products)): ?>
                <?= $this->include('layout/empty_row', [
                    'colspan'   => 8,
                    'icon'      => 'fa-solid fa-database',
                    'message'   => trans('no_products'),
                    'cta_url'   => site_url('products/form'),
                    'cta_label' => trans('new_product', '', 'Nuovo prodotto'),
                ]) ?>
            <?php endif; ?>
            </tbody>

        </table>
    </div>

    </div>
</div>

<?= $this->endSection() ?>