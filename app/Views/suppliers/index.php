<?php $this->setVar('pageTitle', trans('suppliers', '', 'Fornitori')); ?>
<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php /* Stessa barra dei clienti (partial condiviso): schede, stato e comandi
         restano allineati, cambia solo l'elenco sotto. */ ?>
<?= $this->include('layout/anagrafica_bar', ['entity' => 'suppliers', 'status' => $status]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-shopping-basket" aria-hidden="true"></i></span>
                <h2 class="ip-table-card-label"><?= esc(trans('suppliers', '', 'Fornitori')) ?></h2>
                <span class="badge text-bg-secondary"><?= count($suppliers) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search" data-target="#suppliers-list-table">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca fornitore…')) ?>" autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>

        <div class="table-responsive">
            <?php /* ip-table-card-collapse non è un dettaglio: è la classe che
                     sotto i 576px trasforma le righe in schede usando i
                     data-label. Senza, la tabella dei fornitori restava
                     larga 900px su un telefono mentre quella dei clienti
                     diventava una lista. */ ?>
            <table class="table table-hover ip-clickable-rows ip-table-card-collapse" id="suppliers-list-table">
                <thead>
                <tr>
                    <th scope="col"><?= trans('supplier', '', 'Fornitore') ?></th>
                    <th scope="col"><?= trans('email', '', 'Email') ?></th>
                    <th class="amount" scope="col"><?= trans('expenses', '', 'Spese') ?></th>
                    <th class="amount" scope="col"><?= trans('total', '', 'Totale') ?></th>
                    <th class="amount" scope="col"><?= trans('balance', '', 'Da pagare') ?></th>
                    <th scope="col"><?= trans('options', '', 'Opzioni') ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($suppliers as $supplier): ?>
                    <?php $open = (float) $supplier['expense_balance']; ?>
                    <tr class="<?= !db_bool($supplier['supplier_active']) ? 'ip-status-empty' : '' ?>">
                        <?= $this->include('layout/anagrafica_nome', [
                            'label'  => trans('supplier', '', 'Fornitore'),
                            'url'    => site_url('suppliers/view/' . (int) $supplier['supplier_id']),
                            'name'   => $supplier['supplier_name'],
                            'active' => db_bool($supplier['supplier_active']),
                            // La P. IVA sta sotto il nome come il tipo cliente nei
                            // clienti: da colonna a sé faceva dei fornitori una
                            // tabella di sei colonne fitte contro le quattro
                            // larghe dei clienti, e in monospaziato pesava più
                            // del nome che deve guidare la riga.
                            'meta'   => !empty($supplier['supplier_vat_id'])
                                ? '<span class="ip-num">' . esc($supplier['supplier_vat_id']) . '</span>'
                                : '',
                            // Stessa scheda compatta delle fatture e delle
                            // spese: nome in alto a sinistra, totale a destra,
                            // P. IVA sulla seconda riga, residuo sulla terza.
                            'cell'   => 'number',
                        ]) ?>
                        <?php /* Email sulla seconda riga della scheda, come nei
                                 clienti; numero documenti e opzioni restano
                                 nella vista tabellare. */ ?>
                        <td data-cell="client" data-label="<?= esc(trans('email', '', 'Email')) ?>"><?= $supplier['supplier_email'] ? esc($supplier['supplier_email']) : '<span class="ip-cell-empty">—</span>' ?></td>
                        <?php // Conteggio, non importo: resta nella colonna numerica
                              // ma spento (.ip-col-count), altrimenti il "4" e il totale
                              // in euro affiancati sembravano la stessa grandezza. ?>
                        <td class="amount ip-hide-on-mobile ip-col-count" data-label="<?= esc(trans('expenses', '', 'Spese')) ?>"><?= (int) $supplier['expense_count'] ?></td>
                        <td class="amount" data-cell="amount" data-label="<?= esc(trans('total', '', 'Totale')) ?>"><?= format_currency($supplier['expense_total']) ?></td>
                        <?php
                        // Il residuo si mostra con il proprio segno: con un
                        // saldo a credito (note di credito ricevute non ancora
                        // rimborsate) il valore è negativo, e con il vecchio
                        // "$open > 0" spariva dietro un trattino come se il
                        // fornitore fosse in pari. È la stessa regola della
                        // lista clienti e della lista spese.
                        ?>
                        <td class="amount" data-cell="balance" data-label="<?= esc(trans('balance', '', 'Da pagare')) ?>"><?= round($open, 2) != 0.0 ? format_currency($open) : '<span class="ip-cell-empty">—</span>' ?></td>
                        <td class="ip-hide-on-mobile" data-label="<?= esc(trans('options', '', 'Opzioni')) ?>">
                            <div class="options btn-group">
                                <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options', '', 'Opzioni') ?>
                                </button>
                                <ul class="dropdown-menu">
                                    <li><a href="<?= site_url('suppliers/view/' . (int) $supplier['supplier_id']) ?>">
                                        <i class="fa fa-solid fa-eye fa-margin" aria-hidden="true"></i> <?= trans('view', '', 'Apri') ?></a></li>
                                    <li><a href="<?= site_url('suppliers/form/' . (int) $supplier['supplier_id']) ?>">
                                        <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit', '', 'Modifica') ?></a></li>
                                    <li><a href="<?= site_url('expenses/form') ?>?supplier_id=<?= (int) $supplier['supplier_id'] ?>">
                                        <i class="fa fa-solid fa-plus fa-margin" aria-hidden="true"></i> <?= trans('add_expense', '', 'Nuova spesa') ?></a></li>
                                    <li>
                                        <form action="<?= site_url('suppliers/delete/' . (int) $supplier['supplier_id']) ?>" method="POST">
                                            <?= csrf_field() ?>
                                            <button type="button" class="dropdown-button" data-bs-toggle="confirm"
                                                    data-confirm-title="<?= esc(trans('confirm', '', 'Conferma'), 'attr') ?>"
                                                    data-confirm-body="<?= esc(trans('delete_supplier_warning', '', 'Eliminare il fornitore? Le spese collegate restano, perdono solo il collegamento.'), 'attr') ?>"
                                                    data-confirm-ok="<?= esc(trans('delete', '', 'Elimina'), 'attr') ?>" data-confirm-danger="1">
                                                <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete', '', 'Elimina') ?>
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php
                // Solo con la lista vuota: la riga empty-row fa nascondere
                // l'intestazione a tutta la tabella (vedi clients/index.php).
                ?>
                <?php if (empty($suppliers)): ?>
                    <?= $this->include('layout/empty_row', [
                        'colspan'   => 6,
                        'icon'      => 'fa-solid fa-shopping-basket',
                        'message'   => trans('no_suppliers', '', 'Nessun fornitore.'),
                        'cta_url'   => site_url('suppliers/form'),
                        'cta_label' => trans('add_supplier', '', 'Nuovo fornitore'),
                    ]) ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ===== Modal: Importa Fornitori ===== -->
<div class="modal fade" id="importSuppliersModal" tabindex="-1" aria-labelledby="importSuppliersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="importSuppliersModalLabel">
                    <i class="fa fa-solid fa-upload me-2" aria-hidden="true"></i><?= trans('import_suppliers', '', 'Importa fornitori da CSV') ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="<?= site_url('suppliers/import') ?>" method="POST" enctype="multipart/form-data" id="importSuppliersForm">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <p class="text-muted small">
                        <?= trans('import_csv_hint', '', 'Il file CSV deve avere le intestazioni in italiano o inglese. Delimitatore ; o , rilevato automaticamente. Codifica UTF-8 o ISO-8859-1.') ?>
                        <a href="<?= site_url('suppliers/export_template') ?>" class="d-block mt-1">
                            <i class="fa fa-regular fa-file-text" aria-hidden="true"></i> <?= trans('download_template', '', 'Scarica template CSV di esempio') ?>
                        </a>
                    </p>
                    <div class="mb-3">
                        <label for="import_file_suppliers" class="form-label"><?= trans('csv_file', '', 'File CSV') ?></label>
                        <input type="file" class="form-control" id="import_file_suppliers" name="import_file"
                               accept=".csv,.txt" required>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="update_existing_suppliers"
                               name="update_existing" value="1" checked>
                        <label class="form-check-label" for="update_existing_suppliers">
                            <?= trans('update_existing', '', 'Aggiorna i record già presenti (stessa P. IVA o nome)') ?>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= trans('cancel', '', 'Annulla') ?></button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-solid fa-upload" aria-hidden="true"></i> <?= trans('import', '', 'Importa') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
