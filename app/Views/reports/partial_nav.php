<?php
/**
 * Breadcrumb dei report — ritorno all'hub.
 *
 * Dalla pagina di un report non c'era modo di tornare all'elenco se non
 * ripassando dal menu: quattro pagine sorelle senza un filo che le leghi.
 *
 * Uso:
 *   <?= $this->include('reports/partial_nav', ['current' => trans('invoice_aging')]) ?>
 */
$_rn     = (array) ($this->renderVars['options'] ?? []);
$current = $_rn['current'] ?? ($current ?? '');
?>
<nav class="ip-report-nav" aria-label="<?= esc(trans('reports'), 'attr') ?>">
    <a href="<?= site_url('reports') ?>"><?= trans('reports') ?></a>
    <span class="ip-report-nav__sep" aria-hidden="true"><i class="fa fa-solid fa-chevron-right" aria-hidden="true"></i></span>
    <span class="ip-report-nav__current"><?= esc($current) ?></span>
</nav>
