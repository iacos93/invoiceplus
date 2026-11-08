<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
/**
 * Budget mensile per voce.
 *
 * Dodici caselle per riga, non un totale annuo: il confronto che serve è
 * mensile, e una ripartizione automatica nasconderebbe la stagionalità. Il
 * totale di riga si aggiorna mentre si digita (script in fondo), così non
 * serve salvare per vedere quanto si è previsto in un anno.
 */
$this->setVar('pageTitle', trans('management_budget', '', 'Budget'));

$yearLabel = trans('management_budget', '', 'Budget') . ' · ' . $year;

$actions = '<a class="btn btn-sm btn-secondary" href="' . site_url('management') . '">'
    . '<i class="fa fa-solid fa-arrow-left" aria-hidden="true"></i> ' . trans('management', '', 'Bilancino') . '</a>';

$typeLabels = \App\Models\CostingAccountModel::TYPES;
?>

<?= $this->include('layout/headerbar', ['title' => $yearLabel, 'actions' => $actions]) ?>

<div id="content">
    <?= $this->include('management/_tabs', ['active' => 'budget']) ?>

    <?php if ($grouped === [] || array_sum(array_map('count', $grouped)) === 0): ?>
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step"><i class="fa fa-solid fa-sitemap" aria-hidden="true"></i></span>
                <h2 class="ip-doc-section__title"><?= trans('management_empty_title', '', 'Prima il piano dei conti') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <p><?= trans('management_budget_needs_plan', '', 'Il budget si assegna alle voci del piano dei conti: importane uno o crea le voci che usi.') ?></p>
                <a class="btn btn-primary" href="<?= site_url('management/accounts') ?>">
                    <i class="fa fa-solid fa-sitemap" aria-hidden="true"></i>
                    <?= trans('management_plan', '', 'Piano dei conti') ?>
                </a>
            </div>
        </section>
    <?php else: ?>
        <form method="post" action="<?= site_url('management/budget') ?>" data-budget-form>
            <?= csrf_field() ?>

            <div class="ip-management-tools">
                <p class="ip-doc-section__hint mb-0">
                    <i class="fa fa-solid fa-info-circle" aria-hidden="true"></i>
                    <?= trans('management_budget_hint', '', 'Lascia vuoto un mese senza previsione. I valori sono al netto dell\'IVA, come i costi del Bilancino.') ?>
                </p>
            </div>

            <div class="card ip-table-card">
                <div class="card-header ip-table-card-header">
                    <div class="ip-table-card-title">
                        <span class="card-header-icon"><i class="fa fa-solid fa-bullseye" aria-hidden="true"></i></span>
                        <span class="ip-table-card-label"><?= trans('management_budget', '', 'Budget') ?></span>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table ip-bilancino ip-bilancino--budget-grid">
                        <thead>
                        <tr>
                            <th scope="col" class="ip-bilancino__name"><?= trans('management_item', '', 'Voce') ?></th>
                            <?php foreach ($month_names as $name): ?>
                                <th scope="col" class="ip-bilancino__month"><?= esc(mb_substr($name, 0, 3)) ?></th>
                            <?php endforeach; ?>
                            <th scope="col" class="ip-bilancino__total"><?= trans('total', '', 'Totale') ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($typeLabels as $type => $typeLabel): ?>
                            <?php if (($grouped[$type] ?? []) === []) { continue; } ?>
                            <tr class="ip-bilancino__section">
                                <th scope="colgroup" colspan="14"><span class="ip-bilancino__band"><?= esc($typeLabel) ?></span></th>
                            </tr>
                            <?php foreach ($grouped[$type] as $row): ?>
                                <tr data-budget-row>
                                    <th scope="row" class="ip-bilancino__name" style="--ip-level: <?= (int) $row['level'] ?>">
                                        <span class="ip-bilancino__indent" aria-hidden="true"></span>
                                        <span class="ip-plan-code"><?= esc($row['account_code']) ?></span>
                                        <?= esc($row['account_name']) ?>
                                    </th>
                                    <?php for ($m = 1; $m <= 12; $m++):
                                        $current = $grid[(int) $row['account_id']][$m] ?? null;
                                    ?>
                                        <td class="ip-bilancino__month">
                                            <input type="text" inputmode="decimal"
                                                   class="form-control form-control-sm amount ip-budget-input"
                                                   name="budget[<?= (int) $row['account_id'] ?>][<?= $m ?>]"
                                                   aria-label="<?= esc($row['account_name'] . ' — ' . $month_names[$m], 'attr') ?>"
                                                   value="<?= $current !== null && (float) $current != 0.0 ? esc(format_amount($current)) : '' ?>">
                                        </td>
                                    <?php endfor; ?>
                                    <td class="ip-bilancino__total" data-budget-total>—</td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                        <tr class="is-subtotal">
                            <th scope="row"><?= trans('total', '', 'Totale') ?></th>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <td class="ip-bilancino__month" data-budget-month-total="<?= $m ?>">—</td>
                            <?php endfor; ?>
                            <td class="ip-bilancino__total" data-budget-grand-total>—</td>
                        </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="ip-doc-actions">
                <button type="submit" class="btn btn-sm btn-success">
                    <i class="fa fa-solid fa-check" aria-hidden="true"></i> <?= trans('save', '', 'Salva') ?>
                </button>
                <a class="btn btn-sm btn-secondary" href="<?= site_url('management') ?>">
                    <?= trans('cancel', '', 'Annulla') ?>
                </a>
            </div>
        </form>

        <script>
        /* Totali di riga, di mese e generale calcolati mentre si digita.
         * Il formato è quello locale ("1.200,50"): si tolgono i separatori
         * delle migliaia e si converte la virgola, come fa il motore righe
         * dei documenti. */
        (function () {
            "use strict";

            var form   = document.querySelector("[data-budget-form]");
            if (!form) { return; }

            var dec    = <?= json_encode((string) get_setting('decimal_point', ',')) ?>;
            var thou   = <?= json_encode((string) get_setting('thousands_separator', '.')) ?>;

            function parse(value) {
                value = String(value == null ? "" : value).trim();
                if (!value) { return 0; }
                if (thou) { value = value.split(thou).join(""); }
                if (dec)  { value = value.split(dec).join("."); }
                var n = parseFloat(value);
                return isNaN(n) ? 0 : n;
            }

            function format(n) {
                var fixed = n.toFixed(2).split(".");
                if (thou) { fixed[0] = fixed[0].replace(/\B(?=(\d{3})+(?!\d))/g, thou); }
                return fixed[0] + dec + fixed[1];
            }

            function update() {
                var monthTotals = {};
                var grand = 0;

                form.querySelectorAll("[data-budget-row]").forEach(function (row) {
                    var total = 0;

                    row.querySelectorAll(".ip-budget-input").forEach(function (input, index) {
                        var value = parse(input.value);
                        var month = index + 1;

                        total += value;
                        monthTotals[month] = (monthTotals[month] || 0) + value;
                    });

                    var cell = row.querySelector("[data-budget-total]");
                    if (cell) { cell.textContent = total === 0 ? "—" : format(total); }
                    grand += total;
                });

                for (var m = 1; m <= 12; m++) {
                    var cell = form.querySelector('[data-budget-month-total="' + m + '"]');
                    if (cell) { cell.textContent = monthTotals[m] ? format(monthTotals[m]) : "—"; }
                }

                var grandCell = form.querySelector("[data-budget-grand-total]");
                if (grandCell) { grandCell.textContent = grand === 0 ? "—" : format(grand); }
            }

            form.addEventListener("input", update);
            update();
        })();
        </script>
    <?php endif; ?>
</div>

<?= $this->endSection() ?>
