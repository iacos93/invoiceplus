<?php

use App\Services\InvoiceMath;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Aritmetica dei documenti e importi del tracciato FatturaPA.
 *
 * I casi vengono dalla simulazione d'uso che ha trovato i difetti: lo sconto
 * 10% su 3,5 × 45,90 (16,06 invece di 16,07), la fattura PA in split payment
 * rimasta aperta per l'IVA, la nota di credito con quantità negative che la
 * XSD rifiuta. Un errore qui non si vede a video: lo si scopre da una
 * ricevuta di scarto dello SdI.
 *
 * @internal
 */
final class InvoiceMathTest extends CIUnitTestCase
{
    public function testRound2IgnoresFloatNoise(): void
    {
        // 3,5 × 4,59 = 16,064999999999998 in binario
        $this->assertSame(16.07, InvoiceMath::round2((45.90 / 100 * 10) * 3.5));
        $this->assertSame(1.01, InvoiceMath::round2(1.005));
        $this->assertSame(-16.07, InvoiceMath::round2(-16.065));
        $this->assertSame(0.0, InvoiceMath::round2(-0.001));
    }

    public function testLineDiscountPercentRoundsHalfUp(): void
    {
        $line = InvoiceMath::lineAmounts(3.5, 45.90, 0, 10, 22);

        $this->assertSame(160.65, $line['subtotal']);
        $this->assertSame(16.07, $line['discount']);
        $this->assertSame(144.58, $line['taxable']);
        $this->assertSame(31.81, $line['tax']);
        $this->assertSame(176.39, $line['total']);
    }

    public function testLineDiscountAmountIsPerUnit(): void
    {
        $line = InvoiceMath::lineAmounts(4, 10.00, 1.50, 0, 22);

        $this->assertSame(6.0, $line['discount']);
        $this->assertSame(34.0, $line['taxable']);
    }

    public function testLineWithNaturaHasNoTax(): void
    {
        $line = InvoiceMath::lineAmounts(1, 150, 0, 0, 22, true);

        $this->assertSame(0.0, $line['tax']);
        $this->assertSame(150.0, $line['total']);
    }

    /** Lo SdI ricostruisce PrezzoTotale applicando gli sconti in cascata al prezzo: deve tornare entro il centesimo. */
    public function testDiscountNodesRebuildTheLineTotal(): void
    {
        foreach ([[3.5, 45.90, 0, 10], [4, 10.00, 1.50, 0], [2, 99.99, 5.00, 12.5]] as [$qty, $price, $amount, $pct]) {
            $line  = InvoiceMath::lineAmounts($qty, $price, $amount, $pct, 22);
            $nodes = InvoiceMath::lineDiscounts($amount, $pct);

            $unit = $price;
            foreach ($nodes as $node) {
                $unit = isset($node['Percentuale'])
                    ? $unit - $price * (float) $node['Percentuale'] / 100
                    : $unit - (float) $node['Importo'];
            }

            $this->assertEqualsWithDelta($line['taxable'], $unit * $qty, 0.01, "q={$qty} p={$price}");
        }
    }

    public function testDiscountNodesPercentFirst(): void
    {
        $nodes = InvoiceMath::lineDiscounts(5, 12.5);

        $this->assertSame([
            ['Tipo' => 'SC', 'Percentuale' => '12.50'],
            ['Tipo' => 'SC', 'Importo' => '5.00'],
        ], $nodes);
        $this->assertSame([], InvoiceMath::lineDiscounts(0, 0));
    }

    /** Su un prezzo che esce negativo lo sconto a importo deve seguirne il segno, o l'allontanerebbe da zero. */
    public function testDiscountAmountFollowsPriceSign(): void
    {
        $this->assertSame([['Tipo' => 'SC', 'Importo' => '-5.00']], InvoiceMath::lineDiscounts(5, 0, -1));
    }

    /** Quantita non ammette il segno: una riga di storno in una TD01 porta il segno sul prezzo. */
    public function testNegativeQuantityMovesSignToPrice(): void
    {
        $line = InvoiceMath::xmlLine(-2, 19.99, -39.98);

        $this->assertSame(2.0, $line['quantity']);
        $this->assertSame(-19.99, $line['price']);
        $this->assertSame(-39.98, $line['total']);
        $this->assertSame(-1, $line['sign']);
    }

