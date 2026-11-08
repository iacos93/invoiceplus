<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
$this->setVar('pageTitle', trans('finance', '', 'Utile e imposte'));
$yearLabel = trans('finance', '', 'Utile e imposte') . ' · ' . (int) $year;
$actions = '<a class="btn btn-sm btn-secondary" href="' . site_url('finance/export/csv') . '" title="' . esc(trans('export_csv_hint', '', 'Tabella apribile in Excel, separata da punto e virgola. Per controlli e invio al commercialista.'), 'attr') . '">'
    . '<i class="fa fa-regular fa-file-text" aria-hidden="true"></i> ' . trans('export_csv', '', 'CSV') . '</a>'
    . '<a class="btn btn-sm btn-secondary" href="' . site_url('finance/export/pdf') . '" title="' . esc(trans('finance_pdf_hint', '', 'Il prospetto impaginato, da girare al commercialista.'), 'attr') . '">'
    . '<i class="fa fa-regular fa-file-pdf" aria-hidden="true"></i> ' . trans('export_pdf', '', 'PDF') . '</a>';
?>
<?= $this->include('layout/headerbar', ['title' => $yearLabel, 'actions' => $actions]) ?>

<div id="content">

    <div class="ip-preview">

        <div>
            <?php
            // Prospetto scalare: ricavi in alto, le sottrazioni in discesa, il
            // netto in fondo. È la forma del conto economico scalare, cioè il
            // documento che questa pagina riassume — e soprattutto è l'unica
            // che mostra il legame fra le cifre. Quattro riquadri affiancati
            // davano lo stesso peso agli addendi (ricavi, costi) e ai risultati
            // (utile, imposte), che è esattamente il rapporto che conta qui.
            $revenue  = (float) $income['net'];
            $costs    = (float) $expenses['amount'];
            $taxTotal = (float) $taxes['total'];
            $netAfter = (float) $taxes['net'];

            // La barra di ripartizione regge solo quando c'è un ricavo da
            // ripartire e resta qualcosa dopo costi e imposte: in perdita le
            // quote non sommerebbero a cento e la barra mentirebbe.
            $showSplit = $revenue > 0 && $netAfter > 0;
            $share     = static fn(float $v): float => $revenue > 0 ? round($v / $revenue * 100, 1) : 0.0;
            ?>
            <section class="ip-doc-section">
                <div class="ip-doc-section__head">
                    <span class="ip-doc-step"><i class="fa fa-solid fa-balance-scale" aria-hidden="true"></i></span>
                    <h2 class="ip-doc-section__title"><?= trans('statement', '', 'Prospetto') ?> <?= (int) $year ?></h2>
                </div>
                <div class="ip-doc-section__body">

                    <table class="ip-prospetto">
                        <caption class="ip-visually-hidden">
                            <?= trans('statement_caption', '', 'Ricavi meno costi e imposte stimate, con il netto risultante') ?>
                        </caption>
                        <tbody>
                        <tr>
                            <td class="ip-prospetto__op" aria-hidden="true"></td>
                            <th scope="row" class="ip-prospetto__label">
                                <?= trans('income', '', 'Ricavi') ?>
                                <span class="ip-prospetto__note">
                                    <?php /* L'incassato è di cassa e comprende
                                             l'IVA: sotto un ricavo netto non è
                                             una sua quota, va detto. */ ?>
                                    <?= trans('collected_gross', '', 'incassato, IVA inclusa') ?>
                                    <?= format_currency($income['collected']) ?>
                                </span>
                            </th>
                            <td class="ip-prospetto__amount"><?= format_currency($revenue) ?></td>
                        </tr>
                        <tr>
                            <td class="ip-prospetto__op" aria-hidden="true">&minus;</td>
                            <th scope="row" class="ip-prospetto__label">
                                <?= trans('expenses', '', 'Costi') ?>
                                <span class="ip-prospetto__note">
                                    <?= trans('of_which_deductible', '', 'di cui deducibili') ?>
                                    <?= format_currency($expenses['deductible']) ?>
                                </span>
                            </th>
                            <td class="ip-prospetto__amount is-expense"><?= format_currency($costs) ?></td>
                        </tr>
                        <tr class="is-subtotal">
                            <td class="ip-prospetto__op" aria-hidden="true">=</td>
                            <th scope="row" class="ip-prospetto__label"><?= trans('profit', '', 'Utile lordo') ?></th>
                            <td class="ip-prospetto__amount <?= $profit < 0 ? 'is-expense' : '' ?>">
                                <?= format_currency($profit) ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="ip-prospetto__op" aria-hidden="true">&minus;</td>
                            <th scope="row" class="ip-prospetto__label"><?= trans('estimated_taxes', '', 'Imposte stimate') ?></th>
                            <td class="ip-prospetto__amount is-tax"><?= format_currency($taxTotal) ?></td>
                        </tr>
                        <?php
                        // Composizione dell'imposta sotto la sua riga, invece che
                        // in un elenco separato a lato: la freccia dice che sono
                        // parti di quel totale, non voci nuove del prospetto.
                        foreach ($taxes['lines'] as $line): ?>
                            <tr class="is-detail">
                                <td class="ip-prospetto__op" aria-hidden="true">&#8627;</td>
                                <th scope="row" class="ip-prospetto__label"><?= esc($line['label']) ?></th>
                                <td class="ip-prospetto__amount"><?= format_currency($line['amount']) ?></td>
                            </tr>
                            <?php foreach ($line['children'] ?? [] as $child): ?>
                                <tr class="is-detail is-nested">
                                    <td class="ip-prospetto__op" aria-hidden="true"></td>
                                    <th scope="row" class="ip-prospetto__label"><?= esc($child['label']) ?></th>
                                    <td class="ip-prospetto__amount"><?= format_currency($child['amount']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        <tr class="is-result">
                            <td class="ip-prospetto__op" aria-hidden="true">=</td>
                            <th scope="row" class="ip-prospetto__label"><?= trans('net_after_tax', '', 'Netto stimato') ?></th>
                            <td class="ip-prospetto__amount <?= $netAfter < 0 ? 'is-expense' : 'is-net' ?>">
                                <?= format_currency($netAfter) ?>
                            </td>
                        </tr>
                        </tbody>
                    </table>

                    <?php if ($showSplit): ?>
                        <?php
                        // Dove finiscono i ricavi dell'anno. Le tre quote sommano
                        // per costruzione: costi + imposte + netto = ricavi.
                        $shareCosts = $share($costs);
                        $shareTax   = $share($taxTotal);
                        $shareNet   = $share($netAfter);
                        ?>
                        <div class="ip-split">
                            <p class="ip-doc-label"><?= trans('where_revenue_goes', '', 'Dove vanno i ricavi') ?></p>
                            <div class="ip-split__bar" role="img"
                                 aria-label="<?= esc(sprintf(
                                     trans('split_aria', '', 'Su %s di ricavi: %s in costi, %s in imposte, %s netto.'),
                                     format_currency($revenue),
                                     format_amount($shareCosts) . '%',
                                     format_amount($shareTax) . '%',
                                     format_amount($shareNet) . '%'
                                 )) ?>">
                                <span class="ip-split__seg is-costi" style="width: <?= $shareCosts ?>%"></span>
                                <span class="ip-split__seg is-imposte" style="width: <?= $shareTax ?>%"></span>
                                <span class="ip-split__seg is-netto" style="width: <?= $shareNet ?>%"></span>
                            </div>
                            <ul class="ip-split__legend">
                                <li><span class="ip-split__key is-costi"></span>
                                    <?= trans('expenses', '', 'Costi') ?>
                                    <b><?= format_amount($shareCosts) ?>%</b>
                                </li>
                                <li><span class="ip-split__key is-imposte"></span>
                                    <?= trans('taxes', '', 'Imposte') ?>
                                    <b><?= format_amount($shareTax) ?>%</b>
                                </li>
                                <li><span class="ip-split__key is-netto"></span>
                                    <?= trans('net_after_tax', '', 'Netto') ?>
                                    <b><?= format_amount($shareNet) ?>%</b>
                                </li>
                            </ul>
                        </div>
                    <?php elseif ($revenue > 0 || $costs > 0): ?>
                        <p class="ip-split__loss">
                            <i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i>
                            <?= trans('finance_at_loss', '', 'I costi superano i ricavi: per questo anno non risulta un utile da ripartire.') ?>
                        </p>
                    <?php endif; ?>

                </div>
            </section>

            <section class="ip-doc-section">
                <div class="ip-doc-section__head">
                    <span class="ip-doc-step"><i class="fa fa-solid fa-line-chart" aria-hidden="true"></i></span>
                    <h2 class="ip-doc-section__title"><?= trans('income_vs_expenses', '', 'Entrate e uscite per mese') ?></h2>
                </div>
                <div class="ip-doc-section__body">
                    <?php
                    // Le barre condividono la stessa scala (il massimo dell'anno):
                    // un ricavo di 4.000 € e un costo di 4.000 € hanno la stessa
                    // lunghezza, così il confronto tra le colonne è diretto.
                    $chartMax     = 0.0;
                    $totalIncome  = 0.0;
                    $totalExpense = 0.0;

                    foreach ($by_month as $row) {
                        $chartMax     = max($chartMax, (float) $row['income'], (float) $row['expense']);
                        $totalIncome  += (float) $row['income'];
                        $totalExpense += (float) $row['expense'];
                    }

                    $yearEmpty = $chartMax <= 0;

                    // Funzione per calcolare la larghezza della barra percentuale
                    $barWidth = static fn(float $v): float => $v > 0 && $chartMax > 0 ? max(2, round($v / $chartMax * 100, 1)) : 0;
                    ?>
                    <?php /* Contenitore scorrevole: la tabella tiene un min-width
                             di 640px per lasciare spazio alle barre, e su viewport
                             strette deve scorrere dentro di se' invece di spingere
                             fuori la card. */ ?>
                    <div class="table-responsive">
                    <table class="ip-items-table ip-finance-month">
                        <thead>
                        <tr>
                            <th scope="col"><?= trans('month', '', 'Mese') ?></th>
                            <th scope="col" class="ip-finance-month__col is-income"><?= trans('income', '', 'Ricavi') ?></th>
                            <th scope="col" class="ip-finance-month__col is-expense"><?= trans('expenses', '', 'Costi') ?></th>
                            <th scope="col" class="ip-items-table__total"><?= trans('balance', '', 'Saldo') ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($yearEmpty): ?>
                            <tr>
                                <td colspan="4" class="ip-items-table__empty">
                                    <?= trans('finance_no_activity', '', 'Nessun ricavo o costo registrato per questo anno.') ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($by_month as $m => $row): ?>
                                <?php
                                $mIncome  = (float) $row['income'];
                                $mExpense = (float) $row['expense'];
                                $delta    = $mIncome - $mExpense;

                                // Un mese senza movimenti resta in elenco — l'anno
                                // ha una forma, e i vuoti ne fanno parte — ma non
                                // disegna barre: prima ogni mese fermo stampava due
                                // binari grigi vuoti, e i mesi con lavoro dentro
                                // finivano in mezzo al rumore.
                                $mEmpty = $mIncome <= 0 && $mExpense <= 0;
                                ?>
                                <tr class="<?= $mEmpty ? 'is-quiet' : '' ?>">
                                    <td class="ip-finance-month__name"><?= esc($month_names[$m]) ?></td>
                                    <?php /* Le classi di colonna stanno anche sulle celle del corpo,
                                             non solo sulle intestazioni: il separatore fra Ricavi e
                                             Costi è disegnato da un selettore di adiacenza e sulle
                                             righe non aveva appigli — le barre sembravano attraversare
                                             il confine fra le due colonne. */ ?>
                                    <td class="ip-finance-month__col is-income">
                                        <div class="ip-finance-month__cell">
                                            <span class="ip-finance-month__amount"><?= format_currency($mIncome) ?></span>
                                            <?php if ($mIncome > 0): ?>
                                                <span class="ip-finance-month__bar is-income">
                                                    <span style="width: <?= $barWidth($mIncome) ?>%"></span>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="ip-finance-month__col is-expense">
                                        <div class="ip-finance-month__cell">
                                            <span class="ip-finance-month__amount"><?= format_currency($mExpense) ?></span>
                                            <?php if ($mExpense > 0): ?>
                                                <span class="ip-finance-month__bar is-expense">
                                                    <span style="width: <?= $barWidth($mExpense) ?>%"></span>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="ip-items-table__total ip-finance-month__saldo <?= $delta < 0 ? 'is-negative' : '' ?>">
                                        <strong><?= format_currency($delta) ?></strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                        <?php if (!$yearEmpty): ?>
                            <?php /* Senza totale le dodici righe non si legavano alle
                                     cifre del prospetto: restava da sommarle a mente
                                     per verificare che parlassero dello stesso anno. */ ?>
                            <tfoot>
                            <tr class="ip-finance-month__foot">
                                <th scope="row"><?= trans('total', '', 'Totale') ?></th>
                                <td><?= format_currency($totalIncome) ?></td>
                                <td><?= format_currency($totalExpense) ?></td>
                                <td class="ip-items-table__total ip-finance-month__saldo <?= ($totalIncome - $totalExpense) < 0 ? 'is-negative' : '' ?>">
                                    <strong><?= format_currency($totalIncome - $totalExpense) ?></strong>
                                </td>
                            </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                    </div>
                </div>
            </section>

            <section class="ip-doc-section">
                <div class="ip-doc-section__head">
                    <span class="ip-doc-step"><i class="fa fa-solid fa-pie-chart" aria-hidden="true"></i></span>
                    <h2 class="ip-doc-section__title"><?= trans('expenses_by_category', '', 'Costi per categoria') ?></h2>
                </div>
                <div class="ip-doc-section__body">
                    <?php
                    // Quota di ogni categoria sul totale dell'anno: la riga dice
                    // dove va il denaro, non solo quanto è stato speso.
                    $categoryTotal = array_sum(array_column($by_category, 'amount'));

                    // Se l'unica voce è il fondo "Senza categoria" il prospetto
                    // non dice ancora niente su dove vadano i soldi: la riga da
                    // sola non lo segnala, quindi lo si dice.
                    $onlyUncategorized = count($by_category) === 1
                        && ($by_category[0]['category'] ?? '') === 'Senza categoria';
                    ?>
                    <div class="table-responsive">
                    <table class="ip-items-table ip-finance-cat">
                        <thead>
                        <tr>
                            <th scope="col"><?= trans('category', '', 'Categoria') ?></th>
                            <th scope="col" class="ip-items-table__num"><?= trans('documents', '', 'Documenti') ?></th>
                            <th scope="col" class="ip-items-table__total"><?= trans('amount', '', 'Importo') ?></th>
                            <th scope="col" class="ip-finance-cat__share"><?= trans('share', '', 'Quota') ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($by_category as $row): ?>
                            <?php $pct = $categoryTotal > 0 ? round((float) $row['amount'] / $categoryTotal * 100, 1) : 0.0; ?>
                            <tr>
                                <td><?= esc($row['category']) ?></td>
                                <td class="ip-items-table__num"><?= (int) $row['documents'] ?></td>
                                <td class="ip-items-table__total"><?= format_currency($row['amount']) ?></td>
                                <td class="ip-finance-cat__share">
                                    <?= $categoryTotal > 0 ? format_amount($pct) . '%' : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($by_category)): ?>
                            <tr><td colspan="4" class="ip-items-table__empty"><?= trans('no_costs_recorded') ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    </div>

                    <?php if ($onlyUncategorized): ?>
                        <p class="ip-number-help ip-finance-cat__hint">
                            <?= trans('categorize_expenses_hint', '', 'Tutti i costi sono senza categoria. Assegnane una dalla scheda della spesa per vedere dove vanno i soldi.') ?>
                            <a href="<?= site_url('expenses') ?>"><?= trans('go_to_expenses', '', 'Vai alle spese') ?></a>
                        </p>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <aside class="ip-preview__side ip-finance-side">
            <div class="card">
                <?php /* Testata come quella delle card-elenco (icona in riquadro
                         + etichetta): il .card-header nudo metteva l'icona
                         da 30px in linea con il testo e nel tema scuro il
                         titolo restava l'unico punto della pagina senza la
                         gerarchia del resto. */ ?>
                <div class="card-header ip-table-card-header">
                    <div class="ip-table-card-title">
                        <span class="card-header-icon"><i class="fa fa-solid fa-calculator" aria-hidden="true"></i></span>
                        <h2 class="ip-table-card-label"><?= trans('tax_estimate', '', 'Stima imposte') ?></h2>
                    </div>
                </div>
                <div class="card-body">

                    <p class="ip-doc-label" style="margin-bottom:8px"><?= trans('applied_regime', '', 'Regime applicato') ?></p>
                    <dl class="ip-preview__totals">
                        <?php if (!empty($company['company_name'])): ?>
                            <dt><?= trans('company', '', 'Azienda') ?></dt>
                            <dd><?= esc($company['company_name']) ?></dd>
                        <?php endif; ?>
                        <dt><?= trans('company_form', '', 'Forma aziendale') ?></dt>
                        <dd><?= esc($forms[$form]['label']) ?></dd>
                        <?php if ($forms[$form]['mode'] === 'flat'): ?>
                            <dt><?= trans('coefficient', '', 'Coefficiente di redditività') ?></dt>
                            <dd><?= format_amount($coefficient) ?>%</dd>
                        <?php endif; ?>
                        <?php if ($forms[$form]['mode'] !== 'corporate'): ?>
                            <dt><?= trans('contributions', '', 'Aliquota contributi') ?></dt>
                            <dd><?= format_amount($contributions) ?>%</dd>
                        <?php endif; ?>
                    </dl>
                    <p class="ip-number-help" style="margin-top:8px">
                        <?= trans('regime_comes_from_company', '', 'Il regime è quello dell\'azienda: per cambiarlo vai nella') ?>
                        <a href="<?= site_url('companies') ?>"><?= trans('scheda_azienda', '', 'scheda azienda') ?></a>.
                    </p>

                    <hr>

                    <?php /* Le singole voci d'imposta stanno nel prospetto, sotto
                             la riga che compongono. Qui resta come si e' arrivati
                             a quel numero: imponibile e legislazione applicata. */ ?>
                    <p class="ip-doc-label" style="margin-bottom:8px"><?= trans('estimate_basis', '', 'Base della stima') ?></p>
                    <dl class="ip-preview__totals">
                        <dt><?= trans('taxable', '', 'Imponibile') ?></dt>
                        <dd><?= format_currency($taxes['taxable']) ?></dd>
                        <dt><?= trans('fiscal_params_year', '', 'Parametri fiscali') ?></dt>
                        <dd>
                            <a href="<?= site_url('fiscal_years') ?>"><?= (int) $fiscal_year ?></a>
                            <?php if (!empty($fiscal_projection)): ?>
                                <span class="badge ip-status text-bg-warning"><?= trans('projection', '', 'Proiezione') ?></span>
                            <?php endif; ?>
                        </dd>
                    </dl>

                    <?php if (!empty($fiscal_projection)): ?>
                        <p class="ip-number-help" style="margin-top:8px">
                            <?= esc(sprintf(
                                trans('projection_notice', '', 'I parametri del %d sono stimati, non ancora pubblicati: aggiornali appena escono i valori ufficiali.'),
                                (int) $fiscal_year
                            )) ?>
                        </p>
                    <?php endif; ?>

                    <?php if (!$fiscal_exact): ?>
                        <p class="ip-number-help" style="margin-top:8px">
                            <?= esc(sprintf(
                                trans('fiscal_year_fallback', '', 'Per il %d non sono definiti parametri propri: la stima usa quelli del %d, l\'anno coperto più vicino.'),
                                (int) $year,
                                (int) $fiscal_year
                            )) ?>
                        </p>
                    <?php endif; ?>

                    <?php foreach ($taxes['warnings'] ?? [] as $warning): ?>
                        <div class="alert alert-danger small" style="margin-top:10px; margin-bottom:0">
                            <i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i> <?= esc($warning) ?>
                        </div>
                    <?php endforeach; ?>

                    <p class="ip-number-help" style="margin-top:12px">
                        <?= esc($forms[$form]['note']) ?>
                    </p>
                    <div class="alert alert-warning small" style="margin-top:10px; margin-bottom:0">
                        <i class="fa fa-solid fa-info-circle" aria-hidden="true"></i>
                        <?= trans('tax_disclaimer', '', 'Stima gestionale per accantonare durante l\'anno. Non tiene conto di altri redditi, detrazioni personali, acconti versati e minimi contributivi: il calcolo definitivo resta del commercialista.') ?>
                    </div>

                    <?php
                    /* L'accantonamento stimato è la cifra che poi si versa con
                       una delega: portarla nell'F24 evita di ricopiarla a mano
                       (e di sbagliare una cifra). Resta un suggerimento, la
                       delega si compila e si conferma nell'altra pagina. */
                    if ($taxTotal > 0):
                    ?>
                        <a class="btn btn-primary w-100" style="margin-top:12px"
                           href="<?= site_url('f24/form') ?>?amount=<?= number_format($taxTotal, 2, '.', '') ?>&amp;period=<?= rawurlencode(trans('estimated_taxes', '', 'Imposte stimate') . ' ' . (int) $year) ?>">
                            <i class="fa fa-solid fa-building-columns" aria-hidden="true"></i>
                            <?= trans('register_f24', '', 'Registra F24') ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </aside>
    </div>
</div>

<?= $this->endSection() ?>
