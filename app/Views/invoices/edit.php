<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('invoice', '', 'Fattura') . ' ' . ($invoice['invoice_number'] ?: ('#' . $invoice['invoice_id']))); ?>

<?php
// Mappa classi Bootstrap 3 per i badge di stato (stile CI3)
$status_label_class = [
    'draft' => 'text-bg-secondary',
    'sent' => 'text-bg-info',
    'viewed' => 'text-bg-warning',
    'paid' => 'text-bg-success',
    'overdue' => 'text-bg-danger',
];
$invoice_status_class = $invoice_statuses[$invoice['invoice_status_id']]['class'] ?? 'draft';
$is_draft             = (int) $invoice['invoice_status_id'] === 1;
// Il legame con la fattura d'origine e' la fonte certa; invoice_sign c'e'
// solo sulle note create dopo che createCredit() ha cominciato a scriverlo.
$is_credit            = !empty($invoice['creditinvoice_parent_id']) || (int) ($invoice['invoice_sign'] ?? 0) === -1;
?>

<?php
$title = trans('invoice', '', 'Fattura') . ' ' . esc($invoice['invoice_number'] ?: ('#' . $invoice['invoice_id']))
    . ' <span class="badge ip-status ' . ($status_label_class[$invoice_status_class] ?? 'text-bg-secondary') . '">'
    . esc($invoice_statuses[$invoice['invoice_status_id']]['label'] ?? trans('draft'))
    . '</span>';
$actions = '<div class="ip-doc-actions">'
    . '<div class="headerbar-total">'
    . '<span class="headerbar-total__label">' . trans('total', '', 'Totale') . '</span>'
    . '<span class="headerbar-total__amount">' . format_currency($invoice['invoice_total'] ?? 0) . '</span>'
    . '</div>'
    // Azione primaria sempre visibile: l'anteprima del documento.
    . '<a class="btn btn-sm btn-primary" href="' . site_url('invoices/view/' . (int) $invoice['invoice_id']) . '"><i class="fa fa-solid fa-eye" aria-hidden="true"></i> ' . trans('view', '', 'Anteprima') . '</a>';

// Azioni secondarie raggruppate nel menu "Altro": cosi' la barra resta
// leggibile anche quando la fattura abilita tutti gli allegati
// Menu "Altro": PDF / XML FatturaPA, invece di affollare l'headerbar.
$more = '';
$more .= '<li><a class="dropdown-item" href="' . site_url('invoices/generate_pdf/' . (int) $invoice['invoice_id']) . '"><i class="fa fa-regular fa-file-pdf" aria-hidden="true"></i> ' . trans('download_pdf', '', 'PDF') . '</a></li>';
// Anche per le note di credito: escono come TD04 con importi positivi e
// riferimento alla fattura d'origine, quindi l'XML e' valido e va inviato
// allo SdI come qualunque altro documento.
$more .= '<li><a class="dropdown-item" href="' . site_url('invoices/generate_xml/' . (int) $invoice['invoice_id']) . '"><i class="fa fa-regular fa-file-code" aria-hidden="true"></i> ' . trans('it_download_xml', '', 'XML FatturaPA') . '</a></li>';
$more .= '<li><form action="' . site_url('invoices/ajax/create_credit') . '" method="POST">' . csrf_field() . '<input type="hidden" name="invoice_id" value="' . (int) $invoice['invoice_id'] . '"><button type="submit" class="dropdown-item dropdown-button"><i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('create_credit_invoice', '', 'Nota di credito') . '</button></form></li>';
$more .= '<li><form action="' . site_url('invoices/ajax/copy_invoice') . '" method="POST">' . csrf_field() . '<input type="hidden" name="invoice_id" value="' . (int) $invoice['invoice_id'] . '"><button type="submit" class="dropdown-item dropdown-button"><i class="fa fa-solid fa-copy" aria-hidden="true"></i> ' . trans('copy_invoice', '', 'Copia') . '</button></form></li>';
if ($is_draft):
    $more .= '<li><hr class="dropdown-divider"></li>';
    $more .= '<li><form action="' . site_url('invoices/delete/' . (int) $invoice['invoice_id']) . '" method="POST">' . csrf_field() . '<button type="button" class="dropdown-item dropdown-button text-danger" data-bs-toggle="confirm" data-confirm-title="' . esc(trans('confirm', '', 'Conferma')) . '" data-confirm-body="' . esc(trans('delete_invoice_warning', '', 'Eliminare la fattura?')) . '" data-confirm-ok="' . esc(trans('delete', '', 'Elimina')) . '" data-confirm-danger="1"><i class="fa fa-solid fa-trash-can" aria-hidden="true"></i> ' . trans('delete', '', 'Elimina') . '</button></form></li>';
