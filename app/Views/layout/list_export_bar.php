<?php
/**
 * Partial: barra di esportazione in coda a un elenco.
 *
 * Stessa forma su fatture e spese: le etichette cambiano, il componente no.
 * I link sono GET, così l'esportazione porta con sé anno e mesi già scelti
 * nella barra periodo senza dover ricompilare un form.
 *
 * Parametri attesi:
 *   $links array elenco di ['url' => string, 'label' => string,
 *                           'icon' => string (classe Font Awesome)]
 *   $empty bool  true quando la lista non ha righe: non c'è niente da
 *                esportare, quindi la barra non compare (stessa scelta del
 *                piede totali).
 */
$_le   = (array) ($this->renderVars['options'] ?? []);
$links = $_le['links'] ?? ($links ?? []);
$empty = (bool) ($_le['empty'] ?? ($empty ?? false));

if ($links === [] || $empty) {
    return;
}
?>
<div class="ip-list-export">
    <span class="ip-list-export__label">
        <i class="fa fa-solid fa-download" aria-hidden="true"></i>
        <?= trans('export', '', 'Esporta') ?>
    </span>
    <?php foreach ($links as $link): ?>
        <a class="btn btn-sm btn-secondary" href="<?= esc($link['url'] ?? '#', 'attr') ?>">
            <i class="fa <?= esc($link['icon'] ?? 'fa-solid fa-file-arrow-down', 'attr') ?>" aria-hidden="true"></i>
            <?= esc($link['label'] ?? '') ?>
        </a>
    <?php endforeach; ?>
</div>
