<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php
$this->setVar('pageTitle', 'F24');
$btn = function ($key, $label) use ($state) {
    $cls = ($state === $key) ? 'btn-primary' : 'btn-secondary';
    return '<a href="' . site_url('f24') . ($key ? '?state=' . $key : '') . '" class="btn ' . $cls . '">' . $label . '</a>';
};
$filters = '<div class="btn-group btn-group-sm index-options">'
         . $btn('', trans('all', '', 'Tutti')) . $btn('open', trans('unpaid', '', 'Da pagare')) . $btn('paid', trans('paid', '', 'Pagati'))
         . '</div>';
$actions = '<a class="btn btn-sm btn-secondary" href="' . site_url('f24/import') . '"><i class="fa fa-solid fa-upload" aria-hidden="true"></i> ' . trans('import_f24_receipts', '', 'Importa quietanze') . '</a> '
         . '<a class="btn btn-sm btn-primary" href="' . site_url('f24/form') . '"><i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('new_f24', '', 'Nuovo F24') . '</a>';
?>
<?= $this->include('layout/headerbar', ['title' => 'F24', 'filters' => $filters, 'actions' => $actions]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-university" aria-hidden="true"></i></span>
                <span class="ip-table-card-label">F24</span>
                <span class="badge text-bg-secondary"><?= count($items) ?></span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover ip-clickable-rows ip-table-card-collapse">
                <thead><tr>
                    <th scope="col"><?= trans('due_date', '', 'Scadenza') ?></th>
                    <th scope="col"><?= trans('period', '', 'Periodo') ?></th>
                    <th scope="col" class="amount"><?= trans('amount', '', 'Importo') ?></th>
                    <th scope="col"><?= trans('paid_on', '', 'Pagato il') ?></th>
                    <th scope="col"><?= trans('file', '', 'Modello') ?></th>
                    <th scope="col"><?= trans('options', '', 'Opzioni') ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($items as $f): ?>
                    <tr>
                        <?php // Scheda mobile: scadenza e importo in alto, periodo
                              // sotto, stato del versamento a destra; modello e
                              // opzioni restano nella vista tabellare. ?>
                        <td data-cell="number"><?= esc(date_from_mysql($f['f24_due_date'])) ?></td>
                        <td data-cell="client"><?= esc($f['f24_period'] ?: '—') ?></td>
                        <td class="amount" data-cell="amount"><?= format_currency($f['f24_amount']) ?></td>
                        <td data-cell="status">
                            <?php if (!empty($f['f24_paid_date'])): ?>
                                <span class="badge ip-status text-bg-success"><?= esc(date_from_mysql($f['f24_paid_date'])) ?></span>
                                <?php if (!empty($f['f24_receipt_file'])): ?>
                                    <a href="<?= site_url('f24/receipt/' . (int) $f['f24_id']) ?>" data-no-row-click title="<?= esc(trans('f24_receipt', '', 'Quietanza'), 'attr') ?>" aria-label="<?= esc(trans('f24_receipt', '', 'Quietanza'), 'attr') ?>"><i class="fa fa-solid fa-receipt" aria-hidden="true"></i></a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge ip-status text-bg-warning"><?= trans('unpaid', '', 'Da pagare') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="ip-hide-on-mobile">
                            <?php if (!empty($f['f24_file'])): ?>
                                <a href="<?= site_url('f24/download/' . (int) $f['f24_id']) ?>" data-no-row-click><i class="fa fa-solid fa-download" aria-hidden="true"></i></a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="ip-hide-on-mobile">
                            <div class="options btn-group">
                                <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options', '', 'Opzioni') ?>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a href="<?= site_url('f24/form/' . (int) $f['f24_id']) ?>"><i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit', '', 'Modifica') ?></a></li>
                                    <li><form action="<?= site_url('f24/delete/' . (int) $f['f24_id']) ?>" method="POST"><?= csrf_field() ?>
                                        <button type="button" class="dropdown-button" data-bs-toggle="confirm"
                                                data-confirm-title="<?= esc(trans('confirm', '', 'Conferma'), 'attr') ?>"
                                                data-confirm-body="<?= esc(trans('delete_record_warning', '', 'Eliminare l\'F24?'), 'attr') ?>"
                                                data-confirm-ok="<?= esc(trans('delete', '', 'Elimina'), 'attr') ?>" data-confirm-danger="1">
                                            <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete', '', 'Elimina') ?></button></form></li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($items)): ?>
                    <?= $this->include('layout/empty_row', [
                        'colspan'   => 6,
                        'icon'      => 'fa-solid fa-university',
                        'message'   => trans('no_f24', '', 'Nessun F24 registrato.'),
                        'cta_url'   => site_url('f24/form'),
                        'cta_label' => trans('new_f24', '', 'Nuovo F24'),
                    ]) ?>
                <?php endif; ?>
                </tbody>
                <?php
                /* Quanto pesano le deleghe che si stanno guardando, e quanto
                   resta da versare. Il controller calcolava già i due importi
                   ma la pagina non li mostrava: l'elenco diceva riga per riga
                   e non rispondeva alla domanda per cui lo si apre. Il saldo
                   compare solo quando è diverso dal totale: con il filtro
                   "Da pagare" le due cifre coinciderebbero, con "Pagati"
                   sarebbe uno zero inutile. */
                if (!empty($items)):
                    $f24Total = (float) ($period_totals['total'] ?? 0);
                    $f24Open  = (float) ($period_totals['balance'] ?? 0);
                    // Il saldo si mostra solo se c'è davvero qualcosa da
                    // versare e non coincide già con il totale: con "Da
                    // pagare" le due righe direbbero lo stesso numero, con
                    // "Pagati" l'ultima sarebbe uno zero.
                    $showOpen = $f24Open > 0.005 && abs($f24Open - $f24Total) >= 0.005;
                ?>
                <tfoot class="ip-list-totals">
                    <tr class="ip-list-totals__row<?= $showOpen ? '' : ' ip-list-totals__row--grand' ?>">
                        <th colspan="2" scope="row" class="ip-list-totals__label"><?= trans('total', '', 'Totale') ?></th>
                        <td class="amount ip-list-totals__value"><?= format_currency($f24Total) ?></td>
                        <td></td><td></td><td></td>
                    </tr>
                    <?php if ($showOpen): ?>
                        <tr class="ip-list-totals__row ip-list-totals__row--grand">
                            <th colspan="2" scope="row" class="ip-list-totals__label"><?= trans('unpaid', '', 'Da pagare') ?></th>
                            <td class="amount ip-list-totals__value"><?= format_currency($f24Open) ?></td>
                            <td></td><td></td><td></td>
                        </tr>
                    <?php endif; ?>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
