<?php
/* Stesso caso della vista preventivo: con public_view la vista non estendeva
   alcun layout, quindi la fattura condivisa col cliente arrivava come
   frammento HTML senza <head> e senza CSS. */
?>
<?= $this->extend('layout/guest') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('invoice', '', 'Fattura') . ' #' . ($invoice['invoice_number'] ?: $invoice['invoice_id'])); ?>

<?php
// Mappa classi Bootstrap 3 per i badge di stato (stile CI3)
$status_label_class = [
    'draft' => 'text-bg-secondary',
    'sent' => 'text-bg-info',
    'viewed' => 'text-bg-warning',
    'paid' => 'text-bg-success',
    'overdue' => 'text-bg-danger',
];
$statusInfo        = $invoice_statuses[(string) $invoice['invoice_status_id']] ?? ['label' => '', 'class' => 'draft'];
$status_class_label = $status_label_class[$statusInfo['class']] ?? 'text-bg-secondary';
?>

<?php if (!empty($invoice_password_required)): ?>

    <?= $this->include('layout/headerbar', ['title' => trans('invoice', '', 'Fattura')]) ?>

    <div id="content">
        <div class="card">
            <div class="card-header"><?= trans('invoice_password_required', '', 'Questa fattura e protetta da password.') ?></div>
            <div class="card-body">
                <p><?= trans('invoice_password_required', '', 'Questa fattura e protetta da password.') ?></p>
                <form method="POST" action="<?= site_url('guest/view/invoice/' . $invoice_url_key) ?>">
                    <?= csrf_field() ?>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="invoice_password"><?= trans('password', '', 'Password') ?></label>
                        <input type="password" name="invoice_password" id="invoice_password"
                               class="form-control" required>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-solid fa-unlock" aria-hidden="true"></i> <?= trans('submit', '', 'Invia') ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

<?php else: ?>

