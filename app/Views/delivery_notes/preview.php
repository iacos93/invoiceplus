<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php
$id = (int) $note['delivery_note_id'];
$this->setVar('pageTitle', 'DDT ' . ($note['delivery_note_number'] ?: ('#' . $id)));

$title = trans('delivery_note', '', 'DDT') . ' ' . esc($note['delivery_note_number'] ?: ('#' . $id));
if (empty($note['invoice_id'])) {
    $title .= ' <span class="badge ip-status text-bg-warning">' . esc(trans('to_invoice', '', 'Da fatturare')) . '</span>';
} else {
    $title .= ' <span class="badge ip-status text-bg-success">' . esc(trans('invoiced', '', 'Fatturato')) . '</span>';
}

$actions = '<div class="ip-doc-actions">'
    . '<a class="btn btn-sm btn-primary" href="' . site_url('delivery_notes/form/' . $id) . '"><i class="fa fa-solid fa-pencil" aria-hidden="true"></i> ' . trans('edit', '', 'Modifica') . '</a>'
    . '<a class="btn btn-sm btn-secondary" href="' . site_url('delivery_notes/generate_pdf/' . $id) . '"><i class="fa fa-regular fa-file-pdf" aria-hidden="true"></i> PDF</a>';

if (empty($note['invoice_id'])) {
    $actions .= '<form action="' . site_url('delivery_notes/to_invoice/' . $id) . '" method="POST">' . csrf_field()
        . '<button type="submit" class="btn btn-sm btn-secondary"><i class="fa fa-regular fa-file-text" aria-hidden="true"></i> '
        . trans('ddt_to_invoice', '', 'Crea fattura') . '</button></form>';
}

$actions .= '</div>';
?>
<?= $this->include('layout/headerbar', ['title' => $title, 'actions' => $actions]) ?>

<div id="content">
    <div class="ip-preview">
        <div class="ip-preview__sheet">
            <iframe id="document_sheet" class="ip-preview__frame"
                    src="<?= site_url('delivery_notes/preview_sheet/' . $id) ?>"
                    title="DDT" loading="lazy"></iframe>
        </div>
        <aside class="ip-preview__side">
            <div class="card">
                <div class="card-header"><?= trans('summary', '', 'Riepilogo') ?></div>
                <div class="card-body">
                    <dl class="ip-preview__totals">
                        <dt><?= trans('client', '', 'Destinatario') ?></dt>
                        <dd><a href="<?= site_url('clients/view/' . (int) $note['client_id']) ?>"><?= esc(format_client($note)) ?></a></dd>
                        <dt><?= trans('date', '', 'Data') ?></dt>
                        <dd><?= esc(date_from_mysql($note['delivery_note_date'])) ?></dd>
                        <dt><?= trans('transport_reason', '', 'Causale') ?></dt>
                        <dd><?= esc($note['transport_reason'] ?: '—') ?></dd>
                        <dt><?= trans('packages', '', 'Colli') ?></dt>
                        <dd><?= esc($note['packages'] ?: '—') ?></dd>
                        <dt><?= trans('carrier', '', 'Vettore') ?></dt>
                        <dd><?= esc($note['carrier'] ?: '—') ?></dd>
                        <dt class="is-total"><?= trans('rows', '', 'Righe') ?></dt>
                        <dd class="is-total"><?= count($items) ?></dd>
                    </dl>
                    <?php if (!empty($note['invoice_id'])): ?>
                        <hr>
                        <p class="ip-number-help">
                            <?= trans('ddt_invoiced_in', '', 'Fatturato con') ?>
                            <a href="<?= site_url('invoices/view/' . (int) $note['invoice_id']) ?>">
                                <?= esc($note['invoice_number'] ?: ('#' . $note['invoice_id'])) ?>
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php /* Adattamento del foglio al riquadro: una copia sola per le tre
         anteprime (fattura, preventivo, DDT), in layout/preview_sheet. */ ?>
<script><?= $this->include('layout/preview_sheet') ?></script>
<?= $this->endSection() ?>
