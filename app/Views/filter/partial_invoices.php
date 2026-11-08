<?php
// Mappa classi Bootstrap 3 per i badge di stato (stile CI3)
$status_label_class = [
    'draft' => 'text-bg-secondary',
    'sent' => 'text-bg-info',
    'viewed' => 'text-bg-warning',
    'paid' => 'text-bg-success',
    'overdue' => 'text-bg-danger',
];
?>

<div class="table-responsive">
    <table class="table table-hover ip-clickable-rows">

        <thead>
        <tr>
            <th scope="col"><?= trans('status', '', 'Stato') ?></th>
            <th scope="col"><?= trans('invoice', '', 'Fattura') ?></th>
            <th scope="col"><?= trans('date', '', 'Data') ?></th>
            <th scope="col"><?= trans('client', '', 'Cliente') ?></th>
            <th scope="col" class="amount"><?= trans('total', '', 'Totale') ?></th>
            <th scope="col" class="amount"><?= trans('balance', '', 'Saldo') ?></th>
            <th scope="col"><?= trans('options', '', 'Opzioni') ?></th>
        </tr>
        </thead>

        <tbody>
        <?php foreach ($invoices as $invoice): ?>
            <?php
            $statusInfo          = $invoice_statuses[$invoice['invoice_status_id']] ?? ['label' => '', 'class' => 'draft'];
            $status_class_label  = $status_label_class[$statusInfo['class']] ?? 'text-bg-secondary';
            ?>
            <tr>
                <td>
                    <span class="badge ip-status <?= $status_class_label ?>"><?= esc($statusInfo['label']) ?></span>
                </td>
                <td>
                    <a href="<?= site_url('invoices/view/' . (int) $invoice['invoice_id']) ?>">
                        <?= esc($invoice['invoice_number'] ?: ('#' . $invoice['invoice_id'])) ?>
                    </a>
                </td>
                <td><?= date_from_mysql($invoice['invoice_date_created']) ?></td>
                <td><?= esc(format_client($invoice)) ?></td>
                <td class="amount"><?= format_currency($invoice['invoice_total'] ?? 0) ?></td>
                <td class="amount"><?= format_currency($invoice['invoice_balance'] ?? 0) ?></td>
                <td>
                    <div class="options btn-group">
                        <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                            <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options', '', 'Opzioni') ?>
                        </button>
                        <ul class="dropdown-menu">
                            <li>
                                <a href="<?= site_url('invoices/view/' . (int) $invoice['invoice_id']) ?>">
                                    <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit', '', 'Modifica') ?>
                                </a>
                            </li>
                            <li>
                                <a href="<?= site_url('invoices/view/' . (int) $invoice['invoice_id']) ?>">
                                    <i class="fa fa-solid fa-eye fa-margin" aria-hidden="true"></i> <?= trans('view', '', 'Vista') ?>
                                </a>
                            </li>
                            <li>
                                <a href="<?= site_url('invoices/generate_pdf/' . (int) $invoice['invoice_id']) ?>"
                                   target="_blank">
                                    <i class="fa fa-regular fa-file-pdf fa-margin" aria-hidden="true"></i> <?= trans('download_pdf', '', 'PDF') ?>
                                </a>
                            </li>
                            <li>
                                <a href="<?= site_url('invoices/generate_xml/' . (int) $invoice['invoice_id']) ?>"
                                   target="_blank">
                                    <i class="fa fa-regular fa-file-code fa-margin" aria-hidden="true"></i> XML
                                </a>
                            </li>
                            <li>
                                <form action="<?= site_url('invoices/delete/' . (int) $invoice['invoice_id']) ?>"
                                      method="POST">
                                    <?= csrf_field() ?>
                                    <button type="button" class="dropdown-button"
                                            data-bs-toggle="confirm" data-confirm-title="<?= esc(trans('confirm', '', 'Conferma')) ?>" data-confirm-body="<?= esc(trans('delete_invoice_warning', '', 'Eliminare la fattura?')) ?>" data-confirm-ok="<?= esc(trans('delete', '', 'Elimina')) ?>" data-confirm-danger="1">
                                        <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete', '', 'Elimina') ?>
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($invoices)): ?>
            <tr><td colspan="7"><?= trans('no_invoices_found') ?></td></tr>
        <?php endif; ?>
        </tbody>

    </table>
</div>
<input type="hidden" name="csrf_test_name" value="<?= csrf_hash() ?>">