<?php
    $title = trans('invoice', '', 'Fattura')
        . ' #' . esc($invoice['invoice_number'] ?: $invoice['invoice_id'])
        . ' <span class="badge ip-status ' . $status_class_label . '">' . esc($statusInfo['label']) . '</span>';
    $actions = '<div class="options btn-group btn-group-sm">';
    if (!empty($public_view)):
        $actions .= '<a class="btn btn-success" href="' . site_url('guest/view/generate_invoice_pdf/' . $invoice_url_key) . '"><i class="fa fa-regular fa-file-pdf" aria-hidden="true"></i> ' . trans('download_pdf', '', 'PDF') . '</a>';
    else:
        $actions .= '<a class="btn btn-success" href="' . site_url('guest/invoices/generate_pdf/' . $invoice_id) . '"><i class="fa fa-regular fa-file-pdf" aria-hidden="true"></i> ' . trans('download_pdf', '', 'PDF') . '</a>';
    endif;
    $actions .= '</div>';
    ?>
    <?= $this->include('layout/headerbar', ['title' => $title, 'actions' => $actions]) ?>

    <div id="content">

        <div class="row">

            <div class="col-12 col-md-6">
                <div class="card">
                    <div class="card-header"><?= trans('bill_to', '', 'Fatturare a') ?></div>
                    <div class="card-body">
                        <h4><?= esc(format_client($invoice)) ?></h4>
                        <div class="client-address">
                            <?php if (!empty($invoice['client_address_1'])): ?><?= esc($invoice['client_address_1']) ?><br><?php endif; ?>
                            <?php if (!empty($invoice['client_address_2'])): ?><?= esc($invoice['client_address_2']) ?><br><?php endif; ?>
                            <?php if (!empty($invoice['client_city']) || !empty($invoice['client_state']) || !empty($invoice['client_zip'])): ?>
                                <?= esc(trim(($invoice['client_city'] ?? '') . ' ' . ($invoice['client_state'] ?? '') . ' ' . ($invoice['client_zip'] ?? ''))) ?><br>
                            <?php endif; ?>
                            <?php if (!empty($invoice['client_phone'])): ?><?= trans('phone', '', 'Telefono') ?>: <?= esc($invoice['client_phone']) ?><br><?php endif; ?>
                            <?php if (!empty($invoice['client_email'])): ?><?= trans('email', '', 'Email') ?>: <?= esc($invoice['client_email']) ?><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="card">
                    <div class="card-header"><?= trans('invoice', '', 'Fattura') ?></div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr>
                                <td><strong><?= trans('invoice', '', 'Fattura') ?> #</strong></td>
                                <td><?= esc($invoice['invoice_number'] ?: $invoice['invoice_id']) ?></td>
                            </tr>
                            <tr>
                                <td><strong><?= trans('date', '', 'Data') ?></strong></td>
                                <td><?= date_from_mysql($invoice['invoice_date_created']) ?></td>
                            </tr>
                            <tr>
                                <td><strong><?= trans('due_date', '', 'Scadenza') ?></strong></td>
                                <td><?= date_from_mysql($invoice['invoice_date_due']) ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

        </div>

        <div class="card">
            <div class="card-header"><?= trans('items', '', 'Righe') ?></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                        <tr>
                            <th scope="col"><?= trans('item', '', 'Articolo') ?></th>
                            <th scope="col"><?= trans('description', '', 'Descrizione') ?></th>
                            <th scope="col" class="amount"><?= trans('quantity', '', 'Quantita') ?></th>
                            <th scope="col" class="amount"><?= trans('price', '', 'Prezzo') ?></th>
                            <th scope="col" class="amount"><?= trans('discount', '', 'Sconto') ?></th>
                            <th scope="col" class="amount"><?= trans('total', '', 'Totale') ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= esc($item['item_name']) ?></td>
                                <td><?= nl2br(esc($item['item_description'] ?? '')) ?></td>
                                <td class="amount"><?= esc($item['item_quantity']) ?></td>
                                <td class="amount"><?= format_currency($item['item_price']) ?></td>
                                <td class="amount"><?= format_currency($item['item_discount'] ?? 0) ?></td>
                                <td class="amount"><?= format_currency($item['item_total'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row">

            <div class="col-12 col-md-6 offset-md-6">
                <div class="card">
                    <div class="card-header"><?= trans('invoice_totals', '', 'Totali') ?></div>
                    <div class="card-body">
                        <table class="table table-bordered text-end">
                            <tr>
                                <td style="width: 60%;"><?= trans('subtotal', '', 'Imponibile') ?></td>
                                <td class="amount"><?= format_currency($invoice['invoice_item_subtotal'] ?? 0) ?></td>
                            </tr>
                            <?php // Sconto di documento e cassa agiscono sull'imponibile, prima delle imposte. ?>
                            <?php if ((float) ($invoice['invoice_discount_total'] ?? 0) != 0): ?>
                                <tr>
                                    <td><?= trans('invoice_discount', '', 'Sconto sul documento') ?></td>
                                    <td class="amount">− <?= format_currency($invoice['invoice_discount_total']) ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if ((float) ($invoice['invoice_fund_amount'] ?? 0) != 0): ?>
                                <tr>
                                    <td><?= trans('fund_contribution', '', 'Contributo cassa') ?></td>
                                    <td class="amount"><?= format_currency($invoice['invoice_fund_amount']) ?></td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <td><?= trans('item_tax', '', 'Imposte righe') ?></td>
                                <td class="amount"><?= format_currency($invoice['invoice_item_tax_total'] ?? 0) ?></td>
                            </tr>
                            <tr>
                                <td><?= trans('invoice_tax', '', 'Imposta fattura') ?></td>
                                <td class="amount">
                                    <?php if (!empty($invoice_tax_rates)): ?>
                                        <?php foreach ($invoice_tax_rates as $invoice_tax_rate): ?>
                                            <?= esc($invoice_tax_rate['invoice_tax_rate_name'] . ' ' . $invoice_tax_rate['invoice_tax_rate_percent']) ?>%:
                                            <?= format_currency($invoice_tax_rate['invoice_tax_rate_amount']) ?><br>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <?= format_currency(0) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><b><?= trans('total', '', 'Totale') ?></b></td>
                                <td class="amount"><b><?= format_currency($invoice['invoice_total'] ?? 0) ?></b></td>
                            </tr>
                            <tr>
                                <td><?= trans('paid', '', 'Pagato') ?></td>
                                <td class="amount"><?= format_currency($invoice['invoice_paid'] ?? 0) ?></td>
                            </tr>
                            <tr>
                                <td><b><?= trans('balance', '', 'Saldo') ?></b></td>
                                <td class="amount"><b><?= format_currency($invoice['invoice_balance'] ?? 0) ?></b></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

        </div>

        <?php if (!empty($invoice['invoice_terms'])): ?>
            <div class="card">
                <div class="card-header"><?= trans('invoice_terms', '', 'Note fattura') ?></div>
                <div class="card-body">
                    <?= nl2br(esc($invoice['invoice_terms'])) ?>
                </div>
            </div>
        <?php endif; ?>

    </div>

<?php endif; ?>

<?= $this->endSection() ?>
