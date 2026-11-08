<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
$this->setVar('pageTitle', trans('invoice_groups'));
$actions = '<a class="btn btn-sm btn-primary" href="' . ip_resource_url('invoice_groups', 'form') . '">'
         . '<i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new') . '</a>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('invoice_groups'), 'actions' => $actions]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-list-ol" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('invoice_groups')) ?></span>
                <span class="badge text-bg-secondary"><?= isset($pager) ? $pager->getTotal('ip_invoice_groups') : count($invoice_groups) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search" data-target="#invoice-groups-list-table">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca gruppo…')) ?>" autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover ip-table-card-collapse" id="invoice-groups-list-table">

            <thead>
            <tr>
                <th scope="col"><?= trans('name') ?></th>
                <th scope="col"><?= trans('identifier_format') ?></th>
                <th scope="col"><?= trans('next_number', '', 'Prossimo numero') ?></th>
                <th scope="col"><?= trans('used_for', '', 'Usata per') ?></th>
                <th scope="col"><?= trans('options') ?></th>
            </tr>
            </thead>

            <tbody>
            <?php
            $default_invoice_group = (int) company_setting('default_invoice_group', 0);
            $default_quote_group   = (int) company_setting('default_quote_group', 0);
            $draft_invoice_group   = (int) company_setting('draft_invoice_group', 0);
            $draft_quote_group     = (int) company_setting('draft_quote_group', 0);
            $group_model           = new \App\Models\InvoiceGroupModel();
            ?>
            <?php foreach ($invoice_groups as $invoice_group): ?>
                <?php
                $group_id  = (int) $invoice_group['invoice_group_id'];
                $used_for  = [];
                if ($group_id === $default_invoice_group) { $used_for[] = trans('invoices', '', 'Fatture'); }
                if ($group_id === $draft_invoice_group)   { $used_for[] = trans('invoice_drafts', '', 'Bozze fatture'); }
                if ($group_id === $default_quote_group)   { $used_for[] = trans('quotes', '', 'Preventivi'); }
                if ($group_id === $draft_quote_group)     { $used_for[] = trans('quote_drafts', '', 'Bozze preventivi'); }
                ?>
                <tr>
                    <td data-label="<?= esc(trans('name')) ?>"><?= esc($invoice_group['invoice_group_name']) ?></td>
                    <td data-label="<?= esc(trans('identifier_format')) ?>">
                        <?php if (trim((string) $invoice_group['invoice_group_identifier_format']) !== ''): ?>
                            <code><?= esc($invoice_group['invoice_group_identifier_format']) ?></code>
                        <?php else: ?>
                            <span class="text-muted"><?= trans('not_set', '', 'Non impostato') ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= esc(trans('next_number', '', 'Prossimo numero')) ?>"><strong><?= esc($group_model->previewNumber($invoice_group)) ?></strong></td>
                    <td data-label="<?= esc(trans('used_for', '', 'Usata per')) ?>">
                        <?php if ($used_for): ?>
                            <?php foreach ($used_for as $label): ?>
                                <span class="badge text-bg-secondary"><?= esc($label) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= esc(trans('options')) ?>">
                        <div class="options btn-group">
                            <button type="button" class="btn btn-secondary btn-sm dropdown-toggle"
                               data-bs-toggle="dropdown">
                                <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options') ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a href="<?= ip_resource_url('invoice_groups', 'form', $invoice_group['invoice_group_id']) ?>">
                                        <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit') ?>
                                    </a>
                                </li>
                                <li>
                                    <form action="<?= ip_resource_url('invoice_groups', 'delete', $invoice_group['invoice_group_id']) ?>"
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
            <?php if (empty($invoice_groups)): ?>
                <?= $this->include('layout/empty_row', [
                    'colspan'   => 5,
                    'icon'      => 'fa-solid fa-list-ol',
                    'message'   => trans('no_invoice_groups'),
                    'cta_url'   => ip_resource_url('invoice_groups', 'form'),
                    'cta_label' => trans('new'),
                ]) ?>
            <?php endif; ?>
            </tbody>

        </table>
    </div>
    <?= $this->include('layout/pager_footer', ['group' => 'ip_invoice_groups']) ?>
    </div>
</div>

<?= $this->endSection() ?>