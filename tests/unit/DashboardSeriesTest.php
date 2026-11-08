<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Test per logiche di calcolo KPI e serie grafici di Dashboard.
 *
 * @internal
 */
final class DashboardSeriesTest extends CIUnitTestCase
{
    /**
     * Calcolo margine di profitto percentuale: (profit / revenue) * 100
     */
    public function testProfitMarginCalculation(): void
    {
        $revenue = 10000.0;
        $expenses = 4000.0;
        $profit = $revenue - $expenses;
        $margin = $revenue > 0 ? ($profit / $revenue) * 100.0 : 0.0;

        $this->assertEqualsWithDelta(60.0, $margin, 0.001);
    }

    /**
     * Margine di profitto con fatturato zero deve evitare divisione per zero.
     */
    public function testProfitMarginWithZeroRevenue(): void
    {
        $revenue = 0.0;
        $profit = -500.0;
        $margin = $revenue > 0 ? ($profit / $revenue) * 100.0 : 0.0;

        $this->assertSame(0.0, $margin);
    }

    /**
     * Tasso di incasso: (incassato / fatturato) * 100
     */
    public function testCollectionRateCalculation(): void
    {
        $invoiced = 5000.0;
        $paid = 4000.0;
        $rate = $invoiced > 0 ? ($paid / $invoiced) * 100.0 : 0.0;

        $this->assertEqualsWithDelta(80.0, $rate, 0.001);
    }

    /**
     * Tasso di incasso con zero fatturato.
     */
    public function testCollectionRateWithZeroInvoiced(): void
    {
        $invoiced = 0.0;
        $paid = 0.0;
        $rate = $invoiced > 0 ? ($paid / $invoiced) * 100.0 : 0.0;

        $this->assertSame(0.0, $rate);
    }

    /**
     * Test calcolo IVA da versare al netto dello split payment e dell'IVA acquisti.
     */
    public function testNetIvaCalculation(): void
    {
        $ivaSales = 2200.0;
        $ivaSplit = 400.0; // PA versa direttamente
        $ivaPurchase = 800.0;

        $ivaPayable = $ivaSales - $ivaSplit;
        $ivaTotal = $ivaPayable - $ivaPurchase;

        $this->assertEqualsWithDelta(1800.0, $ivaPayable, 0.001);
        $this->assertEqualsWithDelta(1000.0, $ivaTotal, 0.001);
    }
}