    /** Nota di credito TD04: internamente negativa, nel tracciato tutta positiva. */
    public function testCreditNoteLineIsFlippedPositive(): void
    {
        $line = InvoiceMath::xmlLine(-3.5, 45.90, -144.58, -1);

        $this->assertSame(3.5, $line['quantity']);
        $this->assertSame(45.90, $line['price']);
        $this->assertSame(144.58, $line['total']);
        $this->assertSame(1, $line['sign']);
    }

    /** Fattura PA in split payment: la PA versa il netto, l'IVA va all'Erario. */
    public function testSplitPaymentAmountDueIsNet(): void
    {
        $this->assertSame(1049.0, InvoiceMath::amountDue(1279.78, 230.78, 'S'));
        $this->assertSame(0.0, InvoiceMath::balance(1279.78, 230.78, 'S', 1049.00));
    }

    public function testOrdinaryAmountDueIsGross(): void
    {
        $this->assertSame(1279.78, InvoiceMath::amountDue(1279.78, 230.78, 'I'));
        $this->assertSame(1279.78, InvoiceMath::amountDue(1279.78, 230.78, null));
        $this->assertSame(230.78, InvoiceMath::balance(1279.78, 230.78, 'D', 1049.00));
    }

    /** Ritenuta sull'imponibile netto: 2 × 100 con sconto 5 €/unità = 190, al 20% = 38. */
    public function testWithholdingOnNetTaxable(): void
    {
        $this->assertSame(38.0, InvoiceMath::withholding(190.00, 'RT01', 20));
        $this->assertSame(0.0, InvoiceMath::withholding(190.00, '', 20));
        $this->assertSame(0.0, InvoiceMath::withholding(190.00, 'RT01', 0));
        $this->assertSame(-38.0, InvoiceMath::withholding(-190.00, 'RT01', 20));
    }

    /** Il sostituto d'imposta trattiene la ritenuta: il dovuto è totale meno ritenuta. */
    public function testAmountDueNetOfWithholding(): void
    {
        $this->assertSame(193.8, InvoiceMath::amountDue(231.80, 41.80, 'D', 38.00));
        $this->assertSame(0.0, InvoiceMath::balance(231.80, 41.80, 'I', 193.80, 38.00));
        // PA in split payment con ritenuta: né IVA né ritenuta arrivano al fornitore
        $this->assertSame(152.0, InvoiceMath::amountDue(231.80, 41.80, 'S', 38.00));
    }

    public function testPaidWhenBalanceReachesZero(): void
    {
        $this->assertTrue(InvoiceMath::shouldMarkPaid(2, 1742.98, 0.0, false));
        $this->assertTrue(InvoiceMath::shouldMarkPaid(3, 1742.98, 0.0, false));
    }

    public function testNotPaidWhileBalanceOpen(): void
    {
        $this->assertFalse(InvoiceMath::shouldMarkPaid(2, 1742.98, 0.01, false));
    }

    /** Un incasso con un centesimo in più chiude la fattura, non la lascia "Inviata" per sempre. */
    public function testOverpaidIsPaid(): void
    {
        $this->assertTrue(InvoiceMath::shouldMarkPaid(2, 1742.97, -0.01, false));
    }

    /** Nota di credito (totale negativo): aperta finché non è rimborsata. */
    public function testCreditNoteFollowsTheSignOfItsTotal(): void
    {
        $this->assertFalse(InvoiceMath::shouldMarkPaid(2, -1742.97, -1742.97, true));
        $this->assertTrue(InvoiceMath::shouldMarkPaid(2, -1742.97, 0.0, true));
    }

    /** Le bozze le emette l'utente; una fattura vuota a zero non è "pagata". */
    public function testDraftsAndEmptyInvoicesStayPut(): void
    {
        $this->assertFalse(InvoiceMath::shouldMarkPaid(1, 100.0, 0.0, false));
        $this->assertFalse(InvoiceMath::shouldMarkPaid(2, 0.0, 0.0, false));
    }

