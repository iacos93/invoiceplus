<?php
/**
 * Partial: navigazione per mese della lista documenti, con i totali del
 * periodo mostrato. Usata da invoices/index, quotes/index e expenses/index.
 *
 * L'anno arriva dal filtro globale in topbar; qui si sceglie solo il mese (o
 * l'intero periodo). I due filtri sono indipendenti — come lo sono già nei
 * modelli, che applicano YEAR() e MONTH() come condizioni separate — quindi
 * la riga dei mesi resta visibile anche con "tutti gli anni" in topbar: in
 * quel caso il mese vale su tutti gli anni in archivio.
 *
 * Selezione multipla: un clic sceglie un mese solo, shift+clic estende
 * l'intervallo dall'ultimo mese scelto, ctrl/cmd+clic aggiunge o toglie un
 * mese. Senza JavaScript i link restano normali link a un mese, quindi la
 * barra funziona comunque; lo script è solo un miglioramento progressivo.
 *
 * Parametri attesi:
 *   $base_url           string  rotta della lista, es. 'invoices/status/all'
 *   $year               int     anno selezionato (0 = tutti)
 *   $months             list<int> mesi selezionati (vuoto = tutto l'anno)
 *   $month              int     ripiego storico per il mese singolo
 *   $totals             array   ['count','net','tax','total','balance']
 *   $month_totals       array   [mese => ['net','tax','total','balance']]
 *   $month_total_label  string  descrizione dell'importo sotto il mese
 *   $extra_query        array   filtri da conservare (es. ['fav' => '1'])
 */
$_pn = (array) ($this->renderVars['options'] ?? []);

$base_url = $_pn['base_url'] ?? ($base_url ?? '');
$year     = (int) ($_pn['year'] ?? ($year ?? 0));

$months = $_pn['months'] ?? ($months ?? []);
$months = is_array($months) ? $months : [(int) $months];
$months = array_values(array_unique(array_filter(
    array_map('intval', $months),
    static fn (int $m): bool => $m >= 1 && $m <= 12
)));

// Compatibilità con i chiamanti che passano ancora il vecchio $month scalare.
if ($months === []) {
    $legacyMonth = (int) ($_pn['month'] ?? ($month ?? 0));

    if ($legacyMonth >= 1 && $legacyMonth <= 12) {
        $months = [$legacyMonth];
    }
}

$totals            = $_pn['totals'] ?? ($totals ?? []);
$month_totals      = $_pn['month_totals'] ?? ($month_totals ?? []);
$month_total_label = (string) ($_pn['month_total_label'] ?? ($month_total_label ?? trans('total', '', 'Totale')));

$extra = $_pn['extra_query'] ?? ($extra_query ?? []);
$extra = is_array($extra) ? array_filter(
    $extra,
    static fn ($v): bool => $v !== '' && $v !== null
) : [];

// Nomi dei mesi nella lingua dell'app: la funzione condivisa evita dodici
// IntlDateFormatter nello stesso ciclo e tiene le etichette allineate a
// quelle delle serie mensili.
$monthNames = [];
for ($m = 1; $m <= 12; $m++) {
    $monthNames[$m] = month_label(mktime(0, 0, 0, $m, 1, 2000));
}

// year sempre esplicito nella query string, anche a 0 ("Tutti"): il
// controller usa il filtro anno globale (topbar) come default SOLO quando
// year non compare affatto nell'URL. Senza year=0 esplicito qui, il link
// "Tutti" di questa barra sarebbe silenziosamente riscavalcato dall'anno
// scelto in topbar invece di mostrare davvero tutti gli anni.
$urlFor = static function (array $selected) use ($base_url, $year, $extra): string {
    $query = array_merge(['year' => $year], $extra, ip_month_query($selected));

    return site_url($base_url) . '?' . http_build_query($query);
};

// "Tutti i mesi" è un solo stato: il pulsante seleziona l'intero arco 1-12,
// così i dodici mesi si accendono e la selezione resta modificabile. Lo stato
// vuoto (apertura iniziale della pagina, link storici con month=0) filtra già
// esattamente come 1-12, ma prima si disegnava diverso — solo "Tutti" acceso,
// mesi spenti, e shift/ctrl+clic senza effetto perché non c'era una selezione
// da cui partire. Lo si normalizza qui a 1-12, così le due strade arrivano
// alla stessa barra e allo stesso comportamento dei clic.
$allMonths = range(1, 12);
if ($months === []) {
    $months = $allMonths;
}
$isAllSelected = count($months) === 12;

