<?php

namespace App\Services;

/**
 * FinanceService — logica di calcolo per la pagina "Utile e imposte".
 *
 * Separata dal controller per tre ragioni:
 * 1. Il controller resta sottile (solo orchestrazione e passaggio alla view)
 * 2. I calcoli fiscali sono testabili in isolamento senza HTTP
 * 3. La stessa logica può servire ad API future o export
 *
 * Le aliquote non stanno più qui: arrivano già pronte da chi chiama, che le
 * legge dalla tabella dei parametri fiscali (FiscalYearModel). Prima erano
 * costanti di classe, e siccome la pagina ha un filtro anno il 2024 e il 2026
 * producevano la stessa identica stima.
 *
 * La classe non tocca né database né impostazioni: riceve numeri e restituisce
 * numeri. È quello che la rende verificabile in isolamento (tests/unit) e
 * riusabile da export e comandi CLI.
 *
 * IMPORTANTE: quella prodotta qui è una **stima gestionale**, utile per
 * accantonare durante l'anno, non un calcolo fiscale. Il calcolo definitivo
 * resta del commercialista.
 */
class FinanceService
{
    /**
     * Forme aziendali con il modo in cui si stima il carico fiscale.
     * 'flat'        = imposta sostitutiva su una quota forfettaria dei ricavi
     * 'progressive' = scaglioni IRPEF sull'utile
     * 'corporate'   = IRES + IRAP sull'utile
     */
    public static function companyForms(): array
    {
        return [
            'forfettario' => [
                'label'  => 'Ditta individuale — regime forfettario',
                'mode'   => 'flat',
                'note'   => 'Imponibile calcolato sui ricavi con coefficiente di redditività; i costi effettivi non sono deducibili.',
            ],
            'semplificato' => [
                'label' => 'Ditta individuale / professionista — regime ordinario',
                'mode'  => 'progressive',
                'note'  => 'IRPEF a scaglioni sull\'utile (ricavi meno costi deducibili), più addizionali e contributi previdenziali.',
            ],
            'societa_capitali' => [
                'label' => 'Società di capitali (SRL, SPA)',
                'mode'  => 'corporate',
                'note'  => 'IRES sull\'utile più IRAP sul valore della produzione.',
            ],
        ];
    }

