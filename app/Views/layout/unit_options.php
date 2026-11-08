<?php
/**
 * Opzioni <option> raggruppate per tipo di unita' di misura, da inserire
 * dentro un <select name="item_product_unit_id"> (o product unit_id).
 *
 * Parametri:
 *   $unit_groups  array  struttura restituita da UnitModel::getGroupedByType()
 *   $selected     mixed  id unita' da preselezionare (default 0 = nessuna)
 *
 * Il <option value="0"> ("Nessuna") e' lasciato alla vista chiamante, cosi'
 * puo' scegliere etichetta e placeholder.
 */
$selected = $selected ?? 0;

foreach ($unit_groups as $group):
    if (empty($group['units'])):
        continue;
    endif;
?>
    <optgroup label="<?= esc($group['label'], 'attr') ?>">
        <?php foreach ($group['units'] as $unit): ?>
            <option value="<?= (int) $unit['unit_id'] ?>"<?= (int) $selected === (int) $unit['unit_id'] ? ' selected' : '' ?>>
                <?= esc($unit['unit_name'] . ' / ' . $unit['unit_name_plrl']) ?>
            </option>
        <?php endforeach; ?>
    </optgroup>
<?php endforeach; ?>