endif;

$actions .= '<div class="dropdown">'
    . '<button type="button" class="btn btn-sm btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="' . esc(trans('more_actions', '', 'Altre azioni')) . '">'
    . '<i class="fa fa-solid fa-ellipsis-h" aria-hidden="true"></i> <span class="d-none d-md-inline">' . esc(trans('more_actions', '', 'Altro')) . '</span>'
    . '</button>'
    . '<ul class="dropdown-menu dropdown-menu-end">' . $more . '</ul>'
    . '</div>';

// Salva sempre visibile: e' l'azione di chiusura del documento.
$actions .= '<button type="submit" form="invoice_details_form" class="btn btn-sm btn-success"><i class="fa fa-solid fa-check" aria-hidden="true"></i> ' . trans('save', '', 'Salva') . '</button>';
$actions .= '</div>';
?>
<?= $this->include('layout/headerbar', ['title' => $title, 'actions' => $actions]) ?>

<?php
/**
 * Modifica fattura: stesse sezioni numerate, stessa griglia campi, stessa
 * tabella righe e stessa barra fissa del form "Nuova fattura". Prima le due
 * schermate mostravano lo stesso documento in due linguaggi diversi — card
 * affiancate e input con etichetta incorporata qui, sezioni e tabella pulita
 * là — e passando da una all'altra sembrava di cambiare programma.
 *
 * Le meccaniche restano quelle esistenti: form separati per dati generali,
 * righe, imposte e note, ognuno con il proprio salvataggio server-side.
 */
?>

