<?php
/**
 * Stato vuoto dei report.
 *
 * Due casi diversi che prima davano lo stesso messaggio: il report non e'
 * ancora stato eseguito (si deve premere Esegui) oppure e' stato eseguito e
 * non ha prodotto righe (si devono allargare i filtri). Dire quale dei due e'
 * risparmia il giro a vuoto.
 *
 * Uso:
 *   <?= $this->include('reports/partial_empty', ['ran' => $has_results]) ?>
 *   <?= $this->include('reports/partial_empty', ['ran' => true, 'message' => '...', 'icon' => 'fa-solid fa-check']) ?>
 */
$_re     = (array) ($this->renderVars['options'] ?? []);
$ran     = (bool) ($_re['ran'] ?? ($ran ?? false));
$message = (string) ($_re['message'] ?? ($message ?? ''));
$icon    = (string) ($_re['icon'] ?? ($icon ?? ''));

if ($message === '') {
    $message = $ran
        ? trans('report_no_rows', '', "Nessun dato nel periodo scelto. Allarga l'intervallo e riesegui il report.")
        : trans('report_run_hint', '', 'Esegui il report per visualizzare i risultati.');
}

if ($icon === '') {
    $icon = $ran ? 'fa-solid fa-filter' : 'fa-solid fa-chart-column';
}
?>
<div class="empty-state card">
    <div class="card-body empty-body">
        <i class="fa <?= esc($icon, 'attr') ?> empty-state-icon" aria-hidden="true"></i>
        <p class="empty-state-text"><?= esc($message) ?></p>
    </div>
</div>