// URL usato dallo script per ricostruire la selezione multipla: la base è la
// stessa dei link, i mesi vengono accodati come month[]=.
$navBase = site_url($base_url);
?>
<div class="ip-period-nav">

    <?php /* L'anno si sceglie dal filtro globale in topbar: ripeterlo qui erano
             due comandi per la stessa cosa, con il rischio di leggerli in
             disaccordo. Restano i mesi, che il filtro globale non copre.

             La voce di sinistra seleziona tutti e dodici i mesi e cambia nome
             secondo l'anno: con un anno scelto è "Anno intero", con "tutti
             gli anni" sarebbe una bugia, perché di anni ne mostra molti.
             Selezionare l'arco intero (e non svuotare il filtro) tiene la
             barra coerente: i dodici mesi si accendono e da lì si può
             restringere con shift/clic senza passare da uno stato "nessun
             mese". */ ?>
    <div class="ip-period-nav-months" role="group"
         aria-label="<?= esc(trans('period', '', 'Periodo'), 'attr') ?>"
         data-ip-month-nav
         data-base="<?= esc($navBase, 'attr') ?>"
         data-year="<?= (int) $year ?>"
         data-extra="<?= esc(json_encode((object) $extra), 'attr') ?>"
         data-selected="<?= esc(implode(',', $months), 'attr') ?>"
         aria-describedby="ip-period-hint">
        <a class="ip-period-month ip-period-month--all <?= $isAllSelected ? 'is-active' : '' ?>"
           data-month="0"
           href="<?= esc($urlFor($allMonths)) ?>"
           aria-pressed="<?= $isAllSelected ? 'true' : 'false' ?>"
           title="<?= esc($year > 0
               ? trans('all_year', '', 'Anno intero')
               : trans('all_months', '', 'Tutti i mesi'), 'attr') ?>">
            <span class="ip-period-month-name"><?= $year > 0
                ? esc(trans('all_year', '', 'Anno intero'))
                : esc(trans('all_months', '', 'Tutti i mesi')) ?></span>
        </a>
        <?php for ($m = 1; $m <= 12; $m++): ?>
            <?php
            $isActive = in_array($m, $months, true);
            $amount   = (float) ($month_totals[$m]['total'] ?? 0);
            // Un mese senza documenti non mostra "0 €": dodici zeri sotto i
            // nomi sono solo rumore, e la voce resta comunque cliccabile. Il
            // mese conta come popolato se una qualsiasi delle tre voci è
            // diversa da zero (una nota di credito può portare a zero il
            // totale lordo senza azzerare l'imponibile).
            $hasAmount = abs($amount) >= 0.005
                      || abs((float) ($month_totals[$m]['net'] ?? 0)) >= 0.005
                      || abs((float) ($month_totals[$m]['tax'] ?? 0)) >= 0.005;
            ?>
            <a class="ip-period-month <?= $isActive ? 'is-active' : '' ?>"
               data-month="<?= $m ?>"
               href="<?= esc($urlFor([$m])) ?>"
               title="<?= esc($hasAmount
                   ? $monthNames[$m] . ': ' . $month_total_label . ' ' . format_currency($amount)
                   : $monthNames[$m], 'attr') ?>"
               aria-pressed="<?= $isActive ? 'true' : 'false' ?>">
                <span class="ip-period-month-name"><?= esc($monthNames[$m]) ?></span>
                <?php if ($hasAmount): ?>
                    <?php /* Importo senza centesimi: la voce è larga 74px e i
                             decimali la facevano debordare. Il centesimo resta
                             nel title, per chi vuole il dato esatto. */ ?>
                    <span class="ip-period-month-amount"><?= esc(format_currency($amount, 0)) ?></span>
                <?php endif; ?>
            </a>
        <?php endfor; ?>
    </div>

    <?php
    /* La selezione multipla esisteva già ma non era scritta da nessuna parte:
       chi voleva due mesi li apriva uno per volta. La riga compare solo con
       JavaScript attivo — lo script in fondo toglie `hidden` — perché senza
       script i mesi restano link singoli e promettere shift+clic sarebbe
       falso.

       Sta subito sotto i mesi, prima dei totali: in fondo alla barra, dopo
       la striscia di riepilogo, si leggeva come una nota ai totali e non
       come l'istruzione dei pulsanti a cui si riferisce. */
    ?>
    <p class="ip-period-hint" id="ip-period-hint" data-ip-period-hint hidden>
        <i class="fa fa-solid fa-circle-info" aria-hidden="true"></i>
        <?php
        /* I nomi dei tasti diventano <kbd> dopo l'escape, non dentro la
           stringa di lingua: la traduzione resta testo puro (niente HTML da
           fidarsi nei file di lingua) e "Shift", "Ctrl", "⌘" si scrivono
           uguali in italiano e in inglese, quindi la sostituzione vale per
           entrambe. Lo <span> serve perché il paragrafo è un contenitore
           flex: senza, ogni <kbd> diventerebbe una voce flex a sé e la frase
           si spezzerebbe in blocchi. */
        $hintText = esc(trans(
            'period_hint_multiselect',
            '',
            'Un clic sceglie un mese. Con Shift+clic si estende l’intervallo; con Ctrl/⌘+clic si aggiunge o si toglie un mese.'
        ));
        ?>
        <span><?= strtr($hintText, [
            'Shift' => '<kbd>Shift</kbd>',
            'Ctrl'  => '<kbd>Ctrl</kbd>',
            '⌘'     => '<kbd>⌘</kbd>',
        ]) ?></span>
    </p>

    <?php
    /* I totali del periodo escono dalla riga dei mesi e usano la striscia di
       riepilogo condivisa (layout/summary_strip): prima erano testo muto
       spinto a destra, cioè un terzo modo di mostrare gli stessi numeri che
       bacheca e scadenzario mostravano in altre due forme.

       Imponibile, IVA e totale lordo restano voci distinte: "Totale" da solo
       non dice se l'IVA è dentro o fuori, ed è proprio la differenza che si
       controlla su un elenco documenti. */
    ?>
    <?php if (!empty($totals)): ?>
        <?= view('layout/summary_strip', ['items' => [
            [
                'label' => trans('documents', '', 'Documenti'),
                'value' => (string) (int) ($totals['count'] ?? 0),
            ],
            [
                'label' => trans('net_total', '', 'Imponibile netto'),
                'value' => format_currency($totals['net'] ?? 0),
            ],
            [
                'label' => trans('vat', '', 'IVA'),
                'value' => format_currency($totals['tax'] ?? 0),
            ],
            [
                'label' => trans('gross_total', '', 'Totale lordo (IVA incl.)'),
                'value' => format_currency($totals['total'] ?? 0),
            ],
            [
                'label' => trans('balance', '', 'Saldo'),
                'value' => array_key_exists('balance', $totals) ? format_currency($totals['balance']) : null,
                'tone'  => (float) ($totals['balance'] ?? 0) > 0 ? 'danger' : 'default',
            ],
        ]]) ?>
    <?php endif; ?>