    /** Come in CI3: una nota di credito si può chiudere a totale zero. */
    public function testCreditNoteCanCloseAtZero(): void
    {
        $this->assertTrue(InvoiceMath::shouldMarkPaid(2, 0.0, 0.0, true));
    }

    /**
     * Pagata con 600 + 400, poi il pagamento da 400 viene eliminato: il saldo
     * torna aperto e la fattura deve tornare fra quelle da incassare, invece
     * di restare "Pagato" e sparire da scadenzario e solleciti.
     */
    public function testPaidInvoiceReopensWhenBalanceOpensAgain(): void
    {
        $this->assertTrue(InvoiceMath::shouldReopen(4, 1000.0, 400.0, false, true));
    }

    public function testSettledPaidInvoiceStaysPaid(): void
    {
        $this->assertFalse(InvoiceMath::shouldReopen(4, 1000.0, 0.0, false, true));
        // incasso con un centesimo in più: resta chiusa, come per shouldMarkPaid()
        $this->assertFalse(InvoiceMath::shouldReopen(4, 1000.0, -0.01, false, true));
    }

    /** "Pagato" scelto a mano, senza pagamenti registrati: un ricalcolo non lo smentisce. */
    public function testManuallyPaidInvoiceWithoutPaymentsIsNotReopened(): void
    {
        $this->assertFalse(InvoiceMath::shouldReopen(4, 1000.0, 1000.0, false, false));
    }

    /** Solo lo stato 4 si riapre: bozze e inviate non sono affare di questa regola. */
    public function testOnlyPaidStatusCanReopen(): void
    {
        $this->assertFalse(InvoiceMath::shouldReopen(2, 1000.0, 400.0, false, true));
        $this->assertFalse(InvoiceMath::shouldReopen(1, 1000.0, 400.0, false, true));
    }

    /** Nota di credito rimborsata a metà: torna aperta nel suo verso (negativo). */
    public function testCreditNoteReopensWhenRefundIsRemoved(): void
    {
        $this->assertTrue(InvoiceMath::shouldReopen(4, -500.0, -200.0, true, true));
        $this->assertFalse(InvoiceMath::shouldReopen(4, -500.0, 0.0, true, true));
    }

    /**
     * Sconto di documento sull'imponibile: l'IVA si ricalcola sul netto.
     * Prima lo sconto si toglieva dal totale IVA inclusa e il riepilogo XML
     * riportava ancora l'imponibile pieno.
     */
    public function testDocumentDiscountReducesTaxableBeforeVat(): void
    {
        $s = InvoiceMath::documentSummary(
            [['taxable' => 1000.0, 'rate' => 22.0]],
            100.0
        );

        $this->assertSame(100.0, $s['discount']);
        $this->assertSame(900.0, $s['taxable']);
        $this->assertSame(198.0, $s['tax']);
        $this->assertSame(1098.0, $s['total']);
        $this->assertSame(900.0, $s['buckets']['22.00|']['taxable']);
    }

    /** Importo e poi percentuale sul residuo, come in CI3. */
    public function testDocumentDiscountAmountThenPercent(): void
    {
        $s = InvoiceMath::documentSummary([['taxable' => 1000.0, 'rate' => 22.0]], 100.0, 10.0);

        $this->assertSame(190.0, $s['discount']);
        $this->assertSame(810.0, $s['taxable']);
    }

    /**
     * Con più aliquote lo sconto si divide in proporzione e l'ultima quota
     * assorbe l'arrotondamento: la somma resta lo sconto indicato.
     */
    public function testDocumentDiscountIsSplitAcrossRatesWithoutLosingCents(): void
    {
        $s = InvoiceMath::documentSummary([
            ['taxable' => 100.0, 'rate' => 22.0],
            ['taxable' => 100.0, 'rate' => 10.0],
            ['taxable' => 100.0, 'rate' => 0.0, 'natura' => 'N2.2'],
        ], 10.0);

        $shares = array_column($s['buckets'], 'discount');
        $this->assertSame([3.33, 3.33, 3.34], $shares);
        $this->assertSame(10.0, InvoiceMath::round2(array_sum($shares)));
        // 96,67 × 22% + 96,67 × 10%; la riga N2.2 non porta IVA
        $this->assertSame(InvoiceMath::round2(21.27 + 9.67), $s['tax']);
        $this->assertSame(0.0, $s['buckets']['0.00|N2.2']['tax']);
    }

