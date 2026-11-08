<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
/**
 * Flusso di cassa — incassi e uscite effettivi, confrontati con l'EBITDA.
 *
 * L'EBITDA è per competenza (la fattura conta quando è emessa), il saldo di
 * cassa per movimenti (conta quando il denaro arriva o parte). Le due curve
 * che divergono raccontano la cosa più utile di questa pagina: si sta
 * crescendo a fatturato ma non a incassi, oppure il contrario.
 *
 * Limite dichiarato: le uscite sono attribuite al mese del documento perché
 * l'applicazione non registra la data dei singoli pagamenti di spesa.
 */
$this->setVar('pageTitle', trans('management_cashflow', '', 'Flusso di cassa'));

$yearLabel = trans('management_cashflow', '', 'Flusso di cassa') . ' · ' . $year;

$actions = '<a class="btn btn-sm btn-secondary" href="' . site_url('management/export/csv') . '">'
    . '<i class="fa fa-regular fa-file-text" aria-hidden="true"></i> CSV</a>'
    . '<a class="btn btn-sm btn-secondary" href="' . site_url('management/export/pdf') . '">'
    . '<i class="fa fa-regular fa-file-pdf" aria-hidden="true"></i> PDF</a>';

$fmt = static fn($v): string => format_currency((float) $v);

$chartMax = 0.0;
foreach ($cashflow['incassi'] as $m => $value) {
    $chartMax = max($chartMax, (float) $value, (float) $cashflow['uscite'][$m]);
}
$barWidth = static fn(float $v): float => $v > 0 && $chartMax > 0 ? max(2, round($v / $chartMax * 100, 1)) : 0.0;
$empty    = $chartMax <= 0 && array_sum($totals['ebitda']) == 0.0;
?>

<?= $this->include('layout/headerbar', ['title' => $yearLabel, 'actions' => $actions]) ?>

