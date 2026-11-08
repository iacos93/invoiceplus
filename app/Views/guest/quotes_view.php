<?php
/* Anche la pagina pubblica passa dal layout guest. Prima, con public_view
   valorizzato, la vista non estendeva niente: al cliente arrivava un
   frammento di HTML senza <head>, senza CSS e senza viewport, cioe' testo
   nudo invece del documento. Il layout nasconde da solo navigazione e
   logout quando public_view e' impostato (vedi layout/guest.php). */
?>
<?= $this->extend('layout/guest') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('quote', '', 'Preventivo') . ' #' . ($quote['quote_number'] ?: $quote['quote_id'])); ?>

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
$statusInfo        = $quote_statuses[(string) $quote['quote_status_id']] ?? ['label' => '', 'class' => 'draft'];
$status_class_label = $status_label_class[$statusInfo['class']] ?? 'text-bg-secondary';
?>

<?php if (!empty($quote_password_required)): ?>

    <?= $this->include('layout/headerbar', ['title' => trans('quote', '', 'Preventivo')]) ?>

    <div id="content">
        <div class="card">
            <div class="card-header"><?= trans('quote_password_required', '', 'Questo preventivo e protetto da password.') ?></div>
            <div class="card-body">
                <p><?= trans('quote_password_required', '', 'Questo preventivo e protetto da password.') ?></p>
                <form method="POST" action="<?= site_url('guest/view/quote/' . $quote_url_key) ?>">
                    <?= csrf_field() ?>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="quote_password"><?= trans('password', '', 'Password') ?></label>
                        <input type="password" name="quote_password" id="quote_password"
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
$title = trans('quote', '', 'Preventivo')
    . ' #' . esc($quote['quote_number'] ?: $quote['quote_id'])
    . ' <span class="badge ip-status ' . $status_class_label . '">' . esc($statusInfo['label']) . '</span>';
$actions = '<div class="options btn-group btn-group-sm">';
if (in_array((int) $quote['quote_status_id'], [2, 3], true)):
    /*
     * Approvare o rifiutare cambia lo stato del documento: sono form POST con
     * token CSRF, non link. Prima erano GET, quindi il solo fatto che il
     * browser o un prefetch seguisse l'URL approvava il preventivo, e chi
     * metteva quel link in un <img src> su una pagina di terzi otteneva lo
     * stesso effetto su un cliente che la visitava.
     *
     * La conferma passa dalla modale condivisa (layout/confirm_modal, gia'
     * caricata dal layout guest): un preventivo approvato non torna indietro
     * da questa pagina, meglio un clic in piu' che uno per sbaglio.
     */
    // L'area guest passa quote_id, quella pubblica quote_url_key: ognuna
    // delle due ne fornisce uno solo, quindi si leggono entrambi con un
    // fallback dal record invece di dare per scontato quello dell'altra.
    $actionQuoteId  = (int) ($quote_id ?? $quote['quote_id'] ?? 0);
    $actionQuoteKey = (string) ($quote_url_key ?? '');

    $quoteAction = static function (string $action, string $label, string $icon, string $btn) use ($actionQuoteId, $actionQuoteKey, $public_view): string {
        $url = !empty($public_view)
            ? site_url('guest/view/' . $action . '_quote/' . $actionQuoteKey)
            : site_url('guest/quotes/' . $action . '/' . $actionQuoteId);
        $confirmBody = $action === 'approve'
            ? trans('confirm_approve_quote', '', 'Approvare questo preventivo?')
            : trans('confirm_reject_quote', '', 'Rifiutare questo preventivo?');

        return '<form method="post" action="' . $url . '" class="d-inline">'
            . csrf_field()
            . '<button type="submit" class="btn ' . $btn . '"'
            . ' data-bs-toggle="confirm"'
            . ' data-confirm-title="' . esc(trans('confirm', '', 'Conferma'), 'attr') . '"'
            . ' data-confirm-body="' . esc($confirmBody, 'attr') . '"'
            . ' data-confirm-ok="' . esc($label, 'attr') . '"'
            . ($action === 'reject' ? ' data-confirm-danger="1"' : '') . '>'
            . '<i class="fa fa-solid ' . $icon . '" aria-hidden="true"></i> ' . esc($label)
            . '</button></form>';
    };

    $actions .= $quoteAction('approve', trans('approve_this_quote', '', 'Approva'), 'fa-check', 'btn-success')
        . $quoteAction('reject', trans('reject_this_quote', '', 'Rifiuta'), 'fa-times', 'btn-danger');
elseif ((int) $quote['quote_status_id'] === 4):
    $actions .= '<span class="badge text-bg-success"><i class="fa fa-solid fa-check" aria-hidden="true"></i> ' . trans('quote_approved', '', 'Approvato') . '</span>';
elseif ((int) $quote['quote_status_id'] === 5):
    $actions .= '<span class="badge text-bg-danger"><i class="fa fa-solid fa-times" aria-hidden="true"></i> ' . trans('quote_rejected', '', 'Rifiutato') . '</span>';