    /**
     * Stima del carico fiscale secondo la forma aziendale e l'anno.
     *
     * @param array<string, mixed> $fiscal parametri dell'anno (FiscalYearModel::paramsForYear)
     * @param array<string, mixed> $company assetto: aliquote e schema contributivo
     *
     * @return array{taxable: float, lines: list<array{label: string, amount: float, children?: list<array{label: string, amount: float}>}>, total: float, net: float, warnings: list<string>}
     */
    public function estimateTaxes(
        string $mode,
        float $revenue,
        float $profit,
        array $fiscal,
        array $company = []
    ): array {
        $lines    = [];
        $warnings = [];

        $coefficient  = (float) ($company['coefficient'] ?? 78);
        $isStartup    = (bool) ($company['forfettario_startup'] ?? false);
        // 'separata' e' il default perche' e' il comportamento che l'app aveva
        // prima di distinguere i due schemi: aggiornando, chi non tocca la
        // scheda azienda ritrova la stessa stima di sempre.
        $inpsScheme   = (string) ($company['inps_scheme'] ?? 'separata');
        $customRate   = isset($company['contributions']) ? (float) $company['contributions'] : null;

        // L'imponibile cambia col regime: nel forfettario è una quota dei
        // ricavi, negli altri l'utile. Va calcolato prima delle voci
        // aggiuntive, che ci si calcolano sopra.
        $taxable = $mode === 'flat'
            ? $revenue * ($coefficient / 100)
            : max(0.0, $profit);

        // Voci contributive aggiuntive della scheda azienda (INAIL, fondi,
        // quote associative...): percentuale su una base scelta dall'azienda.
        // Se deducibili riducono la base su cui si calcolano imposta
        // sostitutiva e IRPEF, come i contributi previdenziali. Nelle società
        // di capitali la voce compare nella stima ma non deduce: IRES e IRAP
        // si calcolano sull'utile, che resta tale.
        $extras          = $this->normalizeExtras($company['extras'] ?? []);
        $extraLines      = [];
        $deductibleTotal = 0.0;

        foreach ($extras as $extra) {
            $amount = $this->extraAmount($extra, $taxable, $profit, $revenue);

            if (!empty($extra['deductible'])) {
                $deductibleTotal += $amount;
            }

            $extraLines[] = [
                'label'  => $extra['name'] . ' (' . $this->pct($extra['rate']) . '%)',
                'amount' => $amount,
            ];
        }

        if ($mode === 'flat') {
            // Forfettario: imponibile = ricavi × coefficiente di redditività.
            $contributions = $this->contributions($taxable, $fiscal, $inpsScheme, $customRate);

            // I contributi versati si deducono dall'imponibile dell'imposta
            // sostitutiva: senza questo passaggio la stima è più alta del vero.
            $rate       = $isStartup
                ? (float) $fiscal['forfettario']['aliquota_startup']
                : (float) $fiscal['forfettario']['aliquota_ordinaria'];
            $substitute = max(0.0, $taxable - $contributions['total'] - $deductibleTotal) * ($rate / 100);

            $lines[] = $contributions['line'];
            array_push($lines, ...$extraLines);
            $lines[] = [
                'label'  => 'Imposta sostitutiva (' . $this->pct($rate) . '%)',
                'amount' => $substitute,
            ];

            // Sopra il tetto il regime decade: la stima resterebbe forfettaria
            // mentre la tassazione reale sarebbe ordinaria, quindi si avvisa
            // invece di mostrare un numero che non si applica piu'.
            $limit = (float) $fiscal['forfettario']['limite_ricavi'];
            if ($revenue > $limit) {
                $warnings[] = 'I ricavi superano il limite di ' . $this->money($limit)
                    . ' previsto per il regime forfettario: da qui la stima non è più attendibile.';
            }
        } elseif ($mode === 'corporate') {
            $ires = (float) $fiscal['ires']['aliquota'];
            $irap = (float) $fiscal['irap']['aliquota'];

            $lines[] = ['label' => 'IRES (' . $this->pct($ires) . '%)', 'amount' => $taxable * ($ires / 100)];
            $lines[] = ['label' => 'IRAP (' . $this->pct($irap) . '%)', 'amount' => $taxable * ($irap / 100)];
            array_push($lines, ...$extraLines);
        } else {
            $contributions = $this->contributions($taxable, $fiscal, $inpsScheme, $customRate);

            // I contributi sono deducibili dal reddito imponibile IRPEF.
            $irpefBase = max(0.0, $taxable - $contributions['total'] - $deductibleTotal);

            // Le addizionali dell'anno sono medie nazionali; se l'azienda
            // conosce le proprie (variano per regione e comune) valgono quelle.
            $regionale = isset($company['add_regionale'])
                ? (float) $company['add_regionale']
                : (float) $fiscal['irpef']['addizionale_regionale'];
            $comunale  = isset($company['add_comunale'])
                ? (float) $company['add_comunale']
                : (float) $fiscal['irpef']['addizionale_comunale'];

            $lines[] = $contributions['line'];
            array_push($lines, ...$extraLines);
            $lines[] = [
                'label'  => 'IRPEF (scaglioni)',
                'amount' => $this->irpef($irpefBase, $fiscal['irpef']['scaglioni']),
            ];
            // Prima erano omesse del tutto: su un imponibile medio valgono
            // qualche punto percentuale, cioe' abbastanza da falsare
            // l'accantonamento.
            $lines[] = [
                'label'  => 'Addizionali regionale e comunale ('
                    . $this->pct($regionale + $comunale) . '%)',
                'amount' => $irpefBase * (($regionale + $comunale) / 100),
                'children' => [
                    ['label' => 'Regionale (' . $this->pct($regionale) . '%)', 'amount' => $irpefBase * ($regionale / 100)],
                    ['label' => 'Comunale (' . $this->pct($comunale) . '%)', 'amount' => $irpefBase * ($comunale / 100)],
                ],
            ];
        }

        $total = array_sum(array_column($lines, 'amount'));

        return [
            'taxable'  => $taxable,
            'lines'    => $lines,
            'total'    => $total,
            'net'      => $profit - $total,
            'warnings' => $warnings,
        ];
    }

    /**
     * Voci contributive aggiuntive normalizzate per il calcolo.
     *
     * I dati arrivano dalla scheda azienda (JSON) o dal chiamante: qui si
     * scartano le righe senza nome o con aliquota non positiva e si riconduce
     * la base al dominio ammesso, così il calcolo non dipende dalla forma in
     * cui i dati sono arrivati.
     *
     * @param mixed $extras
     *
     * @return list<array{name: string, rate: float, base: string, deductible: bool}>
     */
    private function normalizeExtras($extras): array
    {
        if (!is_array($extras)) {
            return [];
        }

        $normalized = [];

        foreach ($extras as $extra) {
            if (!is_array($extra)) {
                continue;
            }

            $name = trim((string) ($extra['name'] ?? ''));
            $rate = (float) ($extra['rate'] ?? 0);

            if ($name === '' || $rate <= 0) {
                continue;
            }

            $base = (string) ($extra['base'] ?? 'taxable');

            if (!in_array($base, ['taxable', 'profit', 'revenue'], true)) {
                $base = 'taxable';
            }

            $normalized[] = [
                'name'       => $name,
                'rate'       => $rate,
                'base'       => $base,
                'deductible' => !empty($extra['deductible']),
            ];
        }

        return $normalized;
    }

    /**
     * Importo di una voce aggiuntiva sulla base che l'azienda ha scelto.
     *
     * La base è espressa nel dominio della scheda: 'taxable' (imponibile),
     * 'profit' (utile lordo), 'revenue' (ricavi). Su una base negativa la voce
     * non produce importo, come le altre imposte della stima.
     */
    private function extraAmount(array $extra, float $taxable, float $profit, float $revenue): float
    {
        $base = match ($extra['base'] ?? 'taxable') {
            'revenue' => $revenue,
            'profit'  => $profit,
            default   => $taxable,
        };

        return max(0.0, $base) * ((float) $extra['rate'] / 100);
    }

