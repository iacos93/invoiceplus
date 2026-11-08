<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
/**
 * Bilancino — conto economico riclassificato per destinazione.
 *
 * Dodici colonne rendono la tabella larga: scorre dentro il proprio
 * contenitore invece di allargare la pagina. Le voci di secondo livello sono
 * rientrate, ma il padre mostra già il totale dei figli, quindi la somma
 * delle righe visibili non va rifatta a mente per controllare il risultato.
 */
$this->setVar('pageTitle', trans('management', '', 'Controllo di gestione'));

$year  = (int) $year;
$kpi   = $kpi;
$fmt   = static fn($v): string => format_currency((float) $v);
$num   = static fn($v): string => format_amount((float) $v);
// Celle mensili: lo zero diventa un trattino. Con il piano appena creato la
// tabella è quasi tutta a zero, e trenta righe di "0,00 €" nascondevano i
// pochi importi veri; il trattino dice "nessun movimento" senza fare rumore.
// La colonna Totale e le righe di risultato (margine, EBITDA) restano in
// cifre: lì uno zero è un'informazione, non un mese vuoto.
$cell  = static fn($v): string => (float) $v == 0.0 ? '—' : format_currency((float) $v);

// Solo le esportazioni: Piano dei conti, Budget e Assegna voci erano anche
// qui, ma sono già nelle schede sotto l'intestazione e nella sidebar. Tre
// copie dello stesso link rendevano l'intestazione una seconda barra di
// navigazione e spingevano CSV e PDF, le uniche azioni della pagina, in coda.
$actions = '<a class="btn btn-sm btn-secondary" href="' . site_url('management/export/csv') . '">'
    . '<i class="fa fa-regular fa-file-text" aria-hidden="true"></i> CSV</a>'
    . '<a class="btn btn-sm btn-secondary" href="' . site_url('management/export/pdf') . '">'
    . '<i class="fa fa-regular fa-file-pdf" aria-hidden="true"></i> PDF</a>';

$scopeTitle = trans('management', '', 'Controllo di gestione') . ' · ' . $year;

$sectionLabels = [
    'ricavo'          => trans('management_revenue', '', 'Ricavi'),
    'costo_variabile' => trans('management_variable_costs', '', 'Costi variabili'),
    'costo_fisso'     => trans('management_fixed_costs', '', 'Costi fissi'),
];

// Le sezioni con il loro totale: il margine si legge fra variabili e fissi,
// dove si forma, non in fondo alla tabella.
$sections = [
    ['type' => 'ricavo',          'total' => $totals['ricavo'],          'total_label' => trans('management_total_revenue', '', 'Totale ricavi')],
    ['type' => 'costo_variabile', 'total' => $totals['costo_variabile'], 'total_label' => trans('management_total_variable', '', 'Totale costi variabili')],
    ['type' => 'costo_fisso',     'total' => $totals['costo_fisso'],     'total_label' => trans('management_total_fixed', '', 'Totale costi fissi')],
];
?>

<?= $this->include('layout/headerbar', ['title' => $scopeTitle, 'actions' => $actions]) ?>

