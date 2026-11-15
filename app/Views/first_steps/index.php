<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php
$this->setVar('pageTitle', trans('first_steps', '', 'Primi passi'));

/** @var list<array{key: string, group: string, label: string, why: string, url: string, done: bool}> $steps */
/** @var array{done: int, total: int} $progress */
$percent  = $progress['total'] > 0 ? (int) round($progress['done'] / $progress['total'] * 100) : 0;
$complete = $progress['done'] === $progress['total'];

$groups = [];
foreach ($steps as $step) {
    $groups[$step['group']][] = $step;
}

$dismissed = get_setting('first_steps_dismissed', '') === '1';
?>
<?= $this->include('layout/headerbar', ['title' => trans('first_steps', '', 'Primi passi')]) ?>

<div id="content" class="ip-first-steps">

    <?php /* Il riepilogo risponde a una domanda: a che punto sono? Barra e
             conteggio, non solo colore. */ ?>
    <section class="ip-first-steps__hero" aria-labelledby="fs-title">
        <div>
            <h2 id="fs-title" class="ip-first-steps__title">
                <?= $complete
                    ? esc(trans('first_steps_done', '', 'Installazione a punto'))
                    : esc(trans('first_steps_todo', '', 'Mettiamo a punto l\'installazione')) ?>
            </h2>
            <p class="ip-first-steps__lead">
                <?= $complete
                    ? esc(trans('first_steps_done_lead', '', 'Tutti i passi sono fatti. Puoi tornare qui quando vuoi: lo stato si ricava dai dati veri.'))
                    : esc((int) $progress['total'] . ' ' . trans('first_steps_lead', '', 'cose che conviene sistemare prima di emettere la prima fattura vera. Ogni voce porta alla pagina dove si fa; lo stato si aggiorna da solo.')) ?>
            </p>
        </div>
        <div class="ip-first-steps__meter" role="group" aria-label="<?= esc(trans('first_steps_progress', '', 'Avanzamento'), 'attr') ?>">
            <p class="ip-first-steps__count"><strong><?= (int) $progress['done'] ?></strong> / <?= (int) $progress['total'] ?></p>
            <div class="ip-first-steps__bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"
                 aria-valuenow="<?= $percent ?>" aria-valuetext="<?= (int) $progress['done'] ?> su <?= (int) $progress['total'] ?>">
                <span style="width: <?= $percent ?>%"></span>
            </div>
        </div>
    </section>

    <?php foreach ($groups as $group => $items): ?>
        <section class="ip-first-steps__group" aria-label="<?= esc($group, 'attr') ?>">
            <h3 class="ip-first-steps__group-title"><?= esc($group) ?></h3>
            <ul class="ip-first-steps__list">
                <?php foreach ($items as $step): ?>
                    <li class="ip-first-steps__item<?= $step['done'] ? ' is-done' : '' ?>">
                        <span class="ip-first-steps__mark" aria-hidden="true">
                            <i class="fa fa-solid <?= $step['done'] ? 'fa-check' : 'fa-circle' ?>"></i>
                        </span>
                        <div class="ip-first-steps__text">
                            <p class="ip-first-steps__label">
                                <?= esc($step['label']) ?>
                                <span class="visually-hidden">— <?= $step['done'] ? esc(trans('done', '', 'fatto')) : esc(trans('to_do', '', 'da fare')) ?></span>
                            </p>
                            <p class="ip-first-steps__why"><?= esc($step['why']) ?></p>
                        </div>
                        <a class="btn btn-sm <?= $step['done'] ? 'btn-secondary' : 'btn-primary' ?>" href="<?= site_url($step['url']) ?>">
                            <?= $step['done'] ? esc(trans('review', '', 'Rivedi')) : esc(trans('do_it', '', 'Fallo ora')) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>

    <?php if (! $dismissed): ?>
        <form method="post" action="<?= site_url('first_steps/dismiss') ?>" class="ip-first-steps__foot">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-secondary btn-sm">
                <?= esc(trans('first_steps_dismiss', '', 'Non mostrare più il promemoria in Bacheca')) ?>
            </button>
        </form>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
