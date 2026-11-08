<?= $this->extend('layout/base') ?>
<?= $this->section('content') ?>
<?php $this->setVar('pageTitle', format_client($client)); ?>

<?php
// Mappa classi Bootstrap 3 per i badge di stato (stile CI3)
$status_label_class = [
    'draft' => 'text-bg-secondary',
    'sent' => 'text-bg-info',
    'viewed' => 'text-bg-warning',
    'paid' => 'text-bg-success',
    'approved' => 'text-bg-success',
    'rejected' => 'text-bg-danger',
    'canceled' => 'text-bg-dark',
    'overdue' => 'text-bg-danger',
];
?>

<?php
// Geometria e scala dei grafici SVG della scheda cliente: riusa la stessa
// "gabbia" e la stessa scala "tonda" dei grafici della dashboard (passo
// 1/2/2.5/5 x 10^n), così le due pagine si leggono allo stesso modo.
$W     = 600;
$H     = 190;
$padTop    = 12;
$padBottom = 28;
$padLeft   = 56;
$barH      = $H - $padTop - $padBottom;
$plotW     = $W - $padLeft;

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

$axisLabel = static function (float $v): string {
    $sym = trim((string) get_setting('currency_symbol')) ?: '';
    if ($v >= 1000000) {
        return $sym . rtrim(rtrim(number_format($v / 1000000, 1, ',', '.'), '0'), ',') . 'M';
    }
    if ($v >= 1000) {
        return $sym . rtrim(rtrim(number_format($v / 1000, 1, ',', '.'), '0'), ',') . 'k';
    }
    return $sym . number_format($v, 0, ',', '.');
};

$chartScale = static function (float $max) use ($niceStep, $axisLabel, $padTop, $barH): array {
    $step      = $niceStep($max / 4);
    $tickCount = $max > 0 ? (int) ceil($max / $step) : 1;
    $axisMax   = $step * $tickCount;
    $yOf       = static fn(float $v): float => $axisMax > 0
        ? $padTop + $barH - $barH * ($v / $axisMax)
        : $padTop + $barH;

    return ['step' => $step, 'tickCount' => $tickCount, 'axisMax' => $axisMax, 'yOf' => $yOf];
};

// Donut composizione incassato/saldo/scaduto: tre archi su una circonferenza
// unica (stroke-dasharray), con il totale fatturato al centro. Niente librerie
// esterne, come tutti i grafici dell'app: SVG inline, coerente col tema.
// Riceve le fette gia' pronte (chiave, colore, valore, etichetta): e' la STESSA
// lista che scrive la legenda nel corpo della card, quindi colori, importi,
// percentuali e tooltip non possono mai raccontare storie diverse.
//
// Separatore tra le fette: un sottile respiro (2.5px) che stacca gli archi e
// rende leggibile anche la fetta piu' piccola (es. il 9% giallo tra verde e
// rosso nello screenshot, che prima si perdeva senza bordo). Raggio e spessore
// leggermente aumentati per dare piu' presenza alla card dedicata.
$clientDonut = static function (array $slices, float $total): string {
    if ($total <= 0) {
        return '';
    }
    $r  = 60;
    $cx = 80;
    $cy = 80;
    $sw = 24;
    $C  = 2 * M_PI * $r;
    $gap = 2.5;
    $visible = 0;
    foreach ($slices as $s) { if ($s['v'] > 0) { $visible++; } }
    $off = 0;
    $arcs = '';
    foreach ($slices as $s) {
        if ($s['v'] <= 0) {
            continue;
        }
        $len = ($s['v'] / $total) * $C;
        // Togliamo il gap solo se c'e' piu' di una fetta, altrimenti il cerchio
        // intero perderebbe un pezzettino senza motivo.
        $draw = $visible > 1 ? max(0, $len - $gap) : $len;
        // Nel <title> (tooltip nativo, zero JS) anche la quota percentuale:
        // sull'arco l'occhio stima le proporzioni, il numero la precisa.
        $pct = (int) round($s['v'] / $total * 100);
        $arcs .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="' . $s['c']
            . '" stroke-width="' . $sw . '" stroke-dasharray="' . round($draw, 2) . ' ' . round($C - $draw, 2)
            . '" stroke-dashoffset="' . round(-$off, 2) . '" transform="rotate(-90 ' . $cx . ' ' . $cy . ')"'
            . ' stroke-linecap="butt"><title>' . esc($s['label'] . ': ' . format_currency($s['v'])
                . ' (' . $pct . '%)') . '</title></circle>';
        $off += $len;
    }
    // Al centro, invece del totale (già nelle KPI sopra), il tasso di
    // incasso: e' la domanda vera su un cliente ("quanto di quanto gli ho
    // fatturato e' gia' in cassa?"). Gli archi ne sono la rappresentazione.
    $paidV = 0.0;
    foreach ($slices as $s) {
        if (($s['k'] ?? '') === 'paid') { $paidV = $s['v']; break; }
    }
    $rate  = $total > 0 ? ($paidV / $total) * 100.0 : 0.0;

    return '<svg class="ip-donut" viewBox="0 0 160 160" role="img" aria-label="'
        . esc(trans('client_collection_donut', '', 'Tasso di incasso')) . '">'
        . '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="var(--ip-border-soft)" stroke-width="' . $sw . '"/>'
        . $arcs
        . '<text class="ip-donut-total" x="' . $cx . '" y="' . ($cy - 4) . '" text-anchor="middle">' . number_format($rate, 0, ',', '.') . '%</text>'
        . '<text class="ip-donut-sub" x="' . $cx . '" y="' . ($cy + 14) . '" text-anchor="middle">' . esc(trans('dashboard_collection_rate', '', 'Tasso di incasso')) . '</text>'
        . '</svg>';
};
?>