    /**
     * Contributi previdenziali secondo lo schema dichiarato dall'azienda.
     *
     * Gestione separata: percentuale piena sul reddito, nessuna soglia.
     * Artigiani e commercianti: contributo fisso dovuto comunque fino al
     * minimale, poi percentuale sulla sola eccedenza e fino al massimale. La
     * percentuale piatta applicata prima sbagliava da entrambi i lati — troppo
     * poco a reddito basso (dove il fisso si paga lo stesso) e troppo a reddito
     * alto (dove oltre il massimale non si versa piu').
     *
     * @return array{total: float, line: array{label: string, amount: float, children?: list<array{label: string, amount: float}>}}
     */
    private function contributions(float $income, array $fiscal, string $scheme, ?float $customRate): array
    {
        if ($scheme === 'artigiani') {
            $p         = $fiscal['inps_artigiani'];
            $minimale  = (float) $p['minimale'];
            $massimale = (float) $p['massimale'];
            $rate      = $customRate ?? (float) $p['aliquota'];
            $fisso     = (float) $p['contributo_fisso'];

            $eccedenza  = max(0.0, min($income, $massimale) - $minimale);
            $variabile  = $eccedenza * ($rate / 100);

            return [
                'total' => $fisso + $variabile,
                'line'  => [
                    'label'    => 'Contributi INPS artigiani/commercianti',
                    'amount'   => $fisso + $variabile,
                    'children' => [
                        ['label' => 'Contributo fisso (fino a ' . $this->money($minimale) . ')', 'amount' => $fisso],
                        ['label' => 'Quota sull\'eccedenza (' . $this->pct($rate) . '%)', 'amount' => $variabile],
                    ],
                ],
            ];
        }

        $rate  = $customRate ?? (float) $fiscal['inps_gestione_separata']['aliquota'];
        $total = $income * ($rate / 100);

        return [
            'total' => $total,
            'line'  => [
                'label'  => 'Contributi previdenziali (' . $this->pct($rate) . '%)',
                'amount' => $total,
            ],
        ];
    }

    /**
     * IRPEF a scaglioni: ogni aliquota si applica solo alla quota eccedente.
     *
     * @param list<array{0: int|null, 1: float}> $scaglioni
     */
    private function irpef(float $amount, array $scaglioni): float
    {
        if (!$scaglioni) {
            return 0.0;
        }

        $tax       = 0.0;
        $remaining = $amount;
        $previous  = 0.0;

        // Aliquota dell'ultimo scaglione: serve sotto, per il reddito che
        // eccede l'ultima soglia.
        $lastRate = (float) (end($scaglioni)[1] ?? 0);

        foreach ($scaglioni as [$threshold, $rate]) {
            if ($remaining <= 0) {
                break;
            }

            // null = ultimo scaglione, senza tetto superiore.
            $span  = $threshold === null ? $remaining : (float) $threshold - $previous;
            $slice = min($remaining, $span);
            $tax  += $slice * ($rate / 100);

            $remaining -= $slice;
            $previous   = (float) ($threshold ?? 0);
        }

        // Una scala progressiva non ha tetto: l'ultimo scaglione si applica a
        // tutto cio' che sta sopra la penultima soglia. Se i dati arrivano con
        // un tetto anche sull'ultimo — la scheda dei parametri fiscali lascia
        // compilare quel campo — senza questa riga il reddito eccedente non
        // verrebbe tassato affatto, e l'IRPEF si fermerebbe a un importo fisso
        // qualunque sia il reddito. Un errore che non si nota: nessuna
        // eccezione, solo una stima troppo bassa.
        if ($remaining > 0) {
            $tax += $remaining * ($lastRate / 100);
        }

        return $tax;
    }

    /**
     * Percentuale dentro un'etichetta: "24", "3,9", "26,23".
     *
     * Non usa format_amount() di proposito. Quello legge il formato numerico
     * dalle impostazioni, cioe' dal database: la stima e' matematica pura e
     * deve poter girare senza, altrimenti non e' verificabile in isolamento e
     * non e' riusabile fuori dal web (export, CLI, code schedulate).
     */
    private function pct(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',');
    }

    /** Come pct(), per le soglie di legge citate nelle etichette. */
    private function money(float $value): string
    {
        return '€' . number_format($value, 2, ',', '.');
    }

    /**
     * Genera i nomi dei mesi nella locale corrente.
     *
     * Prima la view istanziava un IntlDateFormatter dentro un loop di 12
     * iterazioni. Qui se ne crea uno solo e si riutilizza.
     *
     * @return array<int, string> Mesi indicizzati 1-12
     */
    public static function monthNames(string $locale = 'it'): array
    {
        static $cache = [];

        if (isset($cache[$locale])) {
            return $cache[$locale];
        }

        $formatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            null,
            null,
            'LLLL'
        );

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ucfirst($formatter->format(mktime(0, 0, 0, $m, 1, 2000)));
        }

        $cache[$locale] = $months;

        return $months;
    }
}
