<?php
/**
 * Partial: barra filtri di una lista.
 *
 * Un solo componente per desktop e telefono: prima i gruppi erano scritti due
 * volte (uno nell'headerbar, uno nel sottomenu mobile) e le due copie
 * divergevano — il filtro "Preferiti" esisteva solo su desktop. Qui il markup
 * nasce da un elenco di gruppi, quindi le voci sono sempre le stesse ovunque.
 *
 * Parametri attesi:
 *   $groups array  elenco di [
 *       'label'   => string|null,   // etichetta del gruppo ("Stato", "Tipo")
 *       'buttons' => list<[
 *           'url'    => string,
 *           'label'  => string,
 *           'active' => bool,
 *           'icon'   => string|null, // es. 'fa-solid fa-star'
 *       ]>,
 *   ]
 */
$_lf    = (array) ($this->renderVars['options'] ?? []);
$groups = $_lf['groups'] ?? ($groups ?? []);
?>
<div class="ip-filter-bar">
    <?php foreach ($groups as $group): ?>
        <?php if (empty($group['buttons'])) {
            continue;
        } ?>
        <?php $groupLabel = (string) ($group['label'] ?? ''); ?>
        <div class="ip-filter-group">
            <?php if ($groupLabel !== ''): ?>
                <span class="ip-filter-group__label"><?= esc($groupLabel) ?></span>
            <?php endif; ?>
            <div class="btn-group btn-group-sm index-options"
                 role="group"
                 <?= $groupLabel !== '' ? 'aria-label="' . esc($groupLabel, 'attr') . '"' : '' ?>>
                <?php foreach ($group['buttons'] as $button): ?>
                    <?php $isActive = !empty($button['active']); ?>
                    <a href="<?= esc($button['url'] ?? '#', 'attr') ?>"
                       class="btn <?= $isActive ? 'btn-primary' : 'btn-secondary' ?>"
                       aria-pressed="<?= $isActive ? 'true' : 'false' ?>">
                        <?php if (!empty($button['icon'])): ?>
                            <i class="fa <?= esc($button['icon']) ?>" aria-hidden="true"></i>
                        <?php endif; ?>
                        <?= esc($button['label'] ?? '') ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
