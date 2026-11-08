<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', $supplier['supplier_name']); ?>

<?php
$id      = (int) $supplier['supplier_id'];
$actions = '<div class="btn-group btn-group-sm">'
    . '<a href="' . site_url('expenses/form') . '?supplier_id=' . $id . '" class="btn btn-secondary"><i class="fa fa-solid fa-plus" aria-hidden="true"></i> ' . trans('add_expense', '', 'Nuova spesa') . '</a>'
    . '<a href="' . site_url('suppliers/form/' . $id) . '" class="btn btn-secondary"><i class="fa fa-regular fa-edit" aria-hidden="true"></i> ' . trans('edit') . '</a>'
    . '</div>'
    . '<form action="' . site_url('suppliers/delete/' . $id) . '" method="POST" style="display:inline-block">' . csrf_field()
    . '<button type="button" class="btn btn-danger btn-sm" data-bs-toggle="confirm"'
    . ' data-confirm-title="' . esc(trans('confirm', '', 'Conferma'), 'attr') . '"'
    . ' data-confirm-body="' . esc(trans('delete_supplier_warning', '', 'Eliminare il fornitore? Le spese collegate restano, perdono solo il collegamento.'), 'attr') . '"'
    . ' data-confirm-ok="' . esc(trans('delete', '', 'Elimina'), 'attr') . '" data-confirm-danger="1">'
    . '<i class="fa fa-solid fa-trash-can" aria-hidden="true"></i> ' . trans('delete') . '</button></form>';

$title = esc($supplier['supplier_name']);
if (!db_bool($supplier['supplier_active'])) {
    $title .= ' <span class="badge ip-status text-bg-secondary">' . trans('inactive', '', 'Non attivo') . '</span>';
}

// Riga anagrafica: mostra solo i campi valorizzati, cosi' una scheda scarna
// non diventa un elenco di trattini.
$rows = array_filter([
    trans('vat_id', '', 'Partita IVA')          => $supplier['supplier_vat_id'],
    trans('tax_code', '', 'Codice fiscale')     => $supplier['supplier_tax_code'],
    trans('email', '', 'Email')                 => $supplier['supplier_email'],
    trans('phone', '', 'Telefono')              => $supplier['supplier_phone'],
    trans('web', '', 'Sito web')                => $supplier['supplier_web'],
    trans('sdi_code', '', 'Codice destinatario') => $supplier['supplier_sdi_code'],
    trans('sdi_pec', '', 'PEC')                 => $supplier['supplier_sdi_pec'],
    trans('iban', '', 'IBAN')                   => $supplier['supplier_iban'],
], static fn($v) => trim((string) $v) !== '');

$addr = array_filter([
    $supplier['supplier_address_1'],
    $supplier['supplier_address_2'],
    trim(($supplier['supplier_zip'] ?? '') . ' ' . ($supplier['supplier_city'] ?? '') . ' ' . ($supplier['supplier_state'] ?? '')),
    $supplier['supplier_country'],
], static fn($v) => trim((string) $v) !== '');
?>

<?= $this->include('layout/headerbar', ['title' => $title, 'actions' => $actions]) ?>

<?= $this->include('layout/tabs', [
    'id'   => 'supplier-tabs',
    'tabs' => [
        ['id' => 'supplierDetails', 'label' => trans('details', '', 'Dettagli')],
        ['id' => 'supplierExpenses', 'label' => trans('expenses', '', 'Spese'), 'badge' => (int) $supplier['expense_count']],
    ],
]) ?>