<div id="content" class="ip-doc-form">

    <?php if ($is_credit): ?>
        <div class="alert alert-warning small">
            <i class="fa fa-solid fa-minus" aria-hidden="true"></i>&nbsp;
            <?= trans('credit_invoice_for_invoice', '', 'Nota di credito per la fattura') ?>
            <a href="<?= site_url('invoices/view/' . (int) $invoice['creditinvoice_parent_id']) ?>">
                #<?= (int) $invoice['creditinvoice_parent_id'] ?>
            </a>
        </div>
    <?php endif; ?>

    <!-- ① Cliente -->
    <?= $this->include('layout/document_client_block', [
        'doc_id'               => $invoice['invoice_id'],
        'doc_id_field'         => 'invoice_id',
        'entity'               => $invoice,
        'clients'              => $clients,
        'change_client_action' => 'invoices/ajax/change_client',
        'preview_title'        => trans('invoice_totals', '', 'Totali'),
        'totals'               => [
            ['label' => trans('subtotal', '', 'Imponibile'), 'value' => format_currency($invoice['invoice_item_subtotal'] ?? 0)],
            // Sconto di documento e contributo cassa stanno fra imponibile e
            // IVA perché è lì che agiscono (InvoiceMath::documentSummary):
            // senza queste righe "Imponibile + IVA" non dava il totale.
            ...((float) ($invoice['invoice_discount_total'] ?? 0) != 0 ? [
                ['label' => trans('invoice_discount', '', 'Sconto sul documento'), 'value' => '− ' . format_currency($invoice['invoice_discount_total'])],
            ] : []),
            ...((float) ($invoice['invoice_fund_amount'] ?? 0) != 0 ? [
                ['label' => trans('fund_contribution', '', 'Contributo cassa') . ' ' . esc($invoice['invoice_fund_type'] ?? ''), 'value' => format_currency($invoice['invoice_fund_amount'])],
            ] : []),
            ['label' => trans('tax', '', 'IVA'), 'value' => format_currency(($invoice['invoice_item_tax_total'] ?? 0) + ($invoice['invoice_tax_total'] ?? 0))],
            ['label' => trans('total', '', 'Totale'), 'value' => format_currency($invoice['invoice_total'] ?? 0), 'is_total' => true],
            // La ritenuta la trattiene e la versa il cliente: senza questa
            // riga il saldo più basso del totale sembrava un errore.
            ...((float) ($invoice['invoice_withholding_amount'] ?? 0) != 0 ? [
                ['label' => trans('withholding_tax', '', 'Ritenuta d\'acconto'), 'value' => '− ' . format_currency($invoice['invoice_withholding_amount'])],
            ] : []),
            // Con lo split payment il saldo parte dal netto, non dal totale:
            // senza questa riga "Totale 1.279,78 / Saldo 1.049,00" sembra un
            // errore di calcolo invece dell'IVA che la PA versa all'Erario.
            ...(strtoupper((string) ($invoice['invoice_vat_exigibility'] ?? '')) === 'S' ? [
                ['label' => trans('split_payment_vat', '', 'IVA in scissione (versata dalla PA)'), 'value' => '− ' . format_currency(($invoice['invoice_item_tax_total'] ?? 0) + ($invoice['invoice_tax_total'] ?? 0))],
            ] : []),
            ['label' => trans('paid', '', 'Pagato'), 'value' => format_currency($invoice['invoice_paid'] ?? 0)],
            ['label' => trans('balance', '', 'Saldo'), 'value' => format_currency($invoice['invoice_balance'] ?? 0), 'is_total' => true],
        ],
    ]) ?>

    <!-- ② Dati documento -->
    <form method="POST" action="<?= site_url('invoices/ajax/save') ?>" id="invoice_details_form">
        <?= csrf_field() ?>
        <input type="hidden" name="invoice_id" value="<?= (int) $invoice['invoice_id'] ?>">

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">2</span>
                <h2 class="ip-doc-section__title"><?= trans('document_data', '', 'Dati documento') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <?php
                // Ordine dei campi: prima cosa identifica il documento (numero,
                // date, stato), poi come viene incassato, infine come viene
                // condiviso. Erano in fila senza raggruppamento, e il conto di
                // saldo finiva fra la password PDF e il metodo di pagamento.
                ?>
                <div class="ip-doc-grid">

                    <div class="ip-doc-subgroup"><?= trans('document_data', '', 'Identificazione') ?></div>

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="invoice_number"><?= trans('invoice', '', 'Fattura') ?> #</label>
                        <input type="text" name="invoice_number" id="invoice_number" class="form-control"
                               value="<?= esc($invoice['invoice_number']) ?>"
                               placeholder="<?= esc(trans('not_set', '', 'Non impostato'), 'attr') ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="invoice_status_id"><?= trans('status', '', 'Stato') ?></label>
                        <select name="invoice_status_id" id="invoice_status_id" class="form-control simple-select">
                            <?php foreach ($invoice_statuses as $key => $status): ?>
                                <option value="<?= (int) $key ?>"
                                    <?= (int) $invoice['invoice_status_id'] === (int) $key ? 'selected' : '' ?>>
                                    <?= esc($status['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ip-doc-field"><!-- riempie la terza colonna --></div>

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="invoice_date_created"><?= trans('date', '', 'Data') ?></label>
                        <input type="text" name="invoice_date_created" id="invoice_date_created"
                               class="form-control datepicker"
                               value="<?= esc(date_from_mysql($invoice['invoice_date_created'])) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="invoice_date_due"><?= trans('due_date', '', 'Scadenza') ?></label>
                        <input type="text" name="invoice_date_due" id="invoice_date_due"
                               class="form-control datepicker"
                               value="<?= esc(date_from_mysql($invoice['invoice_date_due'])) ?>">
                    </div>

                    <div class="ip-doc-subgroup">
                        <?= trans('payment', '', 'Incasso') ?>
                        <span class="ip-doc-subgroup__hint">
                            <?= trans('payment_hint', '', 'Modalità di incasso e, se serve, su quale conto') ?>
                        </span>
                    </div>

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="payment_method"><?= trans('payment_method', '', 'Metodo di pagamento') ?></label>
                        <select name="payment_method" id="payment_method" class="form-control simple-select">
                            <option value="0"><?= trans('select_payment_method', '', 'Seleziona metodo') ?></option>
                            <?php foreach ($payment_methods as $payment_method): ?>
                                <option value="<?= (int) $payment_method['payment_method_id'] ?>"
                                    data-requires-bank-account="<?= db_bool($payment_method['payment_method_requires_bank_account']) ? 1 : 0 ?>"
                                    <?= (int) $invoice['payment_method'] === (int) $payment_method['payment_method_id'] ? 'selected' : '' ?>>
                                    <?= esc($payment_method['payment_method_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="ip-field-help"><?= trans('payment_method_field_hint', '', 'Come verrà pagata la fattura (bonifico, contanti, RID…)') ?></p>
                    </div>
                    <?php if (!empty($bank_accounts)): ?>
                        <div class="ip-doc-field" id="bank_account_id_wrap">
                            <label class="ip-doc-label" for="bank_account_id"><?= trans('bank_account', '', 'Conto di saldo') ?></label>
                            <select name="bank_account_id" id="bank_account_id" class="form-control simple-select">
                                <option value=""><?= trans('default_bank_account', '', 'Conto predefinito') ?></option>
                                <?php foreach ($bank_accounts as $account): ?>
                                    <option value="<?= (int) $account['bank_account_id'] ?>"
                                        <?= (int) ($invoice['bank_account_id'] ?? 0) === (int) $account['bank_account_id'] ? 'selected' : '' ?>>
                                        <?= esc($account['bank_account_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="ip-field-help"><?= trans('bank_account_field_hint', '', 'Su quale IBAN aziendale verrà accreditato il pagamento — resta nascosto se il metodo scelto non lo richiede (es. contanti)') ?></p>
                        </div>
                    <?php endif; ?>

                    <div class="ip-doc-subgroup">
                        <?= trans('invoice_discount', '', 'Sconto sul documento') ?>
                        <span class="ip-doc-subgroup__hint">
                            <?= trans('invoice_discount_hint', '', 'Riduce l\'imponibile prima dell\'IVA, ripartito fra le aliquote; prima l\'importo, poi la percentuale sul resto') ?>
                        </span>
                    </div>

                    <?php
                    // Lo sconto di documento non aveva un campo: arrivava solo dallo
                    // sconto predefinito del cliente alla creazione, e da qui non si
                    // poteva né vedere né togliere. Sta nel form testata, quindi lo
                    // salva qualunque Salva della pagina (InvoicesAjax ricalcola).
                    ?>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="invoice_discount_amount"><?= trans('discount_amount', '', 'Importo (€)') ?></label>
                        <input type="text" inputmode="decimal" name="invoice_discount_amount" id="invoice_discount_amount" class="form-control amount"
                               value="<?= (float) ($invoice['invoice_discount_amount'] ?? 0) != 0 ? esc(format_amount($invoice['invoice_discount_amount'])) : '' ?>"
                               placeholder="0">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="invoice_discount_percent"><?= trans('discount_percent', '', 'Percentuale (%)') ?></label>
                        <input type="text" inputmode="decimal" name="invoice_discount_percent" id="invoice_discount_percent" class="form-control amount"
                               value="<?= (float) ($invoice['invoice_discount_percent'] ?? 0) != 0 ? esc(format_amount($invoice['invoice_discount_percent'])) : '' ?>"
                               placeholder="0">
                    </div>

                    <div class="ip-doc-subgroup">
                        <?= trans('sharing', '', 'Condivisione') ?>
                        <span class="ip-doc-subgroup__hint">
                            <?= trans('sharing_hint', '', 'Come il cliente accede al documento') ?>
                        </span>
                    </div>

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="invoice_password"><?= trans('invoice_password', '', 'Password PDF') ?></label>
                        <input type="text" name="invoice_password" id="invoice_password" class="form-control"
                               value="<?= esc(decrypt_secret((string) ($invoice['invoice_password'] ?? ''))) ?>" autocomplete="off">
                    </div>
                    <?php if (!$is_draft): ?>
                        <div class="ip-doc-field">
                            <label class="ip-doc-label" for="invoice-guest-url"><?= trans('guest_url', '', 'URL ospite') ?></label>
                            <input type="text" id="invoice-guest-url" readonly class="form-control"
                                   value="<?= esc(site_url('guest/view/invoice/' . $invoice['invoice_url_key'])) ?>">
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

    <?php
    // Il form "Dati documento" prosegue fino in fondo alla sezione ③: prima
    // la fattura elettronica aveva un <form> suo con un Salva suo, e il
    // Salva in testata (che invia solo invoice_details_form) ignorava in
    // silenzio tipo documento, esigibilita', CIG e il resto modificati li'.
    // Un form solo: qualunque Salva della pagina salva tutto.
    ?>
    <!-- ③ Fattura elettronica -->
    <section class="ip-doc-section">
        <div class="ip-doc-section__head">
            <span class="ip-doc-step">3</span>
            <h2 class="ip-doc-section__title"><?= trans('electronic_invoice', '', 'Fattura elettronica') ?></h2>
            <span class="ip-doc-section__hint">
                <?= trans('einvoice_hint', '', 'CIG e CUP sono obbligatori verso la Pubblica Amministrazione') ?>
            </span>
        </div>
        <div class="ip-doc-section__body">
                    <?php
                    // Un solo form per tutti i blocchi del tracciato: sono dati che
                    // si compilano insieme, e spezzarli costringerebbe a salvare
                    // quattro volte per una fattura verso la PA. La logica di
                    // visualizzazione (solo i campi essenziali in vista, il resto
                    // in gruppi collassabili) è condivisa con il form di creazione
                    // in invoices/partial_einvoice_fields.php.
                    $get = static fn(string $name) => $invoice[$name] ?? '';
                    echo view('invoices/partial_einvoice_fields', ['get' => $get]);
                    ?>

                    <div class="ip-doc-newrow">
                    <button type="submit" class="btn btn-sm btn-secondary">
                        <i class="fa fa-solid fa-check" aria-hidden="true"></i> <?= trans('save', '', 'Salva') ?>
                    </button>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= site_url('invoices/validate_xml/' . (int) $invoice['invoice_id']) ?>">
                        <i class="fa fa-solid fa-check-circle" aria-hidden="true"></i> <?= trans('validate_xml', '', 'Verifica XML') ?>
                    </a>
                </div>
        </div>
    </section>
    </form>

    <!-- ④ Righe -->
    <section class="ip-doc-section">
        <div class="ip-doc-section__head">
            <span class="ip-doc-step">4</span>
            <h2 class="ip-doc-section__title"><?= trans('invoice_items', '', 'Righe') ?></h2>
            <span class="ip-doc-section__hint"><?= count($items) ?> <?= trans('items', '', 'righe') ?></span>
        </div>

        <?= $this->include('layout/document_item_table', [
            'items'         => $items,
            'tax_rates'     => $tax_rates,
            'unit_groups'   => $unit_groups,
            'doc_form_id'   => 'invoice_details_form',
            'doc_has_tasks' => true,
            'doc_costing_accounts' => $costing_accounts ?? [],
        ]) ?>

    </section>

    <!-- ⑤ Imposte e pagamenti -->
    <section class="ip-doc-section">
        <div class="ip-doc-section__head">
            <span class="ip-doc-step">5</span>
            <h2 class="ip-doc-section__title"><?= trans('invoice_tax_rates', '', 'Imposte e pagamenti') ?></h2>
        </div>
        <div class="ip-doc-section__body">
            <div class="ip-doc-client">

                <div>
                    <p class="ip-doc-label"><?= trans('invoice_tax_rates', '', 'Imposte fattura') ?></p>
                    <div class="ip-doc-items">
                    <table class="ip-items-table">
                        <thead>
                        <tr>
                            <th scope="col"><?= trans('tax_rate', '', 'Aliquota') ?></th>
                            <th scope="col" class="ip-items-table__total"><?= trans('amount', '', 'Importo') ?></th>
                            <th scope="col" class="ip-items-table__actions"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($invoice_tax_rates as $invoice_tax_rate): ?>
                            <tr>
                                <td>
                                    <?= esc($invoice_tax_rate['invoice_tax_rate_name']) ?>
                                    (<?= format_amount($invoice_tax_rate['invoice_tax_rate_percent']) ?>%)
                                </td>
                                <td class="ip-items-table__total"><?= format_currency($invoice_tax_rate['invoice_tax_rate_amount']) ?></td>
                                <td class="ip-items-table__actions">
                                    <form method="POST" action="<?= site_url('invoices/delete_invoice_tax/' . (int) $invoice['invoice_id'] . '/' . (int) $invoice_tax_rate['invoice_tax_rate_id']) ?>">
                                        <?= csrf_field() ?>
                                        <button type="button" class="ip-item-row__remove" data-bs-toggle="confirm"
                                                data-confirm-title="<?= esc(trans('confirm', '', 'Conferma'), 'attr') ?>"
                                                data-confirm-body="<?= esc(trans('delete_tax_warning', '', 'Eliminare l\'imposta?'), 'attr') ?>"
                                                data-confirm-ok="<?= esc(trans('delete', '', 'Elimina'), 'attr') ?>" data-confirm-danger="1">
                                            <i class="fa fa-solid fa-trash-can" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($invoice_tax_rates)): ?>
                            <tr><td colspan="3" class="ip-items-table__empty"><?= trans('no_taxes') ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    </div>

                    <form method="POST" action="<?= site_url('invoices/ajax/save_invoice_tax_rate') ?>" class="ip-doc-newrow">
                        <?= csrf_field() ?>
                        <input type="hidden" name="invoice_id" value="<?= (int) $invoice['invoice_id'] ?>">
                        <select name="tax_rate_id" class="form-control input-sm simple-select"
                                            aria-label="<?= esc(trans('tax_rate', '', 'Aliquota')) ?>">
                            <?php foreach ($tax_rates as $tax_rate): ?>
                                <option value="<?= (int) $tax_rate['tax_rate_id'] ?>">
                                    <?= format_amount($tax_rate['tax_rate_percent']) ?>% - <?= esc($tax_rate['tax_rate_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label class="form-check form-check-inline ip-doc-newrow__check">
                            <input type="checkbox" name="include_item_tax" value="1" checked>
                            <?= trans('include_item_tax', '', 'Includi imposte righe') ?>
                        </label>
                        <button type="submit" class="btn btn-sm btn-secondary">
                            <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('add_tax_rate', '', 'Aggiungi') ?>
                        </button>
                    </form>
                </div>

                <div>
                    <p class="ip-doc-label"><?= trans('payments', '', 'Pagamenti') ?></p>
                    <div class="ip-doc-items">
                    <table class="ip-items-table">
                        <thead>
                        <tr>
                            <th scope="col"><?= trans('date', '', 'Data') ?></th>
                            <th scope="col"><?= trans('payment_method', '', 'Metodo') ?></th>
                            <th scope="col" class="ip-items-table__total"><?= trans('amount', '', 'Importo') ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?= esc(date_from_mysql($payment['payment_date'])) ?></td>
                                <td><?= esc($payment['payment_method_name'] ?? '') ?></td>
                                <td class="ip-items-table__total"><?= format_currency($payment['payment_amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($payments)): ?>
                            <tr><td colspan="3" class="ip-items-table__empty"><?= trans('no_payments') ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    </div>

                    <div class="ip-doc-newrow">
                        <a class="btn btn-sm btn-secondary" href="<?= site_url('payments/form?invoice_id=' . (int) $invoice['invoice_id']) ?>">
                            <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('enter_payment', '', 'Inserisci pagamento') ?>
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </section>

    <!-- ⑥ Note -->
    <?= $this->include('layout/document_notes_block', [
        'doc_id'       => $invoice['invoice_id'],
        'doc_id_field' => 'invoice_id',
        'note_action'  => 'invoices/ajax/save',
        'note_name'    => 'invoice_terms',
        'note_label'   => trans('invoice_terms', '', 'Condizioni fattura'),
        'note_value'   => $invoice['invoice_terms'],
        'client_id'    => $invoice['client_id'],
        'doc_form_id'  => 'invoice_details_form',
        'step_number'  => 6,
    ]) ?>

</div>

<script>
(function () {
    // Il codice buono serve solo alle fatture Carta del Docente: il campo
    // segue il flag, così non si compila per errore una fattura ordinaria.
    var flag   = document.getElementById('invoice_carta_docente');
    var field  = document.getElementById('ip-voucher-field');

    if (!flag || !field) { return; }

    function toggle() { field.hidden = !flag.checked; }

    flag.addEventListener('change', toggle);
    toggle();
})();

(function () {
    // "Conto di saldo" ha senso solo per metodi di pagamento bancari (bonifico,
    // RID...): per contanti/assegno il flag payment_method_requires_bank_account
    // (impostato per metodo in Impostazioni > Metodi di pagamento) lo nasconde,
    // cosi' l'utente non si chiede a cosa serva un IBAN su una fattura pagata
    // in contanti. simple-select (IPSelect) triggera comunque il 'change'
    // nativo sulla <select> sottostante, quindi basta ascoltare quello.
    var methodSelect = document.getElementById('payment_method');
    var accountWrap  = document.getElementById('bank_account_id_wrap');

    if (!methodSelect || !accountWrap) { return; }

    function toggleBankAccount() {
        var selected = methodSelect.options[methodSelect.selectedIndex];
        var requires = selected ? selected.getAttribute('data-requires-bank-account') : '1';
        accountWrap.hidden = requires === '0';
    }

    methodSelect.addEventListener('change', toggleBankAccount);
    toggleBankAccount();
})();
</script>

<?= $this->endSection() ?>
