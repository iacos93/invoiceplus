<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
/**
 * Anteprima fattura: il documento come verrà scaricato o inviato, dentro un
 * iframe che carica lo stesso template del PDF. La modifica sta su una pagina
 * separata (/invoices/edit) — una fattura si prepara e si rilegge molte volte
 * prima di spedirla, quindi la vista principale mostra il risultato, non i
 * campi.
 */
$status_label_class = [
    'draft'   => 'text-bg-secondary',
    'sent'    => 'text-bg-info',
    'viewed'  => 'text-bg-warning',
    'paid'    => 'text-bg-success',
    'overdue' => 'text-bg-danger',
];

$invoice_status_class = $statuses[$invoice['invoice_status_id']]['class'] ?? 'draft';
$is_draft             = (int) $invoice['invoice_status_id'] === 1;
$is_credit            = !empty($invoice['creditinvoice_parent_id']);
$invoice_id           = (int) $invoice['invoice_id'];

$this->setVar('pageTitle', trans('invoice', '', 'Fattura') . ' ' . ($invoice['invoice_number'] ?: ('#' . $invoice_id)));

$is_draft_number = db_bool($invoice['invoice_number_is_draft'] ?? false);

$title = trans('invoice', '', 'Fattura') . ' ' . esc($invoice['invoice_number'] ?: ('#' . $invoice_id))
    . ' <span class="badge ip-status ' . ($status_label_class[$invoice_status_class] ?? 'text-bg-secondary') . '">'
    . esc($statuses[$invoice['invoice_status_id']]['label'] ?? trans('draft'))
    . '</span>';

// Il numero provvisorio somiglia a uno definitivo: senza un segno esplicito si
// rischia di citarlo al cliente come se fosse quello vero.
if ($is_draft_number) {
    $title .= ' <span class="badge text-bg-warning">'
        . esc(trans('provisional_number', '', 'Numero provvisorio'))
        . '</span>';
}

// Esito della trasmissione allo SdI, se presente (rc, ns, pending...).
if (!empty($sdi_transmission)) {
    $title .= ' <span class="badge ip-sdi-badge text-bg-' . \App\Libraries\Sdi\SdiSender::esitoClass($sdi_transmission['esito']) . '">'
        . esc(\App\Libraries\Sdi\SdiSender::esitoLabel($sdi_transmission['esito']))
        . '</span>';
}

$actions = '<div class="ip-doc-actions">'
    . '<a class="btn btn-sm btn-primary" href="' . site_url('invoices/edit/' . $invoice_id) . '">'
    . '<i class="fa fa-solid fa-pencil" aria-hidden="true"></i> ' . trans('edit', '', 'Modifica') . '</a>'
    . '<a class="btn btn-sm btn-secondary" href="' . site_url('invoices/generate_pdf/' . $invoice_id) . '">'
    . '<i class="fa fa-regular fa-file-pdf" aria-hidden="true"></i> ' . trans('download_pdf', '', 'PDF') . '</a>';

// Anche per le note di credito: escono come TD04 con importi positivi e
// riferimento alla fattura d'origine (Invoices::generateXml), quindi il
// tracciato e' valido e va mostrato e scaricato come per le fatture.
$actions .= '<a class="btn btn-sm btn-secondary" href="' . site_url('invoices/preview_xml/' . $invoice_id) . '">'
    . '<i class="fa fa-solid fa-eye" aria-hidden="true"></i> ' . trans('xml_preview', '', 'Anteprima XML') . '</a>'
    . '<a class="btn btn-sm btn-secondary" href="' . site_url('invoices/generate_xml/' . $invoice_id) . '">'
    . '<i class="fa fa-regular fa-file-code" aria-hidden="true"></i> ' . trans('it_download_xml', '', 'XML') . '</a>';

$actions .= '<a class="btn btn-sm btn-secondary" href="' . site_url('invoices/validate_xml/' . $invoice_id) . '">'
    . '<i class="fa fa-regular fa-check-square" aria-hidden="true"></i> ' . trans('validate_xml', '', 'Verifica XML') . '</a>';

