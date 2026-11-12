<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
/**
 * Anteprima preventivo: stesso impianto della fattura — il foglio in un
 * iframe che carica il template PDF, la modifica su /quotes/edit.
 */
$status_label_class = [
    'draft'    => 'text-bg-secondary',
    'sent'     => 'text-bg-info',
    'viewed'   => 'text-bg-warning',
    'approved' => 'text-bg-success',
    'rejected' => 'text-bg-danger',
    'canceled' => 'text-bg-dark',
];

$quote_status_class = $quote_statuses[$quote['quote_status_id']]['class'] ?? 'draft';
$is_draft           = (int) $quote['quote_status_id'] === 1;
$quote_id           = (int) $quote['quote_id'];

$this->setVar('pageTitle', trans('quote', '', 'Preventivo') . ' ' . ($quote['quote_number'] ?: ('#' . $quote_id)));

$is_draft_number = db_bool($quote['quote_number_is_draft'] ?? false);

$title = trans('quote', '', 'Preventivo') . ' ' . esc($quote['quote_number'] ?: ('#' . $quote_id))
    . ' <span class="badge ip-status ' . ($status_label_class[$quote_status_class] ?? 'text-bg-secondary') . '">'
    . esc($quote_statuses[$quote['quote_status_id']]['label'] ?? trans('draft'))
    . '</span>';

if ($is_draft_number) {
    $title .= ' <span class="badge text-bg-warning">'
        . esc(trans('provisional_number', '', 'Numero provvisorio'))
        . '</span>';
}

$actions = '<div class="ip-doc-actions">'
    . '<a class="btn btn-sm btn-primary" href="' . site_url('quotes/edit/' . $quote_id) . '">'
    . '<i class="fa fa-solid fa-pencil" aria-hidden="true"></i> ' . trans('edit', '', 'Modifica') . '</a>'
    . '<a class="btn btn-sm btn-secondary" href="' . site_url('quotes/generate_pdf/' . $quote_id) . '">'
    . '<i class="fa fa-regular fa-file-pdf" aria-hidden="true"></i> ' . trans('download_pdf', '', 'PDF') . '</a>'
    // Invio per email: stessa schermata della fattura, con template, mittente
    // e allegato PDF del preventivo.
    . '<a class="btn btn-sm btn-secondary" href="' . site_url('mailer/quote/' . $quote_id) . '">'
    . '<i class="fa fa-solid fa-envelope" aria-hidden="true"></i> ' . trans('email_quote', '', 'Invia preventivo') . '</a>'
    . '<form action="' . site_url('quotes/ajax/quote_to_invoice') . '" method="POST">' . csrf_field()
    . '<input type="hidden" name="quote_id" value="' . $quote_id . '">'
    . '<button type="submit" class="btn btn-sm btn-secondary"><i class="fa fa-regular fa-file-text" aria-hidden="true"></i> '
    . trans('quote_to_invoice', '', 'Converti in fattura') . '</button></form>'
    . '<div class="dropdown">'
    . '<button type="button" class="btn btn-sm btn-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="' . esc(trans('options', '', 'Opzioni'), 'attr') . '">'
    . '<i class="fa fa-solid fa-cog" aria-hidden="true"></i></button><ul class="dropdown-menu dropdown-menu-end">'
    . '<li><a href="' . site_url('delivery_notes/from/quote/' . $quote_id) . '">'
    . '<i class="fa fa-solid fa-truck fa-margin" aria-hidden="true"></i> ' . trans('create_delivery_note', '', 'Crea DDT') . '</a></li>'
    . '<li><form action="' . site_url('quotes/ajax/copy_quote') . '" method="POST">' . csrf_field()
    . '<input type="hidden" name="quote_id" value="' . $quote_id . '">'
    . '<button type="submit" class="dropdown-button"><i class="fa fa-solid fa-copy fa-margin" aria-hidden="true"></i> '
    . trans('copy_quote', '', 'Copia') . '</button></form></li>';

if ($is_draft) {
    $actions .= '<li><form action="' . site_url('quotes/delete/' . $quote_id) . '" method="POST">' . csrf_field()
        . '<button type="button" class="dropdown-button" data-bs-toggle="confirm"'
        . ' data-confirm-title="' . esc(trans('confirm', '', 'Conferma'), 'attr') . '"'
        . ' data-confirm-body="' . esc(trans('delete_record_warning', '', 'Eliminare il preventivo?'), 'attr') . '"'
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
            <?= trans('provisional_number_notice_quote', '', 'Questo preventivo ha un numero provvisorio dalla serie bozze. Il numero definitivo viene assegnato quando esce dallo stato di bozza.') ?>
        </div>
    <?php endif; ?>

    <div class="ip-preview">

        <div class="ip-preview__sheet">
            <iframe id="document_sheet" class="ip-preview__frame"
                    src="<?= site_url('quotes/preview_pdf/' . $quote_id) ?>"
                    title="<?= esc(trans('quote', '', 'Preventivo'), 'attr') ?>"
                    loading="lazy"></iframe>
        </div>

        <aside class="ip-preview__side">

            <div class="card">
                <div class="card-header"><?= trans('summary', '', 'Riepilogo') ?></div>
                <div class="card-body">
                    <dl class="ip-preview__totals">
                        <dt><?= trans('client', '', 'Cliente') ?></dt>
                        <dd>
                            <a href="<?= site_url('clients/view/' . (int) $quote['client_id']) ?>">
                                <?= esc(format_client($quote)) ?>
                            </a>
                        </dd>
                        <dt><?= trans('date', '', 'Data') ?></dt>
                        <dd><?= esc(date_from_mysql($quote['quote_date_created'])) ?></dd>
                        <dt><?= trans('expires', '', 'Scadenza') ?></dt>
                        <dd><?= esc(date_from_mysql($quote['quote_date_expires'])) ?></dd>
                    </dl>

                    <hr>

                    <dl class="ip-preview__totals">
                        <dt><?= trans('subtotal', '', 'Imponibile') ?></dt>
                        <dd><?= format_currency($quote['quote_item_subtotal'] ?? 0) ?></dd>
                        <dt><?= trans('tax', '', 'IVA') ?></dt>
                        <dd><?= format_currency(($quote['quote_item_tax_total'] ?? 0) + ($quote['quote_tax_total'] ?? 0)) ?></dd>
                        <dt class="is-total"><?= trans('total', '', 'Totale') ?></dt>
                        <dd class="is-total"><?= format_currency($quote['quote_total'] ?? 0) ?></dd>
                    </dl>
                </div>
            </div>

        </aside>
    </div>
</div>

<?php /* Adattamento del foglio al riquadro: una copia sola per le tre
         anteprime (fattura, preventivo, DDT), in layout/preview_sheet. */ ?>
<script><?= $this->include('layout/preview_sheet') ?></script>

<?= $this->endSection() ?>
