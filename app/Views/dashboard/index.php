<?php $this->setVar('pageTitle', trans('dashboard')); ?>
<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>

<?php
$status_label_class = [
    'draft'    => 'text-bg-secondary',
    'sent'     => 'text-bg-primary',
    'viewed'   => 'text-bg-warning',
    'paid'     => 'text-bg-success',
    'approved' => 'text-bg-success',
    'rejected' => 'text-bg-danger',
    'canceled' => 'text-bg-secondary',
    'overdue'  => 'text-bg-danger',
];

// Barra impilata "composizione per stato": una riga di segmenti proporzionali
// al totale. Era disegnata solo nella finestra delle fatture, quindi le due
// colonne di breakdown affiancate non si somigliavano; il preventivo aveva la
// stessa tabella ma senza il colpo d'occhio sulla quota.
// $rows: liste di ['label' => string, 'value' => float, 'status' => string].
// Il colore non viaggia più come esadecimale: il segmento porta la classe
// dello stato e la tinta la decide il CSS (--ip-*), così chiaro e scuro
// restano sincroni come per i timbri di stato in tabella.
$statusMixBar = static function (array $rows, string $ariaLabel, string $listBase = '', string $docsLabel = ''): string {
    $rows  = array_values(array_filter($rows, static fn($r) => (float) $r['value'] > 0));
    $total = array_sum(array_map(static fn($r) => (float) $r['value'], $rows));
    if ($total <= 0 || $rows === []) {
        return '';
    }

    // Ogni segmento è un collegamento all'elenco di quello stato, con i valori
    // nel tooltip di ip-chart-tips.js: prima era un <span> con il solo tooltip
    // nativo del browser, alto 6px e senza un posto dove andare.
    $segments = '';
    foreach ($rows as $row) {
        $share = (float) $row['value'] / $total * 100;
        $tip   = [
            'title' => $row['label'],
            'rows'  => [
                [trans('amount', '', 'Importo'), strip_tags(format_currency($row['value'])), (string) ($row['status'] ?? '')],
                [$docsLabel !== '' ? $docsLabel : trans('documents', '', 'Documenti'), (string) (int) ($row['count'] ?? 0), ''],
                [trans('share', '', 'Quota'), number_format($share, 1, ',', '.') . '%', ''],
            ],
        ];
        if ($listBase !== '') {
            $tip['hint'] = trans('chart_open_status', '', 'Clic per aprire questo stato');
        }
        $payload = esc((string) json_encode([
            'title' => $tip['title'],
            'rows'  => array_map(static fn(array $r): array => ['label' => $r[0], 'value' => $r[1], 'cls' => $r[2]], $tip['rows']),
        ] + (isset($tip['hint']) ? ['hint' => $tip['hint']] : []), JSON_UNESCAPED_UNICODE), 'attr');

        $tag   = $listBase !== '' ? 'a' : 'span';
        $href  = $listBase !== '' ? ' href="' . esc(rtrim($listBase, '/') . '/' . ($row['status'] ?? ''), 'attr') . '"' : '';
        $label = esc($row['label'] . ': ' . strip_tags(format_currency($row['value'])) . ' (' . number_format($share, 0, ',', '.') . '%)', 'attr');
        $segments .= '<' . $tag . $href . ' class="ip-status-bar-seg ip-status-bar-seg--' . esc((string) ($row['status'] ?? ''), 'attr')
            . '" style="width:' . round($share, 2) . '%" data-tip="' . $payload . '" aria-label="' . $label . '"></' . $tag . '>';
    }

    return '<div class="ip-status-bar-wrap" role="group" aria-label="' . esc($ariaLabel) . '">'
        . '<div class="ip-status-bar">' . $segments . '</div></div>';
};

// Chip cliente compatto per le tabelle "recenti": iniziale in cerchio + nome,
// più scansionabile del solo testo su liste con molte righe.
$clientChip = static function ($row): string {
    $name    = format_client($row);
    $trimmed = trim((string) $name);
    $initial = $trimmed !== '' ? mb_strtoupper(mb_substr($trimmed, 0, 1)) : '?';

    // Nome in uno span proprio, su una riga sola con i puntini (vedi
    // .ip-client-chip__name): nella finestra a mezza larghezza la colonna è
    // stretta e "Comune di Testopoli" andava a capo su tre righe, sfasando
    // l'altezza di ogni riga. Il nome intero resta nel title.
    return '<span class="ip-avatar-sm" aria-hidden="true">' . esc($initial) . '</span>'
        . '<span class="ip-client-chip__name" title="' . esc($name, 'attr') . '">' . esc($name) . '</span>';
};

// === Helpers finestre (stile Fattura24) ====================================
// La dashboard è un report di sintesi a "finestre": ogni finestra è una card
// con header (icona + titolo + azioni espandi/nascondi) e corpo. L'utente
// può riorganizzarla come in Fattura24 (espandere, nascondere, ripristinare
// da "Personalizza finestre"). L'header di ogni finestra:

// "Mostra tutto": prima stava in testata, ma con i due pulsanti di finestra
// a destra la riga si affollava e il link finiva schiacciato. Spostato nel
// footer della card (winFoot), dove ha tutto lo spazio in orizzontale e resta
// l'ancora naturale "vai all'elenco completo" sotto la tabella.
$winHead = static function (string $windowId, string $title, ?string $url = null, string $icon = 'fa-solid fa-table-cells-large', ?string $count = null): string {
    $titleTag = $url !== null
        ? '<h2 class="ip-window-title"><a href="' . esc($url) . '">' . esc($title) . '</a></h2>'
        : '<h2 class="ip-window-title">' . esc($title) . '</h2>';
    $count = $count !== null ? '<span class="ip-window-count">' . esc($count) . '</span>' : '';

    return '<header class="ip-window-header">'
        . '<span class="card-header-icon"><i class="fa ' . esc($icon) . '" aria-hidden="true"></i></span>'
        . $titleTag
        . $count
        . '<span class="ip-window-actions">'
        . '<button type="button" class="ip-window-btn ip-window-expand" title="' . esc(trans('dashboard_expand', '', 'Espandi')) . '" aria-label="' . esc(trans('dashboard_expand', '', 'Espandi')) . '"><i class="fa fa-solid fa-up-right-and-down-left-from-center" aria-hidden="true"></i></button>'
        . '<button type="button" class="ip-window-btn ip-window-hide" title="' . esc(trans('dashboard_hide', '', 'Nascondi')) . '" aria-label="' . esc(trans('dashboard_hide', '', 'Nascondi')) . '"><i class="fa fa-solid fa-times" aria-hidden="true"></i></button>'
        . '</span></header>';
};

$winFoot = static function (?string $moreUrl = null, ?string $moreLabel = null): string {
    if ($moreUrl === null) {
        return '';
    }
    return '<footer class="ip-window-footer">'
        . '<a class="ip-window-more" href="' . esc($moreUrl) . '">' . esc($moreLabel ?? trans('view_all')) . ' <i class="fa fa-solid fa-arrow-right" aria-hidden="true"></i></a>'
        . '</footer>';
};

// Elenco finestre per il pannello "Personalizza finestre" (ripristino di
// quelle nascoste). Gli id devono combaciare con data-window delle sezioni.
$windowsList = [
    ['id' => 'indice',           'title' => trans('dashboard_profit_label', '', 'Indice di redditività')],
    ['id' => 'ricavi',           'title' => trans('dashboard_ricavi', '', 'Ricavi')],
    ['id' => 'incassato',        'title' => trans('dashboard_incassato', '', 'Incassato')],
    ['id' => 'saldo',            'title' => trans('dashboard_outstanding', '', 'Saldo da incassare')],
    ['id' => 'scadute',          'title' => trans('overdue_invoices')],
    ['id' => 'costi',            'title' => trans('dashboard_costi', '', 'Costi')],
    ['id' => 'iva',              'title' => trans('dashboard_iva', '', 'IVA')],
    ['id' => 'graf_ricavi',      'title' => trans('dashboard_revenue_chart', '', 'Grafico ricavi')],
    ['id' => 'graf_costi',       'title' => trans('dashboard_expenses_chart', '', 'Grafico costi')],
    ['id' => 'stato_fatture',    'title' => trans('invoice_overview_breakdown', '', 'Fatture per stato')],
    ['id' => 'stato_preventivi', 'title' => trans('quote_overview_breakdown', '', 'Preventivi per stato')],
    ['id' => 'ultime_fatture',   'title' => trans('recent_invoices')],
    ['id' => 'ultimi_preventivi','title' => trans('recent_quotes')],
    ['id' => 'ultimi_ddt',       'title' => trans('recent_delivery_notes', '', 'DDT recenti')],
    ['id' => 'progetti',         'title' => trans('projects')],
    ['id' => 'attivita',         'title' => trans('tasks')],
];

// Trend badge: Δ% vs anno precedente. $delta è null quando il confronto
// non ha senso (es. anno precedente a zero, oppure anno corrente
// "tutti gli anni" che non ha un predecessore). $invert true per le KPI
// dove un delta positivo è "cattiva notizia" (es. scadute/outstanding/costi).
$trendBadge = static function (?float $delta, bool $invert = false, ?string $prevText = null): string {
    if ($delta === null) {
        return $prevText !== null
            ? '<span class="ip-trend ip-trend--muted" title="' . esc($prevText) . '">' . esc($prevText) . '</span>'
            : '';
    }
    $up       = $delta >= 0;
    $goodUp   = $invert ? !$up : $up;
    $variant  = $goodUp ? 'up' : 'down';
    $icon     = $up ? 'fa-solid fa-arrow-up' : 'fa-solid fa-arrow-down';
    $sign     = $up ? '+' : '';
    $label    = $sign . number_format($delta, 1, ',', '.') . '%';
    $title    = $prevText !== null ? ' title="' . esc($prevText) . '"' : '';

    return '<span class="ip-trend ip-trend--' . $variant . '"' . $title
        . '><i class="fa ' . $icon . '" aria-hidden="true"></i> ' . esc($label) . '</span>';
};

