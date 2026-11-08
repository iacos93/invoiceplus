<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
$this->setVar('pageTitle', trans('tax_rates'));
$actions = '<a class="btn btn-sm btn-primary" href="' . site_url('tax_rates/form') . '">'
         . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new') . '</a>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('tax_rates'), 'actions' => $actions]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-percent" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('tax_rates')) ?></span>
                <span class="badge text-bg-secondary"><?= isset($pager) ? $pager->getTotal('ip_tax_rates') : count($tax_rates) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search" data-target="#tax-rates-list-table">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca aliquota…')) ?>" autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover ip-clickable-rows ip-table-card-collapse" id="tax-rates-list-table">

            <thead>
            <tr>
                <th scope="col"><?= trans('tax_rate_name') ?></th>
                <th scope="col"><?= trans('tax_rate_percent') ?></th>
                <th scope="col"><?= trans('tax_rate_natura', '', 'Natura IVA') ?></th>
                <th scope="col"><?= trans('options') ?></th>
            </tr>
            </thead>

            <tbody>
            <?php foreach ($tax_rates as $tax_rate):
                $natura        = $tax_rate['tax_rate_natura'] ?? null;
                $reverseCharge = \App\Models\TaxRateModel::isReverseCharge($natura);
            ?>
                <tr>
                    <td class="ip-table-cell-truncate" data-cell="number" title="<?= esc($tax_rate['tax_rate_name']) ?>">
                        <?= esc($tax_rate['tax_rate_name']) ?>
                    </td>
                    <td class="amount" data-cell="amount"><?= esc(format_amount($tax_rate['tax_rate_percent'])) ?>%</td>
                    <td data-cell="client">
                        <?php if ($natura): ?>
                            <span class="badge <?= $reverseCharge ? 'text-bg-warning' : 'text-bg-secondary' ?>">
                                <?php if ($reverseCharge): ?><i class="fa fa-solid fa-right-left fa-margin" aria-hidden="true"></i><?php endif; ?>
                                <?= esc($natura) ?>
                            </span>
                            <?php /* Il codice da solo (es. "N6.3") non dice nulla a chi non lo
                                     conosce a memoria: la descrizione testuale accanto al badge
                                     evita di dover aprire il form o passare il mouse per capire
                                     di che operazione si tratta. */ ?>
                            <span class="ip-table-cell-note"><?= esc(\App\Models\TaxRateModel::NATURA_CODES[$natura]['label'] ?? '') ?></span>
                        <?php else: ?>
                            <?php /* Un trattino da solo, qui, e' ambiguo: sembra un dato
                                     mancante da compilare, non "nessuna Natura perche' e'
                                     un'aliquota IVA ordinaria" (che e' la normalita' per 4/10/22%).
                                     Un testo esplicito evita il dubbio senza dover aprire il
                                     form per scoprire che va bene cosi'. */ ?>
                            <span class="text-muted"><?= esc(trans('tax_rate_natura_none_short', '', 'IVA ordinaria')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="ip-hide-on-mobile">
                        <div class="options btn-group">
                            <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options') ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="<?= site_url('tax_rates/form/' . $tax_rate['tax_rate_id']) ?>">
                                        <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit') ?>
                                    </a>
                                </li>
                                <li>
                                    <form action="<?= site_url('tax_rates/delete/' . $tax_rate['tax_rate_id']) ?>"
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
            <?php if (empty($tax_rates)): ?>
                <?= $this->include('layout/empty_row', [
                    'colspan'   => 4,
                    'icon'      => 'fa-solid fa-percent',
                    'message'   => trans('no_tax_rates'),
                    'cta_url'   => site_url('tax_rates/form'),
                    'cta_label' => trans('new'),
                ]) ?>
            <?php endif; ?>
            </tbody>

        </table>
    </div>
    <?= $this->include('layout/pager_footer', ['group' => 'ip_tax_rates']) ?>
    </div>
</div>

<?= $this->endSection() ?>