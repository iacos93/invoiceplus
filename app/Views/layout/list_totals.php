<?php
/**
 * Partial: piede di tabella con i totali del periodo.
 *
 * Sta in <tfoot>, non in una striscia a parte, perché ogni totale deve
 * cadere sotto la colonna che riassume: imponibile sotto "Imponibile", IVA
 * sotto "IVA", totale sotto "Totale", saldo sotto "Saldo". Le voci restano
 * su righe separate — "Totale" da solo non dice se l'IVA è dentro o fuori,
 * ed è la differenza che si controlla su un elenco documenti.
 *
 * Con almeno una riga il footer è sempre presente. Con l'elenco vuoto no:
 * mostrerebbe "0,00" sotto ogni colonna mentre l'empty-state dice già che
 * non c'è nulla, e il riepilogo in testa ripete gli stessi zeri. Sparire è
 * la scelta più pulita — i totali tornano appena la lista ha una riga.
 *
 * Parametri attesi:
 *   $totals         array ['count' => int, 'net' => float, 'tax' => float,
 *                          'total' => float, 'balance' => float]
 *   $label_colspan  int   colonne prima della colonna imponibile
 *   $has_balance    bool  se mostrare la colonna saldo (default true)
 *   $has_options    bool  se la tabella ha una colonna opzioni in coda
 *                         (default true)
 *   $secondary_before int quante colonne .ip-col-secondary stanno fra quelle
 *                         coperte dall'etichetta (default 0). Quelle colonne
 *                         spariscono alle larghezze intermedie: un colspan
 *                         fisso continuerebbe a contarle, il piede avrebbe una
 *                         colonna più del corpo e allargherebbe la tabella.
 *                         Per ognuna si stampa una cella vuota con la stessa
 *                         classe, che sparisce insieme alla colonna.
 *   $net_secondary  bool  se la colonna imponibile è .ip-col-secondary
 *                         (default false): la sua cella la segue, e la riga
 *                         dell'imponibile, rimasta senza cifra, esce con lei.
 */
$_lt = (array) ($this->renderVars['options'] ?? []);

$totals       = $_lt['totals'] ?? ($totals ?? []);
$labelColspan = max(1, (int) ($_lt['label_colspan'] ?? ($label_colspan ?? 7)));
$hasBalance   = (bool) ($_lt['has_balance'] ?? ($has_balance ?? true));
$hasOptions   = (bool) ($_lt['has_options'] ?? ($has_options ?? true));
$secondaryBefore = max(0, min($labelColspan - 1, (int) ($_lt['secondary_before'] ?? 0)));
$netSecondary    = (bool) ($_lt['net_secondary'] ?? false);
$labelColspan   -= $secondaryBefore;
$secondaryCells  = str_repeat('<td class="ip-col-secondary"></td>', $secondaryBefore);

// 'count' arriva dagli stessi totali aggregati della lista: se è zero, la
// lista è vuota e il piede non ha niente da riassumere.
if ((int) ($totals['count'] ?? 0) === 0) {
    return;
}

$net   = (float) ($totals['net'] ?? 0);
$tax   = (float) ($totals['tax'] ?? 0);
$total = (float) ($totals['total'] ?? 0);
$saldo = (float) ($totals['balance'] ?? 0);

// Le colonne imponibile e IVA seguono la stessa visibilità delle colonne
// corrispondenti nel corpo tabella: imponibile nascosto in modalità card,
// IVA solo sugli schermi larghi.
$netClass = 'amount ip-hide-on-mobile' . ($netSecondary ? ' ip-col-secondary' : '');
$taxClass = 'amount d-none d-xxl-table-cell';
$balanceLabel = trans('balance', '', 'Saldo');
?>
<tfoot class="ip-list-totals">
    <tr class="ip-list-totals__row<?= $netSecondary ? ' ip-list-totals__row--secondary' : '' ?>">
        <?= $secondaryCells ?>
        <th colspan="<?= $labelColspan ?>" scope="row" class="ip-list-totals__label">
            <?= trans('net_total', '', 'Totale imponibile netto') ?>
        </th>
        <td class="<?= $netClass ?> ip-list-totals__value"><?= format_currency($net) ?></td>
        <td class="<?= $taxClass ?>"></td>
        <td class="amount"></td>
        <?php if ($hasBalance): ?><td class="amount"></td><?php endif; ?>
        <?php if ($hasOptions): ?><td class="ip-hide-on-mobile"></td><?php endif; ?>
    </tr>
    <tr class="ip-list-totals__row ip-list-totals__row--tax">
        <?= $secondaryCells ?>
        <th colspan="<?= $labelColspan ?>" scope="row" class="ip-list-totals__label">
            <?= trans('vat', '', 'IVA') ?>
        </th>
        <td class="<?= $netClass ?>"></td>
        <td class="<?= $taxClass ?> ip-list-totals__value"><?= format_currency($tax) ?></td>
        <td class="amount"></td>
        <?php if ($hasBalance): ?><td class="amount"></td><?php endif; ?>
        <?php if ($hasOptions): ?><td class="ip-hide-on-mobile"></td><?php endif; ?>
    </tr>
    <tr class="ip-list-totals__row ip-list-totals__row--grand">
        <?= $secondaryCells ?>
        <th colspan="<?= $labelColspan ?>" scope="row" class="ip-list-totals__label">
            <?= trans('gross_total', '', 'Totale lordo (IVA inclusa)') ?>
        </th>
        <td class="<?= $netClass ?>"></td>
        <td class="<?= $taxClass ?>"></td>
        <td class="amount ip-list-totals__value"><?= format_currency($total) ?></td>
        <?php if ($hasBalance): ?>
            <td class="amount ip-list-totals__value" data-label="<?= esc($balanceLabel, 'attr') ?>">
                <?= format_currency($saldo) ?>
            </td>
        <?php endif; ?>
        <?php if ($hasOptions): ?><td class="ip-hide-on-mobile"></td><?php endif; ?>
    </tr>
</tfoot>
