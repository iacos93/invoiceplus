<!DOCTYPE html>
<html lang="<?= trans('cldr') ?>">
<head>
    <meta charset="utf-8">
    <title><?= trans('finance', '', 'Utile e imposte') ?> <?= (int) $year ?></title>
    <style>
        /* Impaginazione del prospetto per Dompdf: niente variabili CSS, solo
           regole che il motore di rendering di Dompdf sappia rispettare. */
        @page { size: A4; }
        body {
            font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #333; margin: 0;
            /* Conserva gli sfondi delle righe "totale"/"risultato" anche nella
               stampa dal browser (altrimenti il testo in bianco su sfondo
               colorato diventerebbe illeggibile); dompdf li rende comunque. */
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        h1 { font-size: 19px; color: #2c3e50; margin: 0 0 2px; }
        .meta { font-size: 11px; color: #888; margin-bottom: 14px; }
        h2 {
            font-size: 13.5px; color: #2c3e50; margin: 18px 0 6px;
            padding-bottom: 4px; border-bottom: 1px solid #ccc;
        }
        table { width: 100%; border-collapse: collapse; margin: 6px 0; }
        th {
            background: #f2f2f2; border: 1px solid #ccc; padding: 5px 7px;
            font-size: 11px; text-align: left;
        }
        td { border: 1px solid #ddd; padding: 5px 7px; font-size: 11px; }
        .num { text-align: right; white-space: nowrap; }
        .det { color: #666; padding-left: 18px; }
        .total td { font-weight: bold; background: #fafafa; }
        .result td { font-weight: bold; background: #eef4fb; }
        .pos { color: #27ae60; }
        .neg { color: #c0392b; }
        .infobox { margin: 14px 0; padding: 8px 10px; border: 1px solid #ddd; border-radius: 3px; }
        .infobox table { margin: 0; }
        .infobox td { border: none; padding: 2px 7px; }
        .infobox .k { color: #666; width: 40%; }
        .note { font-size: 10px; color: #888; margin-top: 18px; border-top: 1px solid #ddd; padding-top: 6px; }
        .warn { font-size: 10px; color: #c0392b; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>

<h1><?= trans('finance', '', 'Utile e imposte') ?> — <?= (int) $year ?></h1>
<div class="meta">
    <?= esc($company['company_name'] ?? '') ?>
    &nbsp;·&nbsp; <?= trans('generated_on', '', 'Generato il') ?> <?= esc(date(date_format_setting())) ?>
</div>

<h2><?= trans('statement', '', 'Prospetto') ?></h2>
<table>
    <tbody>
    <tr>
        <td><?= trans('income', '', 'Ricavi') ?></td>
        <td class="num"><?= format_currency($income['net']) ?></td>
        <td class="num muted"><?= trans('collected_gross', '', 'incassato, IVA inclusa') ?> <?= format_currency($income['collected']) ?></td>
    </tr>
    <tr>
        <td><?= trans('expenses', '', 'Costi') ?></td>
        <td class="num neg">&minus;<?= format_currency($expenses['amount']) ?></td>
        <td class="num muted"><?= trans('of_which_deductible', '', 'di cui deducibili') ?> <?= format_currency($expenses['deductible']) ?></td>
    </tr>
    <tr class="total">
        <td><?= trans('profit', '', 'Utile lordo') ?></td>
        <td class="num <?= $profit < 0 ? 'neg' : 'pos' ?>"><?= format_currency($profit) ?></td>
        <td></td>
    </tr>
    <tr>
        <td><?= trans('estimated_taxes', '', 'Imposte stimate') ?></td>
        <td class="num">&minus;<?= format_currency($taxes['total']) ?></td>
        <td></td>
    </tr>
    <?php foreach ($taxes['lines'] as $line): ?>
        <tr>
            <td class="det">&#8627; <?= esc($line['label']) ?></td>
            <td class="num"><?= format_currency($line['amount']) ?></td>
            <td></td>
        </tr>
        <?php foreach ($line['children'] ?? [] as $child): ?>
            <tr>
                <td class="det" style="padding-left:30px"><?= esc($child['label']) ?></td>
                <td class="num"><?= format_currency($child['amount']) ?></td>
                <td></td>
            </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    <tr class="result">
        <td><?= trans('net_after_tax', '', 'Netto stimato') ?></td>
        <td class="num <?= $taxes['net'] < 0 ? 'neg' : 'pos' ?>"><?= format_currency($taxes['net']) ?></td>
        <td></td>
    </tr>
    </tbody>
</table>

<div class="infobox">
    <table>
        <?php if (!empty($company['company_name'])): ?>
            <tr><td class="k"><?= trans('company', '', 'Azienda') ?></td><td><?= esc($company['company_name']) ?></td></tr>
        <?php endif; ?>
        <tr><td class="k"><?= trans('company_form', '', 'Forma aziendale') ?></td><td><?= esc($forms[$form]['label']) ?></td></tr>
        <?php if ($forms[$form]['mode'] === 'flat'): ?>
            <tr><td class="k"><?= trans('coefficient', '', 'Coefficiente di redditività') ?></td><td><?= format_amount($coefficient) ?>%</td></tr>
        <?php endif; ?>
        <?php if ($forms[$form]['mode'] !== 'corporate'): ?>
            <tr><td class="k"><?= trans('contributions', '', 'Aliquota contributi') ?></td><td><?= format_amount($contributions) ?>%</td></tr>
        <?php endif; ?>
        <tr><td class="k"><?= trans('taxable', '', 'Imponibile') ?></td><td><?= format_currency($taxes['taxable']) ?></td></tr>
        <tr>
            <td class="k"><?= trans('fiscal_params_year', '', 'Parametri fiscali') ?></td>
            <td><?= (int) $fiscal_year ?>
                <?= $fiscal_projection ? '— ' . trans('projection', '', 'Proiezione') : '' ?></td>
        </tr>
    </table>
    <?php if (!$fiscal_exact): ?>
        <p class="warn">
            <?= esc(sprintf(
                trans('fiscal_year_fallback', '', 'Per il %d non sono definiti parametri propri: la stima usa quelli del %d.'),
                (int) $year,
                (int) $fiscal_year
            )) ?>
        </p>
    <?php endif; ?>
    <?php foreach ($taxes['warnings'] ?? [] as $warning): ?>
        <p class="warn"><?= esc($warning) ?></p>
    <?php endforeach; ?>
</div>

<h2><?= trans('income_vs_expenses', '', 'Entrate e uscite per mese') ?></h2>
<table>
    <thead>
    <tr>
        <th scope="col"><?= trans('month', '', 'Mese') ?></th>
        <th scope="col" class="num"><?= trans('income', '', 'Ricavi') ?></th>
        <th scope="col" class="num"><?= trans('expenses', '', 'Costi') ?></th>
        <th scope="col" class="num"><?= trans('balance', '', 'Saldo') ?></th>
    </tr>
    </thead>
    <tbody>
    <?php
    $totalIncome  = 0.0;
    $totalExpense = 0.0;
    ?>
    <?php foreach ($by_month as $m => $row): ?>
        <?php
        $mIncome  = (float) $row['income'];
        $mExpense = (float) $row['expense'];
        $totalIncome  += $mIncome;
        $totalExpense += $mExpense;
        $delta    = $mIncome - $mExpense;
        ?>
        <tr>
            <td><?= esc($month_names[$m]) ?></td>
            <td class="num"><?= format_currency($mIncome) ?></td>
            <td class="num"><?= format_currency($mExpense) ?></td>
            <td class="num <?= $delta < 0 ? 'neg' : '' ?>"><?= format_currency($delta) ?></td>
        </tr>
    <?php endforeach; ?>
    <tr class="total">
        <td><?= trans('total', '', 'Totale') ?></td>
        <td class="num"><?= format_currency($totalIncome) ?></td>
        <td class="num"><?= format_currency($totalExpense) ?></td>
        <td class="num <?= ($totalIncome - $totalExpense) < 0 ? 'neg' : '' ?>"><?= format_currency($totalIncome - $totalExpense) ?></td>
    </tr>
    </tbody>
</table>

<h2><?= trans('expenses_by_category', '', 'Costi per categoria') ?></h2>
<table>
    <thead>
    <tr>
        <th scope="col"><?= trans('category', '', 'Categoria') ?></th>
        <th scope="col" class="num"><?= trans('documents', '', 'Documenti') ?></th>
        <th scope="col" class="num"><?= trans('amount', '', 'Importo') ?></th>
        <th scope="col" class="num"><?= trans('share', '', 'Quota') ?></th>
    </tr>
    </thead>
    <tbody>
    <?php $categoryTotal = array_sum(array_column($by_category, 'amount')); ?>
    <?php foreach ($by_category as $row): ?>
        <?php $pct = $categoryTotal > 0 ? round((float) $row['amount'] / $categoryTotal * 100, 1) : 0.0; ?>
        <tr>
            <td><?= esc($row['category']) ?></td>
            <td class="num"><?= (int) $row['documents'] ?></td>
            <td class="num"><?= format_currency($row['amount']) ?></td>
            <td class="num"><?= $categoryTotal > 0 ? format_amount($pct) . '%' : '—' ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($by_category)): ?>
        <tr><td colspan="4"><?= trans('no_costs_recorded') ?></td></tr>
    <?php endif; ?>
    </tbody>
</table>

<p class="note">
    <?= esc($forms[$form]['note']) ?>
    <br>
    <?= trans('tax_disclaimer', '', 'Stima gestionale per accantonare durante l\'anno. Non tiene conto di altri redditi, detrazioni personali, acconti versati e minimi contributivi: il calcolo definitivo resta del commercialista.') ?>
</p>

</body>
</html>