<?php $this->setVar('pageTitle', trans('clients')); ?>
<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php /* Barra (schede anagrafica, stato, esporta/importa, nuovo) e sottomenu
         mobile arrivano dal partial condiviso con i fornitori: le due liste
         devono restare identiche in tutto ciò che non è la colonna dati. */ ?>
<?= $this->include('layout/anagrafica_bar', ['entity' => 'clients', 'status' => $status]) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-users" aria-hidden="true"></i></span>
                <h2 class="ip-table-card-label"><?= esc(trans('clients')) ?></h2>
                <span class="badge text-bg-secondary"><?= isset($pager) ? $pager->getTotal('ip_clients') : count($clients) ?></span>
            </div>
            <div class="ip-live-search ip-table-card-search"
                 data-target="#clients-table-wrap"
                 data-action="<?= site_url('filter/filter_clients') ?>">
                <i class="fa fa-solid fa-search" aria-hidden="true"></i>
                <input type="text" name="filter_query" class="form-control"
                       placeholder="<?= esc(trans('search', '', 'Cerca cliente, email, telefono…')) ?>"
                       autocomplete="off"
                       aria-label="<?= esc(trans('search', '', 'Cerca')) ?>">
            </div>
        </div>

        <div id="clients-table-wrap">
            <div class="table-responsive">
                <table class="table table-hover ip-clickable-rows ip-table-card-collapse">
                    <thead>
                    <tr>
                        <th scope="col"><?= trans('client_name') ?></th>
                        <th scope="col"><?= trans('email_address') ?></th>
                        <th scope="col"><?= trans('phone_number') ?></th>
                        <th class="amount" scope="col"><?= trans('balance') ?></th>
                        <th scope="col"><?= trans('options') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($clients as $client): ?>
                        <?php
                        // Stato e nome nella stessa cella, come nei fornitori: la
                        // colonna "Attivo" con Sì/No diceva la stessa cosa due volte
                        // (anche il filtro in alto la dice) e le due anagrafiche
                        // avevano così due larghezze di tabella diverse.
                        // Le celle vuote mostrano un trattino, non uno spazio muto:
                        // sul telefono una cella vuota è indistinguibile da un dato
                        // che non è stato caricato.
                        $clientName = $client['client_fullname'] ?: format_client($client);
                        $clientBal  = (float) ($client['client_invoice_balance'] ?? 0);
                        ?>
                        <tr class="<?= db_bool($client['client_active']) ? '' : 'ip-status-empty' ?>">
                            <?= $this->include('layout/anagrafica_nome', [
                                'label'  => trans('client_name'),
                                'url'    => site_url('clients/view/' . $client['client_id']),
                                'name'   => $clientName,
                                'active' => db_bool($client['client_active']),
                                'meta'   => client_type_badge($client['client_type'] ?? null),
                                // Ruolo della cella nella scheda mobile: stesso
                                // schema delle liste documenti (nome in alto a
                                // sinistra, saldo a destra, email sotto).
                                'cell'   => 'number',
                            ]) ?>
                            <td data-cell="client" data-label="<?= esc(trans('email_address')) ?>"><?= esc($client['client_email'] ?: '—') ?></td>
                            <?php // Telefono e opzioni restano nella vista tabellare:
                                  // sulla scheda sarebbero due righe in più, e il
                                  // numero/link al cliente aprono già la scheda. ?>
                            <td class="ip-hide-on-mobile" data-label="<?= esc(trans('phone_number')) ?>"><?= esc($client['client_phone'] ?: ($client['client_mobile'] ?: '—')) ?></td>
                            <td class="amount" data-cell="amount" data-label="<?= esc(trans('balance')) ?>"><?= $clientBal != 0.0 ? format_currency($clientBal) : '—' ?></td>
                            <td class="ip-hide-on-mobile" data-label="<?= esc(trans('options')) ?>">
                                <div class="options btn-group">
                                    <button type="button" class="btn btn-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                        <i class="fa fa-solid fa-cog" aria-hidden="true"></i> <?= trans('options') ?>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li>
                                            <a href="<?= site_url('clients/view/' . $client['client_id']) ?>">
                                                <i class="fa fa-solid fa-eye fa-margin" aria-hidden="true"></i> <?= trans('view') ?>
                                            </a>
                                        </li>
                                        <li>
                                            <a href="<?= site_url('clients/form/' . $client['client_id']) ?>">
                                                <i class="fa fa-regular fa-edit fa-margin" aria-hidden="true"></i> <?= trans('edit') ?>
                                            </a>
                                        </li>
                                        <li>
                                            <form action="<?= site_url('clients/delete/' . $client['client_id']) ?>"
                                                  method="POST">
                                                <?= csrf_field() ?>
                                                <button type="button" class="dropdown-button"
                                                        data-bs-toggle="confirm" data-confirm-title="<?= esc(trans('confirm', '', 'Conferma')) ?>" data-confirm-body="<?= esc(trans('delete_client_warning')) ?>" data-confirm-ok="<?= esc(trans('delete', '', 'Elimina')) ?>" data-confirm-danger="1">
                                                    <i class="fa fa-solid fa-trash-can fa-margin" aria-hidden="true"></i> <?= trans('delete') ?>
                                                </button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php
                    // Solo con la lista vuota. empty_row() emette SEMPRE una
                    // <tr class="empty-row">, e il CSS che nasconde l'intestazione
                    // delle tabelle vuote reagisce alla presenza di quella riga
                    // (.table:has(> tbody > tr.empty-row) > thead): incluso a
                    // ogni pagina, toglieva l'intestazione alla tabella piena e
                    // lasciava "Nessun cliente presente." in fondo all'elenco.
                    ?>
                    <?php if (empty($clients)): ?>
                        <?= $this->include('layout/empty_row', [
                            'colspan'   => 5,
                            'icon'      => 'fa-solid fa-users',
                            'message'   => trans('no_clients'),
                            'cta_url'   => site_url('clients/form'),
                            'cta_label' => trans('new_client', '', 'Nuovo cliente'),
                        ]) ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?= $this->include('layout/pager_footer', ['group' => 'ip_clients']) ?>
    </div>
</div>

<!-- ===== Modal: Importa Clienti ===== -->
<div class="modal fade" id="importClientsModal" tabindex="-1" aria-labelledby="importClientsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="importClientsModalLabel">
                    <i class="fa fa-solid fa-upload me-2" aria-hidden="true"></i><?= trans('import_clients', '', 'Importa clienti da CSV') ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="<?= site_url('clients/import') ?>" method="POST" enctype="multipart/form-data" id="importClientsForm">
                <?= csrf_field() ?>
                <div class="modal-body">
                    <p class="text-muted small">
                        <?= trans('import_csv_hint', '', 'Il file CSV deve avere le intestazioni in italiano o inglese. Delimitatore ; o , rilevato automaticamente. Codifica UTF-8 o ISO-8859-1.') ?>
                        <a href="<?= site_url('clients/export_template') ?>" class="d-block mt-1">
                            <i class="fa fa-regular fa-file-text" aria-hidden="true"></i> <?= trans('download_template', '', 'Scarica template CSV di esempio') ?>
                        </a>
                    </p>
                    <div class="mb-3">
                        <label for="import_file_clients" class="form-label"><?= trans('csv_file', '', 'File CSV') ?></label>
                        <input type="file" class="form-control" id="import_file_clients" name="import_file"
                               accept=".csv,.txt" required>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="update_existing_clients"
                               name="update_existing" value="1" checked>
                        <label class="form-check-label" for="update_existing_clients">
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
