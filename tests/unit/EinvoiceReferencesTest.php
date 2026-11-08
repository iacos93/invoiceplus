<?php

use App\Services\EinvoiceReferences;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Quando CIG/CUP restano senza un riferimento in cui viaggiare.
 *
 * Il caso della simulazione: fattura PA con il solo CIG, uscita con
 * DatiOrdineAcquisto.IdDocumento uguale al numero della fattura stessa.
 *
 * @internal
 */
final class EinvoiceReferencesTest extends CIUnitTestCase
{
    public function testCigWithoutAnyReferenceIsMissing(): void
    {
        $this->assertTrue(EinvoiceReferences::missingOrderReference(['invoice_cig' => 'Z1A2B3C4D5']));
        $this->assertTrue(EinvoiceReferences::missingOrderReference(['invoice_cup' => 'J11B12000010001', 'invoice_order_number' => '  ']));
    }

    public function testAnyReferenceNumberCarriesTheCodes(): void
    {
        foreach (EinvoiceReferences::NUMBER_FIELDS as $field) {
            $this->assertFalse(
                EinvoiceReferences::missingOrderReference(['invoice_cig' => 'Z1A2B3C4D5', $field => 'DET-12/2026']),
                $field
            );
        }
    }

    public function testNothingToSayWithoutCigOrCup(): void
    {
        $this->assertFalse(EinvoiceReferences::missingOrderReference([]));
        $this->assertFalse(EinvoiceReferences::missingOrderReference(['invoice_cig' => ' ']));
    }

    /** La nota di credito porta CIG/CUP in DatiFattureCollegate. */
    public function testCreditNoteUsesTheLinkedInvoice(): void
    {
        $this->assertFalse(EinvoiceReferences::missingOrderReference([
            'invoice_cig'             => 'Z1A2B3C4D5',
            'creditinvoice_parent_id' => 6,
        ]));
    }

    public function testFallbackIsAValidIdDocumento(): void
    {
        // String20Type del tracciato: da 1 a 20 caratteri.
        $this->assertMatchesRegularExpression('/^.{1,20}$/', EinvoiceReferences::FALLBACK_ID);
    }
}