// Sollecito manuale: solo su una fattura scaduta, e apre la schermata di
// invio gia' impostata come sollecito (Mailer::invoice). Non c'e' un invio
// automatico dei solleciti, per scelta.
if (\App\Controllers\Mailer::isOverdue($invoice)) {
    $actions .= '<a class="btn btn-sm btn-warning" href="' . site_url('mailer/invoice/' . $invoice_id) . '">'
        . '<i class="fa fa-solid fa-envelope" aria-hidden="true"></i> ' . trans('send_reminder', '', 'Sollecita') . '</a>';
}

$actions .= '<div class="dropdown">'
    . '<button type="button" class="btn btn-sm btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="' . esc(trans('options', '', 'Opzioni'), 'attr') . '">'
    . '<i class="fa fa-solid fa-cog" aria-hidden="true"></i></button><ul class="dropdown-menu dropdown-menu-end">';

$actions .= '<li><a href="' . site_url('delivery_notes/from/invoice/' . $invoice_id) . '">'
    . '<i class="fa fa-solid fa-truck fa-margin" aria-hidden="true"></i> ' . trans('create_delivery_note', '', 'Crea DDT') . '</a></li>';

$actions .= '<li><form action="' . site_url('invoices/send_sdi/' . $invoice_id) . '" method="POST">' . csrf_field()
    . '<button type="button" class="dropdown-button" data-bs-toggle="confirm"'
    . ' data-confirm-title="' . esc(trans('send_to_sdi', '', 'Invia a SdI'), 'attr') . '"'
    . ' data-confirm-body="' . esc(trans('send_to_sdi_confirm', '', 'Inviare la fattura allo SdI (Sistema di Interscambio) tramite il canale configurato?'), 'attr') . '"'
    . ' data-confirm-ok="' . esc(trans('send_to_sdi', '', 'Invia a SdI'), 'attr') . '">'
    . '<i class="fa fa-regular fa-paper-plane fa-margin" aria-hidden="true"></i> ' . trans('send_to_sdi', '', 'Invia a SdI') . '</button></form></li>';

$actions .= '<li><form action="' . site_url('invoices/ajax/create_credit') . '" method="POST">' . csrf_field()
    . '<input type="hidden" name="invoice_id" value="' . $invoice_id . '">'
    . '<button type="submit" class="dropdown-button"><i class="fa fa-solid fa-plus fa-margin" aria-hidden="true"></i> '
    . trans('create_credit_invoice', '', 'Nota di credito') . '</button></form></li>'
    . '<li><form action="' . site_url('invoices/ajax/copy_invoice') . '" method="POST">' . csrf_field()
    . '<input type="hidden" name="invoice_id" value="' . $invoice_id . '">'
    . '<button type="submit" class="dropdown-button"><i class="fa fa-solid fa-copy fa-margin" aria-hidden="true"></i> '
    . trans('copy_invoice', '', 'Copia') . '</button></form></li>';

if ($is_draft) {
    $actions .= '<li><form action="' . site_url('invoices/delete/' . $invoice_id) . '" method="POST">' . csrf_field()
        . '<button type="button" class="dropdown-button" data-bs-toggle="confirm"'
        . ' data-confirm-title="' . esc(trans('confirm', '', 'Conferma'), 'attr') . '"'
        . ' data-confirm-body="' . esc(trans('delete_invoice_warning', '', 'Eliminare la fattura?'), 'attr') . '"'
        . ' data-confirm-ok="' . esc(trans('delete', '', 'Elimina'), 'attr') . '" data-confirm-danger="1">'
        . '<i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> ' . trans('delete', '', 'Elimina') . '</button></form></li>';
}

$actions .= '</ul></div></div>';
?>

<?= $this->include('layout/headerbar', ['title' => $title, 'actions' => $actions]) ?>

