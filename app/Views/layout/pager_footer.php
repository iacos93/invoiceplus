<?php
/**
 * Footer con il pager condiviso per le pagine elenco.
 *
 * Usato dalle viste lista subito prima della chiusura della card:
 *   <?= $this->include('layout/pager_footer', ['group' => 'ip_tax_rates']) ?>
 *
 * $this->include(view, [...]) in CodeIgniter 4 non estrae il secondo
 * parametro come variabili di vista (e' riservato alle render options,
 * es. cache): va letto da renderVars['options'], stesso trucco usato in
 * layout/headerbar.php.
 *
 * $pager arriva invece dallo scope della vista chiamante (dati del
 * controller), non serve passarlo esplicitamente.
 */
$_pf   = (array) ($this->renderVars['options'] ?? []);
$group = $_pf['group'] ?? ($group ?? '');

if (!isset($pager) || empty($group)) {
    return;
}

// Nessun footer se il pager ha una sola pagina: il card-footer resterebbe
// vuoto (e con il suo bordo) anche quando non c'e' alcuna paginazione da
// mostrare, ingolfando la card di una riga inutile.
if (method_exists($pager, 'getPageCount') && $pager->getPageCount($group) <= 1) {
    return;
}
?>
<div class="card-footer ip-table-card-footer d-flex justify-content-end">
    <?= $pager->links($group, 'ip_pager') ?>
</div>
