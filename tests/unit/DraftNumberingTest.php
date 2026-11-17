<?php

use App\Models\InvoiceModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il numero di una bozza non deve sembrare un progressivo.
 *
 * In produzione una bozza senza numero mostrava al suo posto l'id (220), che
 * ha la forma di un numero di fattura e veniva letto come tale. Accanto, la
 * serie bozze puntava alla serie ufficiale: il "numero provvisorio" sarebbe
 * stato un progressivo vero, sostituito all'uscita dalla bozza e quindi
 * bruciato.
 *
 * @internal
 */
final class DraftNumberingTest extends CIUnitTestCase
{
    public function testLaSerieBozzeDeveEssereUnAltraSerie(): void
    {
        $this->assertTrue(InvoiceModel::isSeparateDraftSeries(2, 1), 'Due serie distinte: la bozza può avere il provvisorio');

        $this->assertFalse(
            InvoiceModel::isSeparateDraftSeries(1, 1),
            'Serie bozze uguale a quella ufficiale: nessun numero provvisorio, o si brucia un progressivo vero'
        );

        $this->assertFalse(InvoiceModel::isSeparateDraftSeries(0, 1), 'Nessuna serie bozze configurata');
    }

    public function testEtichettaConNumero(): void
    {
        $this->assertSame('FAT-2026-001', invoice_number_label(['invoice_number' => 'FAT-2026-001']));
        $this->assertSame('T-12', invoice_number_label(['invoice_number' => 'T-12']));
    }

    public function testEtichettaSenzaNumeroNonInventaUnProgressivo(): void
    {
        $this->assertSame('Bozza #220', invoice_number_label(['invoice_number' => '', 'invoice_id' => 220]));
        $this->assertSame('Bozza #7', invoice_number_label(['invoice_number' => null, 'invoice_id' => '7']));
        $this->assertSame('Bozza #0', invoice_number_label([]));
    }
}
