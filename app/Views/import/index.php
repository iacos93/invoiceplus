<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php
$this->setVar('pageTitle', trans('import_data', '', 'Importazioni'));
$actions = '<a class="btn btn-secondary btn-sm" href="' . site_url('imports_exports') . '"><i class="fa fa-solid fa-arrow-left" aria-hidden="true"></i> ' . esc(trans('imports_exports', '', 'Importa / Esporta')) . '</a> '
    . '<a class="btn btn-sm btn-primary" href="' . site_url('import/form') . '"><i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new_import', '', 'Nuova importazione') . '</a>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('import_data', '', 'Importazioni'), 'actions' => $actions]) ?>

<div id="content" class="table-content">

    <?php if (empty($imports)): ?>

        <div class="empty-state card">
            <div class="card-body empty-body">
                <i class="fa fa-solid fa-upload empty-state-icon" aria-hidden="true"></i>
                <p class="empty-state-text">
                    <?= trans('no_imports', '', 'Nessuna importazione ancora eseguita. Carica clients.csv, invoices.csv, invoice_items.csv o payments.csv per popolare l’anagrafica da un foglio esistente.') ?>
                </p>
                <a class="btn btn-sm btn-primary" href="<?= site_url('import/form') ?>">
                    <i class="fa fa-solid fa-upload fa-margin" aria-hidden="true"></i> <?= trans('import_first', '', 'Avvia la prima importazione') ?>
                </a>
            </div>
        </div>

    <?php else: ?>

    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-upload" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('import_data', '', 'Importazioni')) ?></span>
                <span class="badge text-bg-secondary"><?= count($imports) ?></span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover">

            <thead>
            <tr>
                <th scope="col"><?= trans('date', '', 'Data') ?></th>
                <th scope="col"><?= trans('files', '', 'File') ?></th>
                <th scope="col" class="text-end"><?= trans('rows', '', 'Righe') ?></th>
                <th scope="col"><?= trans('status', '', 'Esito') ?></th>
                <th scope="col"><?= trans('options') ?></th>
            </tr>
            </thead>

            <tbody>
            <?php foreach ($imports as $import): ?>
                <?php
                $rawDate = (string) ($import['import_date'] ?? '');
                $day     = $rawDate !== '' ? date_from_mysql(substr($rawDate, 0, 10)) : '';
                $time    = strlen($rawDate) >= 16 ? substr($rawDate, 11, 5) : '';
                $files   = trim((string) ($import['import_file'] ?? ''));
                $error   = trim((string) ($import['import_error'] ?? ''));
                ?>
                <tr>
                    <td data-label="<?= esc(trans('date', '', 'Data')) ?>">
                        <?= esc($day ?: '—') ?>
                        <?php if ($time !== ''): ?><span class="ip-cell-sub"><?= esc($time) ?></span><?php endif; ?>
                    </td>
                    <td data-label="<?= esc(trans('files', '', 'File')) ?>">
                        <?php if ($files === ''): ?>
                            <span class="ip-cell-sub">—</span>
                        <?php else: ?>
                            <?php foreach (explode(', ', $files) as $f): ?>
                                <span class="ip-import-filetag"><?= esc($f) ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-end amount" data-label="<?= esc(trans('rows', '', 'Righe')) ?>">
                        <?= (int) ($import['import_rows'] ?? 0) ?>
                    </td>
                    <td data-label="<?= esc(trans('status', '', 'Esito')) ?>">
                        <?php if ($error !== ''): ?>
                            <span class="ip-import-outcome is-error" title="<?= esc($error) ?>">
                                <i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i> <?= esc(trans('with_errors', '', 'Con errori')) ?>
                            </span>
                            <span class="ip-cell-sub"><?= esc(mb_strimwidth($error, 0, 90, '…')) ?></span>
                        <?php else: ?>
                            <span class="ip-import-outcome is-ok">
                                <i class="fa fa-solid fa-check" aria-hidden="true"></i> <?= esc(trans('completed', '', 'Completata')) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td data-label="<?= esc(trans('options')) ?>">
                        <div class="options btn-group">
                            <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options') ?>
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <form action="<?= site_url('import/delete/' . $import['import_id']) ?>"
                                          method="POST">
                                        <?= csrf_field() ?>
                                        <button type="button" class="dropdown-button"
                                                data-bs-toggle="confirm"
                                                data-confirm-title="<?= esc(trans('confirm', '', 'Conferma')) ?>"
                                                data-confirm-body="<?= esc(trans('import_rollback_warning', '', 'Annullare questa importazione? Le righe che aveva inserito — clienti, fatture, righe e pagamenti — verranno rimosse dall’archivio. L’operazione non è reversibile.')) ?>"
                                                data-confirm-ok="<?= esc(trans('undo_import', '', 'Annulla importazione')) ?>"
                                                data-confirm-danger="1">
                                            <i class="fa fa-solid fa-undo fa-margin" aria-hidden="true"></i> <?= esc(trans('undo_import', '', 'Annulla importazione')) ?>
                                        </button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>

        </table>
        </div>
        <div class="card-footer ip-table-card-footer">
            <span class="ip-cell-sub"><i class="fa fa-solid fa-info-circle" aria-hidden="true"></i>
                <?= trans('import_history_note', '', 'Ogni esecuzione registra i file elaborati, le righe entrate e gli eventuali errori. “Annulla importazione” rimuove dall’archivio le righe che quell’esecuzione aveva inserito, non solo la voce da questo elenco.') ?>
            </span>
        </div>
    </div>

    <?php endif; ?>
</div>

<?= $this->endSection() ?>
