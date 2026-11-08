<!DOCTYPE html>
<html lang="<?= trans('cldr') ?>">
<head>
    <meta charset="utf-8">
    <title><?= trans('management', '', 'Bilancino') ?> <?= (int) $year ?></title>
    <style>
        /* Dompdf: solo regole semplici, niente variabili CSS. La tabella ha
           dodici colonne mensili: caratteri piccoli e celle strette, altrimenti
           le cifre vanno a capo e il prospetto diventa illeggibile. */
        @page { size: A4 landscape; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10px; color: #333; margin: 0; }
        h1 { font-size: 17px; color: #2c3e50; margin: 0 0 2px; }
        .meta { font-size: 10px; color: #888; margin-bottom: 12px; }
        h2 { font-size: 12px; color: #2c3e50; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #ccc; }
        table { width: 100%; border-collapse: collapse; margin: 5px 0; }
        th { background: #f2f2f2; border: 1px solid #ccc; padding: 3px 4px; font-size: 9px; text-align: left; }
        td { border: 1px solid #ddd; padding: 3px 4px; font-size: 9px; }
        .num { text-align: right; white-space: nowrap; }
        .section th { background: #2c3e50; color: #fff; font-size: 9.5px; text-transform: uppercase; }
        .section2 th { background: #eef4fb; font-weight: bold; }
        .subtotal th, .subtotal td { font-weight: bold; background: #fafafa; }
        .result th, .result td { font-weight: bold; background: #eef4fb; }
        .unassigned th, .unassigned td { color: #8a6d3b; background: #fcf8e3; }
        .neg { color: #c0392b; }
        .pos { color: #27ae60; }
        .indent1 { padding-left: 12px; }
        .indent2 { padding-left: 22px; }
        .note { font-size: 9px; color: #888; margin-top: 14px; border-top: 1px solid #ddd; padding-top: 5px; }
    </style>
</head>
<body>

<?php
$fmt = static fn($v): string => format_currency((float) $v);
$company = active_company();
?>

<h1><?= trans('management', '', 'Bilancino') ?> — <?= (int) $year ?></h1>
<div class="meta">
    <?= esc($company['company_name'] ?? '') ?>
    &nbsp;·&nbsp; <?= trans('generated_on', '', 'Generato il') ?> <?= esc(date(date_format_setting())) ?>
</div>

<h2><?= trans('management_report_title', '', 'Conto economico per voce') ?></h2>
<table>
    <thead>
    <tr>
        <th scope="col"><?= trans('management_item', '', 'Voce') ?></th>
        <th scope="col"><?= trans('management_code', '', 'Cod.') ?></th>
        <?php foreach ($month_names as $name): ?>
            <th scope="col" class="num"><?= esc(mb_substr($name, 0, 3)) ?></th>
        <?php endforeach; ?>
        <th scope="col" class="num"><?= trans('total', '', 'Totale') ?></th>
    </tr>
    </thead>
    <tbody>
    <?php
    $sections = [
        'ricavo'          => trans('management_revenue', '', 'Ricavi'),
        'costo_variabile' => trans('management_variable_costs', '', 'Costi variabili'),
        'costo_fisso'     => trans('management_fixed_costs', '', 'Costi fissi'),
    ];

    foreach ($sections as $type => $label):
        $totalSeries = $totals[$type];
    ?>
        <tr class="section"><th scope="colgroup" colspan="15"><?= esc($label) ?></th></tr>

        <?php foreach ($accounts[$type] as $row): ?>
            <tr>
                <th scope="row" class="<?= (int) $row['level'] > 0 ? 'indent1' : '' ?>"><?= esc($row['name']) ?></th>
                <td><?= esc($row['code']) ?></td>
                <?php foreach ($row['months'] as $value): ?>
                    <td class="num <?= (float) $value < 0 ? 'neg' : '' ?>"><?= $fmt($value) ?></td>
                <?php endforeach; ?>
                <td class="num"><b><?= $fmt($row['total']) ?></b></td>
            </tr>
        <?php endforeach; ?>

        <tr class="subtotal">
            <th scope="colgroup" colspan="2"><?= trans('total', '', 'Totale') ?> <?= esc(mb_strtolower($label)) ?></th>
            <?php foreach ($totalSeries as $value): ?>
                <td class="num"><?= $fmt($value) ?></td>
            <?php endforeach; ?>
            <td class="num"><?= $fmt(array_sum($totalSeries)) ?></td>
        </tr>

        <?php if ($type === 'ricavo'): ?>
            <tr class="unassigned">
                <th scope="colgroup" colspan="2"><?= trans('management_unassigned_revenue', '', 'Ricavi non assegnati') ?></th>
                <?php foreach ($unassigned['ricavo'] as $value): ?>
                    <td class="num"><?= $fmt($value) ?></td>
                <?php endforeach; ?>
                <td class="num"><?= $fmt(array_sum($unassigned['ricavo'])) ?></td>
            </tr>
        <?php endif; ?>

        <?php if ($type === 'costo_variabile'): ?>
            <tr class="result">
                <th scope="colgroup" colspan="2"><?= trans('management_margin', '', 'Margine di contribuzione') ?></th>
                <?php foreach ($totals['margine'] as $value): ?>
                    <td class="num <?= (float) $value < 0 ? 'neg' : '' ?>"><?= $fmt($value) ?></td>
                <?php endforeach; ?>
                <td class="num"><?= $fmt(array_sum($totals['margine'])) ?></td>
            </tr>
        <?php endif; ?>

        <?php if ($type === 'costo_fisso'): ?>
            <tr class="unassigned">
                <th scope="colgroup" colspan="2"><?= trans('management_unassigned_costs', '', 'Costi non assegnati') ?></th>
                <?php foreach ($unassigned['costo'] as $value): ?>
                    <td class="num"><?= $fmt($value) ?></td>
                <?php endforeach; ?>
                <td class="num"><?= $fmt(array_sum($unassigned['costo'])) ?></td>
            </tr>
            <tr class="result">
                <th scope="colgroup" colspan="2">EBITDA</th>
                <?php foreach ($totals['ebitda'] as $value): ?>
                    <td class="num <?= (float) $value < 0 ? 'neg' : '' ?>"><?= $fmt($value) ?></td>
                <?php endforeach; ?>
                <td class="num"><?= $fmt(array_sum($totals['ebitda'])) ?></td>
            </tr>
        <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
</table>

<h2><?= trans('management_cashflow', '', 'Flusso di cassa') ?></h2>
<table>
    <thead>
    <tr>
        <th scope="col"><?= trans('management_month', '', 'Mese') ?></th>
        <th scope="col" class="num"><?= trans('management_cash_in', '', 'Incassi') ?></th>
        <th scope="col" class="num"><?= trans('management_cash_out', '', 'Uscite') ?></th>
        <th scope="col" class="num"><?= trans('balance', '', 'Saldo') ?></th>
        <th scope="col" class="num"><?= trans('management_cumulative', '', 'Cumulato') ?></th>
        <th scope="col" class="num">EBITDA</th>
    </tr>
    </thead>
    <tbody>
    <?php for ($m = 1; $m <= 12; $m++): ?>
        <tr>
            <th scope="row"><?= esc($month_names[$m]) ?></th>
            <td class="num"><?= $fmt($cashflow['incassi'][$m]) ?></td>
            <td class="num"><?= $fmt($cashflow['uscite'][$m]) ?></td>
            <td class="num <?= (float) $cashflow['saldo'][$m] < 0 ? 'neg' : '' ?>"><?= $fmt($cashflow['saldo'][$m]) ?></td>
            <td class="num <?= (float) $cashflow['cumulato'][$m] < 0 ? 'neg' : '' ?>"><?= $fmt($cashflow['cumulato'][$m]) ?></td>
            <td class="num <?= (float) $totals['ebitda'][$m] < 0 ? 'neg' : '' ?>"><?= $fmt($totals['ebitda'][$m]) ?></td>
        </tr>
    <?php endfor; ?>
    <tr class="subtotal">
        <th scope="row"><?= trans('total', '', 'Totale') ?></th>
        <td class="num"><?= $fmt($kpi['cash_in']) ?></td>
        <td class="num"><?= $fmt($kpi['cash_out']) ?></td>
        <td class="num <?= $kpi['cash_balance'] < 0 ? 'neg' : '' ?>"><?= $fmt($kpi['cash_balance']) ?></td>
        <td class="num"><?= $fmt($cashflow['cumulato'][12]) ?></td>
        <td class="num <?= $kpi['ebitda'] < 0 ? 'neg' : '' ?>"><?= $fmt($kpi['ebitda']) ?></td>
    </tr>
    </tbody>
</table>

<p class="note">
    <?= trans('management_pdf_note', '', 'Stima gestionale: l\'EBITDA non tiene conto di ammortamenti e interessi; le uscite di cassa sono attribuite al mese del documento. Non sostituisce il bilancio civilistico.') ?>
</p>

</body>
</html>
