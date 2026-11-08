<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php
$this->setVar('pageTitle', trans('delivery_notes', '', 'Documenti di trasporto'));
$btn = function ($key, $label) use ($state) {
    $cls = ($state === $key) ? 'btn-primary' : 'btn-secondary';
    return '<a href="' . site_url('delivery_notes') . ($key ? '?state=' . $key : '') . '" class="btn ' . $cls . '">' . $label . '</a>';
};
$filters = '<div class="btn-group btn-group-sm index-options">'
         . $btn('', trans('all', '', 'Tutti'))
         . $btn('open', trans('to_invoice', '', 'Da fatturare'))
         . $btn('invoiced', trans('invoiced', '', 'Fatturati')) . '</div>';
$actions = '<a class="btn btn-sm btn-primary" href="' . site_url('delivery_notes/form') . '"><i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new_delivery_note', '', 'Nuovo DDT') . '</a>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('delivery_notes', '', 'Documenti di trasporto'), 'filters' => $filters, 'actions' => $actions]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-truck" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('delivery_notes', '', 'Documenti di trasporto')) ?></span>
                <span class="badge text-bg-secondary"><?= count($notes) ?></span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover ip-clickable-rows ip-table-card-collapse">
                <thead><tr>
                    <th scope="col"><?= trans('number', '', 'Numero') ?></th>
                    <th scope="col"><?= trans('date', '', 'Data') ?></th>
                    <th scope="col"><?= trans('client', '', 'Destinatario') ?></th>
                    <th scope="col"><?= trans('transport_reason', '', 'Causale') ?></th>
                    <th scope="col"><?= trans('carrier', '', 'Vettore') ?></th>
                    <th scope="col"><?= trans('invoice', '', 'Fattura') ?></th>
                    <th scope="col"><?= trans('options', '', 'Opzioni') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($notes as $n): ?>
                    <tr>
                        <td data-cell="number">
                            <a href="<?= site_url('delivery_notes/view/' . (int) $n['delivery_note_id']) ?>">
                                <span class="ip-num"><?= esc($n['delivery_note_number'] ?: ('#' . $n['delivery_note_id'])) ?></span>
                            </a>
                        </td>
                        <td data-cell="due"><?= esc(date_from_mysql($n['delivery_note_date'])) ?></td>
                        <td data-cell="client"><?= esc(format_client($n)) ?></td>
                        <?php // Causale e vettore restano nella vista tabellare. ?>
                        <td class="ip-hide-on-mobile"><?= esc($n['transport_reason'] ?: '—') ?></td>
                        <td class="ip-hide-on-mobile"><?= esc($n['carrier'] ?: '—') ?></td>
                        <td data-cell="status">
                            <?php if (!empty($n['invoice_id'])): ?>
                                <a href="<?= site_url('invoices/view/' . (int) $n['invoice_id']) ?>">
                                    <span class="ip-num"><?= esc($n['invoice_number'] ?: ('#' . $n['invoice_id'])) ?></span>
                                </a>
                            <?php else: ?>
                                <span class="badge ip-status text-bg-warning"><?= trans('to_invoice', '', 'Da fatturare') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="ip-hide-on-mobile">
                            <div class="options btn-group">
                                <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options', '', 'Opzioni') ?>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a href="<?= site_url('delivery_notes/view/' . (int) $n['delivery_note_id']) ?>"><i class="fa fa-solid fa-eye fa-margin" aria-hidden="true"></i> <?= trans('view', '', 'Vista') ?></a></li>
                                    <li><a href="<?= site_url('delivery_notes/form/' . (int) $n['delivery_note_id']) ?>"><i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit', '', 'Modifica') ?></a></li>
                                    <li><a href="<?= site_url('delivery_notes/generate_pdf/' . (int) $n['delivery_note_id']) ?>"><i class="fa fa-regular fa-file-pdf fa-margin" aria-hidden="true"></i> PDF</a></li>
                                    <li><form action="<?= site_url('delivery_notes/delete/' . (int) $n['delivery_note_id']) ?>" method="POST"><?= csrf_field() ?>
                                        <button type="button" class="dropdown-button" data-bs-toggle="confirm"
                                                data-confirm-title="<?= esc(trans('confirm', '', 'Conferma'), 'attr') ?>"
                                                data-confirm-body="<?= esc(trans('delete_record_warning', '', 'Eliminare il documento?'), 'attr') ?>"
                                                data-confirm-ok="<?= esc(trans('delete', '', 'Elimina'), 'attr') ?>" data-confirm-danger="1">
                                            <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete', '', 'Elimina') ?></button></form></li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($notes)): ?>
                    <?= $this->include('layout/empty_row', [
                        'colspan'   => 7,
                        'icon'      => 'fa-solid fa-truck',
                        'message'   => trans('no_delivery_notes', '', 'Nessun documento di trasporto.'),
                        'cta_url'   => site_url('delivery_notes/form'),
                        'cta_label' => trans('new_delivery_note', '', 'Nuovo DDT'),
                    ]) ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