    /**
     * Cassa previdenziale: contributo sull'imponibile scontato, sommato
     * all'imponibile della prima aliquota e soggetto alla sua IVA.
     */
    public function testFundContributionEntersTaxableAndVat(): void
    {
        $s = InvoiceMath::documentSummary(
            [['taxable' => 1000.0, 'rate' => 22.0]],
            0.0, 0.0, 4.0
        );

        $this->assertSame(1000.0, $s['fund_base']);
        $this->assertSame(40.0, $s['fund']);
        $this->assertSame('22.00|', $s['fund_bucket']);
        $this->assertSame(1040.0, $s['taxable']);
        $this->assertSame(228.8, $s['tax']);
        $this->assertSame(1268.8, $s['total']);
    }

    /**
     * Ritenuta: sull'imponibile scontato; il contributo ne fa parte solo per
     * la rivalsa INPS (TC22), non per le casse professionali.
     */
    public function testWithholdingIncludesFundOnlyForInpsRivalsa(): void
    {
        $lines = [['taxable' => 1000.0, 'rate' => 22.0]];

        $inps = InvoiceMath::documentSummary($lines, 0.0, 0.0, 4.0,
            InvoiceMath::fundSubjectToWithholding('TC22'), 'RT01', 20.0);
        $cassa = InvoiceMath::documentSummary($lines, 0.0, 0.0, 4.0,
            InvoiceMath::fundSubjectToWithholding('TC04'), 'RT01', 20.0);

        $this->assertSame(208.0, $inps['withholding']);
        $this->assertSame(200.0, $cassa['withholding']);
    }

    /** Fondo + sconto insieme: il contributo si calcola dopo lo sconto. */
    public function testFundIsComputedOnDiscountedTaxable(): void
    {
        $s = InvoiceMath::documentSummary([['taxable' => 1000.0, 'rate' => 22.0]], 0.0, 10.0, 4.0);

        $this->assertSame(900.0, $s['fund_base']);
        $this->assertSame(36.0, $s['fund']);
        $this->assertSame(936.0, $s['taxable']);
        $this->assertSame(205.92, $s['tax']);
    }

    /** La nota di credito interna è negativa in tutto, sconto compreso. */
    public function testCreditNoteSummaryKeepsTheSign(): void
    {
        $s = InvoiceMath::documentSummary([['taxable' => -1000.0, 'rate' => 22.0]], -100.0);

        $this->assertSame(-100.0, $s['discount']);
        $this->assertSame(-900.0, $s['taxable']);
        $this->assertSame(-1098.0, $s['total']);
    }

    /** Senza sconti né cassa, il riepilogo è la somma delle righe. */
    public function testPlainDocumentSummary(): void
    {
        $s = InvoiceMath::documentSummary([
            ['taxable' => 144.58, 'rate' => 22.0],
            ['taxable' => 50.0, 'rate' => 22.0],
        ]);

        $this->assertSame(0.0, $s['discount']);
        $this->assertSame(194.58, $s['taxable']);
        $this->assertSame(42.81, $s['tax']);
        $this->assertNull($s['fund_bucket']);
    }

    /**
     * Il form pagamenti propone sempre un importo positivo: il residuo da
     * incassare o, per la nota di credito, da rimborsare. Prima proponeva il
     * saldo così com'era (-1.049,00).
     */
    public function testSuggestedPaymentIsPositiveInTheDocumentDirection(): void
    {
        $this->assertSame(1049.0, InvoiceMath::suggestedPayment(-1279.78, -1049.0));
        $this->assertSame(193.8, InvoiceMath::suggestedPayment(231.80, 193.80));
        // Fattura incassata con un centesimo in più: niente da proporre.
        $this->assertSame(0.0, InvoiceMath::suggestedPayment(1742.97, -0.01));
        // Nota di credito rimborsata oltre il dovuto: idem.
        $this->assertSame(0.0, InvoiceMath::suggestedPayment(-100.0, 5.0));
    }

