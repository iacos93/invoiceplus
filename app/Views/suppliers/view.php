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

// Indirizzo in linea singola per la tessera, come nella scheda cliente: il
// paese si mostra per nome, non con il codice ISO salvato nel campo.
$supplier_address = implode(', ', array_filter([
    $supplier['supplier_address_1'],
    $supplier['supplier_address_2'],
    trim(($supplier['supplier_zip'] ?? '') . ' ' . ($supplier['supplier_city'] ?? '')),
    $supplier['supplier_state'],
    $countries[$supplier['supplier_country'] ?? ''] ?? $supplier['supplier_country'],
], static fn($v) => trim((string) $v) !== ''));

$expense_total   = (float) $supplier['expense_total'];
$expense_balance = (float) $supplier['expense_balance'];
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

            <?php /* Stessa impaginazione della scheda cliente: tessera con i
                     codici da copiare, striscia di cifre, contatti e note
                     affiancati. Prima il fornitore metteva codici, contatti e
                     IBAN in un'unica tabella a metà pagina, e le due schede
                     sembravano di due applicazioni diverse. */ ?>
            <div class="card ip-client-masthead mb-3">
                <div class="ip-client-masthead__body">
                    <div class="ip-client-id">
                        <h2 class="ip-client-name"><?= esc($supplier['supplier_name']) ?></h2>
                        <div class="ip-client-id__meta">
                            <?php if (!db_bool($supplier['supplier_active'])): ?>
                                <span class="badge ip-status text-bg-secondary"><?= trans('inactive', '', 'Non attivo') ?></span>
                            <?php endif; ?>
                            <?php if ($supplier_address !== ''): ?>
                                <span class="ip-client-id__loc">
                                    <i class="fa fa-solid fa-location-dot" aria-hidden="true"></i> <?= esc($supplier_address) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($supplier['supplier_date_created']) && $supplier['supplier_date_created'] !== '0000-00-00 00:00:00'): ?>
                            <p class="ip-client-since">
                                <i class="fa fa-regular fa-clock" aria-hidden="true"></i>
                                <?= trans('supplier_since', '', 'Fornitore dal') ?> <?= esc(datetime_from_mysql($supplier['supplier_date_created'], false)) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <div class="ip-client-codes">
                        <?php
                        foreach ([
                            [trans('vat_id', '', 'Partita IVA'), $supplier['supplier_vat_id']],
                            [trans('tax_code', '', 'Codice fiscale'), $supplier['supplier_tax_code']],
                            [trans('sdi_code', '', 'Codice destinatario'), $supplier['supplier_sdi_code']],
                            // L'IBAN è il codice che si copia di più per un
                            // fornitore: è quello del bonifico.
                            [trans('iban', '', 'IBAN'), $supplier['supplier_iban']],
                        ] as [$label, $value]) {
                            if (trim((string) $value) !== '') {
                                echo fisc_code_block($label, (string) $value);
                            }
                        }
                        echo fisc_code_block(trans('supplier_code', '', 'Codice fornitore'), (string) $id);
                        ?>
                    </div>
                </div>
            </div>

            <div class="card ip-client-summary mb-3">
                <div class="card-header ip-table-card-header">
                    <div class="ip-table-card-title">
                        <span class="card-header-icon"><i class="fa fa-solid fa-line-chart" aria-hidden="true"></i></span>
                        <span class="ip-table-card-label"><?= trans('summary', '', 'Riepilogo') ?></span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="ip-client-stats">
                        <div class="ip-client-stat">
                            <div class="ip-client-stat-label"><i class="fa fa-solid fa-file-invoice-dollar" aria-hidden="true"></i> <?= trans('total_spent', '', 'Totale speso') ?></div>
                            <div class="ip-client-stat-value"><?= format_currency($expense_total) ?></div>
                        </div>
                        <div class="ip-client-stat">
                            <div class="ip-client-stat-label"><i class="fa fa-solid fa-check-circle" aria-hidden="true"></i> <?= trans('expenses_paid_total', '', 'Totale pagato') ?></div>
                            <div class="ip-client-stat-value ip-client-stat-value--success"><?= format_currency($expense_total - $expense_balance) ?></div>
                        </div>
                        <div class="ip-client-stat">
                            <div class="ip-client-stat-label"><i class="fa fa-solid fa-balance-scale" aria-hidden="true"></i> <?= trans('total_balance', '', 'Totale saldo') ?></div>
                            <div class="ip-client-stat-value<?= $expense_balance > 0 ? ' ip-client-stat-value--warning' : '' ?>"><?= format_currency($expense_balance) ?></div>
                        </div>
                        <div class="ip-client-stat">
                            <div class="ip-client-stat-label"><i class="fa fa-solid fa-receipt" aria-hidden="true"></i> <?= trans('expenses_count_label', '', 'Spese registrate') ?></div>
                            <div class="ip-client-stat-value"><?= (int) $supplier['expense_count'] ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-12 col-md-6">
                    <div class="card no-margin h-100">
                        <div class="card-header ip-table-card-header">
                            <div class="ip-table-card-title">
                                <span class="card-header-icon"><i class="fa fa-regular fa-address-book" aria-hidden="true"></i></span>
                                <span class="ip-table-card-label"><?= trans('contact_information') ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (array_filter([$supplier['supplier_email'], $supplier['supplier_phone'], $supplier['supplier_web'], $supplier['supplier_sdi_pec']])): ?>
                                <ul class="ip-contact-list">
                                    <?php if ($supplier['supplier_email']): ?>
                                        <li>
                                            <span class="ip-contact-list__ic"><i class="fa fa-regular fa-envelope" aria-hidden="true"></i></span>
                                            <a href="mailto:<?= esc($supplier['supplier_email']) ?>"><?= esc($supplier['supplier_email']) ?></a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($supplier['supplier_sdi_pec']): ?>
                                        <li>
                                            <span class="ip-contact-list__ic"><i class="fa fa-solid fa-paper-plane" aria-hidden="true"></i></span>
                                            <a href="mailto:<?= esc($supplier['supplier_sdi_pec']) ?>"><?= esc($supplier['supplier_sdi_pec']) ?></a>
                                            <span class="text-muted">(<?= trans('sdi_pec', '', 'PEC') ?>)</span>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($supplier['supplier_phone']): ?>
                                        <li>
                                            <span class="ip-contact-list__ic"><i class="fa fa-solid fa-phone" aria-hidden="true"></i></span>
                                            <a href="tel:<?= esc($supplier['supplier_phone']) ?>"><?= esc($supplier['supplier_phone']) ?></a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($supplier['supplier_web']): ?>
                                        <?php $webUrl = preg_match('#^https?://#i', (string) $supplier['supplier_web']) ? $supplier['supplier_web'] : 'https://' . $supplier['supplier_web']; ?>
                                        <li>
                                            <span class="ip-contact-list__ic"><i class="fa fa-solid fa-earth" aria-hidden="true"></i></span>
                                            <a href="<?= esc($webUrl) ?>" target="_blank" rel="noopener"><?= esc($supplier['supplier_web']) ?></a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            <?php else: ?>
                                <p class="ip-card-empty"><?= trans('no_data_available', '', 'Nessun dato') ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-md-6">
                    <div class="card no-margin h-100">
                        <div class="card-header ip-table-card-header">
                            <div class="ip-table-card-title">
                                <span class="card-header-icon"><i class="fa fa-regular fa-sticky-note" aria-hidden="true"></i></span>
                                <span class="ip-table-card-label"><?= trans('notes') ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php // Il fornitore ha una nota sola, nel campo dell'anagrafica:
                                  // niente registro né modulo di aggiunta come per il cliente. ?>
                            <?php if (trim((string) $supplier['supplier_note']) !== ''): ?>
                                <div class="ip-note-list">
                                    <article class="ip-note">
                                        <p class="ip-note__text"><?= nl2br(esc($supplier['supplier_note'])) ?></p>
                                    </article>
                                </div>
                            <?php else: ?>
                                <p class="ip-card-empty"><?= trans('no_notes') ?></p>
                            <?php endif; ?>
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
                                        <a class="badge ip-status text-bg-info ip-badge-tag" href="<?= site_url('expenses/preview_xml/' . (int) $e['expense_id']) ?>" title="<?= esc(trans('xml_preview', '', 'Anteprima fattura elettronica'), 'attr') ?>">XML</a>
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
                                    <div class="empty-actions">
                                        <a href="<?= site_url('expenses/form') ?>?supplier_id=<?= $id ?>" class="empty-cta">
                                            <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('add_expense', '', 'Nuova spesa') ?>
                                        </a>
                                    </div>
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

<?= $this->include('layout/copy_code_script') ?>

<?= $this->endSection() ?>
