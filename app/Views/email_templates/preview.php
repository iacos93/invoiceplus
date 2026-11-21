<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
$pageTitle = trans('email_template_preview', '', 'Anteprima modello email');
$this->setVar('pageTitle', $pageTitle);

$actions = '<a class="btn btn-sm btn-secondary" href="' . site_url('email_templates') . '">'
         . '<i class="fa fa-solid fa-arrow-left" aria-hidden="true"></i> ' . trans('email_templates') . '</a>';
?>
<?= $this->include('layout/headerbar', ['title' => $pageTitle, 'actions' => $actions]) ?>

<div id="content">
    <div class="card">
        <div class="card-header"><?= trans('email_template_preview', '', 'Anteprima modello email') ?></div>
        <div class="card-body">
            <div class="text-muted small mb-3">
                <?php if ($from !== ''): ?>
                    <div><strong><?= trans('email_preview_from', '', 'Da') ?>:</strong> <?= esc($from) ?></div>
                <?php endif; ?>
                <div><strong><?= trans('email_preview_to', '', 'A') ?>:</strong> <?= esc(trans('email_preview_recipient', '', 'Mario Rossi <mario.rossi@example.test>')) ?></div>
                <div><strong><?= trans('subject') ?>:</strong> <?= esc($subject) ?></div>
                <?php if ($title !== ''): ?>
                    <div><strong><?= trans('email_template') ?>:</strong> <?= esc($title) ?></div>
                <?php endif; ?>
            </div>

            <?php /* Il corpo può essere HTML scritto a mano nel modello: nell'iframe
                     non esegue script e non eredita il CSS dell'applicazione, quindi
                     si vede come in un client di posta senza poter toccare la pagina. */ ?>
            <iframe sandbox
                    title="<?= esc(trans('email_template_preview', '', 'Anteprima modello email'), 'attr') ?>"
                    srcdoc="<?= esc($body, 'attr') ?>"
                    style="display:block;width:100%;height:70vh;min-height:520px;border:1px solid var(--ip-border);border-radius:var(--ip-radius-sm);background:#fff"></iframe>

            <p class="text-muted small mt-2 mb-0">
                <?= trans('email_preview_note', '', 'Anteprima con dati di esempio: i tag sono sostituiti da valori fittizi. I campi personalizzati ({{ip_cf_...}}) restano visibili perché dipendono dal cliente.') ?>
            </p>
        </div>
    </div>
</div>

<?= $this->endSection() ?>