endif;
if (!empty($public_view)):
    $actions .= '<a class="btn btn-success" href="' . site_url('guest/view/generate_quote_pdf/' . $quote_url_key) . '"><i class="fa fa-regular fa-file-pdf" aria-hidden="true"></i> ' . trans('download_pdf', '', 'PDF') . '</a>';
else:
    $actions .= '<a class="btn btn-success" href="' . site_url('guest/quotes/generate_pdf/' . $quote_id) . '"><i class="fa fa-regular fa-file-pdf" aria-hidden="true"></i> ' . trans('download_pdf', '', 'PDF') . '</a>';
endif;
$actions .= '</div>';
?>
<?= $this->include('layout/headerbar', ['title' => $title, 'actions' => $actions]) ?>

<div id="content">

    <?php if (!empty($flash_message)): ?>
        <div class="alert alert-info">
            <?= esc($flash_message) ?>
        </div>
    <?php endif; ?>

    <div class="row">

        <div class="col-12 col-md-6">
            <div class="card">
                <div class="card-header"><?= trans('quote_to', '', 'Preventivo a') ?></div>
                <div class="card-body">
                    <h4><?= esc(format_client($quote)) ?></h4>
                    <div class="client-address">
                        <?php if (!empty($quote['client_address_1'])): ?><?= esc($quote['client_address_1']) ?><br><?php endif; ?>
                        <?php if (!empty($quote['client_address_2'])): ?><?= esc($quote['client_address_2']) ?><br><?php endif; ?>
                        <?php if (!empty($quote['client_city']) || !empty($quote['client_state']) || !empty($quote['client_zip'])): ?>
                            <?= esc(trim(($quote['client_city'] ?? '') . ' ' . ($quote['client_state'] ?? '') . ' ' . ($quote['client_zip'] ?? ''))) ?><br>
                        <?php endif; ?>
                        <?php if (!empty($quote['client_phone'])): ?><?= trans('phone', '', 'Telefono') ?>: <?= esc($quote['client_phone']) ?><br><?php endif; ?>
                        <?php if (!empty($quote['client_email'])): ?><?= trans('email', '', 'Email') ?>: <?= esc($quote['client_email']) ?><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-md-6">
            <div class="card">
                <div class="card-header"><?= trans('quote', '', 'Preventivo') ?></div>
                <div class="card-body">
                    <table class="table table-sm">
                        <tr>
                            <td><strong><?= trans('quote', '', 'Preventivo') ?> #</strong></td>
                            <td><?= esc($quote['quote_number'] ?: $quote['quote_id']) ?></td>
                        </tr>
                        <tr>
                            <td><strong><?= trans('date', '', 'Data') ?></strong></td>
                            <td><?= date_from_mysql($quote['quote_date_created']) ?></td>
                        </tr>
                        <tr>
                            <td><strong><?= trans('expires', '', 'Scade') ?></strong></td>
                            <td><?= date_from_mysql($quote['quote_date_expires']) ?></td>
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
                <div class="card-header"><?= trans('quote_totals', '', 'Totali') ?></div>
                <div class="card-body">
                    <table class="table table-bordered text-end">
                        <tr>
                            <td style="width: 60%;"><?= trans('subtotal', '', 'Imponibile') ?></td>
                            <td class="amount"><?= format_currency($quote['quote_item_subtotal'] ?? 0) ?></td>
                        </tr>
                        <tr>
                            <td><?= trans('item_tax', '', 'Imposte righe') ?></td>
                            <td class="amount"><?= format_currency($quote['quote_item_tax_total'] ?? 0) ?></td>
                        </tr>
                        <tr>
                            <td><?= trans('quote_tax', '', 'Imposta preventivo') ?></td>
                            <td class="amount">
                                <?php if (!empty($quote_tax_rates)): ?>
                                    <?php foreach ($quote_tax_rates as $quote_tax_rate): ?>
                                        <?= esc($quote_tax_rate['quote_tax_rate_name'] . ' ' . $quote_tax_rate['quote_tax_rate_percent']) ?>%:
                                        <?= format_currency($quote_tax_rate['quote_tax_rate_amount']) ?><br>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?= format_currency(0) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><?= trans('discount', '', 'Sconto') ?></td>
                            <td class="amount">
                                <?php if ((float) ($quote['quote_discount_percent'] ?? 0) == 0): ?>
                                    <?= esc($quote['quote_discount_amount'] ?? 0) ?>
                                <?php else: ?>
                                    <?= esc($quote['quote_discount_percent']) ?>%
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td><b><?= trans('total', '', 'Totale') ?></b></td>
                            <td class="amount"><b><?= format_currency($quote['quote_total'] ?? 0) ?></b></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <?php if (!empty($quote['notes'])): ?>
        <div class="card">
            <div class="card-header"><?= trans('notes', '', 'Note') ?></div>
            <div class="card-body">
                <?= nl2br(esc($quote['notes'])) ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php endif; ?>

<?= $this->endSection() ?>