<div id="content">

    <?= $this->include('management/_tabs', ['active' => 'report']) ?>

    <?php if (($accounts_count ?? 0) === 0): ?>
        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step"><i class="fa fa-solid fa-sitemap" aria-hidden="true"></i></span>
                <h2 class="ip-doc-section__title"><?= trans('management_empty_title', '', 'Prima il piano dei conti') ?></h2>
            </div>
            <div class="ip-doc-section__body">
                <p><?= trans('management_empty_body', '', 'Il Bilancino legge i ricavi e i costi in base alle voci del piano dei conti. Puoi partire dal piano standard — venti voci già divise fra ricavi, costi variabili e costi fissi — e personalizzarlo dopo.') ?></p>
                <div class="ip-bilancino-actions">
                    <form method="post" action="<?= site_url('management/accounts/import_standard') ?>" class="d-inline">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-solid fa-download" aria-hidden="true"></i>
                            <?= trans('management_import_standard', '', 'Importa piano standard') ?>
                        </button>
                    </form>
                    <a class="btn btn-secondary" href="<?= site_url('management/accounts/form') ?>">
                        <i class="fa fa-solid fa-plus" aria-hidden="true"></i>
                        <?= trans('management_new_account', '', 'Crea una voce') ?>
                    </a>
                </div>
            </div>
        </section>
    <?php else: ?>

        <?php
        // Quattro numeri che riassumono l'anno. Il margine e l'EBITDA hanno
        // accanto la percentuale sui ricavi: 20.000 € di EBITDA su 100.000 €
        // e su 400.000 € sono due aziende diverse.
        $cards = [
            [
                // "Assegnati" e non "Ricavi": finché il piano è vuoto o le
                // righe non sono classificate questa cifra è zero, mentre in
                // fattura i ricavi ci sono. Chiamarla "Ricavi" e basta
                // farebbe sembrare rotto il modulo al primo avvio; la nota
                // accanto dice dove sono finiti gli altri.
                'label' => trans('management_revenue_assigned', '', 'Ricavi assegnati'),
                'value' => $fmt($kpi['revenue']),
                'note'  => trans('management_unassigned_revenue', '', 'Ricavi non assegnati') . ': ' . $fmt($kpi['unassigned_revenue']),
                'class' => 'is-revenue',
            ],
            [
                'label' => trans('management_margin', '', 'Margine di contribuzione'),
                'value' => $fmt($kpi['margin']),
                'note'  => $kpi['margin_rate'] !== null ? $num($kpi['margin_rate']) . '% ' . trans('management_of_revenue', '', 'dei ricavi') : '—',
                'class' => $kpi['margin'] < 0 ? 'is-negative' : 'is-margin',
            ],
            [
                'label' => 'EBITDA',
                'value' => $fmt($kpi['ebitda']),
                'note'  => $kpi['ebitda_rate'] !== null ? $num($kpi['ebitda_rate']) . '% ' . trans('management_of_revenue', '', 'dei ricavi') : '—',
                'class' => $kpi['ebitda'] < 0 ? 'is-negative' : 'is-ebitda',
            ],
            [
                'label' => trans('management_cash_balance', '', 'Saldo di cassa'),
                'value' => $fmt($kpi['cash_balance']),
                'note'  => trans('management_cash_in', '', 'Incassi') . ': ' . $fmt($kpi['cash_in']),
                'class' => $kpi['cash_balance'] < 0 ? 'is-negative' : 'is-cash',
            ],
        ];
        ?>

        <?php
        // Completezza: è la prima cosa da sapere, perché se metà dei
        // documenti non ha una voce margine ed EBITDA sono numeri parziali.
        // Prima lo diceva solo una nota sotto la tabella, cioè dopo trenta
        // righe e dopo quattro KPI che sembravano sbagliati.
        $unRev   = array_sum(array_map('floatval', $unassigned['ricavo']));
        $unCost  = array_sum(array_map('floatval', $unassigned['costo']));
        $allRev  = (float) $kpi['revenue'] + $unRev;
        $covered = $allRev > 0 ? (float) $kpi['revenue'] / $allRev * 100 : 100.0;
        ?>
        <?php if ($unRev != 0.0 || $unCost != 0.0): ?>
            <div class="ip-bilancino-coverage" role="status">
                <div class="ip-bilancino-coverage__text">
                    <strong><?= sprintf(trans('management_coverage_title', '', 'Ricavi classificati: %s%%'), $num($covered)) ?></strong>
                    <span>
                        <?= sprintf(
                            trans('management_coverage_body', '', 'Restano da assegnare %s di ricavi e %s di costi: margine ed EBITDA sono parziali finché non hanno una voce.'),
                            $fmt($unRev),
                            $fmt($unCost)
                        ) ?>
                    </span>
                    <span class="ip-bilancino-coverage__bar" aria-hidden="true"><span style="width: <?= round(min(100, max(0, $covered)), 1) ?>%"></span></span>
                </div>
                <a class="btn btn-sm btn-primary" href="<?= site_url('management/assign') ?>">
                    <i class="fa fa-solid fa-link" aria-hidden="true"></i>
                    <?= trans('management_assign', '', 'Assegna voci') ?>
                </a>
            </div>
        <?php endif; ?>

        <div class="ip-bilancino-kpi">
            <?php foreach ($cards as $card): ?>
                <div class="ip-bilancino-kpi__card <?= esc($card['class']) ?>">
                    <span class="ip-bilancino-kpi__label"><?= esc($card['label']) ?></span>
                    <span class="ip-bilancino-kpi__value"><?= $card['value'] ?></span>
                    <span class="ip-bilancino-kpi__note"><?= esc($card['note']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step"><i class="fa fa-solid fa-table" aria-hidden="true"></i></span>
                <h2 class="ip-doc-section__title"><?= trans('management_report_title', '', 'Conto economico per voce') ?></h2>
                <?php /* La tabella ha dodici mesi + totale e non entra nella
                         colonna: lo scroll orizzontale c'è, ma su desktop senza
                         trackpad non si vede. La prima colonna è sticky, così
                         il nome della voce resta leggibile mentre si scorre. */ ?>
                <span class="ip-doc-section__hint ip-bilancino-scroll-hint">
                    <i class="fa fa-solid fa-left-right" aria-hidden="true"></i>
                    <?= trans('scroll_for_months', '', 'Scorri per i mesi') ?>
                </span>
            </div>
            <div class="ip-doc-section__body">
                <div class="table-responsive">
                    <table class="ip-bilancino">
                        <caption class="ip-visually-hidden">
                            <?= trans('management_report_caption', '', 'Ricavi, costi variabili e fissi per voce e mese, con margine di contribuzione ed EBITDA.') ?>
                        </caption>
                        <thead>
                        <tr>
                            <th scope="col" class="ip-bilancino__name"><?= trans('management_item', '', 'Voce') ?></th>
                            <th scope="col" class="ip-bilancino__code"><?= trans('management_code', '', 'Cod.') ?></th>
                            <?php foreach ($month_names as $name): ?>
                                <th scope="col" class="ip-bilancino__month"><?= esc(mb_substr($name, 0, 3)) ?></th>
                            <?php endforeach; ?>
                            <th scope="col" class="ip-bilancino__total"><?= trans('total', '', 'Totale') ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sections as $index => $section): ?>
                            <tr class="ip-bilancino__section">
                                <th scope="colgroup" colspan="15"><span class="ip-bilancino__band"><?= esc($sectionLabels[$section['type']]) ?></span></th>
                            </tr>

                            <?php if ($accounts[$section['type']] === []): ?>
                                <tr>
                                    <td colspan="15" class="ip-bilancino__empty">
                                        <?= trans('management_no_accounts_type', '', 'Nessuna voce di questo tipo: aggiungine una dal piano dei conti.') ?>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($accounts[$section['type']] as $row): ?>
                                <tr class="<?= $row['has_children'] ? 'is-parent' : '' ?>">
                                    <th scope="row" class="ip-bilancino__name" style="--ip-level: <?= (int) $row['level'] ?>">
                                        <span class="ip-bilancino__indent" aria-hidden="true"></span>
                                        <?php if ($row['has_children']): ?>
                                            <i class="fa fa-solid fa-caret-down ip-bilancino__caret" aria-hidden="true"></i>
                                        <?php endif; ?>
                                        <?= esc($row['name']) ?>
                                    </th>
                                    <td class="ip-bilancino__code"><?= esc($row['code']) ?></td>
                                    <?php foreach ($row['months'] as $value): ?>
                                        <td class="ip-bilancino__month <?= (float) $value == 0.0 ? 'is-zero' : '' ?>"><?= $cell($value) ?></td>
                                    <?php endforeach; ?>
                                    <td class="ip-bilancino__total"><b><?= $fmt($row['total']) ?></b></td>
                                </tr>
                            <?php endforeach; ?>

                            <tr class="is-subtotal">
                                <th scope="row" class="ip-bilancino__name"><?= esc($section['total_label']) ?></th>
                                <td class="ip-bilancino__code"></td>
                                <?php foreach ($section['total'] as $value): ?>
                                    <td class="ip-bilancino__month <?= (float) $value == 0.0 ? 'is-zero' : '' ?>"><?= $cell($value) ?></td>
                                <?php endforeach; ?>
                                <td class="ip-bilancino__total"><b><?= $fmt(array_sum($section['total'])) ?></b></td>
                            </tr>

                            <?php if ($section['type'] === 'ricavo'): ?>
                                <?php /* Non assegnati: fuori dai totali per tipo, ma in tabella,
                                         altrimenti chi legge crede che il totale ricavi sia tutto. */ ?>
                                <tr class="is-unassigned">
                                    <th scope="row" class="ip-bilancino__name">
                                        <i class="fa fa-solid fa-question-circle" aria-hidden="true"></i>
                                        <?= trans('management_unassigned_revenue', '', 'Ricavi non assegnati') ?>
                                    </th>
                                    <td class="ip-bilancino__code">—</td>
                                    <?php foreach ($unassigned['ricavo'] as $value): ?>
                                        <td class="ip-bilancino__month <?= (float) $value == 0.0 ? 'is-zero' : '' ?>"><?= $cell($value) ?></td>
                                    <?php endforeach; ?>
                                    <td class="ip-bilancino__total"><b><?= $fmt(array_sum($unassigned['ricavo'])) ?></b></td>
                                </tr>
                            <?php endif; ?>

                            <?php if ($section['type'] === 'costo_variabile'): ?>
                                <tr class="is-result">
                                    <th scope="row" class="ip-bilancino__name"><?= trans('management_margin', '', 'Margine di contribuzione') ?></th>
                                    <td class="ip-bilancino__code"></td>
                                    <?php foreach ($totals['margine'] as $value): ?>
                                        <td class="ip-bilancino__month <?= (float) $value < 0 ? 'is-negative' : '' ?>"><?= $fmt($value) ?></td>
                                    <?php endforeach; ?>
                                    <td class="ip-bilancino__total <?= array_sum($totals['margine']) < 0 ? 'is-negative' : '' ?>">
                                        <b><?= $fmt(array_sum($totals['margine'])) ?></b>
                                    </td>
                                </tr>
                            <?php endif; ?>

                            <?php if ($section['type'] === 'costo_fisso'): ?>
                                <tr class="is-unassigned">
                                    <th scope="row" class="ip-bilancino__name">
                                        <i class="fa fa-solid fa-question-circle" aria-hidden="true"></i>
                                        <?= trans('management_unassigned_costs', '', 'Costi non assegnati') ?>
                                    </th>
                                    <td class="ip-bilancino__code">—</td>
                                    <?php foreach ($unassigned['costo'] as $value): ?>
                                        <td class="ip-bilancino__month <?= (float) $value == 0.0 ? 'is-zero' : '' ?>"><?= $cell($value) ?></td>
                                    <?php endforeach; ?>
                                    <td class="ip-bilancino__total"><b><?= $fmt(array_sum($unassigned['costo'])) ?></b></td>
                                </tr>
                                <tr class="is-result is-ebitda">
                                    <th scope="row" class="ip-bilancino__name">EBITDA</th>
                                    <td class="ip-bilancino__code"></td>
                                    <?php foreach ($totals['ebitda'] as $value): ?>
                                        <td class="ip-bilancino__month <?= (float) $value < 0 ? 'is-negative' : '' ?>"><?= $fmt($value) ?></td>
                                    <?php endforeach; ?>
                                    <td class="ip-bilancino__total <?= array_sum($totals['ebitda']) < 0 ? 'is-negative' : '' ?>">
                                        <b><?= $fmt(array_sum($totals['ebitda'])) ?></b>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </section>

        <?php
        // Confronto budget: una riga per voce di primo livello, perché il
        // budget si imposta là e i figli sarebbero rumore. Mostrato solo se
        // esiste almeno un valore: una tabella di zeri non è un confronto.
        $hasBudget = array_sum(array_map('floatval', (array) $budget['totals'])) != 0.0;
        $rootRows  = [];

        foreach ($accounts as $type => $rows) {
            foreach ($rows as $row) {
                if ((int) $row['level'] === 0) {
                    $rootRows[] = $row;
                }
            }
        }
        ?>

        <section class="ip-doc-section">
            <div class="ip-doc-section__head">
                <span class="ip-doc-step"><i class="fa fa-solid fa-bullseye" aria-hidden="true"></i></span>
                <h2 class="ip-doc-section__title"><?= trans('management_budget_vs_actual', '', 'Budget e consuntivo') ?></h2>
                <span class="ip-doc-section__hint">
                    <?= $hasBudget
                        ? trans('management_budget_year', '', 'Budget dell\'anno') . ' ' . $year
                        : trans('management_budget_missing', '', 'Nessun budget impostato per questo anno.') ?>
                </span>
            </div>
            <div class="ip-doc-section__body">
                <?php if (!$hasBudget): ?>
                    <p>
                        <a class="btn btn-sm btn-primary" href="<?= site_url('management/budget') ?>">
                            <i class="fa fa-solid fa-bullseye" aria-hidden="true"></i>
                            <?= trans('management_set_budget', '', 'Imposta il budget') ?>
                        </a>
                    </p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="ip-bilancino ip-bilancino--budget">
                            <thead>
                            <tr>
                                <th scope="col"><?= trans('management_item', '', 'Voce') ?></th>
                                <th scope="col" class="ip-bilancino__total"><?= trans('management_budget', '', 'Budget') ?></th>
                                <th scope="col" class="ip-bilancino__total"><?= trans('management_actual', '', 'Consuntivo') ?></th>
                                <th scope="col" class="ip-bilancino__total"><?= trans('management_variance', '', 'Scostamento') ?></th>
                                <th scope="col" class="ip-bilancino__total">%</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rootRows as $row):
                                // Sui ricavi uno scostamento positivo è buono; sui
                                // costi è il contrario. Il colore segue il tipo,
                                // non il segno.
                                $good = $row['type'] === 'ricavo'
                                    ? $row['variance_amount'] >= 0
                                    : $row['variance_amount'] <= 0;
                                $class = $row['variance_amount'] == 0.0 ? '' : ($good ? 'is-good' : 'is-bad');
                                $rate  = (float) $row['budget_total'] != 0.0
                                    ? $row['variance_amount'] / abs((float) $row['budget_total']) * 100
                                    : null;
                            ?>
                                <tr>
                                    <th scope="row" class="ip-bilancino__name"><?= esc($row['name']) ?></th>
                                    <td class="ip-bilancino__total"><?= $fmt($row['budget_total']) ?></td>
                                    <td class="ip-bilancino__total"><?= $fmt($row['total']) ?></td>
                                    <td class="ip-bilancino__total <?= $class ?>"><?= $fmt($row['variance_amount']) ?></td>
                                    <td class="ip-bilancino__total <?= $class ?>">
                                        <?= $rate === null ? '—' : ($rate > 0 ? '+' : '') . $num($rate) . '%' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <?php
        // Punto di pareggio: quante vendite servono per coprire i costi fissi.
        // Compare solo se il margine è positivo, perché altrimenti non esiste
        // un fatturato che pareggi e scriverlo sarebbe un numero inventato.
        if ($kpi['break_even'] !== null):
        ?>
            <p class="ip-bilancino__break-even">
                <i class="fa fa-solid fa-flag-checkered" aria-hidden="true"></i>
                <?= sprintf(
                    trans('management_break_even', '', 'Punto di pareggio stimato: %s di ricavi, al margine di contribuzione del %s%%.'),
                    $fmt($kpi['break_even']),
                    $num($kpi['margin_rate'])
                ) ?>
            </p>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?= $this->endSection() ?>