</div>

<script>
(function () {
    var nav = document.querySelector('[data-ip-month-nav]');
    if (!nav || nav.dataset.bound === '1') {
        return;
    }
    nav.dataset.bound = '1';

    // Le istruzioni della selezione multipla compaiono solo ora che lo
    // script c'è: senza, i mesi sarebbero link a un mese solo.
    var hint = document.getElementById('ip-period-hint');
    if (hint) {
        hint.hidden = false;
    }

    var base = nav.dataset.base || '';
    var year = nav.dataset.year || '0';
    var extra = {};

    try {
        extra = JSON.parse(nav.dataset.extra || '{}') || {};
    } catch (e) {
        extra = {};
    }

    function selected() {
        return (nav.dataset.selected || '')
            .split(',')
            .map(Number)
            .filter(function (m) { return m >= 1 && m <= 12; });
    }

    function urlFor(months) {
        var params = new URLSearchParams();
        params.set('year', year);

        Object.keys(extra).forEach(function (key) {
            if (extra[key] !== '' && extra[key] !== null) {
                params.set(key, extra[key]);
            }
        });

        months.slice().sort(function (a, b) { return a - b; }).forEach(function (m) {
            params.append('month[]', m);
        });

        return base + '?' + params.toString();
    }

    nav.addEventListener('click', function (event) {
        var link = event.target.closest('.ip-period-month');
        if (!link) {
            return;
        }

        var month = parseInt(link.dataset.month, 10) || 0;
        var current = selected();

        // Shift+clic: intervallo dall'ultimo mese scelto al mese cliccato.
        // Con tutti e dodici i mesi accesi non c'è un "ultimo mese scelto":
        // lo shift+clic vale come clic semplice e lascia agire il link.
        if (event.shiftKey && month > 0 && current.length > 0 && current.length < 12) {
            event.preventDefault();
            var anchor = current[current.length - 1];
            var from = Math.min(anchor, month);
            var to = Math.max(anchor, month);
            var range = [];

            for (var m = from; m <= to; m++) {
                range.push(m);
            }

            window.location.href = urlFor(range);
            return;
        }

        // Ctrl/cmd+clic: aggiunge o toglie un singolo mese dalla selezione.
        if ((event.metaKey || event.ctrlKey) && month > 0) {
            event.preventDefault();
            var index = current.indexOf(month);

            if (index >= 0) {
                current.splice(index, 1);
            } else {
                current.push(month);
            }

            window.location.href = urlFor(current);
        }
    });
})();
</script>