// Sparkline SVG: piccola serie di barre (max 6-7) dentro la finestra KPI.
// Gestisce anche valori negativi (es. IVA): in quel caso la scala si divide
// a metà, le barre positive salgono dalla linea zero e le negative scendono.
// Finestra dei grafici: "ultimi 6 mesi" solo quando finisce questo mese;
// con un anno passato scelto, o un archivio fermo a qualche mese fa, la
// finestra segue i dati e l'etichetta dice dove finisce.
$windowEnd   = strtotime(chart_window_anchor(6));
$windowLabel = date('Y-m', $windowEnd) === date('Y-m')
    ? trans('dashboard_last_6_months', '', 'ultimi 6 mesi')
    : sprintf(trans('dashboard_6_months_until', '', '6 mesi fino a %s'), date('m/Y', $windowEnd));

// Interattività dei grafici (ip-chart-tips.js): ogni mese è un gruppo con i
// propri valori in data-tip (JSON) e, dove ha senso, un link all'elenco
// filtrato per quel mese. I testi dei valori sono già formattati qui, così il
// JS non deve conoscere valuta, separatori né lingua.
$tipAttr = static function (string $title, array $rows, string $hint = ''): string {
    $payload = ['title' => $title, 'rows' => array_map(
        static fn(array $r): array => ['label' => $r[0], 'value' => strip_tags((string) $r[1]), 'cls' => $r[2] ?? ''],
        $rows
    )];
    if ($hint !== '') {
        $payload['hint'] = $hint;
    }

    return ' data-tip="' . esc((string) json_encode($payload, JSON_UNESCAPED_UNICODE), 'attr') . '"';
};
// "2025-08" -> anno e mese per i filtri ?year=&month= delle liste.
$monthHref = static function (string $base, ?string $ym, bool $withMonth = true): string {
    if ($base === '' || $ym === null || preg_match('/^(\d{4})-(\d{2})$/', $ym, $m) !== 1) {
        return '';
    }

    return ' data-href="' . esc($base . (str_contains($base, '?') ? '&' : '?') . 'year=' . $m[1] . ($withMonth ? '&month=' . (int) $m[2] : ''), 'attr') . '"';
};
$openHint = trans('chart_open_month', '', 'Clic per aprire i dettagli');

// Con un anno scelto i 12 mesi sono quelli dell'anno (gennaio-dicembre), non
// una finestra che scivola indietro fino al dicembre dell'anno prima: dirlo
// con il nome dell'anno evita di leggere il primo mese come questo dicembre.
$windowYear    = selected_year();
$windowLabel12 = $windowYear > 0
    ? sprintf(trans('dashboard_12_months_of_year', '', '12 mesi del %d'), $windowYear)
    : (date('Y-m', $windowEnd) === date('Y-m')
        ? trans('dashboard_last_12_months', '', 'ultimi 12 mesi')
        : sprintf(trans('dashboard_12_months_until', '', '12 mesi fino a %s'), date('m/Y', $windowEnd)));

$sparkline = static function (array $values, string $colorVar = 'var(--ip-primary)'): string {
    $values = array_values(array_map('floatval', $values));
    if (count($values) === 0) {
        return '';
    }
    $max    = max(array_map('abs', $values)) ?: 1;
    $hasNeg = min($values) < 0;
    $n      = count($values);
    $w      = 100;
    $h      = 28;
    $gap    = 2;
    $bw     = max(1, ($w - $gap * ($n - 1)) / $n);
    $zeroY  = $hasNeg ? $h / 2 : $h;

    $rects = '';
    foreach ($values as $i => $v) {
        $bh    = ($hasNeg ? $h / 2 : $h) * (abs($v) / $max);
        $x     = $i * ($bw + $gap);
        $y     = $v >= 0 ? $zeroY - $bh : $zeroY;
        $rects .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1)
            . '" width="' . round($bw, 1) . '" height="' . round(max($bh, 0), 1)
            . '" rx="1" fill="' . $colorVar . '" opacity="0.55" />';
    }
    // ultima barra piena (corrente) -> più marcata
    $last = count($values) - 1;
    if ($last >= 0) {
        $v     = $values[$last];
        $bh    = ($hasNeg ? $h / 2 : $h) * (abs($v) / $max);
        $x     = $last * ($bw + $gap);
        $y     = $v >= 0 ? $zeroY - $bh : $zeroY;
        $rects .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1)
            . '" width="' . round($bw, 1) . '" height="' . round(max($bh, 0), 1)
            . '" rx="1" fill="' . $colorVar . '" />';
    }

    return '<svg class="ip-spark" viewBox="0 0 ' . $w . ' ' . $h
        . '" preserveAspectRatio="none" aria-hidden="true">' . $rects . '</svg>';
};

// === Serie estratte per sparkline =========================================
$invoicedSeries = array_map(static fn($r) => (float) $r['invoiced'], $chart_series);
$paidSeries     = array_map(static fn($r) => (float) $r['paid'],     $chart_series);
$expenseSpark   = array_map(static fn($r) => (float) $r['total'],    $expense_series);
$profitSpark    = array_map('floatval', $profit_series);
// Non incassato per mese (fatturato - incassato): sparkline della finestra
// "Saldo da incassare".
$unpaidSpark = [];
foreach ($chart_series as $row) {
    $unpaidSpark[] = (float) $row['invoiced'] - (float) $row['paid'];
}
// L'Indice di redditività mostra sempre 12 mesi, a prescindere dalle finestre
// a 6 delle altre card.
$profitSpark12  = array_map('floatval', $profit_series_12);
$profitLabels   = array_map(static fn($r) => $r['label'], $chart_series_12);
$indiceColor  = $profit_total < 0 ? 'var(--ip-danger)' : 'var(--ip-success)';

// Geometria e scala condivise dai grafici SVG della dashboard: stessa
// gabbia (600x190, padding che ospita la scala a sinistra) e stesso
// meccanismo di scala "tonda" (passo 1/2/2.5/5 x 10^n).
$W = 600;
$H = 190;
$padTop    = 12;
$padBottom = 28;
$padLeft   = 56;
$barH      = $H - $padTop - $padBottom;
$plotW     = $W - $padLeft;

// Passo "tondo" della scala. Arrotondare solo il massimo non basta:
// il passo resta il massimo/4 e produce tacche come 625 o 1.220.
// Scegliendo prima il passo (1/2/2.5/5 x 10^n) tutte le etichette
// cadono su valori leggibili.
$niceStep = static function (float $rough): float {
    if ($rough <= 0) {
        return 1.0;
    }
    $pow = 10 ** (int) floor(log10($rough));
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        if ($rough <= $m * $pow) {
            return $m * $pow;
        }
    }
    return 10 * $pow;
};

// Etichette asse compatte: "€ 1,2k" sta nello spazio, "€ 1.220" no.
$axisLabel = static function (float $v): string {
    if ($v >= 1000000) {
        return currency_wrap(rtrim(rtrim(number_format($v / 1000000, 1, ',', '.'), '0'), ',') . 'M');
    }
    if ($v >= 1000) {
        return currency_wrap(rtrim(rtrim(number_format($v / 1000, 1, ',', '.'), '0'), ',') . 'k');
    }
    return currency_wrap(number_format($v, 0, ',', '.'));
};

// Scala completa per un grafico: passo, tacche, asse max e yOf(valore).
$chartScale = static function (float $max) use ($niceStep, $axisLabel, $padTop, $barH): array {
    $step      = $niceStep($max / 4);
    $tickCount = $max > 0 ? (int) ceil($max / $step) : 1;
    $axisMax   = $step * $tickCount;
    $yOf       = static fn(float $v): float => $axisMax > 0
        ? $padTop + $barH - $barH * ($v / $axisMax)
        : $padTop + $barH;

    return ['step' => $step, 'tickCount' => $tickCount, 'axisMax' => $axisMax, 'yOf' => $yOf];
};

