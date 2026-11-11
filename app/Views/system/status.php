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
?>
<?= $this->include('layout/headerbar', ['title' => trans('system_status', '', 'Stato del sistema'), 'actions' => '']) ?>

<div id="content" class="table-content">
    <div class="card ip-table-card">
        <div class="card-header ip-table-card-header">
            <div class="ip-table-card-title">
                <span class="card-header-icon"><i class="fa fa-solid fa-heart-pulse" aria-hidden="true"></i></span>
                <span class="ip-table-card-label"><?= esc(trans('system_status', '', 'Stato del sistema')) ?></span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table ip-table-card-collapse">
                <caption class="visually-hidden">
                    <?= esc(trans('system_status_caption', '', 'Controlli sull\'installazione: PHP, database, schema, permessi, backup e automazioni')) ?>
                </caption>
                <thead>
                <tr>
                    <th scope="col"><?= esc(trans('system_status_check', '', 'Controllo')) ?></th>
                    <th scope="col"><?= esc(trans('status', '', 'Stato')) ?></th>
                    <th scope="col"><?= esc(trans('details', '', 'Dettaglio')) ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($checks as $check):
                    [$classe, $icona, $testo] = $badges[$check['state']] ?? $badges['warning'];
                    ?>
                    <tr>
                        <th scope="row"><?= esc($check['label']) ?></th>
                        <td>
                            <span class="badge <?= esc($classe, 'attr') ?>">
                                <i class="fa fa-solid <?= esc($icona, 'attr') ?>" aria-hidden="true"></i>
                                <?= esc($testo) ?>
                            </span>
                        </td>
                        <td>
                            <?= esc($check['detail']) ?>
                            <?php if (!empty($check['hint'])): ?>
                                <div class="text-body-secondary small mt-1"><?= esc($check['hint']) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
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
