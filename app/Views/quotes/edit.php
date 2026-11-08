<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('quote') . ' #' . ($quote['quote_number'] ?: $quote['quote_id'])); ?>

<?php
$status_label_class = [
    'draft' => 'text-bg-secondary',
    'sent' => 'text-bg-info',
    'viewed' => 'text-bg-warning',
    'approved' => 'text-bg-success',
    'rejected' => 'text-bg-danger',
    'canceled' => 'text-bg-dark',
];
$statusInfo = $quote_statuses[(string) $quote['quote_status_id']] ?? ['label' => '', 'class' => 'draft'];
$is_draft   = (int) $quote['quote_status_id'] === 1;
?>

<?php
$title = trans('quote') . ' #' . esc($quote['quote_number'] ?: $quote['quote_id'])
    . ' <span class="badge ip-status ' . ($status_label_class[$statusInfo['class']] ?? 'text-bg-secondary') . '">'
    . esc($statusInfo['label'])
    . '</span>';
$actions = '<div class="ip-doc-actions">'
    . '<div class="headerbar-total">'
    . '<span class="headerbar-total__label">' . trans('total', '', 'Totale') . '</span>'
    . '<span class="headerbar-total__amount">' . format_currency($quote['quote_total'] ?? 0) . '</span>'
    . '</div>'
    // Azione primaria sempre visibile: l'anteprima del preventivo.
    . '<a class="btn btn-sm btn-primary" href="' . site_url('quotes/view/' . $quote['quote_id']) . '"><i class="fa fa-solid fa-eye" aria-hidden="true"></i> ' . trans('view', '', 'Anteprima') . '</a>';

// Azioni secondarie nel menu "Altro": converte/copia/elimina affollavano
// l'headerbar di bottoni ridondanti con la fattura.
$more = '';
$more .= '<li><a class="dropdown-item" href="' . site_url('quotes/generate_pdf/' . $quote['quote_id']) . '" target="_blank" rel="noopener"><i class="fa fa-regular fa-file-pdf" aria-hidden="true"></i> ' . trans('pdf') . '</a></li>';
$more .= '<li><form action="' . site_url('quotes/ajax/quote_to_invoice') . '" method="POST">' . csrf_field() . '<input type="hidden" name="quote_id" value="' . $quote['quote_id'] . '"><button type="submit" class="dropdown-item dropdown-button"><i class="fa fa-regular fa-file-text" aria-hidden="true"></i> ' . trans('quote_to_invoice') . '</button></form></li>';
$more .= '<li><form action="' . site_url('quotes/ajax/copy_quote') . '" method="POST">' . csrf_field() . '<input type="hidden" name="quote_id" value="' . $quote['quote_id'] . '"><button type="submit" class="dropdown-item dropdown-button"><i class="fa fa-solid fa-copy" aria-hidden="true"></i> ' . trans('copy_quote') . '</button></form></li>';
if ($is_draft):
    $more .= '<li><hr class="dropdown-divider"></li>';
    $more .= '<li><form action="' . site_url('quotes/delete/' . $quote['quote_id']) . '" method="POST">' . csrf_field() . '<button type="button" class="dropdown-item dropdown-button text-danger" data-bs-toggle="confirm" data-confirm-title="' . esc(trans('confirm', '', 'Conferma')) . '" data-confirm-body="' . esc(trans('delete_quote_warning')) . '" data-confirm-ok="' . esc(trans('delete', '', 'Elimina')) . '" data-confirm-danger="1"><i class="fa fa-solid fa-trash-can" aria-hidden="true"></i> ' . trans('delete') . '</button></form></li>';
endif;

$actions .= '<div class="dropdown">'
    . '<button type="button" class="btn btn-sm btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="' . esc(trans('more_actions', '', 'Altre azioni')) . '">'
    . '<i class="fa fa-solid fa-ellipsis-h" aria-hidden="true"></i> <span class="d-none d-md-inline">' . esc(trans('more_actions', '', 'Altro')) . '</span>'
    . '</button>'
    . '<ul class="dropdown-menu dropdown-menu-end">' . $more . '</ul>'
    . '</div>';

