<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', trans('invoice_aging')); ?>
<?= $this->include('layout/headerbar', [
    'title' => trans('invoice_aging'),
    'meta'  => trans('invoice_aging_meta', '', 'Quanto è aperto, da quanto tempo e per quali clienti.'),
]) ?>

<div id="content">

    <?= $this->include('reports/partial_nav', ['current' => trans('invoice_aging')]) ?>

    <?php
    // Le cinque fasce sono quelle che ReportModel::invoiceAging() calcola
    // davvero (1-30, 31-60, 61-90, 91-120, oltre 120). Prima la view ne
    // mostrava tre, con etichette di un'altra scala (1-15 / 16-30 / oltre 30):
    // il saldo di un cliente scaduto da quattro mesi finiva in range_4, che
    // nessuna colonna leggeva, e la riga appariva a zero con un totale pieno.
    //
    // Il termine di ogni fascia e' quello del credito commerciale, non un
    // numero di livello: "sofferenza" dice cosa fare di quel credito, "5" no.
    $ip_aging_ranges = [
        1 => ['label' => trans('invoice_aging_1_30', '', '1 - 30 giorni'),      'risk' => trans('aging_risk_1', '', 'recente')],
        2 => ['label' => trans('invoice_aging_31_60', '', '31 - 60 giorni'),    'risk' => trans('aging_risk_2', '', 'scaduto')],
        3 => ['label' => trans('invoice_aging_61_90', '', '61 - 90 giorni'),    'risk' => trans('aging_risk_3', '', 'arretrato')],
        4 => ['label' => trans('invoice_aging_91_120', '', '91 - 120 giorni'),  'risk' => trans('aging_risk_4', '', 'critico')],
        5 => ['label' => trans('invoice_aging_above_120', '', 'Oltre 120 giorni'), 'risk' => trans('aging_risk_5', '', 'sofferenza')],
    ];
    ?>

    <?php if (!empty($results)): ?>
        <?php
        // Prima il credito piu' grosso: lo scadenzario serve a decidere chi
        // sollecitare, e in ordine alfabetico il cliente da 3.700 EUR poteva
        // finire in fondo dietro a quelli da poche centinaia.
        usort($results, static fn ($a, $b) => (float) ($b['total_balance'] ?? 0) <=> (float) ($a['total_balance'] ?? 0));

        // Totali di colonna: servono sia ai KPI sia alla riga di chiusura.
        $rangeTotals = array_fill_keys(array_keys($ip_aging_ranges), 0.0);
        $grandTotal  = 0.0;

        foreach ($results as $row) {
            foreach ($ip_aging_ranges as $i => $range) {
                $rangeTotals[$i] += (float) ($row['range_' . $i] ?? 0);
            }
            $grandTotal += (float) ($row['total_balance'] ?? 0);
        }

        // "Oltre 90 giorni" e' la soglia oltre la quale un credito smette di
        // essere un ritardo e diventa un problema di recupero: e' la cifra da
        // guardare per prima, quindi sale nei KPI accanto all'esposizione.
        // La quota dice se e' un caso isolato o la fetta principale del
        // portafoglio: il numero assoluto da solo non lo chiarisce.
        $overNinety      = $rangeTotals[4] + $rangeTotals[5];
        $overNinetyShare = $grandTotal > 0 ? $overNinety / $grandTotal * 100 : 0.0;
        $shareClass      = $overNinetyShare >= 50 ? 'is-danger' : ($overNinetyShare > 0 ? 'is-warning' : 'is-muted');
        ?>
        <div class="ip-report-kpis">
            <div class="ip-report-kpi">
                <span class="ip-report-kpi__label"><?= trans('total_balance') ?></span>
                <span class="ip-report-kpi__value"><?= format_currency($grandTotal) ?></span>
            </div>
            <div class="ip-report-kpi">
                <span class="ip-report-kpi__label"><?= trans('aging_over_90', '', 'Oltre 90 giorni') ?></span>
                <span class="ip-report-kpi__value <?= $overNinety > 0 ? 'is-warning' : 'is-muted' ?>"><?= format_currency($overNinety) ?></span>
            </div>
            <div class="ip-report-kpi">
                <span class="ip-report-kpi__label"><?= trans('aging_share_over_90', '', 'Quota oltre 90') ?></span>
                <span class="ip-report-kpi__value <?= $shareClass ?>"><?= $overNinetyShare > 0 ? format_amount($overNinetyShare) . '%' : '&ndash;' ?></span>
            </div>
            <div class="ip-report-kpi">
                <span class="ip-report-kpi__label"><?= trans('clients') ?></span>
                <span class="ip-report-kpi__value is-muted"><?= count($results) ?></span>
            </div>
        </div>

        <div class="card ip-table-card ip-report-results">
            <div class="card-header ip-table-card-header">
                <div class="ip-table-card-title">
                    <span class="card-header-icon"><i class="fa fa-solid fa-hourglass-half" aria-hidden="true"></i></span>
                    <span class="ip-table-card-label"><?= esc(trans('invoice_aging')) ?></span>
                    <span class="badge text-bg-secondary"><?= count($results) ?></span>
                    <span class="ip-report-period"><?= esc(trans('as_of', '', 'al')) ?> <?= esc(date_from_mysql(date('Y-m-d'))) ?></span>
                </div>
                <a class="btn btn-sm btn-secondary" href="<?= site_url('reports/invoice_aging/pdf') ?>">
                    <i class="fa fa-regular fa-file-pdf" aria-hidden="true"></i> <?= trans('export_pdf', '', 'Esporta PDF') ?>
                </a>
            </div>
            <div class="table-responsive ip-report-scroll">
                <table class="table table-hover ip-aging-table ip-table-card-collapse">
                    <thead>
                    <tr>
                        <th scope="col"><?= trans('client') ?></th>
                        <?php foreach ($ip_aging_ranges as $i => $range): ?>
                            <th scope="col" class="ip-aging-range ip-aging-range--<?= $i ?>">
                                <?= esc($range['label']) ?><br>
                                <span class="ip-aging-range-badge ip-aging-range-badge--<?= $i ?>"><?= esc($range['risk']) ?></span>
                            </th>
                        <?php endforeach; ?>
                        <th scope="col" class="ip-aging-total"><?= trans('total_balance') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($results as $row): ?>
                        <?php
                        // La barra si costruisce sulle fasce, non su total_balance:
                        // cosi' i segmenti sommano esattamente al 100% anche quando
                        // il totale include residui che nessuna fascia intercetta.
                        $values  = [];
                        $rowSum  = 0.0;
                        foreach ($ip_aging_ranges as $i => $range) {
                            $values[$i] = (float) ($row['range_' . $i] ?? 0);
                            $rowSum    += $values[$i];
                        }
                        ?>
                        <tr>
                            <td>
                                <a class="ip-aging-client ip-report-client"
                                   href="<?= site_url('clients/view/' . (int) ($row['client_id'] ?? 0)) ?>"><?= esc(format_client($row)) ?></a>
                                <?php if ($rowSum > 0): ?>
                                    <?php
                                    // La descrizione elenca le fasce con un importo:
                                    // e' quello che la barra mostra a colpo d'occhio e
                                    // che senza di essa resterebbe solo nelle celle.
                                    $barParts = [];
                                    foreach ($values as $i => $value) {
                                        if ($value > 0) {
                                            $barParts[] = $ip_aging_ranges[$i]['label'] . ': ' . format_currency($value);
                                        }
                                    }
                                    ?>
                                    <span class="ip-aging-bar" role="img"
                                          title="<?= esc(implode(' · ', $barParts), 'attr') ?>"
                                          aria-label="<?= esc(trans('aging_bar_aria', '', 'Composizione dello scaduto per fascia di ritardo') . ' — ' . implode(', ', $barParts), 'attr') ?>">
                                        <?php foreach ($values as $i => $value): ?>
                                            <?php if ($value > 0): ?>
                                                <span class="ip-aging-bar__seg ip-aging-bar__seg--<?= $i ?>"
                                                      style="width: <?= round($value / $rowSum * 100, 2) ?>%"></span>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <?php foreach ($values as $i => $value): ?>
                                <td class="ip-aging-range<?= $value > 0 ? '' : ' is-empty' ?>"
                                    data-label="<?= esc($ip_aging_ranges[$i]['label'], 'attr') ?>"
                                    title="<?= esc($ip_aging_ranges[$i]['label'], 'attr') ?>">
                                    <?= $value > 0 ? format_currency($value) : '&ndash;' ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="ip-aging-total" data-label="<?= esc(trans('total_balance'), 'attr') ?>"><?= format_currency($row['total_balance'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="ip-report-total">
                        <td><?= trans('total') ?></td>
                        <?php foreach ($rangeTotals as $i => $value): ?>
                            <td class="amount ip-aging-range<?= $value > 0 ? '' : ' is-empty' ?>" data-label="<?= esc($ip_aging_ranges[$i]['label'], 'attr') ?>"><?= format_currency($value) ?></td>
                        <?php endforeach; ?>
                        <td class="amount" data-label="<?= esc(trans('total_balance'), 'attr') ?>"><?= format_currency($grandTotal) ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>

        </div>
    <?php else: ?>
        <?= $this->include('reports/partial_empty', [
            'ran'     => true,
            'icon'    => 'fa-solid fa-circle-check',
            'message' => trans('no_overdue_invoices', '', 'Nessuna fattura scaduta.'),
        ]) ?>
    <?php endif; ?>

</div>

<?= $this->endSection() ?>