// Grafico ad area della finestra "Indice di redditività": andamento mensile
// dell'utile (fatturato - spese). Definito qui sotto perché riusa $niceStep e
// $axisLabel: prima disegnava quattro righe di griglia senza etichette, quindi
// la curva si leggeva solo in forma — non si ricavava un importo. Ora ha la
// stessa scala "tonda" e lo stesso gutter sinistro dei grafici a barre, così le
// tre finestre grafico della dashboard si leggono allo stesso modo.
$areaChart = static function (array $values, array $labels, string $colorVar, array $meta = []) use ($niceStep, $axisLabel, $tipAttr, $monthHref, $openHint): string {
    $values = array_values(array_map('floatval', $values));
    $labels = array_values($labels);
    $n      = count($values);
    if ($n < 2) {
        return '';
    }
    // Gabbia dimensionata sulla colonna reale, non sulla finestra: il grafico
    // sta accanto alla cifra dell'Indice e occupa ~385px. Con la vecchia gabbia
    // da 900 unità il testo dell'SVG (12px nominali) veniva scalato a 0.43,
    // cioè etichette di asse e mesi intorno ai 5px, illeggibili. A 480x150
    // la scala resta vicina a 1 sulla colonna desktop e l'altezza non cresce.
    $W     = 480;
    $H     = 150;
    $padL  = 56;   // stesso gutter dei grafici a barre: ospita le etichette scala
    $padR  = 12;
    $padT  = 14;
    $padB  = 26;
    $plotW = $W - $padL - $padR;
    $plotH = $H - $padT - $padB;

    $maxV   = max(array_map('abs', $values)) ?: 1.0;
    $hasNeg = min($values) < 0;

    // Scala "tonda": con valori negativi è simmetrica attorno allo zero, così
    // la linea zero cade esattamente a metà e le tacche restano leggibili.
    $step      = $niceStep($maxV / ($hasNeg ? 2 : 3));
    $tickCount = max(1, (int) ceil($maxV / $step));
    $axisMax   = $step * $tickCount;

    $span  = $hasNeg ? $plotH / 2 : $plotH;
    $zeroY = $hasNeg ? $padT + $plotH / 2 : $padT + $plotH;
    $yOf   = static fn(float $v): float => $zeroY - ($v / $axisMax) * $span;

    // La banda del mese è larga quanto la distanza fra due punti, quindi metà
    // sborda oltre il primo e l'ultimo. Appoggiata al bordo, la banda del primo
    // mese finiva sopra le etichette della scala: era ritagliata lì, e si
    // vedeva spostata rispetto al punto che indicava. Mezza banda di margine
    // per lato le fa stare tutte dentro il riquadro e centrate sul proprio mese.
    $bandW = $n > 1 ? $plotW / ($n - 1) : $plotW;
    $inset = $bandW / 2;
    $xOf   = static fn(int $i): float => $padL + $inset + $i * ($plotW - $bandW) / max(1, $n - 1);

    // Etichetta di scala: $axisLabel formatta solo valori positivi (k / M),
    // il segno lo anteponiamo qui per le tacche sotto lo zero.
    $tickLabel = static fn(float $v): string => ($v < 0 ? '-' : '') . $axisLabel(abs($v));

    $grid = '';
    $from = $hasNeg ? -$tickCount : 0;
    for ($t = $from; $t <= $tickCount; $t++) {
        $tickVal = $step * $t;
        $tickY   = round($yOf($tickVal), 1);
        $isZero  = $t === 0;
        $grid   .= '<line class="' . ($isZero ? 'ip-chart-baseline' : 'ip-chart-grid') . '" x1="' . $padL
            . '" y1="' . $tickY . '" x2="' . round($padL + $plotW, 1) . '" y2="' . $tickY . '" />';
        $grid   .= '<text class="ip-chart-axis" x="' . ($padL - 8) . '" y="' . round($tickY + 3.5, 1)
            . '" text-anchor="end">' . esc($tickLabel($tickVal)) . '</text>';
    }

    $pts = [];
    foreach ($values as $i => $v) {
        $pts[] = round($xOf($i), 1) . ',' . round($yOf($v), 1);
    }
    $dLine = 'M' . implode(' L', $pts);
    $dArea = $dLine . ' L' . round($xOf($n - 1), 1) . ',' . round($zeroY, 1)
        . ' L' . round($xOf(0), 1) . ',' . round($zeroY, 1) . ' Z';

    // Un gruppo per mese: banda sensibile a tutta altezza (il bersaglio non è
    // il puntino da 7px), punto e valori nel tooltip di ip-chart-tips.js.
    $dots  = '';
    $xLbl  = '';
    $bands = '';
    foreach ($values as $i => $v) {
        $cx    = $xOf($i);
        $title = (string) ($labels[$i] ?? '');
        $href  = $monthHref($meta[$i]['base'] ?? '', $meta[$i]['ym'] ?? null, (bool) ($meta[$i]['month'] ?? true));
        $bands .= '<g class="ip-chart-month" tabindex="0" role="img" aria-label="' . esc($title . ': ' . strip_tags(format_currency($v)), 'attr') . '"'
            . $tipAttr($title, [[$meta[$i]['row'] ?? trans('dashboard_profit_label', '', 'Utile di impresa'), format_currency($v), $v < 0 ? 'loss' : 'profit']], $href !== '' ? $openHint : '')
            . $href . '>'
            . '<rect class="ip-chart-col" x="' . round($cx - $bandW / 2, 1) . '" y="12" width="' . round($bandW, 1)
            . '" height="' . ($H - 24) . '" rx="6" />'
            . '<circle class="ip-area-dot" cx="' . round($cx, 1) . '" cy="' . round($yOf($v), 1) . '" r="3.5" fill="' . $colorVar . '" />'
            . '</g>';
        // Sei etichette ci stanno tutte: saltarne una sì e una no lasciava
        // "Lug Ago" appaiate in fondo e la spaziatura sembrava sbagliata.
        $xLbl .= '<text class="ip-chart-label" x="' . round($cx, 1) . '" y="' . ($H - 8)
            . '" text-anchor="middle">' . esc($title) . '</text>';
    }
    $dots = $bands;

    return '<svg class="ip-area" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="'
        . esc(trans('dashboard_profit_label', '', 'Indice di redditività')) . '">'
        . $grid
        . '<path class="ip-area-fill" d="' . $dArea . '" fill="' . $colorVar . '" fill-opacity="0.16" />'
        . '<path class="ip-area-line" d="' . $dLine . '" fill="none" stroke="' . $colorVar
        . '" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />'
        . $dots
        . $xLbl
        . '</svg>';
};
?>

<?php
// "Personalizza finestre": pannello per ripristinare le finestre nascoste.
// Vive nell'headerbar della pagina (slot azioni), come ogni comando del
// dashboard; il periodo invece non si sceglie più qui: fatture e preventivi
// seguono sempre l'anno del filtro globale in topbar.
$windowsToggle = '<div class="ip-dash-personalize">'
    . '<button type="button" id="ip-windows-toggle" class="btn btn-sm ip-windows-toggle"'
    . ' aria-haspopup="true" aria-expanded="false">'
    . '<i class="fa fa-solid fa-sliders" aria-hidden="true"></i>'
    . esc(trans('dashboard_personalize', '', 'Personalizza finestre'))
    . '</button>'
    . '<div id="ip-windows-panel" class="ip-windows-panel" hidden>'
    . '<div class="ip-windows-panel-title">' . esc(trans('dashboard_windows_title', '', 'Finestre della dashboard')) . '</div>';
foreach ($windowsList as $win) {
    $windowsToggle .= '<label class="ip-windows-opt">'
        . '<input type="checkbox" value="' . esc($win['id']) . '" checked>'
        . esc($win['title']) . '</label>';
}
$windowsToggle .= '</div></div>';
?>
<?= $this->include('layout/headerbar', ['title' => trans('dashboard'), 'actions' => $windowsToggle]) ?>

