<?php

use App\Services\FinanceService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Copertura della stima fiscale.
 *
 * È la parte dell'applicazione che sbaglia in silenzio: un errore qui non
 * genera un'eccezione, produce un numero plausibile e sbagliato su cui
 * qualcuno accantona i soldi delle tasse. I casi limite (minimale, massimale,
 * confini fra scaglioni) sono quelli che una modifica distratta rompe per
 * primi, quindi stanno tutti qui.
 *
 * @internal
 */
final class FinanceServiceTest extends CIUnitTestCase
{
    private FinanceService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new FinanceService();
    }

    /**
     * Parametri 2025, con la possibilità di sovrascriverne pezzi nel test.
     */
    private function params(array $override = []): array
    {
        $params = array_replace_recursive([
            'irpef' => [
                'scaglioni'             => [[28000, 23.0], [50000, 35.0], [null, 43.0]],
                'addizionale_regionale' => 1.73,
                'addizionale_comunale'  => 0.50,
            ],
            'inps_artigiani' => [
                'minimale'         => 18955.0,
                'massimale'        => 94750.0,
                'aliquota'         => 24.48,
                'contributo_fisso' => 4640.0,
            ],
            'inps_gestione_separata' => ['aliquota' => 26.23],
            'ires' => ['aliquota' => 24.0],
            'irap' => ['aliquota' => 3.9],
            'forfettario' => [
                'aliquota_ordinaria' => 15.0,
                'aliquota_startup'   => 5.0,
                'limite_ricavi'      => 85000.0,
            ],
        ], $override);

        // Gli scaglioni sono una lista: array_replace_recursive li fonderebbe
        // posizione per posizione, lasciando in coda gli elementi della base
        // piu' lunga — passandone due se ne otterrebbero comunque tre, e il
        // test verificherebbe uno scenario diverso da quello scritto.
        if (array_key_exists('irpef', $override) && array_key_exists('scaglioni', $override['irpef'])) {
            $params['irpef']['scaglioni'] = $override['irpef']['scaglioni'];
        }

        return $params;
    }

    /** Somma degli importi delle righe la cui etichetta contiene $needle. */
    private function lineAmount(array $result, string $needle): float
    {
        foreach ($result['lines'] as $line) {
            if (stripos($line['label'], $needle) !== false) {
                return (float) $line['amount'];
            }
        }

        $this->fail('Nessuna riga con etichetta contenente "' . $needle . '"');
    }

    // -- società di capitali -------------------------------------------------

    public function testCorporateAppliesIresAndIrapToProfit(): void
    {
        $r = $this->svc->estimateTaxes('corporate', 4800, 3350, $this->params());

        $this->assertEqualsWithDelta(804.00, $this->lineAmount($r, 'IRES'), 0.01);
        $this->assertEqualsWithDelta(130.65, $this->lineAmount($r, 'IRAP'), 0.01);
        $this->assertEqualsWithDelta(934.65, $r['total'], 0.01);
        $this->assertEqualsWithDelta(2415.35, $r['net'], 0.01);
    }

    public function testCorporateWithLossHasNoTaxButKeepsNegativeNet(): void
    {
        $r = $this->svc->estimateTaxes('corporate', 10000, -5000, $this->params());

        $this->assertSame(0.0, $r['taxable'], 'In perdita non c\'è imponibile');
        $this->assertEqualsWithDelta(0.0, $r['total'], 0.01);
        // La perdita resta tale: il netto non va "corretto" a zero.
        $this->assertEqualsWithDelta(-5000.0, $r['net'], 0.01);
    }

    // -- scaglioni IRPEF -----------------------------------------------------

    public function testIrpefAtFirstBracketCeiling(): void
    {
        // Contributi azzerati per isolare l'IRPEF: 28.000 × 23%.
        $r = $this->svc->estimateTaxes('progressive', 28000, 28000, $this->params(), [
            'inps_scheme' => 'separata', 'contributions' => 0.0,
        ]);

        $this->assertEqualsWithDelta(6440.00, $this->lineAmount($r, 'IRPEF'), 0.01);
    }

    public function testIrpefSpansSecondBracket(): void
    {
        // 28.000×23% + 22.000×35% = 6.440 + 7.700
        $r = $this->svc->estimateTaxes('progressive', 50000, 50000, $this->params(), [
            'inps_scheme' => 'separata', 'contributions' => 0.0,
        ]);

        $this->assertEqualsWithDelta(14140.00, $this->lineAmount($r, 'IRPEF'), 0.01);
    }

    public function testIrpefTopBracketHasNoCeiling(): void
    {
        // 6.440 + 7.700 + 50.000×43%
        $r = $this->svc->estimateTaxes('progressive', 100000, 100000, $this->params(), [
            'inps_scheme' => 'separata', 'contributions' => 0.0,
        ]);

        $this->assertEqualsWithDelta(35640.00, $this->lineAmount($r, 'IRPEF'), 0.01);
    }

    /**
     * Il numero di scaglioni è dato, non cablato: se la legge ne aggiunge uno
     * deve bastare una riga in più nella tabella.
     */
    public function testIrpefSupportsADifferentNumberOfBrackets(): void
    {
        $r = $this->svc->estimateTaxes('progressive', 60000, 60000, $this->params([
            'irpef' => ['scaglioni' => [[15000, 20.0], [28000, 25.0], [50000, 35.0], [null, 45.0]]],
        ]), ['inps_scheme' => 'separata', 'contributions' => 0.0]);

        // 15.000×20% + 13.000×25% + 22.000×35% + 10.000×45%
        $expected = 3000.0 + 3250.0 + 7700.0 + 4500.0;
        $this->assertEqualsWithDelta($expected, $this->lineAmount($r, 'IRPEF'), 0.01);
    }

    /**
     * Una scala progressiva non ha tetto. Se i dati arrivano con un limite
     * anche sull'ultimo scaglione — la scheda dei parametri fiscali lascia
     * compilare quel campo — il reddito eccedente non deve sfuggire
     * all'imposta: prima l'IRPEF si fermava a un importo fisso, uguale per
     * 50.000 e per 500.000 di reddito.
     */
    public function testIncomeAboveTheLastThresholdIsStillTaxed(): void
    {
        $malformed = $this->params(['irpef' => ['scaglioni' => [[28000, 23.0], [50000, 35.0]]]]);

        $at50k  = $this->svc->estimateTaxes('progressive', 50000, 50000, $malformed, ['inps_scheme' => 'separata', 'contributions' => 0.0]);
        $at100k = $this->svc->estimateTaxes('progressive', 100000, 100000, $malformed, ['inps_scheme' => 'separata', 'contributions' => 0.0]);

        $this->assertGreaterThan(
            $this->lineAmount($at50k, 'IRPEF'),
            $this->lineAmount($at100k, 'IRPEF'),
            'Raddoppiando il reddito l\'IRPEF non puo\' restare identica'
        );

        // 6.440 + 7.700 + 50.000 × 43% (l'aliquota dell'ultimo scaglione)
        $this->assertEqualsWithDelta(31640.00, $this->lineAmount($at100k, 'IRPEF'), 0.01);
    }

    public function testIrpefIsMonotonicInIncome(): void
    {
        $previous = -1.0;

        foreach ([0, 10000, 28000, 28001, 50000, 50001, 120000, 400000] as $income) {
            $r   = $this->svc->estimateTaxes('progressive', $income, $income, $this->params(), [
                'inps_scheme' => 'separata', 'contributions' => 0.0,
            ]);
            $tax = $this->lineAmount($r, 'IRPEF');

            $this->assertGreaterThanOrEqual($previous, $tax, 'IRPEF non monotona a ' . $income);
            $this->assertLessThanOrEqual($income, $tax, 'IRPEF non puo\' superare il reddito');

            $previous = $tax;
        }
    }

    public function testEmptyBracketsYieldNoIrpefInsteadOfCrashing(): void
    {
        $r = $this->svc->estimateTaxes('progressive', 50000, 40000, $this->params(['irpef' => ['scaglioni' => []]]), [
            'inps_scheme' => 'separata', 'contributions' => 0.0,
        ]);

        $this->assertSame(0.0, $this->lineAmount($r, 'IRPEF'));
    }

    // -- contributi ----------------------------------------------------------

    public function testArtisanBelowMinimumStillOwesTheFixedContribution(): void
    {
        $r = $this->svc->estimateTaxes('progressive', 6000, 5000, $this->params(), [
            'inps_scheme' => 'artigiani',
        ]);

        // Sotto il minimale la quota sull'eccedenza è nulla, il fisso no.
        $this->assertEqualsWithDelta(4640.00, $this->lineAmount($r, 'INPS'), 0.01);
    }

    public function testArtisanAboveCapStopsGrowing(): void
    {
        $params = $this->params();
        $min    = $params['inps_artigiani']['minimale'];
        $max    = $params['inps_artigiani']['massimale'];

        $atCap   = $this->svc->estimateTaxes('progressive', 100000, $max, $params, ['inps_scheme' => 'artigiani']);
        $wayOver = $this->svc->estimateTaxes('progressive', 300000, 250000, $params, ['inps_scheme' => 'artigiani']);

        $expected = 4640.0 + ($max - $min) * 0.2448;

        $this->assertEqualsWithDelta($expected, $this->lineAmount($atCap, 'INPS'), 0.01);
        $this->assertEqualsWithDelta(
            $this->lineAmount($atCap, 'INPS'),
            $this->lineAmount($wayOver, 'INPS'),
            0.01,
            'Oltre il massimale i contributi non devono più crescere'
        );
    }

    public function testSeparateSchemeIsAFlatPercentage(): void
    {
        $r = $this->svc->estimateTaxes('progressive', 50000, 40000, $this->params(), [
            'inps_scheme' => 'separata',
        ]);

        $this->assertEqualsWithDelta(40000 * 0.2623, $this->lineAmount($r, 'Contributi'), 0.01);
    }

    public function testArtisanBreakdownSumsToItsTotal(): void
    {
        $r = $this->svc->estimateTaxes('progressive', 60000, 40000, $this->params(), [
            'inps_scheme' => 'artigiani',
        ]);

        foreach ($r['lines'] as $line) {
            if (empty($line['children'])) {
                continue;
            }

            $this->assertEqualsWithDelta(
                (float) $line['amount'],
                array_sum(array_column($line['children'], 'amount')),
                0.01,
                'Le sotto-voci di "' . $line['label'] . '" devono sommare al loro totale'
            );
        }
    }

    // -- addizionali ---------------------------------------------------------

    public function testSurchargesApplyToIncomeNetOfContributions(): void
    {
        $r = $this->svc->estimateTaxes('progressive', 60000, 40000, $this->params(), [
            'inps_scheme' => 'separata', 'contributions' => 0.0,
        ]);

        // Con contributi a zero la base è l'utile pieno: 40.000 × 2,23%.
        $this->assertEqualsWithDelta(40000 * 0.0223, $this->lineAmount($r, 'Addizionali'), 0.01);
    }

    public function testCompanySurchargesOverrideTheYearAverages(): void
    {
        $r = $this->svc->estimateTaxes('progressive', 60000, 40000, $this->params(), [
            'inps_scheme' => 'separata', 'contributions' => 0.0,
            'add_regionale' => 3.33, 'add_comunale' => 0.80,
        ]);

        $this->assertEqualsWithDelta(40000 * 0.0413, $this->lineAmount($r, 'Addizionali'), 0.01);
    }

    /**
     * Campo vuoto sulla scheda azienda significa "usa la media dell'anno", non
     * "azzera l'addizionale": è la differenza fra non saperla e non doverla.
     */
    public function testNullSurchargesFallBackToTheYearAverages(): void
    {
        $r = $this->svc->estimateTaxes('progressive', 60000, 40000, $this->params(), [
            'inps_scheme' => 'separata', 'contributions' => 0.0,
            'add_regionale' => null, 'add_comunale' => null,
        ]);

        $this->assertEqualsWithDelta(40000 * 0.0223, $this->lineAmount($r, 'Addizionali'), 0.01);
    }

    // -- forfettario ---------------------------------------------------------

    public function testFlatRateUsesCoefficientOnRevenueNotProfit(): void
    {
        $r = $this->svc->estimateTaxes('flat', 50000, 45000, $this->params(), [
            'coefficient' => 78, 'contributions' => 26.23,
        ]);

        // Imponibile = ricavi × coefficiente, i costi effettivi non contano.
        $this->assertEqualsWithDelta(39000.0, $r['taxable'], 0.01);
        $this->assertEqualsWithDelta(39000 * 0.2623, $this->lineAmount($r, 'Contributi'), 0.01);
        // I contributi si deducono prima dell'imposta sostitutiva.
        $this->assertEqualsWithDelta((39000 - 39000 * 0.2623) * 0.15, $this->lineAmount($r, 'sostitutiva'), 0.01);
    }

    public function testStartupRateIsOneThirdOfTheStandardOne(): void
    {
        $args     = ['coefficient' => 78, 'contributions' => 26.23];
        $standard = $this->svc->estimateTaxes('flat', 50000, 45000, $this->params(), $args);
        $startup  = $this->svc->estimateTaxes('flat', 50000, 45000, $this->params(), $args + ['forfettario_startup' => true]);

        $this->assertEqualsWithDelta(
            $this->lineAmount($standard, 'sostitutiva') / 3,
            $this->lineAmount($startup, 'sostitutiva'),
            0.01
        );
    }

    public function testFlatRateWarnsAboveTheRevenueLimit(): void
    {
        $under = $this->svc->estimateTaxes('flat', 84999, 70000, $this->params(), ['coefficient' => 78]);
        $over  = $this->svc->estimateTaxes('flat', 85001, 70000, $this->params(), ['coefficient' => 78]);

        $this->assertSame([], $under['warnings'], 'Sotto il limite non si avvisa');
        $this->assertNotEmpty($over['warnings'], 'Oltre il limite la stima non è più attendibile e va detto');
    }

    // -- voci aggiuntive (INAIL, fondi, quote) -------------------------------

    public function testFlatRateAddsExtraLineWithoutChangingBase(): void
    {
        $r = $this->svc->estimateTaxes('flat', 50000, 45000, $this->params(), [
            'coefficient' => 78, 'contributions' => 26.23,
            'extras' => [['name' => 'INAIL', 'rate' => 1.2, 'base' => 'taxable', 'deductible' => false]],
        ]);

        // Imponibile 39.000 × 1,2% = 468, in aggiunta alla stima di sempre.
        $this->assertEqualsWithDelta(468.00, $this->lineAmount($r, 'INAIL'), 0.01);
        $this->assertEqualsWithDelta(
            (39000 - 39000 * 0.2623) * 0.15,
            $this->lineAmount($r, 'sostitutiva'),
            0.01,
            'Una voce non deducibile non deve toccare l\'imposta sostitutiva'
        );
        $this->assertEqualsWithDelta(39000.0, $r['taxable'], 0.01);
    }

    public function testDeductibleExtraReducesTheSubstituteTaxBase(): void
    {
        $r = $this->svc->estimateTaxes('flat', 50000, 45000, $this->params(), [
            'coefficient' => 78, 'contributions' => 26.23,
            'extras' => [['name' => 'INAIL', 'rate' => 1.2, 'base' => 'taxable', 'deductible' => true]],
        ]);

        // (39.000 − contributi 10.229,70 − INAIL 468) × 15%
        $this->assertEqualsWithDelta(
            (39000 - 39000 * 0.2623 - 468.00) * 0.15,
            $this->lineAmount($r, 'sostitutiva'),
            0.01
        );
        $this->assertEqualsWithDelta(468.00, $this->lineAmount($r, 'INAIL'), 0.01);
    }

    public function testDeductibleExtraReducesIrpefAndSurchargesBase(): void
    {
        $r = $this->svc->estimateTaxes('progressive', 60000, 40000, $this->params(), [
            'inps_scheme' => 'separata', 'contributions' => 0.0,
            'extras' => [['name' => 'INPS', 'rate' => 2.0, 'base' => 'taxable', 'deductible' => true]],
        ]);

        // Base IRPEF 40.000 − 800 = 39.200: 28.000×23% + 11.200×35%.
        $this->assertEqualsWithDelta(10360.00, $this->lineAmount($r, 'IRPEF'), 0.01);
        // Le addizionali seguono la stessa base ridotta.
        $this->assertEqualsWithDelta(39200 * 0.0223, $this->lineAmount($r, 'Addizionali'), 0.01);
        $this->assertEqualsWithDelta(800.00, $this->lineAmount($r, 'INPS'), 0.01);
    }

    public function testCorporateModeAddsTheLineButNeverDeducts(): void
    {
        $r = $this->svc->estimateTaxes('corporate', 4800, 3350, $this->params(), [
            'extras' => [['name' => 'INAIL', 'rate' => 1.5, 'base' => 'profit', 'deductible' => true]],
        ]);

        // 3.350 × 1,5% = 50,25; IRES e IRAP restano sull'utile pieno.
        $this->assertEqualsWithDelta(50.25, $this->lineAmount($r, 'INAIL'), 0.01);
        $this->assertEqualsWithDelta(804.00, $this->lineAmount($r, 'IRES'), 0.01);
        $this->assertEqualsWithDelta(984.90, $r['total'], 0.01);
    }

    public function testExtrasBaseSelectorMapsToTheChosenAmount(): void
    {
        // Stessa voce, basi diverse: ricavi 50.000, utile 45.000, imponibile 39.000.
        $cases = [
            'revenue' => 50000 * 0.02,
            'profit'  => 45000 * 0.02,
            'taxable' => 39000 * 0.02,
        ];

        foreach ($cases as $base => $expected) {
            $r = $this->svc->estimateTaxes('flat', 50000, 45000, $this->params(), [
                'coefficient' => 78, 'contributions' => 26.23,
                'extras' => [['name' => 'Fondo', 'rate' => 2.0, 'base' => $base, 'deductible' => false]],
            ]);

            $this->assertEqualsWithDelta($expected, $this->lineAmount($r, 'Fondo'), 0.01, 'Base: ' . $base);
        }
    }

    public function testMalformedExtrasAreSkippedOrFallBackToTaxable(): void
    {
        $r = $this->svc->estimateTaxes('flat', 50000, 45000, $this->params(), [
            'coefficient' => 78, 'contributions' => 26.23,
            'extras' => [
                ['name' => '', 'rate' => 10.0, 'base' => 'taxable', 'deductible' => false],
                ['name' => 'Zero', 'rate' => 0.0, 'base' => 'taxable', 'deductible' => false],
                ['name' => 'Base invalida', 'rate' => 2.0, 'base' => 'bogus', 'deductible' => false],
                ['name' => 'Centesimi', 'rate' => 1.5, 'base' => 'revenue', 'deductible' => false],
                'non-array',
            ],
        ]);

        // La base ignota ripiega sull'imponibile: 39.000 × 2%.
        $this->assertEqualsWithDelta(780.00, $this->lineAmount($r, 'Base invalida'), 0.01);
        $this->assertEqualsWithDelta(750.00, $this->lineAmount($r, 'Centesimi'), 0.01);

        // Riga senza nome o con aliquota a zero: nessuna riga, nessun errore.
        $labels = array_column($r['lines'], 'label');
        $this->assertNotContains('Zero', $labels);
        $this->assertNotContains(' (10%)', $labels);
        // Il totale è la somma delle righe esistenti (contributi + 2 voci + sostitutiva).
        $this->assertEqualsWithDelta(
            array_sum(array_column($r['lines'], 'amount')),
            $r['total'],
            0.001
        );
    }

    // -- invarianti ----------------------------------------------------------

    /**
     * La barra di ripartizione del prospetto divide i ricavi in costi, imposte
     * e netto: regge solo se netto = utile − imposte, sempre.
     */
    public function testNetIsAlwaysProfitMinusTotal(): void
    {
        $cases = [
            ['corporate', 4800, 3350, []],
            ['progressive', 60000, 40000, ['inps_scheme' => 'artigiani']],
            ['progressive', 60000, 40000, ['inps_scheme' => 'separata']],
            ['flat', 50000, 45000, ['coefficient' => 78]],
        ];

        foreach ($cases as [$mode, $revenue, $profit, $company]) {
            $r = $this->svc->estimateTaxes($mode, $revenue, $profit, $this->params(), $company);

            $this->assertEqualsWithDelta($profit - $r['total'], $r['net'], 0.001, 'Modo: ' . $mode);
            $this->assertEqualsWithDelta(
                array_sum(array_column($r['lines'], 'amount')),
                $r['total'],
                0.001,
                'Il totale deve essere la somma delle righe — modo: ' . $mode
            );
        }
    }

    public function testRatesComeFromParametersNotFromConstants(): void
    {
        $doubled = $this->svc->estimateTaxes('corporate', 4800, 3350, $this->params([
            'ires' => ['aliquota' => 48.0],
            'irap' => ['aliquota' => 7.8],
        ]));

        $this->assertEqualsWithDelta(1869.30, $doubled['total'], 0.01, 'Cambiando i parametri deve cambiare la stima');
    }
}