<div id="content" class="no-padding">
    <div class="tab-content no-padding">

        <div id="supplierDetails" class="tab-pane tab-rich-content active" role="tabpanel"
             aria-labelledby="tab-supplierDetails" tabindex="0">
            <div class="row g-3 mb-3">
                <div class="col-12 col-md-6">
                    <div class="card no-margin h-100">
                        <div class="card-header ip-table-card-header">
                            <div class="ip-table-card-title">
                                <span class="card-header-icon"><i class="fa fa-solid fa-shopping-basket" aria-hidden="true"></i></span>
                                <span class="ip-table-card-label"><?= trans('supplier_details', '', 'Dati fornitore') ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($addr !== []): ?>
                                <p class="mb-3"><?= nl2br(esc(implode("\n", $addr))) ?></p>
                            <?php endif; ?>
                            <table class="table no-margin ip-overview-table">
                                <tbody>
                                <?php foreach ($rows as $label => $value): ?>
                                    <tr>
                                        <td><?= esc($label) ?></td>
                                        <td class="amount"><span class="ip-amount-value"><?= esc($value) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($rows === [] && $addr === []): ?>
                                    <tr><td colspan="2" class="text-muted"><?= trans('no_anagraphic_data') ?></td></tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                            <?php if (trim((string) $supplier['supplier_note']) !== ''): ?>
                                <p class="ip-number-help mt-3"><?= nl2br(esc($supplier['supplier_note'])) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6">
                    <div class="card no-margin h-100">
                        <div class="card-header ip-table-card-header">
                            <div class="ip-table-card-title">
                                <span class="card-header-icon"><i class="fa fa-solid fa-line-chart" aria-hidden="true"></i></span>
                                <span class="ip-table-card-label"><?= trans('summary', '', 'Riepilogo') ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="card-label"><?= trans('total_spent', '', 'Totale speso') ?></div>
                            <div class="card-value"><?= format_currency($supplier['expense_total']) ?></div>
                            <div class="card-subtext">
                                <span class="ip-kpi-count"><?= (int) $supplier['expense_count'] ?> <?= trans('expenses', '', 'spese') ?></span>
                                <?php if ((float) $supplier['expense_balance'] > 0): ?>
                                    <span class="ip-kpi-period"><?= trans('balance', '', 'Da pagare') ?>: <?= format_currency($supplier['expense_balance']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="supplierExpenses" class="tab-pane table-content" role="tabpanel"
             aria-labelledby="tab-supplierExpenses" tabindex="0">
            <div class="card ip-table-card">
                <div class="table-responsive">
                    <table class="table table-hover table-striped ip-recent-table">
                        <thead>
                        <tr>
                            <th scope="col"><?= trans('date', '', 'Data') ?></th>
                            <th scope="col"><?= trans('number', '', 'Numero') ?></th>
                            <th scope="col"><?= trans('category', '', 'Categoria') ?></th>
                            <th scope="col" class="amount"><?= trans('total', '', 'Totale') ?></th>
                            <th scope="col" class="amount"><?= trans('balance', '', 'Da pagare') ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($expenses as $e): ?>
                            <?php $open = (float) $e['expense_total'] - (float) $e['expense_paid']; ?>
                            <tr>
                                <td data-label="<?= esc(trans('date', '', 'Data')) ?>"><?= esc(date_from_mysql($e['expense_date'])) ?></td>
                                <td data-label="<?= esc(trans('number', '', 'Numero')) ?>">
                                    <a href="<?= site_url('expenses/form/' . (int) $e['expense_id']) ?>">
                                        <span class="ip-num"><?= esc($e['expense_number'] ?: $e['expense_id']) ?></span>
                                    </a>
                                    <?php if (($e['expense_source'] ?? '') === 'xml'): ?>
                                        <a class="badge ip-status text-bg-info" href="<?= site_url('expenses/preview_xml/' . (int) $e['expense_id']) ?>">XML</a>
                                    <?php endif; ?>
                                </td>
                                <td data-label="<?= esc(trans('category', '', 'Categoria')) ?>"><?= esc($e['expense_category_name'] ?? '—') ?></td>
                                <td class="amount" data-label="<?= esc(trans('total', '', 'Totale')) ?>"><?= format_currency($e['expense_total']) ?></td>
                                <td class="amount" data-label="<?= esc(trans('balance', '', 'Da pagare')) ?>"><?= $open > 0 ? format_currency($open) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($expenses)): ?>
                            <tr class="empty-row">
                                <td colspan="5">
                                    <i class="fa fa-solid fa-shopping-cart empty-icon" aria-hidden="true"></i>
                                    <?= trans('no_linked_expenses') ?>
                                    <a href="<?= site_url('expenses/form') ?>?supplier_id=<?= $id ?>" class="empty-cta">
                                        <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('add_expense', '', 'Nuova spesa') ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<?= $this->endSection() ?>