<div id="content" class="dashboard-content">
    <?php if (!empty($first_steps)): ?>
        <?php /* Promemoria dei primi passi: sottile, sopra la bacheca. Chiuderlo
                 («Non mostrare più») sta nella pagina dei primi passi, così il
                 gesto distratto di un clic qui non lo cancella per sempre. */ ?>
        <div class="ip-first-steps-banner" role="status">
            <i class="fa fa-solid fa-list-check" aria-hidden="true"></i>
            <span><?= esc(trans('first_steps_banner', '', 'Primi passi')) ?>:
                <strong><?= (int) $first_steps['done'] ?> su <?= (int) $first_steps['total'] ?></strong>
                <?= esc(trans('first_steps_banner_tail', '', 'fatti. Mancano ancora alcune cose prima di usare l\'installazione per davvero.')) ?></span>
            <a href="<?= site_url('first_steps') ?>" class="btn btn-sm btn-primary"><?= esc(trans('first_steps_open', '', 'Continua')) ?></a>
        </div>
    <?php endif; ?>
    <?php if (!empty($fiscal_year_warning)): ?>
        <div class="alert alert-warning alert-dismissible fade show ip-dashboard-alert" role="alert">
            <i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i>
            <?= trans('fiscal_year_warning', '', 'L\'anno fiscale ' . date('Y') . ' non è configurato: le stime IRPEF/IRES usano i parametri dell\'anno più vicino.') ?>
            <a href="<?= site_url('fiscal_years') ?>" class="alert-link"><?= trans('fiscal_years', '', 'Parametri fiscali') ?></a>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= esc(trans('close', '', 'Chiudi'), 'attr') ?>"></button>
        </div>
    <?php endif; ?>
    <!-- Griglia di finestre. Ogni finestra è una card indipendente:
         espandibile a tutta larghezza, nascondibile, ripristinabile dal
         pannello "Personalizza finestre" (persistenza in localStorage). -->
    <div class="ip-dash-windows" id="ip-dash-windows">

        <!-- INDICE REDDITIVITÀ: prima finestra, a tutta larghezza. E' il
             "totale di chiusura" della pagina: la cifra che risponde alla
             domanda con cui si apre la dashboard ("come va la cassa?").
             Il sommario è un blocco chiusura da registro contabile: fondo
             carta verde pallido e doppia riga sotto l'utile (il tratto che
             il ragioniere traccia sotto il totale quando chiude una pagina
             del mastro). -->
        <section class="ip-window ip-window--wide<?= $profit_total < 0 ? ' ip-window--alert' : '' ?>" data-window="indice">
            <?= $winHead('indice', trans('dashboard_profit_label', '', 'Indice di redditività'), site_url('finance'), 'fa-solid fa-chart-line') ?>
            <div class="ip-window-body ip-indice">
                <div class="ip-indice-summary">
                    <div class="card-label"><?= esc($overview_year_label) ?></div>
                    <?php /* Come le altre finestre, titolo e cifra portano al
                             dettaglio: "Utile e imposte" è la pagina che
                             scompone questo utile e il margine dell'anno. */ ?>
                    <a class="card-value<?= $profit_total < 0 ? ' card-value-danger' : '' ?>" href="<?= site_url('finance') ?>">
                        <?= format_currency($profit_total) ?>
                    </a>
                    <span class="ip-ledger-rule" aria-hidden="true"></span>
                    <div class="card-subtext">
                        <span class="ip-kpi-count"><?= trans('dashboard_margin_label', '', 'Margine') ?>: <?= number_format($profit_margin, 1, ',', '.') ?>%</span>
                        <?php /* L'utile della card è al netto dell'IVA: la voce
                                 accanto deve dirlo, altrimenti si prova a
                                 ricavarlo sottraendo le spese lorde. */ ?>
                        <span class="ip-kpi-period"><?= trans('dashboard_expenses_net_label', '', 'Spese al netto IVA') ?>: <?= format_currency($expenses_net) ?></span>
                    </div>
                    <div class="ip-indice-trend">
                        <?= $trendBadge($profit_delta, false, $profit_delta !== null ? 'vs ' . esc((string) ($overview_prev_year ?? '')) : null) ?>
                    </div>
                </div>
                <div class="ip-indice-chart">
                    <?php
                    $profitMeta = [];
                    foreach ($chart_series_12 as $i => $row) {
                        $profitMeta[$i] = ['ym' => $row['ym'] ?? null, 'base' => site_url('finance'), 'month' => false, 'row' => trans('dashboard_profit_net_label', '', 'Utile al netto IVA')];
                    }
                    ?>
                    <?= $areaChart($profitSpark12, $profitLabels, $indiceColor, $profitMeta) ?>
                </div>
            </div>
        </section>

        <!-- RICAVI -->
        <section class="ip-window" data-window="ricavi">
            <?= $winHead('ricavi', trans('dashboard_ricavi', '', 'Ricavi'), site_url('invoices/status/all'), 'fa-regular fa-file-lines') ?>
            <div class="ip-window-body ip-window-body--kpi">
                <div class="ip-window-body-kpi-main">
                    <div class="card-label"><?= trans('dashboard_revenue_label', '', 'Totale fatturato') ?></div>
                    <a class="card-value" href="<?= site_url('invoices/status/all') ?>">
                        <?= format_currency($invoice_total) ?>
                    </a>
                    <?php /* La cifra grande è il lordo; sotto, le due voci che lo
                             compongono. Imponibile + IVA tornano al centesimo col
                             totale, quindi la card risponde a "quanto è IVA?"
                             senza aprire "Utile e imposte", che prima era l'unico
                             posto dove il netto esisteva. */ ?>
                    <dl class="ip-iva-detail ip-kpi-ledger">
                        <div class="ip-iva-row">
                            <dt><?= trans('net_amount', '', 'Imponibile') ?></dt>
                            <dd><?= format_currency($invoice_net) ?></dd>
                        </div>
                        <div class="ip-iva-row">
                            <dt><?= trans('vat', '', 'IVA') ?></dt>
                            <dd><?= format_currency($invoice_tax) ?></dd>
                        </div>
                    </dl>
                    <div class="card-subtext">
                        <span class="ip-kpi-count"><?= $invoice_count ?> <?= trans('invoices') ?></span>
                    </div>
                </div>
                <div class="ip-window-trend">
                    <?= $trendBadge($invoice_delta, false, $invoice_delta !== null ? 'vs ' . esc((string) ($overview_prev_year ?? '')) : null) ?>
                    <?= $sparkline($invoicedSeries, 'var(--ip-info)') ?>
                </div>
            </div>
        </section>

        <!-- INCASSATO -->
        <section class="ip-window" data-window="incassato">
            <?= $winHead('incassato', trans('dashboard_incassato', '', 'Incassato'), site_url('payments'), 'fa-solid fa-circle-check') ?>
            <div class="ip-window-body ip-window-body--kpi">
                <div class="ip-window-body-kpi-main">
                    <div class="card-label"><?= trans('dashboard_collected_period', '', 'Incassato nel periodo') ?></div>
                    <a class="card-value" href="<?= site_url('payments') ?>">
                        <?= format_currency($paid_current) ?>
                    </a>
                    <div class="card-subtext">
                        <span class="ip-kpi-period"><?= trans('dashboard_collection_rate', '', 'Incassato sul fatturato') ?> <?= number_format($collection_rate, 1, ',', '.') ?>%</span>
                    </div>
                </div>
                <div class="ip-window-trend">
                    <?= $trendBadge($collection_delta) ?>
                    <?= $sparkline($paidSeries, 'var(--ip-success)') ?>
                </div>
            </div>
        </section>

        <!-- SALDO DA INCASSARE -->
        <section class="ip-window" data-window="saldo">
            <?= $winHead('saldo', trans('dashboard_outstanding', '', 'Saldo da incassare'), site_url('invoices'), 'fa-solid fa-hourglass-half') ?>
            <div class="ip-window-body ip-window-body--kpi">
                <div class="ip-window-body-kpi-main">
                    <div class="card-label"><?= trans('dashboard_open_balance', '', 'Non ancora pagate') ?></div>
                    <a class="card-value<?= $overdue_total > 0 ? ' card-value-warning' : '' ?>" href="<?= site_url('invoices') ?>">
                        <?= format_currency($outstanding_total) ?>
                    </a>
                </div>
                <div class="ip-window-trend">
                    <?= $sparkline($unpaidSpark, 'var(--ip-warning)') ?>
                </div>
            </div>
        </section>

        <!-- SCADUTE -->
        <section class="ip-window<?= $overdue_total > 0 ? ' ip-window--alert' : '' ?>" data-window="scadute">
            <?= $winHead('scadute', trans('overdue_invoices'), site_url('invoices/status/overdue'), 'fa-solid fa-triangle-exclamation') ?>
            <div class="ip-window-body ip-window-body--kpi">
                <div class="ip-window-body-kpi-main">
                    <?php /* Il badge a destra dice gia' "Richiede azione" / "Tutto in
                             regola": ripeterlo qui lasciava la stessa frase due volte
                             nella stessa card. L'etichetta dice invece a cosa si
                             riferisce la cifra. */ ?>
                    <div class="card-label"><?= trans('dashboard_overdue_label', '', 'Totale scaduto') ?></div>
                    <a class="card-value<?= $overdue_total > 0 ? ' card-value-danger' : '' ?>" href="<?= site_url('invoices/status/overdue') ?>">
                        <?= format_currency($overdue_total) ?>
                    </a>
                    <div class="card-subtext">
                        <span class="ip-kpi-count"><?= $overdue_count ?> <?= trans('invoices') ?></span>
                    </div>
                </div>
                <div class="ip-window-trend">
                    <?= $overdue_count > 0
                        ? '<span class="ip-trend ip-trend--down"><i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i> ' . trans('dashboard_attention', '', 'Richiede azione') . '</span>'
                        : '<span class="ip-trend ip-trend--up"><i class="fa fa-solid fa-check-circle" aria-hidden="true"></i> ' . trans('dashboard_all_clear', '', 'Tutto in regola') . '</span>' ?>
                </div>
            </div>
        </section>

        <!-- COSTI -->
        <section class="ip-window" data-window="costi">
            <?= $winHead('costi', trans('dashboard_costi', '', 'Costi'), site_url('expenses'), 'fa-solid fa-credit-card') ?>
            <div class="ip-window-body ip-window-body--kpi">
                <div class="ip-window-body-kpi-main">
                    <div class="card-label"><?= trans('dashboard_expenses_label', '', 'Spese del periodo') ?></div>
                    <a class="card-value" href="<?= site_url('expenses') ?>">
                        <?= format_currency($expenses_total) ?>
                    </a>
                    <?php /* Come nei ricavi: lordo in cifra, imponibile e IVA
                             sotto. Il costo che entra nel calcolo dell'utile è
                             il lordo, e vederlo scomposto chiarisce perché
                             l'utile della prima card non è "fatturato - imponibile". */ ?>
                    <dl class="ip-iva-detail ip-kpi-ledger">
                        <div class="ip-iva-row">
                            <dt><?= trans('net_amount', '', 'Imponibile') ?></dt>
                            <dd><?= format_currency($expenses_net) ?></dd>
                        </div>
                        <div class="ip-iva-row">
                            <dt><?= trans('vat', '', 'IVA') ?></dt>
                            <dd><?= format_currency($expenses_tax) ?></dd>
                        </div>
                    </dl>
                    <div class="card-subtext">
                        <span class="ip-kpi-period"><?= trans('dashboard_expenses_6m', '', 'Ultimi 6 mesi') ?>: <?= format_currency($expenses_total_6m) ?></span>
                    </div>
                </div>
                <div class="ip-window-trend">
                    <?= $trendBadge($expenses_delta, true, $expenses_delta !== null ? 'vs ' . esc((string) ($overview_prev_year ?? '')) : null) ?>
                    <?= $sparkline($expenseSpark, 'var(--ip-danger)') ?>
                </div>
            </div>
        </section>

        <!-- IVA -->
        <section class="ip-window" data-window="iva">
            <?= $winHead('iva', trans('dashboard_iva', '', 'IVA'), site_url('invoices/status/all'), 'fa-solid fa-percent') ?>
                <div class="ip-window-body ip-window-body--kpi">
                    <div class="ip-window-body-kpi-main">
                        <?php
                        // Saldo negativo = IVA a credito, non un debito: mostrarlo
                        // come "IVA da pagare −11.733,55 €" in rosso diceva il
                        // contrario di quello che è (con tanti acquisti e le
                        // vendite alla PA in split payment è il caso normale).
                        // Etichetta e colore cambiano insieme, così il segno non
                        // è l'unico indizio.
                        $ivaCredit = $iva_total < 0;
                        ?>
                        <div class="card-label"><?= $ivaCredit
                            ? trans('dashboard_iva_credit', '', 'IVA a credito')
                            : trans('dashboard_iva_payable', '', 'IVA da pagare') ?></div>
                        <div class="card-value<?= $ivaCredit ? ' card-value-success' : '' ?>">
                            <?= format_currency(abs($iva_total)) ?>
                        </div>
                        <dl class="ip-iva-detail">
                            <div class="ip-iva-row">
                                <dt><?= trans('dashboard_iva_subtext', '', 'Vendite') ?></dt>
                                <dd><?= format_currency($iva_payable_tax) ?></dd>
                            </div>
                            <div class="ip-iva-row">
                                <dt><?= trans('dashboard_iva_subtext_purchase', '', 'Acquisti') ?></dt>
                                <dd>&minus;<?= format_currency($iva_purchase_tax) ?></dd>
                            </div>
                            <div class="ip-iva-row ip-iva-row--split">
                                <dt><?= trans('dashboard_iva_split', '', 'Versata da enti') ?></dt>
                                <dd><?= format_currency($iva_split_tax) ?></dd>
                            </div>
                        </dl>
                    </div>
                    <div class="ip-window-trend">
                        <?= $trendBadge($iva_delta) ?>
                        <?= $sparkline($iva_series, 'var(--ip-info)') ?>
                    </div>
                </div>
        </section>

        <!-- GRAFICO RICAVI + GRAFICO COSTI (due finestre affiancate, come
             le due finestre grafico di Fattura24). -->
        <div class="ip-window-split">
            <section class="ip-window" data-window="graf_ricavi">
                <?= $winHead('graf_ricavi', trans('dashboard_revenue_chart', '', 'Grafico ricavi'), site_url('invoices'), 'fa-solid fa-chart-column') ?>
                <?php foreach ([6, 12] as $span):
                $rSeries = $span === 12 ? $chart_series_12 : $chart_series;
                $rMax    = $span === 12 ? $chart_max_12 : $chart_max;
                ?>
                <div class="ip-chart-body ip-chart-variant ip-chart-variant--<?= $span ?>">
                    <div class="ip-chart-meta">
                        <span class="ip-chart-period"><?= esc($span === 12 ? $windowLabel12 : $windowLabel) ?></span>
                        <span class="ip-chart-legend">
                            <?php // Le voci della legenda accendono e spengono la serie (ip-chart-tips.js). ?>
                            <span class="ip-chart-legend-item" data-series="invoiced" role="button" tabindex="0" aria-pressed="true"><span class="ip-chart-legend-dot ip-chart-legend-dot--invoiced"></span><?= trans('dashboard_invoiced_label', '', 'Fatturato') ?></span>
                            <span class="ip-chart-legend-item" data-series="paid" role="button" tabindex="0" aria-pressed="true"><span class="ip-chart-legend-dot ip-chart-legend-dot--paid"></span><?= trans('dashboard_collected_label', '', 'Incassato') ?></span>
                        </span>
                    </div>
                    <?php
                    $cMax = (float) $rMax;
                    $cN   = count($rSeries);
                    // Totali di periodo (ultimi 6 mesi): in intestazione danno
                    // subito il quadro aggregato senza scorrere il grafico.
                    $sumInvoiced = 0.0;
                    $sumPaid     = 0.0;
                    foreach ($rSeries as $row) {
                        $sumInvoiced += (float) $row['invoiced'];
                        $sumPaid     += (float) $row['paid'];
                    }
                    $hasData = $cN > 0 && ($sumInvoiced > 0 || $sumPaid > 0);
                    // Gabbia SVG: N colonne, ciascuna con coppia di barre
                    // affiancate (invoiced + paid) centrate nella colonna.
                    $colW      = $plotW / max(1, $cN);
                    $gapInner  = 2;
                    $barW      = max(1, ($colW - $gapInner) / 2 - $colW * 0.10);
                    $innerPad  = ($colW - ($barW * 2 + $gapInner)) / 2;
                    $revScale  = $chartScale($cMax);
                    ?>
                    <?php if ($hasData): ?>
                    <div class="ip-chart-summary">
                        <span class="ip-chart-summary-item ip-chart-summary-item--invoiced">
                            <?= esc(trans('dashboard_invoiced_label', '', 'Fatturato')) ?>
                            <span class="ip-chart-summary-value"><?= format_currency($sumInvoiced) ?></span>
                        </span>
                        <span class="ip-chart-summary-item ip-chart-summary-item--paid">
                            <?= esc(trans('dashboard_collected_label', '', 'Incassato')) ?>
                            <span class="ip-chart-summary-value"><?= format_currency($sumPaid) ?></span>
                        </span>
                        <span class="ip-chart-summary-item">
                            <?= esc(trans('dashboard_collection_rate', '', 'Tasso di incasso')) ?>
                            <span class="ip-chart-summary-value">
                                <?= $sumInvoiced > 0 ? number_format($sumPaid / $sumInvoiced * 100, 1, ',', '.') . '%' : '—' ?>
                            </span>
                        </span>
                    </div>
                    <svg class="ip-chart" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="<?= esc(trans('dashboard_revenue_chart', '', 'Grafico ricavi')) ?>">
                        <?php /* Scala dei valori: linee orizzontali + etichetta a sinistra.
                                 Prima le linee erano verticali sui bordi colonna, decorative:
                                 non permettevano di leggere un importo senza tooltip. */ ?>
                        <?php for ($t = 0; $t <= $revScale['tickCount']; $t++):
                            $tickVal = $revScale['axisMax'] * $t / $revScale['tickCount'];
                            $tickY   = ($revScale['yOf'])($tickVal);
                        ?>
                            <line class="ip-chart-grid" x1="<?= $padLeft ?>" y1="<?= round($tickY, 1) ?>" x2="<?= $W ?>" y2="<?= round($tickY, 1) ?>" />
                            <text class="ip-chart-axis" x="<?= $padLeft - 8 ?>" y="<?= round($tickY + 3.5, 1) ?>" text-anchor="end"><?= esc($axisLabel($tickVal)) ?></text>
                        <?php endfor; ?>
                        <?php foreach ($rSeries as $i => $row):
                            $inv    = (float) $row['invoiced'];
                            $paid   = (float) $row['paid'];
                            $xCol   = $padLeft + $i * $colW;
                            $xInv   = $xCol + $innerPad;
                            $xPaid  = $xCol + $innerPad + $barW + $gapInner;
                            $yInv   = ($revScale['yOf'])($inv);
                            $yPaid  = ($revScale['yOf'])($paid);
                            $hInv   = $padTop + $barH - $yInv;
                            $hPaid  = $padTop + $barH - $yPaid;
                            $cxBar  = $xCol + $colW / 2;
                            $label  = esc($row['label']);
                        ?>
                        <g class="ip-chart-month" tabindex="0" role="link"
                            aria-label="<?= esc($row['label'] . ': ' . trans('dashboard_invoiced_label', '', 'Fatturato') . ' ' . strip_tags(format_currency($inv)) . ', ' . trans('dashboard_collected_label', '', 'Incassato') . ' ' . strip_tags(format_currency($paid)), 'attr') ?>"
                            <?= $tipAttr($row['label'], [
                                [trans('dashboard_invoiced_label', '', 'Fatturato'), format_currency($inv), 'invoiced'],
                                [trans('dashboard_collected_label', '', 'Incassato'), format_currency($paid), 'paid'],
                            ], $openHint) ?>
                            <?= $monthHref(site_url('invoices/status/all'), $row['ym'] ?? null) ?>>
                            <rect class="ip-chart-col" x="<?= round($xCol, 1) ?>" y="4" width="<?= round($colW, 1) ?>" height="<?= $H - 4 ?>" rx="6" />
                            <rect class="ip-chart-bar ip-chart-bar--invoiced" style="--i:<?= $i ?>"
                                x="<?= round($xInv, 1) ?>" y="<?= round($yInv, 1) ?>"
                                width="<?= round($barW, 1) ?>" height="<?= round(max($hInv, $inv > 0 ? 1 : 0), 1) ?>" rx="2" />
                            <rect class="ip-chart-bar ip-chart-bar--paid" style="--i:<?= $i ?>"
                                x="<?= round($xPaid, 1) ?>" y="<?= round($yPaid, 1) ?>"
                                width="<?= round($barW, 1) ?>" height="<?= round(max($hPaid, $paid > 0 ? 1 : 0), 1) ?>" rx="2" />
                            <text class="ip-chart-label" x="<?= $cxBar ?>" y="<?= $H - 8 ?>" text-anchor="middle"><?= $label ?></text>
                        </g>
                        <?php endforeach; ?>
                        <line class="ip-chart-baseline" x1="<?= $padLeft ?>" y1="<?= $padTop + $barH ?>" x2="<?= $W ?>" y2="<?= $padTop + $barH ?>" />
                    </svg>
                    <?php else: ?>
                    <div class="ip-chart-empty">
                        <i class="fa fa-solid fa-chart-column" aria-hidden="true"></i>
                        <p><?= trans('dashboard_no_chart_data', '', 'Nessun movimento fatturazione nel periodo.') ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </section>

            <section class="ip-window" data-window="graf_costi">
                <?= $winHead('graf_costi', trans('dashboard_expenses_chart', '', 'Grafico costi'), site_url('expenses'), 'fa-solid fa-credit-card') ?>
                <?php foreach ([6, 12] as $span):
                $eSeries = $span === 12 ? $expense_series_12 : $expense_series;
                $eTotal  = $span === 12 ? $expenses_total_12m : $expenses_total_6m;
                $pTotal  = $span === 12 ? $profit_total_12m : $profit_total_6m;
                $iNet    = $span === 12 ? $income_net_12m : $income_net_6m;
                ?>
                <div class="ip-chart-body ip-chart-variant ip-chart-variant--<?= $span ?>">
                    <div class="ip-chart-meta">
                        <span class="ip-chart-period"><?= esc($span === 12 ? $windowLabel12 : $windowLabel) ?></span>
                        <span class="ip-chart-legend">
                            <span class="ip-chart-legend-item"><span class="ip-chart-legend-dot ip-chart-legend-dot--expenses"></span><?= trans('dashboard_expenses_label', '', 'Spese') ?></span>
                        </span>
                    </div>
                    <?php
                    $eN      = count($eSeries);
                    $expMax  = 1.0;
                    foreach ($eSeries as $row) {
                        $expMax = max($expMax, abs((float) $row['total']));
                    }
                    $expScale = $chartScale($expMax);
                    $eColW    = $plotW / max(1, $eN);
                    $eBarW    = max(1, $eColW * 0.42);
                    $eInner   = ($eColW - $eBarW) / 2;
                    $hasExp   = $eTotal > 0;
                    ?>
                    <?php if ($hasExp): ?>
                    <div class="ip-chart-summary">
                        <span class="ip-chart-summary-item ip-chart-summary-item--expenses">
                            <?php /* Le barre sono uscite di cassa, quindi IVA
                                     inclusa: dirlo evita che il lettore sommi
                                     questa voce all'utile (che è al netto). */ ?>
                            <?= esc(trans('dashboard_expenses_gross_label', '', 'Spese IVA inclusa')) ?>
                            <span class="ip-chart-summary-value"><?= format_currency($eTotal) ?></span>
                        </span>
                        <span class="ip-chart-summary-item ip-chart-summary-item--profit">
                            <?= esc(trans('dashboard_profit_label', '', 'Utile di impresa')) ?>
                            <span class="ip-chart-summary-value"><?= format_currency($pTotal) ?></span>
                        </span>
                        <span class="ip-chart-summary-item">
                            <?= esc(trans('dashboard_margin_label', '', 'Margine')) ?>
                            <span class="ip-chart-summary-value">
                                <?= $iNet > 0 ? number_format($pTotal / $iNet * 100, 1, ',', '.') . '%' : '—' ?>
                            </span>
                        </span>
                    </div>
                    <svg class="ip-chart" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="<?= esc(trans('dashboard_expenses_chart', '', 'Grafico costi')) ?>">
                        <?php for ($t = 0; $t <= $expScale['tickCount']; $t++):
                            $tickVal = $expScale['axisMax'] * $t / $expScale['tickCount'];
                            $tickY   = ($expScale['yOf'])($tickVal);
                        ?>
                            <line class="ip-chart-grid" x1="<?= $padLeft ?>" y1="<?= round($tickY, 1) ?>" x2="<?= $W ?>" y2="<?= round($tickY, 1) ?>" />
                            <text class="ip-chart-axis" x="<?= $padLeft - 8 ?>" y="<?= round($tickY + 3.5, 1) ?>" text-anchor="end"><?= esc($axisLabel($tickVal)) ?></text>
                        <?php endfor; ?>
                        <?php foreach ($eSeries as $i => $row):
                            $exp    = (float) $row['total'];
                            $xExp   = $padLeft + $i * $eColW + $eInner;
                            $yExp   = ($expScale['yOf'])($exp);
                            $hExp   = $padTop + $barH - $yExp;
                            $cxBar  = $padLeft + $i * $eColW + $eColW / 2;
                            $label  = esc($row['label']);
                        ?>
                        <g class="ip-chart-month" tabindex="0" role="link"
                            aria-label="<?= esc($row['label'] . ': ' . trans('dashboard_expenses_label', '', 'Spese') . ' ' . strip_tags(format_currency($exp)), 'attr') ?>"
                            <?= $tipAttr($row['label'], [
                                [trans('dashboard_expenses_label', '', 'Spese'), format_currency($exp), 'expenses'],
                            ], $openHint) ?>
                            <?= $monthHref(site_url('expenses'), $row['ym'] ?? null) ?>>
                            <rect class="ip-chart-col" x="<?= round($padLeft + $i * $eColW, 1) ?>" y="4" width="<?= round($eColW, 1) ?>" height="<?= $H - 4 ?>" rx="6" />
                            <rect class="ip-chart-bar ip-chart-bar--expenses" style="--i:<?= $i ?>"
                                x="<?= round($xExp, 1) ?>" y="<?= round($yExp, 1) ?>"
                                width="<?= round($eBarW, 1) ?>" height="<?= round(max($hExp, $exp > 0 ? 1 : 0), 1) ?>" rx="2" />
                            <text class="ip-chart-label" x="<?= $cxBar ?>" y="<?= $H - 8 ?>" text-anchor="middle"><?= $label ?></text>
                        </g>
                        <?php endforeach; ?>
                        <line class="ip-chart-baseline" x1="<?= $padLeft ?>" y1="<?= $padTop + $barH ?>" x2="<?= $W ?>" y2="<?= $padTop + $barH ?>" />
                    </svg>
                    <?php else: ?>
                    <div class="ip-chart-empty">
                        <i class="fa fa-regular fa-credit-card" aria-hidden="true"></i>
                        <p><?= trans('dashboard_no_expense_data', '', 'Nessuna spesa registrata nel periodo.') ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </section>
        </div>

        <!-- Dettaglio: breakdown per stato + tabelle recenti, in due colonne.
             Ogni card è una finestra a sé (espandibile/nascondibile). -->
        <div class="ip-window-split">
            <!-- Fatture per stato -->
                <section class="ip-window" data-window="stato_fatture">
                    <?= $winHead('stato_fatture', trans('invoice_overview_breakdown', '', 'Fatture per stato'), site_url('invoices'), 'fa-solid fa-chart-pie') ?>
                    <div class="ip-window-body ip-window-body--flush">
                        <?php /* Composizione per stato come barra impilata: e' la sola
                                 informazione che il donut aggiungeva rispetto alla
                                 tabella qui sotto (la quota sul totale), su una
                                 codifica piu' leggibile della torta e senza occupare
                                 una card a se'. */ ?>
                        <?= $statusMixBar($donut_data, trans('dashboard_status_mix', '', 'Composizione stati fattura'), site_url('invoices/status'), trans('invoices')) ?>
                        <div class="table-responsive">
                            <table class="table no-margin ip-overview-table">
                                <tbody>
                                <?php foreach ($invoice_status_totals as $total): ?>
                                    <?php $emptyRow = (int) ($total['num_total'] ?? 0) === 0; ?>
                                    <tr class="<?= $emptyRow ? 'ip-status-empty' : '' ?>">
                                        <td>
                                            <a href="<?= site_url('invoices/status/' . $total['class']) ?>"><?= esc($total['label']) ?></a>
                                        </td>
                                        <td class="amount">
                                            <span class="ip-amount-cell">
                                                <span class="badge text-bg-light ip-count-badge" title="<?= esc(trans('invoices')) ?>">
                                                    <?= (int) $total['num_total'] ?>
                                                </span>
                                                <span class="ip-amount-value"><?= format_currency($total['sum_total']) ?></span>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($overdue_count > 0): ?>
                                    <tr class="danger">
                                        <td>
                                            <a href="<?= site_url('invoices/status/overdue') ?>" class="text-danger">
                                                <i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i> <?= trans('overdue_invoices') ?>
                                            </a>
                                        </td>
                                        <td class="amount text-danger"><strong><?= format_currency($overdue_total) ?></strong></td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <!-- Preventivi per stato -->
                <section class="ip-window" data-window="stato_preventivi">
                    <?= $winHead('stato_preventivi', trans('quote_overview_breakdown', '', 'Preventivi per stato'), site_url('quotes'), 'fa-solid fa-chart-pie') ?>
                    <div class="ip-window-body ip-window-body--flush">
                        <?php
                        // Stessa barra della finestra fatture, ricavata dai totali per
                        // stato: le due colonne di breakdown si leggono allo stesso modo.
                        $quoteMix = [];
                        foreach ($quote_status_totals as $total) {
                            $quoteMix[] = [
                                'label'  => $total['label'],
                                'value'  => (float) $total['sum_total'],
                                'status' => $total['class'],
                                'count'  => (int) ($total['num_total'] ?? 0),
                            ];
                        }
                        ?>
                        <?= $statusMixBar($quoteMix, trans('dashboard_status_mix_quotes', '', 'Composizione stati preventivo'), site_url('quotes/status'), trans('quotes')) ?>
                        <div class="table-responsive">
                            <table class="table no-margin ip-overview-table">
                                <tbody>
                                <?php foreach ($quote_status_totals as $total): ?>
                                    <?php $emptyRow = (int) ($total['num_total'] ?? 0) === 0; ?>
                                    <tr class="<?= $emptyRow ? 'ip-status-empty' : '' ?>">
                                        <td>
                                            <a href="<?= site_url('quotes/status/' . $total['class']) ?>"><?= esc($total['label']) ?></a>
                                        </td>
                                        <td class="amount">
                                            <span class="ip-amount-cell">
                                                <span class="badge text-bg-light ip-count-badge" title="<?= esc(trans('quotes')) ?>">
                                                    <?= (int) $total['num_total'] ?>
                                                </span>
                                                <span class="ip-amount-value"><?= format_currency($total['sum_total']) ?></span>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <!-- Ultime fatture -->
                <section class="ip-window" data-window="ultime_fatture">
                    <?= $winHead('ultime_fatture', trans('recent_invoices'), site_url('invoices'), 'fa-solid fa-clock-rotate-left') ?>
                    <div class="ip-window-body ip-window-body--flush">
                        <div class="table-responsive">
                            <table class="table table-hover no-margin ip-recent-table">
                                <thead>
                                <tr>
                                    <th scope="col"><?= trans('status') ?></th>
                                    <th scope="col"><?= trans('due_date') ?></th>
                                    <th scope="col"><?= trans('invoice') ?></th>
                                    <th scope="col"><?= trans('client') ?></th>
                                    <th scope="col" class="amount"><?= trans('balance') ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($invoices as $invoice): ?>
                                    <?php $statusInfo = $invoice_statuses[(string) $invoice['invoice_status_id']] ?? ['label' => '', 'class' => 'draft']; ?>
                                    <?php $overdueClass = !empty($invoice['is_overdue']) ? ' ip-row-overdue' : ''; ?>
                                    <tr class="ip-row-link<?= esc($overdueClass) ?>" data-href="<?= site_url('invoices/view/' . $invoice['invoice_id']) ?>">
                                        <td data-label="<?= esc(trans('status')) ?>">
                                            <span class="badge ip-status <?= $status_label_class[$statusInfo['class']] ?? 'text-bg-secondary' ?>">
                                                <?= esc($statusInfo['label']) ?>
                                            </span>
                                        </td>
                                        <?php /* Il badge pieno andava a capo sotto la data (la colonna
                                                 e' stretta) e rendeva le righe scadute piu' alte delle
                                                 altre. Un'icona inline nello stesso flusso della data
                                                 tiene le righe della stessa altezza e conserva il
                                                 segnale non affidato al solo colore. */ ?>
                                        <td class="ip-due-cell" data-label="<?= esc(trans('due_date')) ?>">
                                            <span class="<?= !empty($invoice['is_overdue']) ? 'text-danger' : '' ?>">
                                                <?= esc(date_from_mysql($invoice['invoice_date_due'])) ?>
                                            </span>
                                            <?php if (!empty($invoice['is_overdue'])): ?>
                                                <i class="fa fa-solid fa-exclamation-triangle ip-overdue-flag"
                                                   title="<?= esc(trans('overdue_invoices')) ?>"
                                                   aria-label="<?= esc(trans('overdue_invoices')) ?>"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="<?= esc(trans('invoice')) ?>">
                                            <a href="<?= site_url('invoices/view/' . $invoice['invoice_id']) ?>">
                                                <span class="ip-num"><?= esc(invoice_number_label($invoice)) ?></span>
                                            </a>
                                        </td>
                                        <td data-label="<?= esc(trans('client')) ?>"><?= $clientChip($invoice) ?></td>
                                        <td class="amount" data-label="<?= esc(trans('balance')) ?>"><?= format_currency($invoice['invoice_balance']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($invoices)): ?>
                                    <tr class="empty-row">
                                        <td colspan="5">
                                            <i class="fa fa-regular fa-file-text empty-icon" aria-hidden="true"></i>
                                            <?= trans('no_records') ?>
                                            <div class="empty-actions">
                                                <a href="<?= site_url('invoices/form_create') ?>" class="empty-cta">
                                                    <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('create_invoice', '', 'Nuova fattura') ?>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?= $winFoot(site_url('invoices')) ?>
                </section>

                <!-- Ultimi preventivi -->
                <section class="ip-window" data-window="ultimi_preventivi">
                    <?= $winHead('ultimi_preventivi', trans('recent_quotes'), site_url('quotes'), 'fa-solid fa-clock-rotate-left') ?>
                    <div class="ip-window-body ip-window-body--flush">
                        <div class="table-responsive">
                            <table class="table table-hover no-margin ip-recent-table">
                                <thead>
                                <tr>
                                    <th scope="col"><?= trans('status') ?></th>
                                    <th scope="col"><?= trans('date') ?></th>
                                    <th scope="col"><?= trans('quote') ?></th>
                                    <th scope="col"><?= trans('client') ?></th>
                                    <th scope="col" class="amount"><?= trans('balance') ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($quotes as $quote): ?>
                                    <?php $statusInfo = $quote_statuses[(string) $quote['quote_status_id']] ?? ['label' => '', 'class' => 'draft']; ?>
                                    <?php $expiredClass = !empty($quote['is_expired']) ? ' ip-row-expired' : ''; ?>
                                    <tr class="ip-row-link<?= esc($expiredClass) ?>" data-href="<?= site_url('quotes/view/' . $quote['quote_id']) ?>">
                                        <td data-label="<?= esc(trans('status')) ?>">
                                            <span class="badge ip-status <?= $status_label_class[$statusInfo['class']] ?? 'text-bg-secondary' ?>">
                                                <?= esc($statusInfo['label']) ?>
                                            </span>
                                        </td>
                                        <td data-label="<?= esc(trans('date')) ?>">
                                            <span class="<?= !empty($quote['is_expired']) ? 'text-danger' : '' ?>">
                                                <?= esc(date_from_mysql($quote['quote_date_created'])) ?>
                                            </span>
                                            <?php if (!empty($quote['quote_date_expires']) && $quote['quote_date_expires'] !== '0000-00-00'): ?>
                                                <span class="ip-row-meta text-muted">· <?= esc(trans('expires')) ?> <?= esc(date_from_mysql($quote['quote_date_expires'])) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="<?= esc(trans('quote')) ?>">
                                            <a href="<?= site_url('quotes/view/' . $quote['quote_id']) ?>">
                                                <span class="ip-num"><?= esc($quote['quote_number'] ?: $quote['quote_id']) ?></span>
                                            </a>
                                        </td>
                                        <td data-label="<?= esc(trans('client')) ?>"><?= $clientChip($quote) ?></td>
                                        <td class="amount" data-label="<?= esc(trans('balance')) ?>"><?= format_currency($quote['quote_total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($quotes)): ?>
                                    <tr class="empty-row">
                                        <td colspan="5">
                                            <i class="fa fa-regular fa-file empty-icon" aria-hidden="true"></i>
                                            <?= trans('no_records') ?>
                                            <div class="empty-actions">
                                                <a href="<?= site_url('quotes/form_create') ?>" class="empty-cta">
                                                    <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('create_quote', '', 'Nuovo preventivo') ?>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?= $winFoot(site_url('quotes')) ?>
                </section>

                <!-- Ultimi DDT -->
                <section class="ip-window" data-window="ultimi_ddt">
                    <?= $winHead('ultimi_ddt', trans('recent_delivery_notes', '', 'DDT recenti'), site_url('delivery_notes'), 'fa-solid fa-truck') ?>
                    <div class="ip-window-body ip-window-body--flush">
                        <div class="table-responsive">
                            <table class="table table-hover no-margin ip-recent-table">
                                <thead>
                                <tr>
                                    <th scope="col"><?= trans('status') ?></th>
                                    <th scope="col"><?= trans('date') ?></th>
                                    <th scope="col"><?= trans('delivery_note', '', 'DDT') ?></th>
                                    <th scope="col"><?= trans('client') ?></th>
                                    <th scope="col"><?= trans('invoice', '', 'Fattura') ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($delivery_notes as $note): ?>
                                    <tr class="ip-row-link" data-href="<?= site_url('delivery_notes/view/' . $note['delivery_note_id']) ?>">
                                        <td data-label="<?= esc(trans('status')) ?>">
                                            <?php if (!empty($note['is_invoiced'])): ?>
                                                <span class="badge ip-status text-bg-success"><?= esc(trans('invoiced', '', 'Fatturato')) ?></span>
                                            <?php else: ?>
                                                <span class="badge ip-status text-bg-warning"><?= esc(trans('to_invoice', '', 'Da fatturare')) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="<?= esc(trans('date')) ?>">
                                            <?= esc(date_from_mysql($note['delivery_note_date'])) ?>
                                        </td>
                                        <td data-label="<?= esc(trans('delivery_note', '', 'DDT')) ?>">
                                            <a href="<?= site_url('delivery_notes/view/' . $note['delivery_note_id']) ?>">
                                                <span class="ip-num"><?= esc($note['delivery_note_number'] ?: ('#' . $note['delivery_note_id'])) ?></span>
                                            </a>
                                        </td>
                                        <td data-label="<?= esc(trans('client')) ?>"><?= $clientChip($note) ?></td>
                                        <td data-label="<?= esc(trans('invoice', '', 'Fattura')) ?>">
                                            <?php if (!empty($note['invoice_id'])): ?>
                                                <a href="<?= site_url('invoices/view/' . $note['invoice_id']) ?>">
                                                    <span class="ip-num"><?= esc(invoice_number_label($note)) ?></span>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($delivery_notes)): ?>
                                    <tr class="empty-row">
                                        <td colspan="5">
                                            <i class="fa fa-solid fa-truck empty-icon" aria-hidden="true"></i>
                                            <?= trans('no_delivery_notes', '', 'Nessun documento di trasporto.') ?>
                                            <div class="empty-actions">
                                                <a href="<?= site_url('delivery_notes/form') ?>" class="empty-cta">
                                                    <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('new_delivery_note', '', 'Nuovo DDT') ?>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?= $winFoot(site_url('delivery_notes')) ?>
                </section>

                <!-- Progetti -->
                <?php if ($projects_enabled): ?>
                <section class="ip-window" data-window="progetti">
                    <?= $winHead('progetti', trans('projects'), site_url('projects'), 'fa-solid fa-list') ?>
                    <div class="ip-window-body ip-window-body--flush">
                        <div class="table-responsive">
                            <table class="table table-hover no-margin ip-recent-table">
                                <thead>
                                <tr>
                                    <th scope="col"><?= trans('project_name') ?></th>
                                    <th scope="col"><?= trans('client_name') ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($projects as $project): ?>
                                    <tr>
                                        <td data-label="<?= esc(trans('project_name')) ?>"><a href="<?= site_url('projects/view/' . $project['project_id']) ?>"><?= esc($project['project_name']) ?></a></td>
                                        <td data-label="<?= esc(trans('client_name')) ?>"><?= $clientChip($project) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($projects)): ?>
                                    <tr class="empty-row">
                                        <td colspan="2">
                                            <i class="fa fa-solid fa-list empty-icon" aria-hidden="true"></i>
                                            <?= trans('no_records') ?>
                                            <div class="empty-actions">
                                                <a href="<?= site_url('projects/form') ?>" class="empty-cta">
                                                    <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('add_project', '', 'Nuovo progetto') ?>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?= $winFoot(site_url('projects')) ?>
                </section>
                <?php endif; ?>

                <!-- Attività -->
                <?php if ($projects_enabled): ?>
                <section class="ip-window" data-window="attivita">
                    <?= $winHead('attivita', trans('tasks'), site_url('tasks'), 'fa-regular fa-square-check', (string) (int) $open_tasks) ?>
                    <div class="ip-window-body ip-window-body--flush">
                        <div class="table-responsive">
                            <table class="table table-hover no-margin ip-recent-table">
                                <thead>
                                <tr>
                                    <th scope="col"><?= trans('status') ?></th>
                                    <th scope="col"><?= trans('task_name') ?></th>
                                    <th scope="col"><?= trans('finish_date') ?></th>
                                    <th scope="col"><?= trans('project') ?></th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($tasks as $task): ?>
                                    <?php $statusInfo = $task_statuses[(string) $task['task_status']] ?? ['label' => '', 'class' => 'draft']; ?>
                                    <tr>
                                        <td data-label="<?= esc(trans('status')) ?>">
                                            <span class="badge ip-status <?= $status_label_class[$statusInfo['class']] ?? 'text-bg-secondary' ?>">
                                                <?= esc($statusInfo['label']) ?>
                                            </span>
                                        </td>
                                        <td data-label="<?= esc(trans('task_name')) ?>"><a href="<?= site_url('tasks/form/' . $task['task_id']) ?>"><?= esc($task['task_name']) ?></a></td>
                                        <td data-label="<?= esc(trans('finish_date')) ?>" class="<?= !empty($task['is_overdue']) ? 'text-danger' : '' ?>">
                                            <?= esc(date_from_mysql($task['task_finish_date'])) ?>
                                        </td>
                                        <td data-label="<?= esc(trans('project')) ?>">
                                            <?php if (!empty($task['project_id'])): ?>
                                                <a href="<?= site_url('projects/view/' . $task['project_id']) ?>"><?= esc($task['project_name'] ?? '') ?></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($tasks)): ?>
                                    <tr class="empty-row">
                                        <td colspan="4">
                                            <i class="fa fa-regular fa-check-square empty-icon" aria-hidden="true"></i>
                                            <?= trans('no_records') ?>
                                            <div class="empty-actions">
                                                <a href="<?= site_url('tasks/form') ?>" class="empty-cta">
                                                    <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('add_task', '', 'Nuova attività') ?>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?= $winFoot(site_url('tasks')) ?>
                </section>
                <?php endif; ?>
        </div>
    </div>