<?php
$title = esc(format_client($client)) . ' <span class="ip-client-type-title">' . client_type_badge($client['client_type'] ?? null) . '</span>';
// Azioni rapide: creare una fattura o un preventivo per QUESTO cliente era
// possibile solo passando dal menu laterale e riselezionando il cliente da
// capo nel form — qui il client_id viene preselezionato (?client_id=).
$actions = '<div class="btn-group btn-group-sm">'
    . '<a href="' . site_url('invoices/form_create') . '?client_id=' . (int) $client['client_id'] . '" class="btn btn-secondary"><i class="fa fa-regular fa-file-text" aria-hidden="true"></i> ' . trans('create_invoice', '', 'Nuova fattura') . '</a>'
    . '<a href="' . site_url('quotes/form_create') . '?client_id=' . (int) $client['client_id'] . '" class="btn btn-secondary"><i class="fa fa-regular fa-file" aria-hidden="true"></i> ' . trans('create_quote', '', 'Nuovo preventivo') . '</a>'
    . '<a href="' . site_url('clients/form/' . $client['client_id']) . '" class="btn btn-secondary"><i class="fa fa-regular fa-edit" aria-hidden="true"></i> ' . trans('edit') . '</a>'
    . '</div>'
    . '<form action="' . site_url('clients/delete/' . $client['client_id']) . '" method="POST" style="display:inline-block">' . csrf_field() . '<button type="button" class="btn btn-danger btn-sm" data-bs-toggle="confirm" data-confirm-title="' . esc(trans('confirm', '', 'Conferma')) . '" data-confirm-body="' . esc(trans('delete_client_warning')) . '" data-confirm-ok="' . esc(trans('delete', '', 'Elimina')) . '" data-confirm-danger="1"><i class="fa fa-solid fa-trash-can" aria-hidden="true"></i> ' . trans('delete') . '</button></form>';
?>
<?= $this->include('layout/headerbar', ['title' => $title, 'actions' => $actions]) ?>

<?php
// Le schede le emette layout/tabs: stessa barra segmentata delle impostazioni,
// invece delle linguette .nav-tabs che erano l'unico punto dell'app a usarle.
// I badge mostrano i conteggi REALI (contati in SQL dal controller): prima
// mostravano count() della lista, bloccata a 20 righe, quindi un cliente con
// 60 fatture vedeva il badge "20" anche se ne aveva 60.
?>
<?= $this->include('layout/tabs', [
    'id'   => 'client-tabs',
    'tabs' => [
        ['id' => 'clientDetails',  'label' => trans('details')],
        ['id' => 'clientQuotes',   'label' => trans('quotes'),   'badge' => (int) $quotes_count],
        ['id' => 'clientInvoices', 'label' => trans('invoices'), 'badge' => (int) $invoices_count],
        ['id' => 'clientPayments', 'label' => trans('payments'), 'badge' => (int) $payments_count],
    ],
]) ?>