    /**
     * Il controllo "importo > saldo" era rovesciato sulle note di credito:
     * bocciava il rimborso parziale e accettava quello eccessivo.
     */
    public function testExceedsBalanceFollowsTheDocumentDirection(): void
    {
        $this->assertFalse(InvoiceMath::exceedsBalance(-1279.78, -1049.0, -500.0));
        $this->assertFalse(InvoiceMath::exceedsBalance(-1279.78, -1049.0, -1049.0));
        $this->assertTrue(InvoiceMath::exceedsBalance(-1279.78, -1049.0, -2000.0));

        $this->assertFalse(InvoiceMath::exceedsBalance(231.80, 193.80, 193.80));
        $this->assertTrue(InvoiceMath::exceedsBalance(231.80, 193.80, 193.81));
    }

    public function testDocumentSign(): void
    {
        $this->assertSame(-1, InvoiceMath::documentSign(-0.01));
        $this->assertSame(1, InvoiceMath::documentSign(0.0));
        $this->assertSame(1, InvoiceMath::documentSign(10.0));
    }

    /**
     * Prezzo scorporato (350 € IVA inclusa → 286,8852459 + IVA): l'IVA si
     * calcola sull'importo esatto della riga, come il gestionale che ha
     * emesso la fattura, e il totale torna 350,00 invece di 350,01.
     */
    public function testVatIsComputedOnExactLineAmount(): void
    {
        $lines = InvoiceMath::linesFromItems([[
            'item_quantity' => 1, 'item_price' => 286.8852459,
            'item_subtotal' => 286.89, 'item_discount' => 0,
            'item_tax_rate_percent' => 22,
        ]]);

        $s = InvoiceMath::documentSummary($lines);

        $this->assertSame(286.89, $s['taxable']);
        $this->assertSame(63.11, $s['tax']);
        $this->assertSame(350.0, $s['total']);
    }

    /** Con prezzi al centesimo l'importo esatto coincide: nessuna differenza. */
    public function testExactAmountMatchesRoundedForCentPrices(): void
    {
        $line = InvoiceMath::linesFromItems([[
            'item_quantity' => 3.5, 'item_price' => 45.90, 'item_discount_percent' => 10,
            'item_subtotal' => 160.65, 'item_discount' => 16.07,
            'item_tax_rate_percent' => 22,
        ]])[0];

        $this->assertSame(144.58, $line['taxable']);
        $this->assertSame(144.585, $line['exact']);
        $this->assertSame(31.81, InvoiceMath::documentSummary([$line])['tax']);
    }

    /**
     * Lo sconto di documento non può superare l'imponibile: oltre quella
     * soglia il totale scende sotto zero e il documento non è più
     * rappresentabile (ImportoTotaleDocumento negativo).
     */
    public function testDocumentDiscountEqualToBaseIsAllowed(): void
    {
        $lines = self::lines(100.0);

        $this->assertFalse(InvoiceMath::discountExceedsBase($lines, 100.0, 0.0));
        $this->assertSame(0.0, InvoiceMath::documentSummary($lines, 100.0, 0.0)['total']);
    }

    public function testDocumentDiscountAboveBaseIsRejected(): void
    {
        $lines = self::lines(100.0);

        $this->assertTrue(InvoiceMath::discountExceedsBase($lines, 100.01, 0.0));
        // 101% arriva oltre l'imponibile anche senza importo.
        $this->assertTrue(InvoiceMath::discountExceedsBase($lines, 0.0, 101.0));
        // Importo più percentuale: la percentuale si applica al residuo
        // (base − importo), come in documentSummary().
        $this->assertFalse(InvoiceMath::discountExceedsBase($lines, 80.0, 30.0));
        $this->assertTrue(InvoiceMath::discountExceedsBase($lines, 80.0, 200.0));
    }

    public function testNoDiscountNeverExceeds(): void
    {
        $this->assertFalse(InvoiceMath::discountExceedsBase(self::lines(0.0), 0.0, 0.0));
        $this->assertFalse(InvoiceMath::discountExceedsBase([], 0.0, 0.0));
    }

    /** @return list<array{taxable: float, exact: float, rate: float, natura: null}> */
    private static function lines(float $taxable): array
    {
        return [[
            'taxable' => $taxable,
            'exact'   => $taxable,
            'rate'    => 22.0,
            'natura'  => null,
        ]];
    }
}
