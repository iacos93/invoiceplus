<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('payment_history')); ?>
<?= $this->include('layout/headerbar', [
    'title' => trans('payment_history'),
    'meta'  => trans('payment_history_meta', '', 'Cosa è entrato e quando, raggruppato per mese.'),
]) ?>

<div id="content">

    <?= $this->include('reports/partial_nav', ['current' => trans('payment_history')]) ?>

    <div class="card ip-report-filter">
        <form method="post" action="<?= site_url('reports/payment_history') ?>">
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
                <div class="ip-quick-range" data-quick-range data-autosubmit data-from="#from_date" data-to="#to_date">
                    <span class="ip-quick-range__label"><?= trans('quick_range', '', 'Periodo rapido:') ?></span>
                    <button type="button" class="ip-quick-range__btn" data-range="today"><?= trans('range_today', '', 'Oggi') ?></button>
                    <button type="button" class="ip-quick-range__btn" data-range="this_month"><?= trans('range_this_month', '', 'Questo mese') ?></button>
                    <button type="button" class="ip-quick-range__btn" data-range="last_month"><?= trans('range_last_month', '', 'Mese scorso') ?></button>
                    <button type="button" class="ip-quick-range__btn" data-range="this_quarter"><?= trans('range_this_quarter', '', 'Questo trimestre') ?></button>
                    <button type="button" class="ip-quick-range__btn" data-range="this_year"><?= trans('range_this_year', '', "Quest'anno") ?></button>
                    <button type="button" class="ip-quick-range__btn" data-range="last_year"><?= trans('range_last_year', '', 'Anno scorso') ?></button>
                </div>

                <div class="ip-report-filter__fields">
                    <div class="ip-report-filter__field">
                        <label class="ip-doc-label" for="from_date"><?= trans('from_date') ?></label>
                        <input type="text" name="from_date" id="from_date" class="form-control datepicker" value="<?= esc($from_date) ?>" required>
                    </div>
                    <div class="ip-report-filter__field">
                        <label class="ip-doc-label" for="to_date"><?= trans('to_date') ?></label>
                        <input type="text" name="to_date" id="to_date" class="form-control datepicker" value="<?= esc($to_date) ?>" required>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <?php if ($has_results && !empty($results)): ?>
        <?php
        // Il totale incassato e' il motivo per cui si apre questo report: qui
        // sopra la tabella, e ripetuto in fondo alla lista per chi la stampa.
        $sum       = 0.0;
        $clientIds = [];

        // Gli incassi si leggono per mese: e' l'unita' con cui si riconcilia
        // la banca e con cui si risponde a "com'e' andato ottobre". I record
        // arrivano gia' ordinati per data decrescente dal model, quindi basta
        // spezzare la sequenza quando cambia il mese — nessun riordino, e la
        // lista resta una sola scansione.
        $monthTotals = [];
        $monthCounts = [];

        foreach ($results as $row) {
            $amount = (float) $row['payment_amount'];
            $sum   += $amount;

            if (isset($row['client_id'])) {
                $clientIds[(int) $row['client_id']] = true;
            }

            $key                 = substr((string) $row['payment_date'], 0, 7);
            $monthTotals[$key]   = ($monthTotals[$key] ?? 0.0) + $amount;
            $monthCounts[$key]   = ($monthCounts[$key] ?? 0) + 1;
        }

        $monthNames   = \App\Services\FinanceService::monthNames(service('request')->getLocale());
        $currentMonth = null;
        ?>
        <div class="ip-report-kpis">
            <div class="ip-report-kpi">
                <span class="ip-report-kpi__label"><?= trans('payments') ?></span>
                <span class="ip-report-kpi__value"><?= count($results) ?></span>
            </div>
            <div class="ip-report-kpi">
                <span class="ip-report-kpi__label"><?= trans('total_collected', '', 'Incassato') ?></span>
                <span class="ip-report-kpi__value is-success"><?= format_currency($sum) ?></span>
            </div>
            <div class="ip-report-kpi">
                <span class="ip-report-kpi__label"><?= trans('clients') ?></span>
                <span class="ip-report-kpi__value is-muted"><?= count($clientIds) ?></span>
            </div>
        </div>

        <div class="card ip-table-card ip-report-results">
            <div class="card-header ip-table-card-header">
                <div class="ip-table-card-title">
                    <span class="card-header-icon"><i class="fa fa-regular fa-credit-card" aria-hidden="true"></i></span>
                    <span class="ip-table-card-label"><?= esc(trans('payment_history')) ?></span>
                    <span class="badge text-bg-secondary"><?= count($results) ?></span>
                    <span class="ip-report-period"><?= esc($from_date) ?> &ndash; <?= esc($to_date) ?></span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover ip-table-card-collapse">
                    <thead>
                    <tr>
                        <th scope="col"><?= trans('date') ?></th>
                        <th scope="col"><?= trans('invoice') ?></th>
                        <th scope="col"><?= trans('client') ?></th>
                        <th scope="col"><?= trans('payment_method') ?></th>
                        <th scope="col"><?= trans('notes') ?></th>
                        <th scope="col" class="amount"><?= trans('amount') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $row): ?>
                        <?php
                        $monthKey = substr((string) $row['payment_date'], 0, 7);
                        if ($monthKey !== $currentMonth):
                            $currentMonth = $monthKey;
                            [$mYear, $mNum] = array_map('intval', explode('-', $monthKey));
                        ?>
                            <tr class="ip-month-row">
                                <td colspan="6">
                                    <span class="ip-month-head">
                                        <span class="ip-month-label"><?= esc(($monthNames[$mNum] ?? '') . ' ' . $mYear) ?></span>
                                        <span class="ip-month-count"><?= $monthCounts[$monthKey] === 1
                                            ? trans('n_payment_one', '', '1 pagamento')
                                            : sprintf(trans('n_payments', '', '%d pagamenti'), $monthCounts[$monthKey]) ?></span>
                                        <span class="ip-month-total"><?= format_currency($monthTotals[$monthKey]) ?></span>
                                    </span>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td data-label="<?= esc(trans('date'), 'attr') ?>"><?= esc(date_from_mysql($row['payment_date'])) ?></td>
                            <td data-label="<?= esc(trans('invoice'), 'attr') ?>">
                                <a class="ip-num" href="<?= site_url('invoices/view/' . (int) $row['invoice_id']) ?>"><?= esc($row['invoice_number']) ?></a>
                            </td>
                            <td data-label="<?= esc(trans('client'), 'attr') ?>">
                                <a class="ip-report-client"
                                   href="<?= site_url('clients/view/' . (int) ($row['client_id'] ?? 0)) ?>"><?= esc(format_client($row)) ?></a>
                            </td>
                            <td data-label="<?= esc(trans('payment_method'), 'attr') ?>"><?= esc($row['payment_method_name'] ?? '') ?></td>
                            <td data-label="<?= esc(trans('notes'), 'attr') ?>"><?= esc($row['payment_note'] ?? '') ?></td>
                            <td class="amount" data-label="<?= esc(trans('amount'), 'attr') ?>"><?= format_currency($row['payment_amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="ip-report-total">
                        <td colspan="5"><?= trans('total') ?></td>
                        <td class="amount" data-label="<?= esc(trans('amount'), 'attr') ?>"><?= format_currency($sum) ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <?= $this->include('reports/partial_empty', ['ran' => (bool) $has_results]) ?>
    <?php endif; ?>

</div>

<?= $this->endSection() ?>
