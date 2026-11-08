<?php

use App\Services\CostingService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Copertura della matematica del Bilancino.
 *
 * Margine di contribuzione ed EBITDA sono le due cifre su cui si decide se
 * alzare i prezzi, prendere un altro cliente o chiudere un ramo. Sbagliarle
 * non produce un errore: produce un numero plausibile, e nessuno se ne accorge
 * finché non è troppo tardi. Per questo i casi coperti sono quelli che una
 * modifica distratta rompe per primi: mesi mancanti, segni, divisioni per zero.
 *
 * CostingService è deliberatamente pura (niente database, niente HTTP), così
 * questi test girano senza fixtures né sessione.
 *
 * @internal
 */
final class CostingServiceTest extends CIUnitTestCase
{
    /**
     * Dodici mesi con un valore solo dove indicato.
     *
     * @param array<int, float> $values mese => importo
     *
     * @return array<int, float>
     */
    private function series(array $values): array
    {
        return array_replace(CostingService::months(), $values);
    }

    public function testMonthsHasTwelveZeroedMonths(): void
    {
        $months = CostingService::months();

        $this->assertCount(12, $months);
        $this->assertSame(range(1, 12), array_keys($months));
        $this->assertSame(0.0, array_sum($months));
    }

    public function testTotalRoundsToCents(): void
    {
        $this->assertSame(30.03, CostingService::total($this->series([1 => 10.005, 2 => 20.025])));
    }

    public function testAddTreatsMissingMonthsAsZero(): void
    {
        // Una serie parziale (tre mesi) non deve produrre indici mancanti:
        // la view cicla sempre su 1..12 e un buco diventerebbe una cella vuota.
        $out = CostingService::add($this->series([1 => 100.0]), [2 => 50.0]);

        $this->assertCount(12, $out);
        $this->assertSame(100.0, $out[1]);
        $this->assertSame(50.0, $out[2]);
        $this->assertSame(0.0, $out[3]);
    }

    public function testSubtractKeepsNegativeMonths(): void
    {
        // Un mese in perdita deve restare negativo: azzerarlo nasconderebbe
        // proprio il mese che si sta cercando nel report.
        $out = CostingService::subtract($this->series([1 => 100.0, 2 => 50.0]), $this->series([1 => 70.0, 2 => 80.0]));

        $this->assertSame(30.0, $out[1]);
        $this->assertSame(-30.0, $out[2]);
    }

    public function testContributionMarginIsRevenueMinusVariable(): void
    {
        $margin = CostingService::contributionMargin(
            $this->series([3 => 1000.0]),
            $this->series([3 => 400.0])
        );

        $this->assertSame(600.0, $margin[3]);
        $this->assertSame(600.0, CostingService::total($margin));
    }

    public function testEbitdaSubtractsVariableAndFixed(): void
    {
        $ebitda = CostingService::ebitda(
            $this->series([6 => 1000.0]),
            $this->series([6 => 250.0]),
            $this->series([6 => 500.0])
        );

        $this->assertSame(250.0, $ebitda[6]);
    }

    public function testEbitdaGoesNegativeWhenFixedCostsExceedRevenue(): void
    {
        $ebitda = CostingService::ebitda(
            $this->series([1 => 100.0]),
            $this->series([1 => 50.0]),
            $this->series([1 => 300.0])
        );

        $this->assertSame(-250.0, $ebitda[1]);
    }

    public function testCumulativeAddsMonthByMonth(): void
    {
        $cumulative = CostingService::cumulative($this->series([1 => 100.0, 2 => -40.0, 3 => 10.0]));

        $this->assertSame(100.0, $cumulative[1]);
        $this->assertSame(60.0, $cumulative[2]);
        $this->assertSame(70.0, $cumulative[3]);
        $this->assertSame(70.0, $cumulative[12]);
    }

    public function testVarianceOnBudget(): void
    {
        $variance = CostingService::variance(1200.0, 1000.0);

        $this->assertSame(200.0, $variance['amount']);
        $this->assertSame(20.0, $variance['percent']);
    }

    public function testVariancePercentUsesAbsoluteBudget(): void
    {
        // Sulle uscite il budget è positivo ma lo scostamento è negativo:
        // la percentuale deve restare leggibile (-50%, non +50%).
        $variance = CostingService::variance(500.0, 1000.0);

        $this->assertSame(-500.0, $variance['amount']);
        $this->assertSame(-50.0, $variance['percent']);
    }

    public function testVariancePercentIsNullWithoutBudget(): void
    {
        // "Scostamento 0%" su un budget inesistente direbbe una cosa falsa:
        // null obbliga la view a mostrare un trattino.
        $this->assertNull(CostingService::variance(100.0, 0.0)['percent']);
    }

    public function testShareIsNullOnZeroBase(): void
    {
        $this->assertNull(CostingService::share(100.0, 0.0));
        $this->assertSame(25.0, CostingService::share(100.0, 400.0));
        $this->assertSame(12.5, CostingService::share(50.0, 400.0));
    }

    public function testSumSeriesHandlesEmptyIterable(): void
    {
        $empty = CostingService::sumSeries([]);

        $this->assertCount(12, $empty);
        $this->assertSame(0.0, CostingService::total($empty));
    }

    public function testSumSeriesDoesNotDoubleCountParents(): void
    {
        // Non è compito del servizio conoscere l'albero, ma la somma di due
        // serie deve restare additiva: è la proprietà su cui si appoggia
        // ManagementReport quando somma solo le radici.
        $sum = CostingService::sumSeries([
            $this->series([1 => 100.0]),
            $this->series([1 => 25.0]),
        ]);

        $this->assertSame(125.0, $sum[1]);
    }
}
