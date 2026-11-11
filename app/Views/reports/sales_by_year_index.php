<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('sales_by_date')); ?>
<?= $this->include('layout/headerbar', [
    'title' => trans('sales_by_date'),
    'meta'  => trans('sales_by_year_meta', '', 'Il fatturato per trimestre, per confrontare anni e stagionalità.'),
]) ?>

<div id="content">

    <?= $this->include('reports/partial_nav', ['current' => trans('sales_by_date')]) ?>

    <?php
    // Il pulsante rapido si accende quando il periodo nei campi e' proprio
    // quello che produrrebbe: ancora all'anno del contesto, come il report.
    $ipRangeSpan   = (int) $year_to - (int) $year_from + 1;
    $ipRangeAnchor = (int) ($range_anchor ?? (int) date('Y'));
    $ipIsRange     = static fn (int $years): bool => (int) $year_to === $ipRangeAnchor && $ipRangeSpan === $years;
    ?>
    <div class="card ip-report-filter">
        <form method="post" action="<?= site_url('reports/sales_by_year') ?>">
            <?= csrf_field() ?>

            <div class="card-header">
                <span class="card-header-icon"><i class="fa fa-solid fa-sliders" aria-hidden="true"></i></span>
                <span><?= trans('period', '', 'Periodo') ?></span>
                <div class="ip-report-filter__actions">
                    <button type="submit" name="btn_submit" value="1" class="btn btn-sm btn-success">
                        <i class="fa fa-solid fa-chart-column" aria-hidden="true"></i> <?= trans('run_report') ?>
                    </button>
                    <button type="submit" name="btn_submit_pdf" value="1" class="btn btn-sm btn-secondary">
                        <i class="fa fa-regular fa-file-pdf" aria-hidden="true"></i> <?= trans('export_pdf', '', 'Esporta PDF') ?>
                    </button>
                </div>
            </div>

            <div class="card-body">
                <div class="ip-quick-range">
                    <span class="ip-quick-range__label"><?= trans('quick_range', '', 'Periodo rapido:') ?></span>
                    <?php foreach ([1 => trans('report_range_year', '', "Solo l'anno"), 2 => trans('report_range_2y', '', 'Ultimi 2 anni'), 3 => trans('report_range_3y', '', 'Ultimi 3 anni'), 5 => trans('report_range_5y', '', 'Ultimi 5 anni')] as $ipYears => $ipLabel): ?>
                        <button type="submit" name="btn_range" value="<?= $ipYears ?>"
                                class="ip-quick-range__btn<?= $ipIsRange($ipYears) ? ' is-active' : '' ?>"
                                aria-pressed="<?= $ipIsRange($ipYears) ? 'true' : 'false' ?>"><?= esc($ipLabel) ?></button>
                    <?php endforeach; ?>
                </div>

                <div class="ip-report-filter__fields">
                    <div class="ip-report-filter__field">
                        <label class="ip-doc-label" for="year_from"><?= trans('year_from') ?></label>
                        <select name="year_from" id="year_from" class="form-select" required>
                            <?php foreach ($year_options as $ipYear): ?>
                                <option value="<?= (int) $ipYear ?>" <?= (int) $ipYear === (int) $year_from ? 'selected' : '' ?>><?= (int) $ipYear ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ip-report-filter__field">
                        <label class="ip-doc-label" for="year_to"><?= trans('year_to') ?></label>
                        <select name="year_to" id="year_to" class="form-select" required>
                            <?php foreach ($year_options as $ipYear): ?>
                                <option value="<?= (int) $ipYear ?>" <?= (int) $ipYear === (int) $year_to ? 'selected' : '' ?>><?= (int) $ipYear ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ip-report-filter__field">
                        <label class="ip-doc-label" for="minQuantity"><?= trans('report_min_sales', '', 'Fatturato minimo (€)') ?></label>
                        <input type="number" id="minQuantity" name="minQuantity" min="0" class="form-control" value="<?= $min_quantity !== null ? (int) $min_quantity : '' ?>">
                    </div>
                    <div class="ip-report-filter__field">
                        <label class="ip-doc-label" for="maxQuantity"><?= trans('report_max_sales', '', 'Fatturato massimo (€)') ?></label>
                        <input type="number" id="maxQuantity" name="maxQuantity" min="0" class="form-control" value="<?= $max_quantity !== null ? (int) $max_quantity : '' ?>">
                    </div>
                    <label class="ip-report-filter__check" for="checkboxTax">
                        <input type="checkbox" class="form-check-input" id="checkboxTax" name="checkboxTax" value="1" <?= $checkbox_tax ? 'checked' : '' ?>>
                        <span><?= trans('values_with_taxes') ?></span>
                    </label>
                </div>
            </div>
        </form>
    </div>

    <?php if ($has_results && !empty($results)): ?>
        <?php
        // Gli anni si ricavano dai nomi delle colonne calcolate dal model
        // (payment_t{trimestre}_{anno}): il model ne genera quattro per anno
        // richiesto, quindi l'intestazione segue i dati e non i campi del form.
        $years = [];
        foreach ($results as $row) {
            foreach ($row as $key => $value) {
                if (preg_match('/^payment_t\d+_(\d{4})$/', (string) $key, $m)) {
                    $years[(int) $m[1]] = true;
                }
            }
        }
        ksort($years);
        $yearList = array_keys($years);

        $grandTotal    = 0.0;
        $quarterTotals = [];
        foreach ($results as $row) {
            $grandTotal += (float) ($row['total_payment'] ?? 0);
            foreach ($yearList as $y) {
                for ($q = 1; $q <= 4; $q++) {
                    $key = 'payment_t' . $q . '_' . $y;
                    $quarterTotals[$key] = ($quarterTotals[$key] ?? 0.0) + (float) ($row[$key] ?? 0);
                }
            }
        }

        $periodLabel = count($yearList) > 1
            ? $yearList[0] . '–' . end($yearList)
            : (string) ($yearList[0] ?? '');

        // Soglie dell'intensita' di cella. Otto o dodici colonne di cifre sono
        // una matrice, e una matrice si legge prima per forma: dove si e'
        // concentrato il lavoro, quale trimestre e' stato vuoto, se un cliente
        // e' stagionale. Le soglie sono i quartili dei valori non nulli e non
        // frazioni del massimo: con un cliente dieci volte piu' grande degli
        // altri, una scala lineare tingerebbe una cella sola e lascerebbe
        // bianco tutto il resto.
        $nonZero = [];
        foreach ($results as $row) {
            foreach ($yearList as $y) {
                for ($q = 1; $q <= 4; $q++) {
                    $v = (float) ($row['payment_t' . $q . '_' . $y] ?? 0);
                    if ($v > 0) {
                        $nonZero[] = $v;
                    }
                }
            }
        }
        sort($nonZero);

        $quartile = static function (array $sorted, float $at): float {
            if ($sorted === []) {
                return 0.0;
            }
            return $sorted[(int) floor((count($sorted) - 1) * $at)];
        };

        $heatBreaks = [
            $quartile($nonZero, 0.25),
            $quartile($nonZero, 0.50),
            $quartile($nonZero, 0.75),
        ];

        $heatLevel = static function (float $value) use ($heatBreaks): int {
            if ($value <= 0) {
                return 0;
            }
            if ($value <= $heatBreaks[0]) {
                return 1;
            }
            if ($value <= $heatBreaks[1]) {
                return 2;
            }
            if ($value <= $heatBreaks[2]) {
                return 3;
            }
            return 4;
        };
        ?>
        <div class="ip-report-kpis">
            <div class="ip-report-kpi">
                <span class="ip-report-kpi__label"><?= trans('clients') ?></span>
                <span class="ip-report-kpi__value"><?= count($results) ?></span>
            </div>
            <div class="ip-report-kpi">
                <span class="ip-report-kpi__label">
                    <?= $checkbox_tax ? trans('sales_with_tax') : trans('sales') ?>
                </span>
                <span class="ip-report-kpi__value"><?= format_currency($grandTotal) ?></span>
            </div>
        </div>

        <div class="card ip-table-card ip-report-results">
            <div class="card-header ip-table-card-header">
                <div class="ip-table-card-title">
                    <span class="card-header-icon"><i class="fa fa-solid fa-chart-column" aria-hidden="true"></i></span>
                    <span class="ip-table-card-label"><?= esc(trans('sales_by_date')) ?></span>
                    <span class="badge text-bg-secondary"><?= count($results) ?></span>
                    <span class="ip-report-period">
                        <?= esc($periodLabel) ?>
                        <?= $checkbox_tax ? '· ' . esc(trans('values_with_taxes')) : '' ?>
                    </span>
                </div>
            </div>
            <div class="table-responsive ip-report-scroll">
                <table class="table table-hover ip-sales-year-table">
                    <thead>
                    <tr>
                        <th scope="col" rowspan="2"><?= trans('client') ?></th>
                        <th scope="col" rowspan="2"><?= trans('vat_id') ?></th>
                        <?php foreach ($yearList as $y): ?>
                            <th scope="col" colspan="4" class="ip-year-group"><?= (int) $y ?></th>
                        <?php endforeach; ?>
                        <th scope="col" rowspan="2" class="ip-year-total"><?= trans('total') ?></th>
                    </tr>
                    <tr>
                        <?php foreach ($yearList as $y): ?>
                            <?php for ($q = 1; $q <= 4; $q++): ?>
                                <th scope="col" class="ip-quarter<?= $q === 1 ? ' is-year-start' : '' ?>">T<?= $q ?></th>
                            <?php endfor; ?>
                        <?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $row): ?>
                        <tr>
                            <td>
                                <a class="ip-report-client"
                                   href="<?= site_url('clients/view/' . (int) ($row['client_id'] ?? 0)) ?>"><?= esc(format_client($row)) ?></a>
                            </td>
                            <td class="ip-vat-id"><?= esc($row['VAT_ID'] ?? '') ?></td>
                            <?php foreach ($yearList as $y): ?>
                                <?php for ($q = 1; $q <= 4; $q++): ?>
                                    <?php
                                    $value = (float) ($row['payment_t' . $q . '_' . $y] ?? 0);
                                    $level = $heatLevel($value);
                                    ?>
                                    <td class="ip-quarter-cell<?= $q === 1 ? ' is-year-start' : '' ?><?= $value > 0 ? ' is-heat-' . $level : ' is-empty' ?>">
                                        <?= $value > 0 ? format_amount($value) : '&ndash;' ?>
                                    </td>
                                <?php endfor; ?>
                            <?php endforeach; ?>
                            <td class="ip-year-total"><?= format_currency($row['total_payment'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="ip-report-total">
                        <td colspan="2"><?= trans('total') ?></td>
                        <?php foreach ($yearList as $y): ?>
                            <?php for ($q = 1; $q <= 4; $q++): ?>
                                <?php $value = $quarterTotals['payment_t' . $q . '_' . $y] ?? 0.0; ?>
                                <td class="ip-quarter-cell<?= $q === 1 ? ' is-year-start' : '' ?>">
                                    <?= $value > 0 ? format_amount($value) : '&ndash;' ?>
                                </td>
                            <?php endfor; ?>
                        <?php endforeach; ?>
                        <td class="amount ip-year-total"><?= format_currency($grandTotal) ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <?php if ($nonZero !== []): ?>
                <div class="ip-heat-scale">
                    <span class="ip-heat-scale__what"><?= trans('heat_scale_hint', '', 'Fatturato nel trimestre') ?></span>
                    <span><?= trans('heat_scale_low', '', 'meno') ?></span>
                    <span class="ip-heat-scale__steps" aria-hidden="true">
                        <span class="ip-heat-scale__step ip-heat-scale__step--1"></span>
                        <span class="ip-heat-scale__step ip-heat-scale__step--2"></span>
                        <span class="ip-heat-scale__step ip-heat-scale__step--3"></span>
                        <span class="ip-heat-scale__step ip-heat-scale__step--4"></span>
                    </span>
                    <span><?= trans('heat_scale_high', '', 'più') ?></span>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?= $this->include('reports/partial_empty', ['ran' => (bool) $has_results]) ?>
    <?php endif; ?>

</div>

<?= $this->endSection() ?>
