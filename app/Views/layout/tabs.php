<?php
/**
 * Partial tabs — barra di navigazione a schede, condivisa dalle schermate a tab.
 *
 * Prima ogni schermata scriveva il proprio markup: le impostazioni usavano una
 * barra segmentata (.ip-settings-tabs), la scheda cliente le linguette Bootstrap
 * (.nav-tabs). Due componenti visivi diversi per la stessa funzione, e nessuno
 * dei due completo lato accessibilita': i pannelli non erano annunciati come
 * tabpanel, non erano legati alla propria scheda (aria-controls /
 * aria-labelledby) e nelle impostazioni mancava anche aria-selected, quindi
 * uno screen reader non sapeva dire quale scheda fosse aperta. Le frecce da
 * tastiera funzionavano gia': quelle le gestisce Bootstrap sul solo
 * data-bs-toggle, a prescindere dai ruoli.
 *
 * Uso:
 *
 *   <?= $this->include('layout/tabs', [
 *       'id'   => 'settings-tabs',       // id della barra (serve alla persistenza)
 *       'tabs' => [
 *           ['id' => 'settings-general',  'label' => trans('general')],
 *           ['id' => 'settings-invoices', 'label' => trans('invoices'), 'badge' => 12],
 *       ],
 *   ]) ?>
 *
 * Ogni pannello deve poi dichiararsi cosi':
 *
 *   <div id="settings-general" class="tab-pane active" role="tabpanel"
 *        aria-labelledby="tab-settings-general" tabindex="0">
 *
 * 'active' e' opzionale: senza, e' attivo il primo tab.
 */
$_t     = (array) ($this->renderVars['options'] ?? []);
$tabsId = $_t['id']     ?? ($id     ?? 'ip-tabs');
$tabs   = $_t['tabs']   ?? ($tabs   ?? []);
$active = $_t['active'] ?? ($active ?? null);

if ($tabs === []) {
    return;
}

$active ??= $tabs[0]['id'];
?>
<ul id="<?= esc($tabsId, 'attr') ?>" class="nav ip-tabs" role="tablist" data-ip-tabs="<?= esc($tabsId, 'attr') ?>">
    <?php foreach ($tabs as $tab): ?>
        <?php $isActive = $tab['id'] === $active; ?>
        <li class="nav-item" role="presentation">
            <a class="nav-link<?= $isActive ? ' active' : '' ?>"
               id="tab-<?= esc($tab['id'], 'attr') ?>"
               href="#<?= esc($tab['id'], 'attr') ?>"
               data-bs-toggle="tab"
               role="tab"
               aria-controls="<?= esc($tab['id'], 'attr') ?>"
               aria-selected="<?= $isActive ? 'true' : 'false' ?>">
                <?= esc($tab['label']) ?>
                <?php if (isset($tab['badge'])): ?>
                    <span class="ip-tab-badge"><?= esc((string) $tab['badge']) ?></span>
                <?php endif; ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