// Salva sempre visibile: e' l'azione di chiusura del documento.
$actions .= '<button type="submit" form="quote_details_form" class="btn btn-sm btn-success"><i class="fa fa-solid fa-check" aria-hidden="true"></i> ' . trans('save', '', 'Salva') . '</button>';
$actions .= '</div>';
?>
<?= $this->include('layout/headerbar', ['title' => $title, 'actions' => $actions]) ?>

<?php
/**
 * Modifica preventivo: stesse sezioni numerate e stessa tabella righe del form
 * "Nuovo preventivo" e della modifica fattura, così i quattro percorsi che
 * portano allo stesso documento si somigliano.
 */
?>

<div id="content" class="ip-doc-form">

    <!-- ① Cliente -->
    <?= $this->include('layout/document_client_block', [
        'doc_id'               => $quote['quote_id'],
        'doc_id_field'         => 'quote_id',
        'entity'               => $quote,
        'clients'              => $clients,
        'change_client_action' => 'quotes/ajax/change_client',
        'preview_title'        => trans('total', '', 'Totale'),
        'totals'               => [
            ['label' => trans('subtotal', '', 'Imponibile'), 'value' => format_currency($quote['quote_item_subtotal'] ?? 0)],
            ['label' => trans('item_tax', '', 'Imposta articolo'), 'value' => format_currency($quote['quote_item_tax_total'] ?? 0)],
            ['label' => trans('quote_tax', '', 'Imposta preventivo'), 'value' => format_currency($quote['quote_tax_total'] ?? 0)],
            ['label' => trans('total', '', 'Totale'), 'value' => format_currency($quote['quote_total'] ?? 0), 'is_total' => true],
        ],
    ]) ?>

    <!-- ② Dati documento -->
    <form method="POST" action="<?= site_url('quotes/ajax/save') ?>" id="quote_details_form">
        <?= csrf_field() ?>
        <input type="hidden" name="quote_id" value="<?= (int) $quote['quote_id'] ?>">

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">2</span>
                <h2 class="ip-doc-section__title"><?= trans('document_data', '', 'Dati documento') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-grid">

                    <div class="ip-doc-subgroup"><?= trans('document_data', '', 'Identificazione') ?></div>

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="quote_number"><?= trans('quote', '', 'Preventivo') ?> #</label>
                        <input type="text" name="quote_number" id="quote_number" class="form-control"
                               value="<?= esc($quote['quote_number']) ?>"
                               placeholder="<?= esc(trans('not_set', '', 'Non impostato'), 'attr') ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="quote_date_created"><?= trans('date', '', 'Data') ?></label>
                        <input type="text" name="quote_date_created" id="quote_date_created"
                               class="form-control datepicker"
                               value="<?= esc(date_from_mysql($quote['quote_date_created'])) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="quote_date_expires"><?= trans('expires', '', 'Scadenza') ?></label>
                        <input type="text" name="quote_date_expires" id="quote_date_expires"
                               class="form-control datepicker"
                               value="<?= esc(date_from_mysql($quote['quote_date_expires'])) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="quote_status_id"><?= trans('status', '', 'Stato') ?></label>
                        <select name="quote_status_id" id="quote_status_id" class="form-control simple-select">
                            <?php foreach ($quote_statuses as $key => $status): ?>
                                <option value="<?= (int) $key ?>"
                                    <?= (int) $quote['quote_status_id'] === (int) $key ? 'selected' : '' ?>>
                                    <?= esc($status['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ip-doc-subgroup"><?= trans('discount', '', 'Sconto') ?></div>

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="quote_discount_amount"><?= trans('discount', '', 'Sconto') ?> (<?= esc(get_setting('currency_symbol', '€')) ?>)</label>
                        <input type="text" name="quote_discount_amount" id="quote_discount_amount"
                               class="form-control amount"
                               value="<?= esc(format_amount($quote['quote_discount_amount'])) ?>">
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="quote_discount_percent"><?= trans('discount', '', 'Sconto') ?> (%)</label>
                        <input type="text" name="quote_discount_percent" id="quote_discount_percent"
                               class="form-control amount"
                               value="<?= esc(format_amount($quote['quote_discount_percent'])) ?>">
                    </div>

                    <div class="ip-doc-subgroup">
                        <?= trans('sharing', '', 'Condivisione') ?>
                        <span class="ip-doc-subgroup__hint">
                            <?= trans('sharing_hint', '', 'Come il cliente accede al documento') ?>
                        </span>
                    </div>

                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="quote_password"><?= trans('quote_password', '', 'Password PDF') ?></label>
                        <input type="text" name="quote_password" id="quote_password" class="form-control"
                               value="<?= esc(decrypt_secret((string) ($quote['quote_password'] ?? ''))) ?>" autocomplete="off">
                    </div>
                </div>
            </div>
        </section>
    </form>

    <!-- ③ Righe -->
    <section class="ip-doc-section">
        <div class="ip-doc-section__head">
            <span class="ip-doc-step">3</span>
            <h2 class="ip-doc-section__title"><?= trans('quote_items', '', 'Righe') ?></h2>
            <span class="ip-doc-section__hint"><?= count($items) ?> <?= trans('items', '', 'righe') ?></span>
        </div>

        <?= $this->include('layout/document_item_table', [
            'items'         => $items,
            'tax_rates'     => $tax_rates,
            'unit_groups'   => $unit_groups,
            'doc_form_id'   => 'quote_details_form',
            'doc_has_tasks' => false,
        ]) ?>

    </section>

    <!-- ④ Imposte preventivo -->
    <section class="ip-doc-section">
        <div class="ip-doc-section__head">
            <span class="ip-doc-step">4</span>
            <h2 class="ip-doc-section__title"><?= trans('quote_tax_rates', '', 'Imposte preventivo') ?></h2>
        </div>
        <div class="ip-doc-section__body">
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
                <?php foreach ($quote_tax_rates as $quoteTaxRate): ?>
                    <tr>
                        <td>
                            <?= esc($quoteTaxRate['tax_rate_name'] ?? '') ?>
                            (<?= esc(format_amount($quoteTaxRate['tax_rate_percent'] ?? 0)) ?>%)
                        </td>
                        <td class="ip-items-table__total"><?= format_currency($quoteTaxRate['quote_tax_rate_amount'] ?? 0) ?></td>
                        <td class="ip-items-table__actions">
                            <form method="POST" action="<?= site_url('quotes/delete_quote_tax/' . (int) $quote['quote_id'] . '/' . (int) $quoteTaxRate['quote_tax_rate_id']) ?>">
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
                <?php if (empty($quote_tax_rates)): ?>
                    <tr><td colspan="3" class="ip-items-table__empty"><?= trans('no_taxes') ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>

            <form method="POST" action="<?= site_url('quotes/ajax/save_quote_tax_rate') ?>" class="ip-doc-newrow">
                <?= csrf_field() ?>
                <input type="hidden" name="quote_id" value="<?= (int) $quote['quote_id'] ?>">
                <select name="tax_rate_id" class="form-control input-sm simple-select"
                                            aria-label="<?= esc(trans('tax_rate', '', 'Aliquota')) ?>">
                    <option value="0"><?= trans('none', '', 'Nessuna') ?></option>
                    <?php foreach ($tax_rates as $taxRate): ?>
                        <option value="<?= (int) $taxRate['tax_rate_id'] ?>">
                            <?= esc(format_amount($taxRate['tax_rate_percent'])) ?>% - <?= esc($taxRate['tax_rate_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="include_item_tax" class="form-control input-sm simple-select"
                                            aria-label="<?= esc(trans('include_item_tax', '', 'Imposta sulle righe inclusa')) ?>">
                    <option value="0"><?= trans('apply_before_item_tax', '', 'Prima dell\'imposta articolo') ?></option>
                    <option value="1"><?= trans('apply_after_item_tax', '', 'Dopo l\'imposta articolo') ?></option>
                </select>
                <button type="submit" class="btn btn-sm btn-secondary">
                    <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('add_tax_rate', '', 'Aggiungi') ?>
                </button>
            </form>
        </div>
    </section>

    <!-- ⑤ Note -->
    <?= $this->include('layout/document_notes_block', [
        'doc_id'       => $quote['quote_id'],
        'doc_id_field' => 'quote_id',
        'note_action'  => 'quotes/ajax/save',
        'note_name'    => 'notes',
        'note_label'   => trans('notes', '', 'Note'),
        'note_value'   => $quote['notes'],
        'client_id'    => $quote['client_id'],
        'step_number'  => 5,
        'doc_form_id'  => 'quote_details_form',
    ]) ?>

</div>

<?= $this->endSection() ?>
