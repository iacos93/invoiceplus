<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('imports_exports', '', 'Importa / Esporta')) ?>
<?= $this->include('layout/headerbar', ['title' => trans('imports_exports', '', 'Importa / Esporta')]) ?>

<div id="content">

    <?php /* Pagina di smistamento ridotta all'osso: tre sezioni con la stessa
             card delle altre pagine (testata con icona, corpo, azioni). Prima
             aveva introduzione, occhielli, elenchi di vantaggi e note di
             formato in un sistema di card tutto suo; i dettagli sui tracciati
             stanno già nelle pagine di importazione ed esportazione, dove
             servono davvero. */ ?>
    <div class="row g-3 mb-3">

        <div class="col-lg-6">
            <section class="card h-100" aria-labelledby="ie-import-title">
                <div class="card-header ip-table-card-header">
                    <div class="ip-table-card-title">
                        <span class="card-header-icon"><i class="fa fa-solid fa-upload" aria-hidden="true"></i></span>
                        <h2 class="ip-table-card-label" id="ie-import-title"><?= esc(trans('import_data', '', 'Importa dati')) ?></h2>
                    </div>
                </div>
                <div class="card-body d-flex flex-column">
                    <p class="ip-ie-desc"><?= esc(trans('ie_import_desc', '', 'Carica clienti, fatture, righe e pagamenti già in tabella. Elabora sempre nell’ordine giusto, riconosce i documenti già presenti e registra ogni esecuzione.')) ?></p>
                    <ul class="ip-ie-files" aria-label="<?= esc(trans('expected_files', '', 'File attesi')) ?>">
                        <li>clients.csv</li>
                        <li>invoices.csv</li>
                        <li>invoice_items.csv</li>
                        <li>payments.csv</li>
                    </ul>
                    <div class="ip-ie-actions">
                        <a href="<?= site_url('import/form') ?>" class="btn btn-primary btn-sm">
                            <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= esc(trans('new_import', '', 'Nuova importazione')) ?>
                        </a>
                        <?php if (! empty($hasHistory)): ?>
                        <a href="<?= site_url('import') ?>" class="btn btn-secondary btn-sm">
                            <i class="fa fa-solid fa-clock-rotate-left" aria-hidden="true"></i> <?= esc(trans('ie_history', '', 'Cronologia importazioni')) ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="card h-100" aria-labelledby="ie-export-title">
                <div class="card-header ip-table-card-header">
                    <div class="ip-table-card-title">
                        <span class="card-header-icon"><i class="fa fa-solid fa-download" aria-hidden="true"></i></span>
                        <h2 class="ip-table-card-label" id="ie-export-title"><?= esc(trans('exports', '', 'Esportazione')) ?></h2>
                    </div>
                </div>
                <div class="card-body d-flex flex-column">
                    <p class="ip-ie-desc"><?= esc(trans('ie_export_desc', '', 'Estrai fatture, preventivi, clienti e prospetti per periodo. Pronto per Excel, per l’archivio PDF o per i file FatturaPA.')) ?></p>
                    <ul class="ip-ie-files" aria-label="<?= esc(trans('formats', '', 'Formati')) ?>">
                        <li>CSV</li>
                        <li>ZIP PDF</li>
                        <li>ZIP XML</li>
                    </ul>
                    <div class="ip-ie-actions">
                        <a href="<?= site_url('exports') ?>" class="btn btn-primary btn-sm">
                            <i class="fa fa-solid fa-download" aria-hidden="true"></i> <?= esc(trans('exports', '', 'Esportazione')) ?>
                        </a>
                        <a href="<?= site_url('finance') ?>" class="btn btn-secondary btn-sm">
                            <?= esc(trans('finance', '', 'Utile e imposte')) ?>
                        </a>
                    </div>
                </div>
            </section>
        </div>

    </div>

    <section class="card" aria-labelledby="ie-registry-title">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-address-book" aria-hidden="true"></i></span>
                <h2 class="ip-table-card-label" id="ie-registry-title"><?= esc(trans('ie_shortcuts_title', '', 'Solo un’anagrafica?')) ?></h2>
            </div>
        </div>
        <ul class="ip-ie-links">
            <li>
                <a href="<?= site_url('clients') ?>">
                    <span><?= esc(trans('clients', '', 'Clienti')) ?><small><?= esc(trans('ie_clients_sub', '', 'Esporta modello · Importa CSV')) ?></small></span>
                    <i class="fa fa-solid fa-chevron-right" aria-hidden="true"></i>
                </a>
            </li>
            <li>
                <a href="<?= site_url('suppliers') ?>">
                    <span><?= esc(trans('suppliers', '', 'Fornitori')) ?><small><?= esc(trans('ie_suppliers_sub', '', 'Esporta modello · Importa CSV')) ?></small></span>
                    <i class="fa fa-solid fa-chevron-right" aria-hidden="true"></i>
                </a>
            </li>
        </ul>
    </section>

</div>

<?= $this->endSection() ?>
