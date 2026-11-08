<?php
// Mappa classi Bootstrap 3 per i badge di stato (stile CI3)
$status_label_class = [
    'draft' => 'text-bg-secondary',
    'sent' => 'text-bg-info',
    'viewed' => 'text-bg-warning',
    'approved' => 'text-bg-success',
    'rejected' => 'text-bg-danger',
    'canceled' => 'text-bg-dark',
];
?>

<div class="table-responsive">
    <table class="table table-hover ip-clickable-rows">

        <thead>
        <tr>
            <th scope="col"><?= trans('status', '', 'Stato') ?></th>
            <th scope="col"><?= trans('quote', '', 'Preventivo') ?></th>
            <th scope="col"><?= trans('created', '', 'Creato') ?></th>
            <th scope="col"><?= trans('due_date', '', 'Scadenza') ?></th>
            <th scope="col"><?= trans('client_name', '', 'Cliente') ?></th>
            <th scope="col" class="amount"><?= trans('amount', '', 'Importo') ?></th>
            <th scope="col"><?= trans('options', '', 'Opzioni') ?></th>
        </tr>
        </thead>

        <tbody>
        <?php foreach ($quotes as $quote): ?>
            <?php
            $statusInfo          = $quote_statuses[(string) $quote['quote_status_id']] ?? ['label' => '', 'class' => 'draft'];
            $status_class_label  = $status_label_class[$statusInfo['class']] ?? 'text-bg-secondary';
            ?>
            <tr>
                <td>
                    <span class="badge ip-status <?= $status_class_label ?>"><?= esc($statusInfo['label']) ?></span>
                </td>
                <td>
                    <a href="<?= site_url('quotes/view/' . (int) $quote['quote_id']) ?>">
                        <?= esc($quote['quote_number'] ?: $quote['quote_id']) ?>
                    </a>
                </td>
                <td><?= date_from_mysql($quote['quote_date_created']) ?></td>
                <td><?= date_from_mysql($quote['quote_date_expires']) ?></td>
                <td><?= esc(format_client($quote)) ?></td>
                <td class="amount"><?= format_currency($quote['quote_total'] ?? 0) ?></td>
                <td>
                    <div class="options btn-group">
                        <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options', '', 'Opzioni') ?>
                        </button>
                        <ul class="dropdown-menu">
                            <li>
                                <a href="<?= site_url('quotes/view/' . (int) $quote['quote_id']) ?>">
                                    <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit', '', 'Modifica') ?>
                                </a>
                            </li>
                            <li>
                                <a href="<?= site_url('quotes/view/' . (int) $quote['quote_id']) ?>">
                                    <i class="fa fa-solid fa-eye fa-margin" aria-hidden="true"></i> <?= trans('view', '', 'Vista') ?>
                                </a>
                            </li>
                            <li>
                                <a href="<?= site_url('quotes/generate_pdf/' . (int) $quote['quote_id']) ?>"
                                   target="_blank">
                                    <i class="fa fa-regular fa-file-pdf fa-margin" aria-hidden="true"></i> <?= trans('download_pdf', '', 'PDF') ?>
                                </a>
                            </li>
                            <li>
                                <form action="<?= site_url('quotes/delete/' . (int) $quote['quote_id']) ?>"
                                      method="POST">
                                    <?= csrf_field() ?>
                                    <button type="button" class="dropdown-button"
                                            data-bs-toggle="confirm" data-confirm-title="<?= esc(trans('confirm', '', 'Conferma')) ?>" data-confirm-body="<?= esc(trans('delete_quote_warning', '', 'Eliminare il preventivo?')) ?>" data-confirm-ok="<?= esc(trans('delete', '', 'Elimina')) ?>" data-confirm-danger="1">
                                        <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete', '', 'Elimina') ?>
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($quotes)): ?>
            <tr><td colspan="7"><?= trans('no_quotes_found') ?></td></tr>
        <?php endif; ?>
        </tbody>

    </table>
</div>
<input type="hidden" name="csrf_test_name" value="<?= csrf_hash() ?>">