<div id="content">

    <?php if ($is_draft_number): ?>
        <div class="alert alert-warning small">
            <i class="fa fa-regular fa-clock" aria-hidden="true"></i>&nbsp;
            <?= trans('provisional_number_notice', '', 'Questa fattura ha un numero provvisorio dalla serie bozze. Il numero definitivo viene assegnato quando la fattura esce dallo stato di bozza.') ?>
        </div>
    <?php endif; ?>

    <?php if ($is_credit): ?>
        <div class="alert alert-warning small">
            <i class="fa fa-solid fa-minus" aria-hidden="true"></i>&nbsp;
            <?= trans('credit_invoice_for_invoice', '', 'Nota di credito per la fattura') ?>
            <a href="<?= site_url('invoices/view/' . (int) $invoice['creditinvoice_parent_id']) ?>">
                #<?= (int) $invoice['creditinvoice_parent_id'] ?>
            </a>
        </div>
    <?php endif; ?>

    <div class="ip-preview">

        <div class="ip-preview__sheet">
            <iframe id="document_sheet" class="ip-preview__frame"
                    src="<?= site_url('invoices/preview_sheet/' . $invoice_id) ?>"
                    title="<?= esc(trans('invoice', '', 'Fattura'), 'attr') ?>"
                    loading="lazy"></iframe>
        </div>

        <aside class="ip-preview__side">

            <div class="card mb-3">
                <div class="card-header"><h2 class="ip-card-heading"><?= trans('summary', '', 'Riepilogo') ?></h2></div>
                <div class="card-body">
                    <dl class="ip-preview__totals">
                        <dt><?= trans('client', '', 'Cliente') ?></dt>
                        <dd>
                            <a href="<?= site_url('clients/view/' . (int) $invoice['client_id']) ?>">
                                <?= esc(format_client($invoice)) ?>
                            </a>
                        </dd>
                        <dt><?= trans('date', '', 'Data') ?></dt>
                        <dd><?= esc(date_from_mysql($invoice['invoice_date_created'])) ?></dd>
                        <dt><?= trans('due_date', '', 'Scadenza') ?></dt>
                        <dd><?= esc(date_from_mysql($invoice['invoice_date_due'])) ?></dd>
                    </dl>

                    <hr>

                    <dl class="ip-preview__totals">
                        <dt><?= trans('subtotal', '', 'Imponibile') ?></dt>
                        <dd><?= format_currency($invoice['invoice_item_subtotal'] ?? 0) ?></dd>
                        <?php // Sconto di documento e cassa agiscono sull'imponibile, prima dell'IVA. ?>
                        <?php if ((float) ($invoice['invoice_discount_total'] ?? 0) != 0): ?>
                            <dt><?= trans('invoice_discount', '', 'Sconto sul documento') ?></dt>
                            <dd>− <?= format_currency($invoice['invoice_discount_total']) ?></dd>
                        <?php endif; ?>
                        <?php if ((float) ($invoice['invoice_fund_amount'] ?? 0) != 0): ?>
                            <dt><?= trans('fund_contribution', '', 'Contributo cassa') ?> <?= esc($invoice['invoice_fund_type'] ?? '') ?></dt>
                            <dd><?= format_currency($invoice['invoice_fund_amount']) ?></dd>
                        <?php endif; ?>
                        <dt><?= trans('tax', '', 'IVA') ?></dt>
                        <dd><?= format_currency(($invoice['invoice_item_tax_total'] ?? 0) + ($invoice['invoice_tax_total'] ?? 0)) ?></dd>
                        <dt class="is-total"><?= trans('total', '', 'Totale') ?></dt>
                        <dd class="is-total"><?= format_currency($invoice['invoice_total'] ?? 0) ?></dd>
                        <?php if (strtoupper((string) ($invoice['invoice_vat_exigibility'] ?? '')) === 'S'): ?>
                            <?php // Split payment: il saldo parte dal netto, l'IVA la versa la PA all'Erario. ?>
                            <dt><?= trans('split_payment_vat', '', 'IVA in scissione (versata dalla PA)') ?></dt>
                            <dd>− <?= format_currency(($invoice['invoice_item_tax_total'] ?? 0) + ($invoice['invoice_tax_total'] ?? 0)) ?></dd>
                        <?php endif; ?>
                        <?php if ((float) ($invoice['invoice_withholding_amount'] ?? 0) != 0): ?>
                            <dt><?= trans('withholding_tax', '', 'Ritenuta d\'acconto') ?></dt>
                            <dd>− <?= format_currency($invoice['invoice_withholding_amount']) ?></dd>
                        <?php endif; ?>
                        <dt><?= trans('paid', '', 'Pagato') ?></dt>
                        <dd><?= format_currency($invoice['invoice_paid'] ?? 0) ?></dd>
                        <dt class="is-total"><?= trans('balance', '', 'Saldo') ?></dt>
                        <dd class="is-total"><?= format_currency($invoice['invoice_balance'] ?? 0) ?></dd>
                    </dl>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="ip-card-heading"><?= trans('payments', '', 'Pagamenti') ?></h2>
                    <a class="btn btn-sm btn-secondary float-end"
                       href="<?= site_url('payments/form?invoice_id=' . (int) $invoice_id) ?>">
                        <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('enter_payment', '', 'Aggiungi') ?>
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($payments)): ?>
                        <p class="ip-preview__empty"><?= trans('no_payments_recorded') ?></p>
                    <?php else: ?>
                        <ul class="ip-preview__payments">
                            <?php foreach ($payments as $payment): ?>
                                <li>
                                    <span class="ip-preview__payment-date"><?= esc(date_from_mysql($payment['payment_date'])) ?></span>
                                    <span class="ip-preview__payment-method"><?= esc($payment['payment_method_name'] ?? '') ?></span>
                                    <span class="ip-preview__payment-amount"><?= format_currency($payment['payment_amount']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($sdi_transmission)): ?>
                <div class="card mt-3">
                    <div class="card-header">
                        <h2 class="ip-card-heading"><?= trans('sdi_transmission', '', 'Sistema di Interscambio (SdI)') ?></h2>
                    </div>
                    <div class="card-body">
                        <dl class="ip-preview__totals">
                            <dt><?= trans('sdi_channel', '', 'Canale') ?></dt>
                            <dd><?= esc(\App\Libraries\Sdi\SdiSender::channelLabel($sdi_transmission['channel'])) ?></dd>
                            <dt><?= trans('sdi_file', '', 'File') ?></dt>
                            <dd class="font-monospace small"><?= esc($sdi_transmission['filename']) ?></dd>
                            <dt><?= trans('sdi_sent_at', '', 'Inviata il') ?></dt>
                            <dd><?= esc($sdi_transmission['sent_at'] ? datetime_from_mysql($sdi_transmission['sent_at']) : '—') ?></dd>
                            <dt><?= trans('sdi_esito', '', 'Esito') ?></dt>
                            <dd>
                                <span class="badge text-bg-<?= esc(\App\Libraries\Sdi\SdiSender::esitoClass($sdi_transmission['esito'])) ?>">
                                    <?= esc(\App\Libraries\Sdi\SdiSender::esitoLabel($sdi_transmission['esito'])) ?>
                                </span>
                            </dd>
                            <?php if (!empty($sdi_transmission['messaggio'])): ?>
                                <dt><?= trans('sdi_message', '', 'Nota') ?></dt>
                                <dd><small><?= esc($sdi_transmission['messaggio']) ?></small></dd>
                            <?php endif; ?>
                        </dl>

                        <?php if ($sdi_transmission['esito'] === null || $sdi_transmission['esito'] === 'pending'): ?>
                            <hr>
                            <form action="<?= site_url('invoices/set_sdi_esito/' . $invoice_id) ?>"
                                  method="POST" class="ip-sdi-esito-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="transmission_id" value="<?= (int) $sdi_transmission['sdi_transmission_id'] ?>">
                                <div class="ip-doc-field">
                                    <label for="sdi_esito" class="ip-doc-label">
                                        <?= trans('sdi_esito_manual', '', 'Esito ricevuto dallo SdI (assente il polling automatico)') ?>
                                    </label>
                                    <select name="esito" id="sdi_esito" class="form-control form-control-sm simple-select">
                                        <?php foreach (\App\Libraries\Sdi\SdiSender::ESITI as $code => $label): ?>
                                            <option value="<?= esc($code) ?>"><?= esc($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ip-doc-field">
                                    <input type="text" name="sdi_note" class="form-control form-control-sm"
                                           placeholder="<?= esc(trans('sdi_message', '', 'Nota'), 'attr') ?>" maxlength="255">
                                </div>
                                <button type="submit" class="btn btn-sm btn-secondary w-100">
                                    <?= trans('sdi_save_esito', '', 'Salva esito') ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        </aside>
    </div>
</div>

<?php /* Adattamento del foglio al riquadro: una copia sola per le tre
         anteprime (fattura, preventivo, DDT), in layout/preview_sheet. */ ?>
<script><?= $this->include('layout/preview_sheet') ?></script>

<?= $this->endSection() ?>
