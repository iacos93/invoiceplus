<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
$this->setVar('pageTitle', trans('system_status', '', 'Stato del sistema'));

// Uno stato = un colore, come per i timbri di documento: verde regolare,
// giallo da controllare, rosso guasto. Il testo non è solo colore, così si
// legge anche senza distinguere le tinte.
$badges = [
    'ok'      => ['text-bg-success', 'fa-circle-check', 'regolare'],
    'warning' => ['text-bg-warning', 'fa-triangle-exclamation', 'da controllare'],
    'error'   => ['text-bg-danger', 'fa-circle-xmark', 'guasto'],
];

// Conteggio per stato e stato peggiore: il riepilogo in testa deve
// rispondere a una domanda sola ("devo preoccuparmi?") prima che si legga
// una riga della lista.
$conteggi = ['ok' => 0, 'warning' => 0, 'error' => 0];

foreach ($checks as $check) {
    $conteggi[$check['state']] = ($conteggi[$check['state']] ?? 0) + 1;
}

$peggiore = $conteggi['error'] > 0
    ? 'error'
    : ($conteggi['warning'] > 0 ? 'warning' : 'ok');

if ($conteggi['error'] > 0) {
    $titolo = $conteggi['error'] === 1
        ? '1 controllo è guasto'
        : $conteggi['error'] . ' controlli sono guasti';
    $sottotitolo = ($conteggi['warning'] > 0
        ? 'Oltre a ' . $conteggi['warning'] . ($conteggi['warning'] === 1 ? ' controllo da controllare.' : ' controlli da controllare.')
        : 'Nessun altro controllo segnala problemi.')
        . ' Il dettaglio qui sotto dice cosa sistemare.';
} elseif ($conteggi['warning'] > 0) {
    $titolo = $conteggi['warning'] === 1
        ? '1 controllo richiede attenzione'
        : $conteggi['warning'] . ' controlli richiedono attenzione';
    $sottotitolo = 'Niente è rotto, ma qualcosa va guardato: il dettaglio dice cosa e come sistemarlo.';
} else {
    $titolo      = 'Tutto in ordine';
    $sottotitolo = 'Backup, schema, automazioni e permessi rispondono come previsto.';
}

// Le cose da sistemare in cima: la pagina si apre per trovarle.
$checks = \App\Services\SystemStatus::bySeverity($checks);

$azioni = '<a class="btn btn-sm btn-secondary" href="' . site_url('system/status') . '">'
    . '<i class="fa fa-solid fa-rotate" aria-hidden="true"></i> '
    . esc(trans('refresh', '', 'Aggiorna')) . '</a>';

$rilevato = '<i class="fa-regular fa-clock" aria-hidden="true"></i> Rilevato il '
    . date('d/m/Y') . ' alle ' . date('H:i');
?>
<?= $this->include('layout/headerbar', [
    'title'   => trans('system_status', '', 'Stato del sistema'),
    'actions' => $azioni,
    'meta'    => $rilevato,
]) ?>

<div id="content" class="table-content">

    <section class="ip-status-hero ip-status-hero--<?= esc($peggiore, 'attr') ?>" aria-labelledby="stato-sintesi">
        <span class="ip-status-hero__icon" aria-hidden="true">
            <i class="fa fa-solid <?= esc($badges[$peggiore][1], 'attr') ?>"></i>
        </span>

        <div class="ip-status-hero__body">
            <p class="ip-status-hero__eyebrow"><?= esc(trans('system_status_summary', '', 'Esito dei controlli')) ?></p>
            <h2 class="ip-status-hero__title" id="stato-sintesi"><?= esc($titolo) ?></h2>
            <p class="ip-status-hero__lead"><?= esc($sottotitolo) ?></p>
        </div>

        <dl class="ip-status-hero__counts">
            <div class="ip-status-count ip-status-count--ok">
                <dt><span class="ip-status-dot" aria-hidden="true"></span><?= esc(trans('system_status_ok', '', 'regolari')) ?></dt>
                <dd><?= (int) $conteggi['ok'] ?></dd>
            </div>
            <div class="ip-status-count ip-status-count--warning">
                <dt><span class="ip-status-dot" aria-hidden="true"></span><?= esc(trans('system_status_warning', '', 'da controllare')) ?></dt>
                <dd><?= (int) $conteggi['warning'] ?></dd>
            </div>
            <div class="ip-status-count ip-status-count--error">
                <dt><span class="ip-status-dot" aria-hidden="true"></span><?= esc(trans('system_status_error', '', 'guasti')) ?></dt>
                <dd><?= (int) $conteggi['error'] ?></dd>
            </div>
        </dl>
    </section>

    <div class="card ip-table-card ip-status-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-heart-pulse" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('system_status_checks', '', 'Controlli')) ?></span>
                <span class="badge text-bg-secondary"><?= count($checks) ?></span>
            </div>
        </div>

        <ul class="ip-status-list" role="list" aria-label="<?= esc(trans('system_status_caption', '', 'Controlli sull\'installazione: PHP, database, schema, permessi, backup e automazioni'), 'attr') ?>">
            <?php foreach ($checks as $check):
                [$classe, $icona, $testo] = $badges[$check['state']] ?? $badges['warning'];
                ?>
                <li class="ip-status-row ip-status-row--<?= esc($check['state'], 'attr') ?>">
                    <span class="ip-status-row__icon" aria-hidden="true">
                        <i class="fa fa-solid <?= esc($icona, 'attr') ?>"></i>
                    </span>

                    <div class="ip-status-row__body">
                        <div class="ip-status-row__head">
                            <h3 class="ip-status-row__label"><?= esc($check['label']) ?></h3>
                            <span class="badge <?= esc($classe, 'attr') ?>">
                                <i class="fa fa-solid <?= esc($icona, 'attr') ?>" aria-hidden="true"></i>
                                <?= esc($testo) ?>
                            </span>
                        </div>

                        <p class="ip-status-row__detail"><?= esc($check['detail']) ?></p>

                        <?php if ($check['key'] === 'backup' && !empty($backup)): ?>
                            <div class="ip-status-row__actions">
                                <a class="btn btn-sm btn-secondary" href="<?= site_url('system/status/backup') ?>" download="<?= esc($backup['name'], 'attr') ?>">
                                    <i class="fa fa-solid fa-download" aria-hidden="true"></i>
                                    <?= esc(trans('system_status_backup_download', '', 'Scarica l\'ultimo backup')) ?>
                                </a>
                                <span class="ip-status-row__actions-note">
                                    <?= esc(trans('system_status_backup_download_note', '', 'Salvalo fuori dal server: in writable/backups viene ruotato e resta sullo stesso disco dell\'applicazione.')) ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($check['hint'])): ?>
                            <p class="ip-status-row__hint">
                                <i class="fa fa-solid fa-circle-info" aria-hidden="true"></i>
                                <span><?= esc($check['hint']) ?></span>
                            </p>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <p class="text-body-secondary small mt-3">
        <?= esc(trans(
            'system_status_footer',
            '',
            'Questa pagina non modifica niente: legge lo stato e lo mostra. La procedura di aggiornamento, backup e ripristino è in docs/installazione-e-aggiornamento.md.'
        )) ?>
    </p>
</div>

<?= $this->endSection() ?>
