<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('imports_exports', '', 'Importa / Esporta')) ?>
<?= $this->include('layout/headerbar', ['title' => trans('imports_exports', '', 'Importa / Esporta')]) ?>

<div id="content">

    <div class="ip-ie-intro">
        <p class="ip-ie-intro__eyebrow"><?= esc(trans('imports_exports', '', 'Importa / Esporta')) ?> · <?= esc(trans('data_portability', '', 'Portabilità dati')) ?></p>
        <h2 class="ip-ie-intro__title"><?= esc(trans('ie_intro_title', '', 'Sposta i dati senza riscriverli.')) ?></h2>
        <p class="ip-ie-intro__desc">
            <?= esc(trans('ie_intro_desc', '', 'Due direzioni, stesso registro: dentro dal CSV quando parti da un foglio esistente, fuori in CSV / ZIP quando il commercialista o l’archivio lo chiedono. Le scorciatoie rapide restano nelle anagrafiche dove le usi davvero.')) ?>
        </p>
    </div>

    <div class="ip-ie-grid">

        <!-- Importa -->
        <article class="ip-ie-card ip-ie-card--import">
            <div class="ip-ie-card__head">
                <div class="ip-ie-card__icon" aria-hidden="true"><i class="fa fa-solid fa-upload"></i></div>
                <div>
                    <p class="ip-ie-card__eyebrow"><?= esc(trans('inbound', '', 'Ingresso')) ?> · CSV</p>
                    <h3 class="ip-ie-card__title"><?= esc(trans('import_data', '', 'Importa dati')) ?></h3>
                    <p class="ip-ie-card__desc"><?= esc(trans('ie_import_desc', '', 'Carica clienti, fatture, righe e pagamenti già in tabella. Elabora sempre nell’ordine giusto, riconosce i documenti già presenti e registra ogni esecuzione.')) ?></p>
                </div>
            </div>

            <div class="ip-ie-card__meta">
                <p class="ip-ie-card__meta-label"><?= esc(trans('expected_files', '', 'File attesi')) ?></p>
                <div class="ip-ie-files" aria-label="file CSV">
                    <span class="ip-ie-file">clients.csv</span>
                    <span class="ip-ie-file">invoices.csv</span>
                    <span class="ip-ie-file">invoice_items.csv</span>
                    <span class="ip-ie-file">payments.csv</span>
                </div>
                <ul class="ip-ie-list">
                    <li><i class="fa fa-solid fa-check" aria-hidden="true"></i><span><?= esc(trans('ie_import_b1', '', 'Modello CSV scaricabile per ogni file, con le intestazioni attese')) ?></span></li>
                    <li><i class="fa fa-solid fa-check" aria-hidden="true"></i><span><?= esc(trans('ie_import_b2', '', 'Date e importi convertiti in lettura; righe già presenti segnalate e saltate')) ?></span></li>
                    <li><i class="fa fa-solid fa-check" aria-hidden="true"></i><span><?= esc(trans('ie_import_b3', '', 'Storico con righe ed errori, e annullamento dell’intera importazione')) ?></span></li>
                </ul>
            </div>

            <div class="ip-ie-card__actions">
                <a href="<?= site_url('import') ?>" class="btn btn-primary btn-sm">
                    <?= esc(trans('import_data', '', 'Importa dati')) ?> <i class="fa fa-solid fa-arrow-right ms-1" aria-hidden="true"></i>
                </a>
                <?php /* Stessa etichetta e icona del pulsante in testa alla
                         cronologia: "Vai al form" non diceva cosa si faceva, e
                         la nota "Cronologia + upload" accanto ripeteva i due
                         pulsanti a parole. Le note sono state tolte da entrambe
                         le card: quella di destra andava a capo da sola e le
                         due basi non erano più allineate. */ ?>
                <a href="<?= site_url('import/form') ?>" class="btn btn-secondary btn-sm">
                    <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= esc(trans('new_import', '', 'Nuova importazione')) ?>
                </a>
            </div>
        </article>

        <!-- Esporta -->
        <article class="ip-ie-card ip-ie-card--export">
            <div class="ip-ie-card__head">
                <div class="ip-ie-card__icon" aria-hidden="true"><i class="fa fa-solid fa-download"></i></div>
                <div>
                    <p class="ip-ie-card__eyebrow"><?= esc(trans('outbound', '', 'Uscita')) ?> · CSV · ZIP</p>
                    <h3 class="ip-ie-card__title"><?= esc(trans('exports', '', 'Esportazione')) ?></h3>
                    <p class="ip-ie-card__desc"><?= esc(trans('ie_export_desc', '', 'Estrai fatture, preventivi, clienti e prospetti per periodo. Pronto per Excel, per l’archivio PDF o per i file FatturaPA.')) ?></p>
                </div>
            </div>

            <div class="ip-ie-card__meta">
                <p class="ip-ie-card__meta-label"><?= esc(trans('formats', '', 'Formati')) ?></p>
                <div class="ip-ie-files">
                    <span class="ip-ie-file">CSV ; Excel</span>
                    <span class="ip-ie-file">ZIP PDF</span>
                    <span class="ip-ie-file">ZIP XML</span>
                </div>
                <ul class="ip-ie-list">
                    <li><i class="fa fa-solid fa-check" aria-hidden="true"></i><span><?= esc(trans('ie_export_b1', '', 'Filtri per periodo + periodi rapidi (anno/trimestre)')) ?></span></li>
                    <li><i class="fa fa-solid fa-check" aria-hidden="true"></i><span><?= esc(trans('ie_export_b2', '', 'Prospetti utile e imposte per categoria / per mese')) ?></span></li>
                </ul>
            </div>

            <?php /* Due pulsanti come nella card di ingresso: "Vai al form"
                     da una parte, "Prospetti" dall'altra. Il colore del
                     pulsante arrivava da uno style inline — l'unico della
                     pagina — e in tema scuro cambiava tinta al variare di
                     --ip-text, che non è un colore da pulsante. */ ?>
            <div class="ip-ie-card__actions">
                <a href="<?= site_url('exports') ?>" class="btn btn-primary btn-sm">
                    <?= esc(trans('exports', '', 'Esportazione')) ?> <i class="fa fa-solid fa-arrow-right ms-1" aria-hidden="true"></i>
                </a>
                <a href="<?= site_url('finance') ?>" class="btn btn-secondary btn-sm">
                    <?= esc(trans('finance', '', 'Utile e imposte')) ?>
                </a>
            </div>
        </article>

    </div>

    <!-- Scorciatoie rapide — ancorate dove l’azione ha senso -->
    <section class="ip-ie-shortcuts" aria-labelledby="ie-shortcuts-title">
        <div class="ip-ie-shortcuts__head">
            <h3 class="ip-ie-shortcuts__title" id="ie-shortcuts-title"><i class="fa fa-solid fa-bolt" aria-hidden="true"></i> <?= esc(trans('quick_import', '', 'Import rapido')) ?></h3>
            <p class="ip-ie-shortcuts__hint"><?= esc(trans('quick_import_hint', '', 'Dall’anagrafica: scarica il modello, incolla i dati e importa — senza passare dall’hub.')) ?></p>
        </div>
        <div class="ip-ie-shortcuts__body">
            <a href="<?= site_url('clients') ?>" class="ip-ie-shortcut">
                <span class="ip-ie-shortcut__left">
                    <span class="ip-ie-shortcut__icon"><i class="fa fa-solid fa-users" aria-hidden="true"></i></span>
                    <span>
                        <p class="ip-ie-shortcut__label"><?= esc(trans('clients', '', 'Clienti')) ?></p>
                        <p class="ip-ie-shortcut__sub"><?= esc(trans('ie_clients_sub', '', 'Esporta modello · Importa CSV')) ?></p>
                    </span>
                </span>
                <i class="fa fa-solid fa-chevron-right ip-ie-shortcut__arrow" aria-hidden="true"></i>
            </a>
            <a href="<?= site_url('suppliers') ?>" class="ip-ie-shortcut">
                <span class="ip-ie-shortcut__left">
                    <span class="ip-ie-shortcut__icon"><i class="fa fa-solid fa-shopping-basket" aria-hidden="true"></i></span>
                    <span>
                        <p class="ip-ie-shortcut__label"><?= esc(trans('suppliers', '', 'Fornitori')) ?></p>
                        <p class="ip-ie-shortcut__sub"><?= esc(trans('ie_suppliers_sub', '', 'Esporta modello · Importa CSV')) ?></p>
                    </span>
                </span>
                <i class="fa fa-solid fa-chevron-right ip-ie-shortcut__arrow" aria-hidden="true"></i>
            </a>
        </div>
    </section>

    <p class="ip-ie-foot">
        <i class="fa fa-solid fa-info-circle" aria-hidden="true"></i>
        <span><?= esc(trans('ie_foot', '', 'In uscita i CSV usano ; e UTF-8 con BOM, il formato che Excel in locale italiano legge senza chiedere nulla. In entrata separatore e codifica sono riconosciuti da soli: contano il nome del file e le intestazioni, che trovi nel modello scaricabile — quelle dei file esportati sono etichette in italiano e non valgono come tracciato d’importazione.')) ?></span>
    </p>

</div>

<?= $this->endSection() ?>
