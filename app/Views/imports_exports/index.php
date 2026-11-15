<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php $this->setVar('pageTitle', trans('imports_exports', '', 'Importa / Esporta')) ?>
<?= $this->include('layout/headerbar', ['title' => trans('imports_exports', '', 'Importa / Esporta')]) ?>

<div id="content">

    <?php /* Pagina di smistamento: una riga per dire cosa si fa qui e due
             strade. Prima apriva con occhiello, slogan e tre righe di testo,
             e chiudeva con una nota larga quanto lo schermo: si leggeva molto
             prima di arrivare ai due pulsanti. L'occhiello ripeteva il titolo
             della pagina; la nota è stata divisa e messa nella card a cui
             ciascuna metà si riferisce. */ ?>
    <div class="ip-ie-intro">
        <h2 class="ip-ie-intro__title"><?= esc(trans('ie_intro_title', '', 'Porta dentro o fuori i tuoi dati')) ?></h2>
        <p class="ip-ie-intro__desc"><?= esc(trans('ie_intro_desc', '', 'Importa da CSV quando parti da un foglio esistente; esporta in CSV o ZIP quando lo chiede il commercialista o l’archivio.')) ?></p>
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
                <p class="ip-ie-card__note"><?= esc(trans('ie_import_note', '', 'Separatore e codifica vengono riconosciuti da soli. Contano il nome del file e le intestazioni del modello scaricabile: un file esportato da qui ha etichette in italiano e non vale come tracciato d’importazione.')) ?></p>
            </div>

            <?php /* Una sola azione principale per card: qui si viene per
                     caricare un file, quindi il pulsante pieno è "Nuova
                     importazione". La cronologia è la strada secondaria.
                     Prima i due pulsanti erano "Importa dati" e "Nuova
                     importazione", e non si capiva quale dei due importasse. */ ?>
            <div class="ip-ie-card__actions">
                <a href="<?= site_url('import/form') ?>" class="btn btn-primary btn-sm">
                    <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= esc(trans('new_import', '', 'Nuova importazione')) ?>
                </a>
                <a href="<?= site_url('import') ?>" class="btn btn-secondary btn-sm">
                    <i class="fa fa-solid fa-clock-rotate-left" aria-hidden="true"></i> <?= esc(trans('ie_history', '', 'Cronologia importazioni')) ?>
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
                <p class="ip-ie-card__note"><?= esc(trans('ie_export_note', '', 'I CSV usano ; e UTF-8 con BOM: il formato che Excel in italiano apre senza chiedere nulla.')) ?></p>
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
            <h3 class="ip-ie-shortcuts__title" id="ie-shortcuts-title"><?= esc(trans('ie_shortcuts_title', '', 'Solo un’anagrafica?')) ?></h3>
            <p class="ip-ie-shortcuts__hint"><?= esc(trans('ie_shortcuts_hint', '', 'Clienti e fornitori si importano anche dalla loro pagina: scarichi il modello, incolli i dati e carichi.')) ?></p>
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

</div>

<?= $this->endSection() ?>
