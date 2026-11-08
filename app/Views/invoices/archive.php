<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('invoice_archive', '', 'Archivio fatture')); ?>
<?php $actions = '<form action="' . site_url('invoices/archive') . '" method="get"><div class="input-group"><input name="invoice_number" id="invoice_number" type="text" class="form-control input-sm" aria-label="' . esc(trans('invoice_number', '', 'Numero fattura'), 'attr') . '" placeholder="' . esc(trans('invoice_number', '', 'Numero fattura'), 'attr') . '" value="' . esc($invoice_number ?? '') . '"><button class="btn btn-secondary btn-sm" type="submit"><i class="fa fa-solid fa-filter" aria-hidden="true"></i> ' . trans('filter_invoices', '', 'Filtra fatture') . '</button></div></form>'; ?>
<?= $this->include('layout/headerbar', ['title' => trans('invoice_archive', '', 'Archivio fatture'), 'actions' => $actions]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-archive" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('invoice_archive', '', 'Archivio fatture')) ?></span>
                <span class="badge text-bg-secondary"><?= count($invoices_archive) ?></span>
            </div>
        </div>
    <div id="filter_results">
        <div class="table-responsive">
            <table class="table table-hover ip-table-card-collapse">

                <thead>
                <tr>
                    <th scope="col"><?= trans('invoice', '', 'Fattura') ?></th>
                    <th scope="col"><?= trans('created', '', 'Creata') ?></th>
                    <th scope="col"><?= trans('options', '', 'Opzioni') ?></th>
                </tr>
                </thead>

                <tbody>
                <?php foreach ($invoices_archive as $file): ?>
                    <tr>
                        <td data-label="<?= esc(trans('invoice', '', 'Fattura')) ?>">
                            <a href="<?= site_url('invoices/archive?download=' . urlencode(basename($file))) ?>"
                               title="<?= trans('invoice', '', 'Fattura') ?>">
                                <i class="fa fa-regular fa-file-pdf" aria-hidden="true"></i>&nbsp;<?= esc(basename($file)) ?>
                            </a>
                        </td>
                        <td data-label="<?= esc(trans('created', '', 'Creata')) ?>">
                            <?= date('F d Y H:i:s.', filemtime($file)) ?>
                        </td>
                        <td data-label="<?= esc(trans('options', '', 'Opzioni')) ?>">
                            <a class="btn btn-sm btn-secondary"
                               href="<?= site_url('invoices/archive?download=' . urlencode(basename($file))) ?>">
                                <i class="fa fa-solid fa-download" aria-hidden="true"></i> <?= trans('download', '', 'Scarica') ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($invoices_archive)): ?>
                    <tr class="empty-row">
                        <td colspan="3">
                            <i class="fa fa-solid fa-archive empty-icon" aria-hidden="true"></i>
                            <?= trans('no_archived_documents') ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>

            </table>
        </div>
    </div>
    </div>
</div>

<?= $this->endSection() ?>