<?php
/**
 * Partial campi personalizzati del cliente.
 *
 * Lo stesso blocco stava scritto cinque volte dentro clients/form.php, una per
 * ogni riquadro del form (anagrafica, indirizzo, contatti, fatturazione, altro):
 * cinque copie identiche che si distinguevano solo per il valore di
 * custom_field_location su cui filtravano. Con cinque copie una correzione ne
 * sistemava un quinto — e infatti la label scollegata dal campo era finita in
 * tutte e cinque.
 *
 * Uso (dentro clients/form.php):
 *
 *   <?= $this->include('clients/partial_custom_fields', ['location' => 1]) ?>
 *
 * Parametri:
 *   location  int  valore di custom_field_location del riquadro (0 = "altro")
 *
 * Le variabili $custom_fields, $custom_values e $model arrivano dalla view che
 * include il partial (stesso scope della view chiamante in CI4).
 *
 * Il valore salvato per SINGLE-/MULTIPLE-CHOICE e' l'id dell'opzione
 * (custom_values_id), non il testo: chi rilegge il campo deve risolverlo, ci
 * pensa ClientCustomModel::getValuesByClient().
 */
$_cf_location = (int) (($this->renderVars['options']['location'] ?? $location ?? 0));
?>
<?php foreach ($custom_fields as $field): ?>
    <?php if ((int) ($field['custom_field_location'] ?? 0) !== $_cf_location) {
        continue;
    } ?>
    <?php
    $fieldId    = $field['custom_field_id'];
    $fieldValue = $model->formValue('custom[' . $fieldId . ']');
    // MULTIPLE-CHOICE esce come lista di checkbox (vedi CLAUDE.md): li'
    // l'etichetta e' il titolo di un gruppo, non la label di un singolo
    // controllo, quindi diventa <span> + aria-labelledby sul contenitore.
    $fieldCtl   = 'custom_' . $fieldId;
    $fieldMulti = $field['custom_field_type'] === 'MULTIPLE-CHOICE' && !empty($custom_values[$fieldId]);
    ?>
    <div class="ip-doc-field"<?= $fieldMulti ? ' role="group" aria-labelledby="' . $fieldCtl . '-label"' : '' ?>>
        <?php if ($fieldMulti): ?>
            <span class="ip-doc-label" id="<?= $fieldCtl ?>-label"><?= esc($field['custom_field_label']) ?></span>
        <?php else: ?>
            <label class="ip-doc-label" for="<?= $fieldCtl ?>"><?= esc($field['custom_field_label']) ?></label>
        <?php endif; ?>

        <?php if ($field['custom_field_type'] === 'SINGLE-CHOICE' && !empty($custom_values[$fieldId])): ?>
            <select name="custom[<?= $fieldId ?>]" id="<?= $fieldCtl ?>" class="form-control simple-select">
                <option value=""><?= trans('none') ?></option>
                <?php foreach ($custom_values[$fieldId] as $option): ?>
                    <option value="<?= esc($option['custom_values_id']) ?>"
                        <?php check_select($fieldValue, $option['custom_values_id']) ?>>
                        <?= esc($option['custom_values_value']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

        <?php elseif ($fieldMulti): ?>
            <?php $selected = array_filter(explode(',', (string) $fieldValue)); ?>
            <?php foreach ($custom_values[$fieldId] as $option): ?>
                <label class="ip-choice-option">
                    <input type="checkbox" name="custom[<?= $fieldId ?>][]"
                           value="<?= esc($option['custom_values_id']) ?>"
                        <?php check_select(in_array((string) $option['custom_values_id'], $selected, true), null, '==', true) ?>>
                    <?= esc($option['custom_values_value']) ?>
                </label>
            <?php endforeach; ?>

        <?php else: ?>
            <input type="text" name="custom[<?= $fieldId ?>]" id="<?= $fieldCtl ?>" class="form-control"
                   value="<?= esc(old('custom[' . $fieldId . ']', $fieldValue)) ?>">
        <?php endif; ?>
    </div>
<?php endforeach; ?>
