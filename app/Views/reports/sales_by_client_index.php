<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('sales_by_client')); ?>
<?= $this->include('layout/headerbar', [
    'title' => trans('sales_by_client'),
    'meta'  => trans('sales_by_client_meta', '', 'Chi fattura di più nel periodo, con quante fatture e quanto pesa.'),
]) ?>

<div id="content">

    <?= $this->include('reports/partial_nav', ['current' => trans('sales_by_client')]) ?>

    <?php
    // Filtro: intestazione con i due pulsanti a destra, campi sotto. I bottoni
    // stanno nell'header e non in fondo al form perche' il form e' una riga
    // sola — in colonna avrebbero richiesto una label vuota per allinearsi.
    ?>
    <div class="card ip-report-filter">
        <form method="post" action="<?= site_url('reports/sales_by_client') ?>">
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
        // Riepilogo prima della tabella: il totale del periodo e' la risposta,
        // le righe sono la prova. Sommato qui e non in SQL perche' le righe
        // sono gia' tutte in memoria e una seconda query direbbe la stessa cosa.
        $clientCount  = count($results);
        $invoiceCount = 0;
        $sumNet       = 0.0;
        $sumGross     = 0.0;

        foreach ($results as $row) {
            $invoiceCount += (int) ($row['invoice_count'] ?? 0);
            $sumNet       += (float) ($row['sales'] ?? 0);
            $sumGross     += (float) ($row['sales_with_tax'] ?? 0);
        }

        // La quota si calcola sull'imponibile perche' e' la colonna su cui la
        // tabella e' ordinata: mescolare le due basi darebbe percentuali che
        // non corrispondono all'ordine delle righe.
        $share = static fn(float $v): float => $sumNet > 0 ? $v / $sumNet * 100 : 0.0;

        // Concentrazione: le prime tre posizioni dicono se il fatturato sta in
        // piedi su pochi clienti. E' un rischio che la classifica mostra riga
        // per riga ma che nessuno somma a mente.
        $top3Net   = 0.0;
        foreach (array_slice($results, 0, 3) as $row) {
            $top3Net += (float) ($row['sales'] ?? 0);
        }
        $top3Share = $share($top3Net);
        ?>
        <?= view('layout/summary_strip', ['items' => [
            [
                'label' => trans('clients'),
                'value' => (string) $clientCount,
            ],
            [
                'label' => trans('invoices'),
                'value' => (string) $invoiceCount,
            ],
            [
                'label' => trans('sales'),
                'value' => format_currency($sumNet),
            ],
            [
                'label' => trans('report_top3_share', '', 'Quota primi 3'),
                'value' => format_amount($top3Share) . '%',
                'tone'  => $top3Share >= 50 ? 'warning' : 'default',
                'hint'  => trans('report_top3_hint', '', 'Peso dei primi tre clienti sul fatturato del periodo.'),
            ],
        ]]) ?>

        <div class="card ip-table-card ip-report-results">
            <div class="card-header ip-table-card-header">
                <div class="ip-table-card-title">
                    <span class="card-header-icon"><i class="fa fa-solid fa-users" aria-hidden="true"></i></span>
                    <span class="ip-table-card-label"><?= esc(trans('sales_by_client')) ?></span>
                    <span class="badge text-bg-secondary"><?= $clientCount ?></span>
                    <span class="ip-report-period"><?= esc($from_date) ?> &ndash; <?= esc($to_date) ?></span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover ip-table-card-collapse ip-rank-table">
                    <thead>
                    <tr>
                        <th scope="col" class="ip-rank"><span class="ip-visually-hidden"><?= trans('position', '', 'Posizione') ?></span>#</th>
                        <th scope="col"><?= trans('client') ?></th>
                        <th scope="col" class="ip-share-pct"><?= trans('share', '', 'Quota') ?></th>
                        <th scope="col" class="amount"><?= trans('invoice_count') ?></th>
                        <th scope="col" class="amount"><?= trans('sales') ?></th>
                        <th scope="col" class="amount"><?= trans('sales_with_tax') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $i => $row): ?>
                        <?php
                        $rowNet   = (float) ($row['sales'] ?? 0);
                        $rowShare = $share($rowNet);
                        ?>
                        <tr>
                            <td class="ip-rank<?= $i < 3 ? ' is-podium' : '' ?>" data-label="<?= esc(trans('position', '', 'Posizione'), 'attr') ?>"><?= $i + 1 ?></td>
                            <td class="ip-rank-name" data-label="<?= esc(trans('client'), 'attr') ?>">
                                <a class="ip-rank-client ip-report-client"
                                   href="<?= site_url('clients/view/' . (int) ($row['client_id'] ?? 0)) ?>"><?= esc(format_client($row)) ?></a>
                                <?php if ($rowShare > 0): ?>
                                    <span class="ip-share-bar" role="img"
                                          aria-label="<?= esc(sprintf(trans('share_aria', '', '%s del fatturato del periodo'), format_amount($rowShare) . '%'), 'attr') ?>">
                                        <span class="ip-share-bar__fill" style="width: <?= round($rowShare, 2) ?>%"></span>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="ip-share-pct" data-label="<?= esc(trans('share', '', 'Quota'), 'attr') ?>"><?= format_amount($rowShare) ?>%</td>
                            <td class="amount" data-label="<?= esc(trans('invoice_count'), 'attr') ?>"><?= (int) ($row['invoice_count'] ?? 0) ?></td>
                            <td class="amount" data-label="<?= esc(trans('sales'), 'attr') ?>"><?= format_currency($rowNet) ?></td>
                            <td class="amount" data-label="<?= esc(trans('sales_with_tax'), 'attr') ?>"><?= format_currency($row['sales_with_tax'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="ip-report-total">
                        <td class="ip-rank"></td>
                        <td><?= trans('total') ?></td>
                        <td class="ip-share-pct" data-label="<?= esc(trans('share', '', 'Quota'), 'attr') ?>"><?= $sumNet > 0 ? '100%' : '&ndash;' ?></td>
                        <td class="amount" data-label="<?= esc(trans('invoice_count'), 'attr') ?>"><?= $invoiceCount ?></td>
                        <td class="amount" data-label="<?= esc(trans('sales'), 'attr') ?>"><?= format_currency($sumNet) ?></td>
                        <td class="amount" data-label="<?= esc(trans('sales_with_tax'), 'attr') ?>"><?= format_currency($sumGross) ?></td>
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