<div id="content">
    <?= $this->include('management/_tabs', ['active' => 'cashflow']) ?>

    <div class="ip-bilancino-kpi">
        <div class="ip-bilancino-kpi__card is-revenue">
            <span class="ip-bilancino-kpi__label"><?= trans('management_cash_in', '', 'Incassi') ?></span>
            <span class="ip-bilancino-kpi__value"><?= $fmt($kpi['cash_in']) ?></span>
            <span class="ip-bilancino-kpi__note"><?= trans('management_cash_in_note', '', 'Pagamenti ricevuti nell\'anno') ?></span>
        </div>
        <div class="ip-bilancino-kpi__card <?= $kpi['cash_out'] > $kpi['cash_in'] ? 'is-negative' : '' ?>">
            <span class="ip-bilancino-kpi__label"><?= trans('management_cash_out', '', 'Uscite') ?></span>
            <span class="ip-bilancino-kpi__value"><?= $fmt($kpi['cash_out']) ?></span>
            <span class="ip-bilancino-kpi__note"><?= trans('management_cash_out_note', '', 'Spese pagate, al lordo dell\'IVA') ?></span>
        </div>
        <div class="ip-bilancino-kpi__card <?= $kpi['cash_balance'] < 0 ? 'is-negative' : 'is-cash' ?>">
            <span class="ip-bilancino-kpi__label"><?= trans('management_cash_balance', '', 'Saldo di cassa') ?></span>
            <span class="ip-bilancino-kpi__value"><?= $fmt($kpi['cash_balance']) ?></span>
            <span class="ip-bilancino-kpi__note"><?= trans('management_cash_balance_note', '', 'Incassi meno uscite dell\'anno') ?></span>
        </div>
        <div class="ip-bilancino-kpi__card <?= $kpi['ebitda'] < 0 ? 'is-negative' : 'is-ebitda' ?>">
            <span class="ip-bilancino-kpi__label">EBITDA</span>
            <span class="ip-bilancino-kpi__value"><?= $fmt($kpi['ebitda']) ?></span>
            <span class="ip-bilancino-kpi__note"><?= trans('management_accrual_note', '', 'Per competenza, non per cassa') ?></span>
        </div>
    </div>

    <section class="ip-doc-section">
        <div class="ip-doc-section__head">
            <span class="ip-doc-step"><i class="fa fa-solid fa-money-bill-1" aria-hidden="true"></i></span>
            <h2 class="ip-doc-section__title"><?= trans('management_cash_monthly', '', 'Incassi e uscite per mese') ?></h2>
        </div>
        <div class="ip-doc-section__body">
            <div class="table-responsive">
                <table class="ip-items-table ip-finance-month">
                    <thead>
                    <tr>
                        <th scope="col"><?= trans('month', '', 'Mese') ?></th>
                        <th scope="col" class="ip-finance-month__col is-income"><?= trans('management_cash_in', '', 'Incassi') ?></th>
                        <th scope="col" class="ip-finance-month__col is-expense"><?= trans('management_cash_out', '', 'Uscite') ?></th>
                        <th scope="col" class="ip-items-table__total"><?= trans('balance', '', 'Saldo') ?></th>
                        <th scope="col" class="ip-items-table__total"><?= trans('management_cumulative', '', 'Cumulato') ?></th>
                        <th scope="col" class="ip-items-table__total">EBITDA</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($empty): ?>
                        <tr>
                            <td colspan="6" class="ip-items-table__empty">
                                <?= trans('management_no_cash_activity', '', 'Nessun incasso, pagamento o costo registrato per questo anno.') ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php for ($m = 1; $m <= 12; $m++):
                            $in    = (float) $cashflow['incassi'][$m];
                            $out   = (float) $cashflow['uscite'][$m];
                            $delta = (float) $cashflow['saldo'][$m];
                            $ebit  = (float) $totals['ebitda'][$m];
                        ?>
                            <tr class="<?= $in == 0.0 && $out == 0.0 && $ebit == 0.0 ? 'is-quiet' : '' ?>">
                                <td class="ip-finance-month__name"><?= esc($month_names[$m]) ?></td>
                                <td class="ip-finance-month__col is-income">
                                    <div class="ip-finance-month__cell">
                                        <span class="ip-finance-month__amount"><?= $fmt($in) ?></span>
                                        <?php if ($in > 0): ?>
                                            <span class="ip-finance-month__bar is-income"><span style="width: <?= $barWidth($in) ?>%"></span></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="ip-finance-month__col is-expense">
                                    <div class="ip-finance-month__cell">
                                        <span class="ip-finance-month__amount"><?= $fmt($out) ?></span>
                                        <?php if ($out > 0): ?>
                                            <span class="ip-finance-month__bar is-expense"><span style="width: <?= $barWidth($out) ?>%"></span></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="ip-items-table__total <?= $delta < 0 ? 'is-negative' : '' ?>">
                                    <strong><?= $fmt($delta) ?></strong>
                                </td>
                                <td class="ip-items-table__total <?= (float) $cashflow['cumulato'][$m] < 0 ? 'is-negative' : '' ?>">
                                    <?= $fmt($cashflow['cumulato'][$m]) ?>
                                </td>
                                <td class="ip-items-table__total <?= $ebit < 0 ? 'is-negative' : '' ?>"><?= $fmt($ebit) ?></td>
                            </tr>
                        <?php endfor; ?>
                    <?php endif; ?>
                    </tbody>
                    <?php if (!$empty): ?>
                        <tfoot>
                        <tr class="ip-finance-month__foot">
                            <th scope="row"><?= trans('total', '', 'Totale') ?></th>
                            <td><?= $fmt($kpi['cash_in']) ?></td>
                            <td><?= $fmt($kpi['cash_out']) ?></td>
                            <td class="ip-items-table__total <?= $kpi['cash_balance'] < 0 ? 'is-negative' : '' ?>">
                                <strong><?= $fmt($kpi['cash_balance']) ?></strong>
                            </td>
                            <td class="ip-items-table__total"><?= $fmt($cashflow['cumulato'][12]) ?></td>
                            <td class="ip-items-table__total <?= $kpi['ebitda'] < 0 ? 'is-negative' : '' ?>"><?= $fmt($kpi['ebitda']) ?></td>
                        </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <p class="ip-bilancino__hint">
                <i class="fa fa-solid fa-info-circle" aria-hidden="true"></i>
                <?= trans('management_cash_hint', '', 'Le uscite sono attribuite al mese del documento: l\'applicazione non registra la data dei singoli pagamenti di spesa. Il confronto con l\'EBITDA è indicativo, non un rendiconto finanziario.') ?>
            </p>
        </div>
    </section>
</div>

<?= $this->endSection() ?>