<div id="content" class="no-padding">
    <div class="tab-content no-padding">

        <div id="clientDetails" class="tab-pane tab-rich-content active" role="tabpanel" aria-labelledby="tab-clientDetails" tabindex="0">

            <?php
            // Monogramma anagrafico: le iniziali del nome (o della ragione
            // sociale) diventano l'avatar della "tessera" del cliente, come
            // l'avatar utente in testata ma in versione squircle brand.
            $mono_source = $client['client_surname'] != ''
                ? ($client['client_name'] . ' ' . $client['client_surname'])
                : (string) $client['client_name'];
            $mono_words  = preg_split('/\s+/', trim($mono_source));
            $monogram    = '';
            foreach (array_filter($mono_words) as $w) { $monogram .= mb_substr($w, 0, 1); }
            $monogram    = mb_strtoupper(mb_substr($monogram, 0, 2));
            if ($monogram === '') { $monogram = '?'; }

            // Indirizzo in linea singola per la testata anagrafica.
            $loc_parts = array_filter([
                $client['client_address_1'],
                $client['client_address_2'],
                trim(($client['client_zip'] ?? '') . ' ' . ($client['client_city'] ?? '')),
                $client['client_state'],
                $countries[$client['client_country']] ?? $client['client_country'],
            ]);
            $client_address = implode(', ', $loc_parts);
            ?>

            <!-- MASTHEAD ANAGRAFICO: il biglietto da visita del cliente. I codici
                 fiscali (P.IVA / C.F.) sono i veri identificativi di un cliente
                 italiano, quindi stanno in primo piano in carattere mono, non
                 sepolti in una tabella. -->
            <div class="card ip-client-masthead mb-3">
                <div class="ip-client-masthead__body">
                    <div class="ip-client-mono" aria-hidden="true"><?= esc($monogram) ?></div>

                    <div class="ip-client-id">
                        <h2 class="ip-client-name"><?= esc(format_client($client)) ?></h2>
                        <div class="ip-client-id__meta">
                            <?= client_type_badge($client['client_type'] ?? null) ?>
                            <?php if ($client_address !== ''): ?>
                                <span class="ip-client-id__loc">
                                    <i class="fa fa-solid fa-location-dot" aria-hidden="true"></i> <?= esc($client_address) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($client['client_date_created']) && $client['client_date_created'] !== '0000-00-00 00:00:00'): ?>
                            <p class="ip-client-since">
                                <i class="fa fa-regular fa-clock" aria-hidden="true"></i>
                                <?= trans('client_since', '', 'Cliente dal') ?> <?= esc(datetime_from_mysql($client['client_date_created'], false)) ?>
                            </p>
                        <?php endif; ?>
                    </div>

                    <?php if ($client['client_vat_id'] || $client['client_tax_code']): ?>
                        <div class="ip-client-codes">
                            <?php if ($client['client_vat_id']): ?>
                                <div class="ip-fisc-code">
                                    <span class="ip-fisc-code__label"><?= trans('vat_id', '', 'Partita IVA') ?></span>
                                    <span class="ip-fisc-code__value"><?= esc($client['client_vat_id']) ?></span>
                                    <button type="button" class="ip-fisc-code__copy" data-copy-code="<?= esc($client['client_vat_id'], 'attr') ?>" title="<?= esc(trans('copy', '', 'Copia')) ?>" aria-label="<?= esc(trans('copy', '', 'Copia')) ?>">
                                        <i class="fa fa-solid fa-copy" aria-hidden="true"></i>
                                    </button>
                                </div>
                            <?php endif; ?>
                            <?php if ($client['client_tax_code']): ?>
                                <div class="ip-fisc-code">
                                    <span class="ip-fisc-code__label"><?= trans('tax_code', '', 'Codice fiscale') ?></span>
                                    <span class="ip-fisc-code__value"><?= esc($client['client_tax_code']) ?></span>
                                    <button type="button" class="ip-fisc-code__copy" data-copy-code="<?= esc($client['client_tax_code'], 'attr') ?>" title="<?= esc(trans('copy', '', 'Copia')) ?>" aria-label="<?= esc(trans('copy', '', 'Copia')) ?>">
                                        <i class="fa fa-solid fa-copy" aria-hidden="true"></i>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIEPILOGO ECONOMICO: le quattro cifre che contano, in piastrelle.
                 Verde incassato, arancio saldo, rosso scaduto (come le KPI). -->
            <div class="card ip-client-summary mb-3">
                <div class="card-header ip-table-card-header">
                    <div class="ip-table-card-title">
                        <span class="card-header-icon"><i class="fa fa-solid fa-line-chart" aria-hidden="true"></i></span>
                        <span class="ip-table-card-label"><?= trans('overview', '', 'Riepilogo economico') ?></span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="ip-client-stats">
                        <div class="ip-client-stat">
                            <div class="ip-client-stat-label"><i class="fa fa-solid fa-file-invoice-dollar" aria-hidden="true"></i> <?= trans('total_billed') ?></div>
                            <div class="ip-client-stat-value"><?= format_currency($client['client_invoice_total']) ?></div>
                        </div>
                        <div class="ip-client-stat">
                            <div class="ip-client-stat-label"><i class="fa fa-solid fa-check-circle" aria-hidden="true"></i> <?= trans('total_paid') ?></div>
                            <div class="ip-client-stat-value ip-client-stat-value--success"><?= format_currency($client['client_invoice_paid']) ?></div>
                        </div>
                        <div class="ip-client-stat">
                            <div class="ip-client-stat-label"><i class="fa fa-solid fa-balance-scale" aria-hidden="true"></i> <?= trans('total_balance') ?></div>
                            <div class="ip-client-stat-value ip-client-stat-value--warning"><?= format_currency($client['client_invoice_balance']) ?></div>
                        </div>
                        <div class="ip-client-stat">
                            <div class="ip-client-stat-label"><i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i> <?= trans('overdue_invoices') ?></div>
                            <div class="ip-client-stat-value<?= $client_overdue_total > 0 ? ' ip-client-stat-value--danger' : '' ?>">
                                <?= format_currency($client_overdue_total) ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($client_overdue_count > 0): ?>
                        <p class="ip-client-stat-hint">
                            <i class="fa fa-solid fa-exclamation-triangle" aria-hidden="true"></i>
                            <a href="#clientInvoices" class="ip-client-stat-hint-link"
                               onclick="event.preventDefault(); var t = document.getElementById('tab-clientInvoices'); if (t) { t.click(); t.scrollIntoView({behavior: 'smooth', block: 'start'}); }">
                                <?= (int) $client_overdue_count ?> <?= trans('invoices') ?> <?= trans('overdue_hint', '', 'scadute da incassare') ?>
                            </a>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ANDAMENTO E COMPOSIZIONE: due card affiancate, una per lettura.
                 A sinistra la "barra di incasso" mensile: ogni mese il fatturato
                 e' la traccia chiara e l'incassato la riempie, cosi' il gap
                 (quota ancora da riscuotere) si vede a colpo d'occhio. A destra
                 il donut firma la pagina col tasso di incasso complessivo.
                 Due card invece di una: le domande sono due ("come va mese per
                 mese?" / "quanto del totale e' in cassa?") e cosi' il titolo
                 di ciascuna puo' rispondere senza ambiguita'. -->
            <div class="row g-3 mb-3">
                <div class="col-12 col-lg-8">
                    <div class="card no-margin h-100">
                        <div class="card-header ip-table-card-header">
                            <div class="ip-table-card-title">
                                <span class="card-header-icon"><i class="fa fa-solid fa-chart-column" aria-hidden="true"></i></span>
                                <span class="ip-table-card-label"><?= trans('client_trend', '', 'Fatturato e incassato') ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php
                            $cMax = (float) $client_chart_max;
                            $cN   = count($client_chart_series);
                            $sumInvoiced = 0.0;
                            $sumPaid     = 0.0;
                            foreach ($client_chart_series as $row) {
                                $sumInvoiced += (float) $row['invoiced'];
                                $sumPaid     += (float) $row['paid'];
                            }
                            $hasData = $cN > 0 && ($sumInvoiced > 0 || $sumPaid > 0);
                            // Una sola colonna centrata per mese: larghezza
                            // definita qui, subito prima del disegno (sotto).
                            $colW      = $plotW / max(1, $cN);
                            $revScale  = $chartScale($cMax);
                            ?>
                            <div class="ip-chart-meta">
                                <span class="ip-chart-period"><?= esc(trans('client_last_12_months', '', 'ultimi 12 mesi')) ?></span>
                                <span class="ip-chart-legend">
                                    <span class="ip-chart-legend-item"><span class="ip-chart-legend-dot ip-chart-legend-dot--track"></span><?= trans('total_billed', '', 'Fatturato') ?></span>
                                    <span class="ip-chart-legend-item"><span class="ip-chart-legend-dot ip-chart-legend-dot--paid"></span><?= trans('total_paid', '', 'Incassato') ?></span>
                                </span>
                            </div>
                            <?php if ($hasData): ?>
                            <div class="ip-chart-summary">
                                <span class="ip-chart-summary-item ip-chart-summary-item--invoiced">
                                    <?= esc(trans('total_billed', '', 'Fatturato')) ?>
                                    <span class="ip-chart-summary-value"><?= format_currency($sumInvoiced) ?></span>
                                </span>
                                <span class="ip-chart-summary-item ip-chart-summary-item--paid">
                                    <?= esc(trans('total_paid', '', 'Incassato')) ?>
                                    <span class="ip-chart-summary-value"><?= format_currency($sumPaid) ?></span>
                                </span>
                                <span class="ip-chart-summary-item">
                                    <?= esc(trans('dashboard_collection_rate', '', 'Tasso di incasso')) ?>
                                    <span class="ip-chart-summary-value">
                                        <?= $sumInvoiced > 0 ? number_format($sumPaid / $sumInvoiced * 100, 1, ',', '.') . '%' : '—' ?>
                                    </span>
                                </span>
                            </div>
                            <?php
                            // "Barra di incasso": per ogni mese la traccia chiara
                            // e' il fatturato (massimo fra fatturato e incassato,
                            // cosi' i prepagamenti non escono dalla scala) e il
                            // riempimento verde e' l'incassato. Il segmento di
                            // traccia non coperto dal verde e' la quota ancora da
                            // riscuotere di quel mese.
                            //
                            // Geometria della colonna: 58% della larghezza del
                            // mese, centrata — il respiro fra le colonne (42%)
                            // tiene i 12 mesi leggibili come ritmo e lascia
                            // alla banda hover il compito di raggruppare.
                            $barW   = max(3.0, $colW * 0.58);
                            $barPad = ($colW - $barW) / 2;
                            ?>
                            <svg class="ip-chart" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="<?= esc(trans('client_trend', '', 'Fatturato e incassato')) ?>">
                                <?php for ($t = 0; $t <= $revScale['tickCount']; $t++):
                                    $tickVal = $revScale['axisMax'] * $t / $revScale['tickCount'];
                                    $tickY   = ($revScale['yOf'])($tickVal);
                                ?>
                                    <line class="ip-chart-grid" x1="<?= $padLeft ?>" y1="<?= round($tickY, 1) ?>" x2="<?= $W ?>" y2="<?= round($tickY, 1) ?>" />
                                    <text class="ip-chart-axis" x="<?= $padLeft - 8 ?>" y="<?= round($tickY + 3.5, 1) ?>" text-anchor="end"><?= esc($axisLabel($tickVal)) ?></text>
                                <?php endfor; ?>
                                <?php foreach ($client_chart_series as $i => $row):
                                    $inv    = (float) $row['invoiced'];
                                    $paid   = (float) $row['paid'];
                                    $track  = max($inv, $paid);
                                    $xCol   = $padLeft + $i * $colW;
                                    $xBar   = $xCol + $barPad;
                                    $yTrack = ($revScale['yOf'])($track);
                                    $yPaid  = ($revScale['yOf'])($paid);
                                    $hTrack = $padTop + $barH - $yTrack;
                                    $hPaid  = $padTop + $barH - $yPaid;
                                    $cxBar  = $xCol + $colW / 2;
                                    $label  = esc($row['label']);
                                ?>
                                <?php // Ogni mese e' un gruppo: la banda di sfondo
                                      // (.ip-chart-col) si tinge all'hover e il
                                      // gruppo evidenzia anche l'etichetta, cosi'
                                      // barre e mese si leggono come una cosa sola. ?>
                                <g class="ip-chart-month">
                                    <rect class="ip-chart-col"
                                        x="<?= round($xCol, 1) ?>" y="4"
                                        width="<?= round($colW, 1) ?>" height="<?= $H - 4 ?>" rx="6"/>
                                    <rect class="ip-chart-bar ip-chart-bar--track" style="--i:<?= $i ?>"
                                        x="<?= round($xBar, 1) ?>" y="<?= round(min($yTrack, $padTop + $barH - ($track > 0 ? 2 : 0)), 1) ?>"
                                        width="<?= round($barW, 1) ?>" height="<?= round(max($hTrack, $track > 0 ? 2 : 0), 1) ?>"
                                        rx="3"><title><?= esc(trans('total_billed', '', 'Fatturato')) ?>: <?= format_currency($inv) ?></title></rect>
                                    <?php // Il minimo di 2 unita' sposta in su il y del
                                          // riempimento, cosi' la barretta resta ancorata
                                          // alla baseline invece di galleggiare. ?>
                                    <rect class="ip-chart-bar ip-chart-bar--paid" style="--i:<?= $i ?>"
                                        x="<?= round($xBar, 1) ?>" y="<?= round(min($yPaid, $padTop + $barH - ($paid > 0 ? 2 : 0)), 1) ?>"
                                        width="<?= round($barW, 1) ?>" height="<?= round(max($hPaid, $paid > 0 ? 2 : 0), 1) ?>"
                                        rx="3"><title><?= esc(trans('total_paid', '', 'Incassato')) ?>: <?= format_currency($paid) ?></title></rect>
                                    <text class="ip-chart-label" x="<?= $cxBar ?>" y="<?= $H - 8 ?>" text-anchor="middle"><?= $label ?></text>
                                </g>
                                <?php endforeach; ?>
                                <line class="ip-chart-baseline" x1="<?= $padLeft ?>" y1="<?= $padTop + $barH ?>" x2="<?= $W ?>" y2="<?= $padTop + $barH ?>" />
                            </svg>
                            <?php else: ?>
                            <div class="ip-chart-empty">
                                <i class="fa fa-solid fa-chart-column" aria-hidden="true"></i>
                                <p><?= trans('dashboard_no_chart_data', '', 'Nessun movimento fatturazione negli ultimi 12 mesi.') ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-4">
                    <div class="card no-margin h-100">
                        <div class="card-header ip-table-card-header">
                            <div class="ip-table-card-title">
                                <span class="card-header-icon"><i class="fa fa-solid fa-pie-chart" aria-hidden="true"></i></span>
                                <span class="ip-table-card-label"><?= trans('client_balance_breakdown', '', 'Composizione del fatturato') ?></span>
                            </div>
                        </div>
                        <div class="card-body d-flex align-items-center justify-content-center">
                            <?php
                            // Il donut spezza il fatturato in incassato / saldo
                            // aperto / scaduto. Il saldo aperto è la differenza
                            // fra il saldo totale e quello scaduto, così la somma
                            // dei tre archi torna esattamente al fatturato.
                            // $donut_slices alimenta sia gli archi (clientDonut)
                            // sia la legenda qui sotto: un solo punto in cui
                            // valori, colori ed etichette sono calcolati.
                            $donut_slices = [
                                [
                                    'k'     => 'paid',
                                    'c'     => 'var(--ip-success)',
                                    'v'     => (float) $client['client_invoice_paid'],
                                    'label' => trans('total_paid', '', 'Incassato'),
                                ],
                                [
                                    'k'     => 'open',
                                    'c'     => 'var(--ip-warning)',
                                    'v'     => max(0.0, (float) $client['client_invoice_balance'] - (float) $client_overdue_total),
                                    'label' => trans('total_balance', '', 'Saldo'),
                                ],
                                [
                                    'k'     => 'overdue',
                                    'c'     => 'var(--ip-danger)',
                                    'v'     => (float) $client_overdue_total,
                                    'label' => trans('overdue_invoices', '', 'Scaduto'),
                                ],
                            ];
                            $donut_total = array_sum(array_column($donut_slices, 'v'));
                            $donutSvg    = $clientDonut($donut_slices, $donut_total);
                            ?>
                            <?php if ($donutSvg !== ''): ?>
                                <div class="ip-donut-wrap">
                                    <?= $donutSvg ?>
                                    <ul class="ip-donut-legend">
                                        <?php foreach ($donut_slices as $slice):
                                            // Una voce a €0,00 è solo rumore: se non
                                            // c'è scaduto la riga sparisce, l'arco pure.
                                            if ($slice['v'] <= 0) { continue; }
                                            $share = (int) round($slice['v'] / $donut_total * 100);
                                        ?>
                                        <li>
                                            <span class="ip-donut-dot ip-donut-dot--<?= $slice['k'] ?>"></span>
                                            <span class="ip-donut-legend-label"><?= esc($slice['label']) ?></span>
                                            <b><?= format_currency($slice['v']) ?></b>
                                            <span class="ip-donut-share"><?= $share ?>%</span>
                                        </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php else: ?>
                                <div class="ip-chart-empty">
                                    <i class="fa fa-solid fa-pie-chart" aria-hidden="true"></i>
                                    <p><?= trans('no_data_available', '', 'Nessun dato') ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-12 col-md-6">

                    <div class="card no-margin h-100">
                        <div class="card-header ip-table-card-header">
                            <div class="ip-table-card-title">
                                <span class="card-header-icon"><i class="fa fa-regular fa-address-book" aria-hidden="true"></i></span>
                                <span class="ip-table-card-label"><?= trans('contact_information') ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (array_filter([$client['client_email'], $client['client_phone'], $client['client_mobile'], $client['client_fax'], $client['client_web']])): ?>
                                <ul class="ip-contact-list">
                                    <?php if ($client['client_email']): ?>
                                        <li>
                                            <span class="ip-contact-list__ic"><i class="fa fa-regular fa-envelope" aria-hidden="true"></i></span>
                                            <a href="mailto:<?= esc($client['client_email']) ?>"><?= esc($client['client_email']) ?></a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($client['client_phone']): ?>
                                        <li>
                                            <span class="ip-contact-list__ic"><i class="fa fa-solid fa-phone" aria-hidden="true"></i></span>
                                            <a href="tel:<?= esc($client['client_phone']) ?>"><?= esc($client['client_phone']) ?></a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($client['client_mobile']): ?>
                                        <li>
                                            <span class="ip-contact-list__ic"><i class="fa fa-solid fa-mobile-screen-button" aria-hidden="true"></i></span>
                                            <a href="tel:<?= esc($client['client_mobile']) ?>"><?= esc($client['client_mobile']) ?></a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($client['client_fax']): ?>
                                        <li>
                                            <span class="ip-contact-list__ic"><i class="fa fa-solid fa-fax" aria-hidden="true"></i></span>
                                            <span><?= esc($client['client_fax']) ?></span>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($client['client_web']): ?>
                                        <?php $webUrl = preg_match('#^https?://#i', (string) $client['client_web']) ? $client['client_web'] : 'https://' . $client['client_web']; ?>
                                        <li>
                                            <span class="ip-contact-list__ic"><i class="fa fa-solid fa-earth" aria-hidden="true"></i></span>
                                            <a href="<?= esc($webUrl) ?>" target="_blank" rel="noopener"><?= esc($client['client_web']) ?></a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            <?php else: ?>
                                <p class="ip-card-empty"><?= trans('no_data_available', '', 'Nessun dato') ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
                <div class="col-12 col-md-6">

                    <div class="card no-margin h-100">
                        <div class="card-header ip-table-card-header">
                            <div class="ip-table-card-title">
                                <span class="card-header-icon"><i class="fa fa-regular fa-sticky-note" aria-hidden="true"></i></span>
                                <span class="ip-table-card-label"><?= trans('notes') ?></span>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="notes_list">
                                <?php foreach ($client_notes as $note): ?>
                                    <div class="card small">
                                        <div class="card-body">
                                            <?= nl2br(esc($note['client_note'])) ?>
                                        </div>
                                        <div class="card-footer text-muted">
                                            <?= esc(date_from_mysql($note['client_note_date'])) ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($client_notes)): ?>
                                    <p class="text-muted"><?= trans('no_notes') ?></p>
                                <?php endif; ?>
                            </div>
                            <form method="POST" action="<?= site_url('clients/ajax/save_client_note') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="client_id" id="client_id"
                                       value="<?= $client['client_id'] ?>">
                                <label for="client_note" class="visually-hidden"><?= trans('add_note') ?></label>
                                <div class="input-group">
                                    <textarea id="client_note" name="client_note" class="form-control" rows="2"
                                              style="resize:none"></textarea>
                                    <button type="submit" class="btn btn-secondary">
                                        <?= trans('add_note') ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                </div>
            </div>

            <?php
            // Dati personali (solo persone fisiche) e campi personalizzati
            // affiancati: entrambi opzionali, restano in colonna da 6 se presenti.
            $has_personal = $client['client_surname'] != '';
            $has_custom   = !empty($custom_fields);
            ?>
            <?php if ($has_personal || $has_custom): ?>
                <div class="row g-3 mb-3">
                    <?php if ($has_personal): ?>
                        <div class="col-12 col-md-6">
                            <div class="card no-margin h-100">
                                <div class="card-header ip-table-card-header">
                                    <div class="ip-table-card-title">
                                        <span class="card-header-icon"><i class="fa fa-regular fa-user" aria-hidden="true"></i></span>
                                        <span class="ip-table-card-label"><?= trans('personal_information') ?></span>
                                    </div>
                                </div>
                                <div class="card-body table-content">
                                    <table class="table no-margin">
                                        <tr>
                                            <th scope="row"><?= trans('birthdate') ?></th>
                                            <td><?= esc(date_from_mysql($client['client_birthdate'])) ?></td>
                                        </tr>
                                        <tr>
                                            <th scope="row"><?= trans('gender') ?></th>
                                            <td>
                                                <?php
                                                $genders = [
                                                    0 => trans('gender_male'),
                                                    1 => trans('gender_female'),
                                                    2 => trans('gender_other'),
                                                ];
                                                echo esc($genders[(int) $client['client_gender']] ?? '');
                                                ?>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($has_custom): ?>
                        <div class="col-12 col-md-6">
                            <div class="card no-margin h-100">
                                <div class="card-header ip-table-card-header">
                                    <div class="ip-table-card-title">
                                        <span class="card-header-icon"><i class="fa fa-regular fa-list-alt" aria-hidden="true"></i></span>
                                        <span class="ip-table-card-label"><?= trans('custom_fields') ?></span>
                                    </div>
                                </div>
                                <div class="card-body table-content">
                                    <table class="table no-margin">
                                        <?php foreach ($custom_fields as $field): ?>
                                            <tr>
                                                <th scope="row"><?= esc($field['custom_field_label']) ?></th>
                                                <td><?= esc($field['client_custom_fieldvalue']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>

        <div id="clientQuotes" class="tab-pane table-content" role="tabpanel" aria-labelledby="tab-clientQuotes" tabindex="0">
            <div class="card ip-table-card">
                <div class="card-header ip-table-card-header">
                    <div class="ip-table-card-title">
                        <span class="card-header-icon"><i class="fa fa-regular fa-file" aria-hidden="true"></i></span>
                        <span class="ip-table-card-label"><?= esc(trans('quotes')) ?></span>
                        <span class="badge text-bg-secondary"><?= (int) $quotes_count ?></span>
                    </div>
                </div>
            <div class="table-responsive">
                <table class="table table-hover ip-table-card-collapse">
                    <thead>
                    <tr>
                        <th scope="col"><?= trans('quote_number', '', 'Numero') ?></th>
                        <th scope="col"><?= trans('date', '', 'Data') ?></th>
                        <th scope="col" class="amount"><?= trans('total', '', 'Totale') ?></th>
                        <th scope="col"><?= trans('status', '', 'Stato') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($quotes as $quote): ?>
                        <?php $status = $quote_statuses[(string) $quote['quote_status_id']] ?? ['label' => '', 'class' => 'draft']; ?>
                        <tr>
                            <td data-label="<?= esc(trans('quote_number', '', 'Numero')) ?>">
                                <a href="<?= site_url('quotes/view/' . $quote['quote_id']) ?>">
                                    <?= esc($quote['quote_number']) ?>
                                </a>
                            </td>
                            <td data-label="<?= esc(trans('date', '', 'Data')) ?>"><?= esc(date_from_mysql($quote['quote_date_created'])) ?></td>
                            <td class="amount" data-label="<?= esc(trans('total', '', 'Totale')) ?>"><?= format_currency($quote['quote_total'] ?? 0) ?></td>
                            <td data-label="<?= esc(trans('status', '', 'Stato')) ?>"><span class="badge ip-status <?= esc($status_label_class[$status['class']] ?? 'text-bg-secondary') ?>"><?= esc($status['label']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($quotes)): ?>
                        <tr class="empty-row">
                            <td colspan="4">
                                <i class="fa fa-regular fa-file empty-icon" aria-hidden="true"></i>
                                <?= trans('no_quotes') ?>
                                <a href="<?= site_url('quotes/form_create') ?>?client_id=<?= (int) $client['client_id'] ?>" class="empty-cta">
                                    <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('create_quote', '', 'Nuovo preventivo') ?>
                                </a>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </div>

        <div id="clientInvoices" class="tab-pane table-content" role="tabpanel" aria-labelledby="tab-clientInvoices" tabindex="0">
            <div class="card ip-table-card">
                <div class="card-header ip-table-card-header">
                    <div class="ip-table-card-title">
                        <span class="card-header-icon"><i class="fa fa-regular fa-file-text" aria-hidden="true"></i></span>
                        <span class="ip-table-card-label"><?= esc(trans('invoices')) ?></span>
                        <span class="badge text-bg-secondary"><?= (int) $invoices_count ?></span>
                    </div>
                </div>
            <div class="table-responsive">
                <table class="table table-hover ip-table-card-collapse">
                    <thead>
                    <tr>
                        <th scope="col"><?= trans('invoice_number', '', 'Numero') ?></th>
                        <th scope="col"><?= trans('date', '', 'Data') ?></th>
                        <th scope="col"><?= trans('due_date', '', 'Scadenza') ?></th>
                        <th scope="col" class="amount"><?= trans('total', '', 'Totale') ?></th>
                        <th scope="col" class="amount"><?= trans('balance', '', 'Saldo') ?></th>
                        <th scope="col"><?= trans('status', '', 'Stato') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invoices as $invoice): ?>
                        <?php $status = $invoice_statuses[(string) $invoice['invoice_status_id']] ?? ['label' => '', 'class' => 'draft']; ?>
                        <tr class="<?= !empty($invoice['is_overdue']) ? 'ip-row-overdue' : '' ?>">
                            <td data-label="<?= esc(trans('invoice_number', '', 'Numero')) ?>">
                                <a href="<?= site_url('invoices/view/' . $invoice['invoice_id']) ?>">
                                    <?= esc($invoice['invoice_number']) ?>
                                </a>
                            </td>
                            <td data-label="<?= esc(trans('date', '', 'Data')) ?>"><?= esc(date_from_mysql($invoice['invoice_date_created'])) ?></td>
                            <td data-label="<?= esc(trans('due_date', '', 'Scadenza')) ?>" class="<?= !empty($invoice['is_overdue']) ? 'text-danger' : '' ?>">
                                <?= esc(date_from_mysql($invoice['invoice_date_due'])) ?>
                            </td>
                            <td class="amount" data-label="<?= esc(trans('total', '', 'Totale')) ?>"><?= format_currency($invoice['invoice_total'] ?? 0) ?></td>
                            <td class="amount" data-label="<?= esc(trans('balance', '', 'Saldo')) ?>"><?= format_currency($invoice['invoice_balance'] ?? 0) ?></td>
                            <td data-label="<?= esc(trans('status', '', 'Stato')) ?>"><span class="badge ip-status <?= esc($status_label_class[$status['class']] ?? 'text-bg-secondary') ?>"><?= esc($status['label']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($invoices)): ?>
                        <tr class="empty-row">
                            <td colspan="6">
                                <i class="fa fa-regular fa-file-text empty-icon" aria-hidden="true"></i>
                                <?= trans('no_invoices') ?>
                                <a href="<?= site_url('invoices/form_create') ?>?client_id=<?= (int) $client['client_id'] ?>" class="empty-cta">
                                    <i class="fa fa-solid fa-plus" aria-hidden="true"></i> <?= trans('create_invoice', '', 'Nuova fattura') ?>
                                </a>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </div>

        <div id="clientPayments" class="tab-pane table-content" role="tabpanel" aria-labelledby="tab-clientPayments" tabindex="0">
            <div class="card ip-table-card">
                <div class="card-header ip-table-card-header">
                    <div class="ip-table-card-title">
                        <span class="card-header-icon"><i class="fa fa-solid fa-money-bill-1" aria-hidden="true"></i></span>
                        <span class="ip-table-card-label"><?= esc(trans('payments')) ?></span>
                        <span class="badge text-bg-secondary"><?= (int) $payments_count ?></span>
                    </div>
                </div>
            <div class="table-responsive">
                <table class="table table-hover ip-table-card-collapse">
                    <thead>
                    <tr>
                        <th scope="col"><?= trans('date', '', 'Data') ?></th>
                        <th scope="col"><?= trans('invoice_number', '', 'Fattura') ?></th>
                        <th scope="col" class="amount"><?= trans('amount', '', 'Importo') ?></th>
                        <th scope="col"><?= trans('payment_method') ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td data-label="<?= esc(trans('date', '', 'Data')) ?>"><?= esc(date_from_mysql($payment['payment_date'])) ?></td>
                            <td data-label="<?= esc(trans('invoice_number', '', 'Fattura')) ?>">
                                <a href="<?= site_url('invoices/view/' . $payment['invoice_id']) ?>">
                                    <?= esc($payment['invoice_number']) ?>
                                </a>
                            </td>
                            <td class="amount" data-label="<?= esc(trans('amount', '', 'Importo')) ?>"><?= format_currency($payment['payment_amount']) ?></td>
                            <td data-label="<?= esc(trans('payment_method')) ?>"><?= esc($payment['payment_method_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($payments)): ?>
                        <tr class="empty-row">
                            <td colspan="4">
                                <i class="fa fa-solid fa-money-bill-1 empty-icon" aria-hidden="true"></i>
                                <?= trans('no_payments') ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </div>
    </div>

</div>

<script>
/* Copia negli appunti i codici fiscali dalla tessera anagrafica: utile per
   incollarli in FatturaPA o nel cassetto fiscale senza ricopiare a mano. */
(function () {
    document.querySelectorAll('.ip-fisc-code__copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var value = btn.getAttribute('data-copy-code');
            if (!value || !navigator.clipboard) { return; }
            navigator.clipboard.writeText(value).then(function () {
                var icon = btn.querySelector('i');
                if (!icon) { return; }
                var prev = icon.className;
                icon.className = 'fa fa-check';
                btn.classList.add('is-copied');
                setTimeout(function () {
                    icon.className = prev;
                    btn.classList.remove('is-copied');
                }, 1200);
            });
        });
    });
})();
</script>

<?= $this->endSection() ?>
