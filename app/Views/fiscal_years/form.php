<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php
$this->setVar('pageTitle', trans('fiscal_year', '', 'Parametri fiscali'));

/**
 * Campo numerico con suffisso, per non ripetere dieci volte lo stesso markup.
 */
$field = static function (string $name, string $label, $value, string $suffix = '%', string $step = '0.01', string $help = '') use ($errors) {
    ?>
    <div class="ip-doc-field">
        <label class="ip-doc-label" for="<?= $name ?>"><?= esc($label) ?></label>
        <div class="ip-input-suffix">
            <input type="number" step="<?= esc($step, 'attr') ?>" min="0" name="<?= $name ?>" id="<?= $name ?>"
                   class="form-control" value="<?= esc($value) ?>">
            <span class="ip-input-suffix__mark"><?= esc($suffix) ?></span>
        </div>
        <?php if ($help !== ''): ?>
            <p class="ip-number-help"><?= esc($help) ?></p>
        <?php endif; ?>
        <?php if (!empty($errors[$name])): ?>
            <span class="ip-field-error"><?= esc($errors[$name]) ?></span>
        <?php endif; ?>
    </div>
    <?php
};

// Due righe vuote di scorta: aggiungere uno scaglione non deve richiedere di
// tornare qui una seconda volta.
$rows = $brackets;
$rows[] = ['limit' => null, 'rate' => null];
$rows[] = ['limit' => null, 'rate' => null];
?>
<form method="post" action="<?= site_url('fiscal_years/form/' . ($fiscal_id ?? '')) ?>">
    <?= csrf_field() ?>
    <?= $this->include('layout/headerbar', ['title' => trans('fiscal_year', '', 'Parametri fiscali'), 'actions' => header_actions()]) ?>

    <div id="content">
        <?= $this->include('layout/alerts') ?>

        <section class="ip-doc-section" id="sec-1">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">1</span>
                <h2 class="ip-doc-section__title"><?= trans('year', '', 'Anno') ?></h2>
                <span class="ip-doc-section__hint"><?= trans('used_by_finance', '', 'Usati dal report Utile e imposte') ?></span>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-grid">
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="fiscal_year"><?= trans('year', '', 'Anno') ?></label>
                        <input type="number" step="1" min="2000" max="2099" name="fiscal_year" id="fiscal_year"
                               class="form-control" value="<?= esc($model->formValue('fiscal_year') ?: date('Y')) ?>">
                        <?php if (!empty($errors['fiscal_year'])): ?>
                            <span class="ip-field-error"><?= esc($errors['fiscal_year']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="ip-doc-field">
                        <label class="ip-doc-label" for="fiscal_is_projection"><?= trans('data_status', '', 'Stato dei valori') ?></label>
                        <?php $proj = db_bool($model->formValue('fiscal_is_projection')) ? 1 : 0; ?>
                        <select name="fiscal_is_projection" id="fiscal_is_projection" class="form-control simple-select">
                            <option value="0" <?= $proj === 0 ? 'selected' : '' ?>><?= trans('in_force', '', 'In vigore — valori pubblicati') ?></option>
                            <option value="1" <?= $proj === 1 ? 'selected' : '' ?>><?= trans('projection', '', 'Proiezione — valori stimati') ?></option>
                        </select>
                        <p class="ip-number-help"><?= trans('projection_help', '', 'Marcando l\'anno come proiezione, il prospetto dichiara che la stima poggia su valori non ancora ufficiali.') ?></p>
                    </div>
                </div>
            </div>
        </section>

        <section class="ip-doc-section" id="sec-2">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">2</span>
                <h2 class="ip-doc-section__title"><?= trans('irpef_brackets', '', 'Scaglioni IRPEF') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <p class="ip-number-help" style="margin-top:0">
                    <?= trans('brackets_help', '', 'Ogni aliquota si applica solo alla quota che eccede lo scaglione precedente. Lascia vuota la soglia dell\'ultimo scaglione: è quello senza tetto. Le righe senza aliquota vengono ignorate.') ?>
                </p>
                <div class="ip-brackets-layout">
                    <div class="table-responsive">
                        <table class="ip-items-table ip-brackets">
                            <thead>
                            <tr>
                                <th scope="col"><?= trans('up_to', '', 'Fino a') ?></th>
                                <th scope="col" class="ip-items-table__total"><?= trans('rate', '', 'Aliquota') ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $b): ?>
                                <tr>
                                    <td>
                                        <input type="number" step="0.01" min="0" name="bracket_limit[]" class="form-control"
                                               aria-label="<?= esc(trans('bracket_limit', '', 'Soglia dello scaglione'), 'attr') ?>"
                                               placeholder="<?= esc(trans('no_ceiling', '', 'nessun tetto'), 'attr') ?>"
                                               value="<?= $b['limit'] === null ? '' : esc($b['limit']) ?>">
                                    </td>
                                    <td class="ip-items-table__total">
                                        <div class="ip-input-suffix">
                                            <input type="number" step="0.01" min="0" name="bracket_rate[]" class="form-control"
                                                   aria-label="<?= esc(trans('bracket_rate', '', 'Aliquota dello scaglione'), 'attr') ?>"
                                                   value="<?= $b['rate'] === null ? '' : esc($b['rate']) ?>">
                                            <span class="ip-input-suffix__mark">%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <aside aria-hidden="true">
                        <div class="ip-ladder" id="ip-ladder"></div>
                        <p class="ip-number-help" style="text-align:center;margin:8px 0 0">
                            <?= trans('ladder_caption', '', 'Scala progressiva: l\'altezza di ogni barra è l\'aliquota dello scaglione.') ?>
                        </p>
                    </aside>
                </div>
            </div>
        </section>

        <section class="ip-doc-section" id="sec-3">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">3</span>
                <h2 class="ip-doc-section__title"><?= trans('surcharges_contributions', '', 'Addizionali e contributi') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-grid">
                    <?php
                    $field('fiscal_add_regionale', trans('add_regionale', '', 'Addizionale regionale'), $model->formValue('fiscal_add_regionale') ?: '1.73', '%', '0.01',
                        trans('surcharge_help', '', 'Varia per regione e comune: qui va la media, o il valore della tua.'));
                    $field('fiscal_add_comunale', trans('add_comunale', '', 'Addizionale comunale'), $model->formValue('fiscal_add_comunale') ?: '0.50');
                    $field('fiscal_inps_gs_aliquota', trans('inps_gs', '', 'INPS gestione separata'), $model->formValue('fiscal_inps_gs_aliquota') ?: '26.23');
                    $field('fiscal_inps_art_aliquota', trans('inps_art_rate', '', 'INPS artigiani — aliquota'), $model->formValue('fiscal_inps_art_aliquota') ?: '24.48');
                    $field('fiscal_inps_art_minimale', trans('inps_minimale', '', 'INPS artigiani — minimale'), $model->formValue('fiscal_inps_art_minimale') ?: '0', '€', '1',
                        trans('minimale_help', '', 'Sotto questo reddito si versa comunque il contributo fisso.'));
                    $field('fiscal_inps_art_massimale', trans('inps_massimale', '', 'INPS artigiani — massimale'), $model->formValue('fiscal_inps_art_massimale') ?: '0', '€', '1',
                        trans('massimale_help', '', 'Oltre questo reddito la quota sull\'eccedenza non cresce più.'));
                    $field('fiscal_inps_art_fisso', trans('inps_fisso', '', 'INPS artigiani — contributo fisso'), $model->formValue('fiscal_inps_art_fisso') ?: '0', '€', '1');
                    ?>
                </div>
            </div>
        </section>

        <section class="ip-doc-section" id="sec-4">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step">4</span>
                <h2 class="ip-doc-section__title"><?= trans('company_flat_taxes', '', 'Imposte societarie e forfettario') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <div class="ip-doc-grid">
                    <?php
                    $field('fiscal_ires_aliquota', trans('ires', '', 'IRES'), $model->formValue('fiscal_ires_aliquota') ?: '24');
                    $field('fiscal_irap_aliquota', trans('irap', '', 'IRAP'), $model->formValue('fiscal_irap_aliquota') ?: '3.9');
                    $field('fiscal_forf_ordinaria', trans('forf_ordinary_rate', '', 'Forfettario — aliquota ordinaria'), $model->formValue('fiscal_forf_ordinaria') ?: '15');
                    $field('fiscal_forf_startup', trans('forf_startup_rate', '', 'Forfettario — aliquota startup'), $model->formValue('fiscal_forf_startup') ?: '5');
                    $field('fiscal_forf_limite', trans('forf_limit', '', 'Forfettario — limite ricavi'), $model->formValue('fiscal_forf_limite') ?: '85000', '€', '1',
                        trans('forf_limit_help', '', 'Oltre questa soglia il prospetto avvisa che la stima forfettaria non si applica più.'));
                    ?>
                </div>
            </div>
        </section>
    </div>
</form>
<script src="<?= _core_asset('js/ip-fiscal-form.js') ?>"></script>
<?= $this->endSection() ?>