</div>

<script>
    // Finestre della dashboard (stile Fattura24): espandi / nascondi /
    // ripristina. Le finestre nascoste persistono in localStorage, così
    // la disposizione scelta dall'utente resta alla ricarica della pagina.
    (function () {
        var KEY = 'ip_dash_hidden';
        var grid = document.getElementById('ip-dash-windows');
        if (!grid) { return; }
        var toggleBtn = document.getElementById('ip-windows-toggle');
        var panel = document.getElementById('ip-windows-panel');

        // Indice di scala per l'ingresso a scaletta delle finestre (animazioni
        // 22e in custom.css): --i segue l'ordine DOM così la parete di KPI si
        // compone dall'alto, come un registro che si riempie.
        grid.querySelectorAll('.ip-window').forEach(function (w, idx) {
            w.style.setProperty('--i', idx);
        });

        function readHidden() {
            try { return JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { return []; }
        }
        function saveHidden(list) {
            try { localStorage.setItem(KEY, JSON.stringify(list)); } catch (e) { /* storage non disponibile */ }
        }
        function windowById(id) {
            return grid.querySelector('.ip-window[data-window="' + id + '"]');
        }
        function applyHidden(list) {
            grid.querySelectorAll('.ip-window').forEach(function (w) {
                var hidden = list.indexOf(w.getAttribute('data-window')) !== -1;
                w.classList.toggle('is-hidden', hidden);
            });
        }
        function syncPanel() {
            var list = readHidden();
            applyHidden(list);
            if (panel) {
                panel.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
                    cb.checked = list.indexOf(cb.value) === -1;
                });
            }
        }
        function setHidden(id, hidden) {
            var list = readHidden();
            var i = list.indexOf(id);
            if (hidden && i === -1) { list.push(id); }
            if (!hidden && i !== -1) { list.splice(i, 1); }
            saveHidden(list);
            applyHidden(list);
            if (panel) {
                var cb = panel.querySelector('input[value="' + id + '"]');
                if (cb) { cb.checked = !hidden; }
            }
        }

        grid.querySelectorAll('.ip-window').forEach(function (w) {
            var id = w.getAttribute('data-window');
            var hideBtn = w.querySelector('.ip-window-hide');
            var expBtn = w.querySelector('.ip-window-expand');

            if (hideBtn) {
                hideBtn.addEventListener('click', function () { setHidden(id, true); });
            }
            if (expBtn) {
                expBtn.addEventListener('click', function () {
                    var expanded = w.classList.toggle('ip-window--expanded');
                    var icon = expBtn.querySelector('i');
                    if (icon) {
                        icon.className = expanded ? 'fa fa-compress' : 'fa fa-expand';
                    }
                    expBtn.title = expBtn.getAttribute('aria-label') || expBtn.title;
                });
            }
        });

        if (toggleBtn && panel) {
            toggleBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = panel.hidden;
                panel.hidden = !open;
                toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            panel.addEventListener('click', function (e) { e.stopPropagation(); });
            ipOn(document, 'click', function () {
                panel.hidden = true;
                toggleBtn.setAttribute('aria-expanded', 'false');
            });
            ipOn(document, 'keydown', function (e) {
                if (e.key === 'Escape') {
                    panel.hidden = true;
                    toggleBtn.setAttribute('aria-expanded', 'false');
                }
            });
            panel.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
                cb.addEventListener('change', function () { setHidden(cb.value, !cb.checked); });
            });
        }

        syncPanel();
    })();

    // Righe cliccabili nelle tabelle "recenti" (fatture e preventivi).
    // Il link sul numero documento resta il bersaglio vero — e' quello che
    // vede la tastiera, quello che il browser sa aprire in una nuova scheda —
    // ma su righe alte 40px era un'area minuscola rispetto allo spazio che la
    // riga occupa: qui il click ovunque nella riga replica quel link.
    // Deliberatamente non si avvolge la riga in un <a> (HTML non valido dentro
    // <tr>) ne' si usa uno stretched-link in overlay, che coprirebbe il chip
    // cliente e gli altri link della riga.
    (function () {
        function rowHref(e) {
            var tr = e.target.closest ? e.target.closest('.ip-recent-table tr[data-href]') : null;
            if (!tr) { return null; }
            // Un click su un elemento gia' interattivo (link cliente, badge
            // con link, pulsanti) deve fare la sua azione, non quella di riga.
            if (e.target.closest('a, button, input, select, textarea, label')) { return null; }
            // Selezione di testo in corso: l'utente stava copiando un importo,
            // non chiedendo di navigare.
            var sel = window.getSelection && window.getSelection().toString();
            if (sel) { return null; }
            return tr.getAttribute('data-href');
        }

        ipOn(document, 'click', function (e) {
            var href = rowHref(e);
            if (!href) { return; }
            if (e.ctrlKey || e.metaKey || e.shiftKey) {
                window.open(href, '_blank', 'noopener');
            } else if (window.ipNavigate) {
                // La riga non è un <a>: la navigazione parziale va chiesta a mano.
                window.ipNavigate(href);
            } else {
                window.location.href = href;
            }
        });

        // Tasto centrale: stessa aspettativa del click centrale su un link,
        // apre in una nuova scheda senza lasciare la dashboard.
        ipOn(document, 'auxclick', function (e) {
            if (e.button !== 1) { return; }
            var href = rowHref(e);
            if (!href) { return; }
            e.preventDefault();
            window.open(href, '_blank', 'noopener');
        });
    })();
</script>

<?= $this->endSection() ?>
