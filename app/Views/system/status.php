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

// Due gruppi invece di un elenco unico. Con dieci righe dello stesso peso un
// guasto e otto "regolare" chiedevano la stessa attenzione; separati, quello
// che va sistemato ha lo spazio per spiegarsi e il resto si scorre in un
// colpo d'occhio.
$daSistemare = array_values(array_filter($checks, static fn (array $c): bool => $c['state'] !== 'ok'));
$regolari    = array_values(array_filter($checks, static fn (array $c): bool => $c['state'] === 'ok'));

// Legenda del riepilogo: numero e parola concordati ("1 guasto", "2 guasti").
$legenda = [
    'error'   => [trans('system_status_error_one', '', 'guasto'), trans('system_status_error', '', 'guasti')],
    'warning' => [trans('system_status_warning', '', 'da controllare'), trans('system_status_warning', '', 'da controllare')],
    'ok'      => [trans('system_status_ok_one', '', 'regolare'), trans('system_status_ok', '', 'regolari')],
];

// I suggerimenti citano comandi tra accenti gravi (`php spark migrate`):
// mostrati così com'erano, gli accenti finivano nel testo e il comando non si
// distingueva dalla frase. Diventano <code>, dopo l'escape: l'accento grave
// non è un carattere che esc() tocca, quindi l'ordine è sicuro.
$conCodice = static fn (string $testo): string => (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', esc($testo));

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

        <div class="ip-status-hero__tally">
            <?php // Una tacca per controllo, nell'ordine della lista: è la
                  // stessa informazione della legenda, in forma di proporzione.
                  // Per questo è nascosta ai lettori di schermo, che la
                  // ricevono dai numeri qui sotto. ?>
            <div class="ip-status-strip" aria-hidden="true">
                <?php foreach ($checks as $check): ?>
                    <span class="ip-status-strip__cell ip-status-strip__cell--<?= esc($check['state'], 'attr') ?>"></span>
                <?php endforeach; ?>
            </div>

            <dl class="ip-status-hero__counts">
                <?php foreach ($legenda as $stato => [$uno, $molti]): ?>
                    <div class="ip-status-count ip-status-count--<?= esc($stato, 'attr') ?><?= $conteggi[$stato] === 0 ? ' is-zero' : '' ?>">
                        <dt><?= esc($conteggi[$stato] === 1 ? $uno : $molti) ?></dt>
                        <dd><span class="ip-status-dot" aria-hidden="true"></span><?= (int) $conteggi[$stato] ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </div>
    </section>

    <?php
    $gruppi = [];

    if ($daSistemare !== []) {
        $gruppi[] = ['problemi', trans('system_status_to_fix', '', 'Da sistemare'), 'fa-wrench', $daSistemare];
    }

    if ($regolari !== []) {
        $gruppi[] = ['regolari', trans('system_status_fine', '', 'Regolari'), 'fa-heart-pulse', $regolari];
    }
    ?>

    <?php foreach ($gruppi as [$gruppo, $etichetta, $iconaGruppo, $righe]): ?>
        <div class="card ip-table-card ip-status-card ip-status-card--<?= esc($gruppo, 'attr') ?>">
            <div class="card-header ip-table-card-header">
                <div class="ip-table-card-title">
                    <span class="card-header-icon"><i class="fa fa-solid <?= esc($iconaGruppo, 'attr') ?>" aria-hidden="true"></i></span>
                    <h2 class="ip-table-card-label" id="stato-gruppo-<?= esc($gruppo, 'attr') ?>"><?= esc($etichetta) ?></h2>
                    <span class="badge text-bg-secondary"><?= count($righe) ?></span>
                </div>
            </div>

            <ul class="ip-status-list" role="list" aria-labelledby="stato-gruppo-<?= esc($gruppo, 'attr') ?>">
                <?php foreach ($righe as $check):
                    [$classe, $icona, $testo] = $badges[$check['state']] ?? $badges['warning'];
                    $mostraBackup = $check['key'] === 'backup' && !empty($backup);
                    ?>
                    <li class="ip-status-row ip-status-row--<?= esc($check['state'], 'attr') ?>">
                        <span class="ip-status-row__icon" aria-hidden="true">
                            <i class="fa fa-solid <?= esc($icona, 'attr') ?>"></i>
                        </span>

                        <h3 class="ip-status-row__label"><?= esc($check['label']) ?></h3>

                        <?php // Il timbro (.ip-status) al posto del badge pieno: è la forma
                              // che gli stati hanno nel resto dell'app, e dieci chip a tinta
                              // piena uno sotto l'altro pesavano più del testo che qualificano. ?>
                        <span class="badge <?= esc($classe, 'attr') ?> ip-status ip-status-row__state"><?= esc($testo) ?></span>

                        <div class="ip-status-row__body">
                            <p class="ip-status-row__detail"><?= $conCodice($check['detail']) ?></p>

                            <?php if (!empty($check['hint'])): ?>
                                <p class="ip-status-row__hint">
                                    <strong class="ip-status-row__hint-label"><?= esc(trans('system_status_what_to_do', '', 'Cosa fare')) ?></strong>
                                    <span><?= $conCodice($check['hint']) ?></span>
                                </p>
                            <?php endif; ?>

                            <?php if ($mostraBackup): ?>
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
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>

    <p class="ip-status-footnote">
        <?= esc(trans(
            'system_status_footer',
            '',
            'Questa pagina non modifica niente: legge lo stato e lo mostra. La procedura di aggiornamento, backup e ripristino è in docs/installazione-e-aggiornamento.md.'
        )) ?>
    </p>
</div>

<?= $this->endSection() ?>
