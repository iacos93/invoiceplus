<?php

use App\Libraries\DocumentRow;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Riga di documento leggibile come array e come oggetto.
 *
 * La composizione dei PDF e' una sola, ma i due template non erano d'accordo
 * sulla forma dei dati: la fattura legge $invoice->campo, il preventivo
 * $quote['campo']. DocumentRow fa da ponte. Se questa classe cambia, i
 * template si rompono o -- peggio -- leggono null in silenzio: qui si fissa
 * il contratto su cui entrambi contano.
 *
 * @internal
 */
final class DocumentRowTest extends CIUnitTestCase
{
    public function testLeggeConEntrambeLeSintassi(): void
    {
        $row = new DocumentRow(['invoice_number' => 'NC2', 'invoice_total' => 122.0]);

        $this->assertSame('NC2', $row->invoice_number);
        $this->assertSame('NC2', $row['invoice_number']);
        $this->assertSame(122.0, $row->invoice_total);
        $this->assertSame(122.0, $row['invoice_total']);
    }

    /** Un campo assente vale null, come la proprieta' mancante di un oggetto. */
    public function testCampoAssenteNonSollevaAvvisi(): void
    {
        $row = new DocumentRow(['invoice_number' => 'NC2']);

        $this->assertNull($row->missing);
        $this->assertNull($row['missing']);
        $this->assertSame('', $row['missing'] ?? '');
        $this->assertFalse(isset($row->missing));
        $this->assertFalse(isset($row['missing']));
    }

    public function testWrapAccettaArrayEOggetti(): void
    {
        $this->assertInstanceOf(DocumentRow::class, DocumentRow::wrap(['a' => 1]));
        $this->assertInstanceOf(DocumentRow::class, DocumentRow::wrap((object) ['a' => 1]));
        $this->assertSame(1, DocumentRow::wrap((object) ['a' => 1])->a);
        $this->assertSame(1, DocumentRow::wrap(['a' => 1])['a']);
    }

    public function testWrapEIdempotente(): void
    {
        $row = DocumentRow::wrap(['a' => 1]);

        $this->assertSame($row, DocumentRow::wrap($row));
    }

    /** null e false restano tali: le viste li controllano con if/empty. */
    public function testWrapLasciaPassareCioCheNonEDocumento(): void
    {
        $this->assertNull(DocumentRow::wrap(null));
        $this->assertFalse(DocumentRow::wrap(false));
        $this->assertSame('testo', DocumentRow::wrap('testo'));
        $this->assertSame(0, DocumentRow::wrap(0));
    }

    public function testRigheDelloStessoDocumentoSiLeggonoAlloStessoModo(): void
    {
        $items = array_map([DocumentRow::class, 'wrap'], [
            ['item_name' => 'Consulenza', 'item_total' => 100.0],
            (object) ['item_name' => 'Rimborso', 'item_total' => 22.0],
        ]);

        $this->assertSame('Consulenza', $items[0]->item_name);
        $this->assertSame('Rimborso', $items[1]['item_name']);
        $this->assertSame(122.0, $items[0]->item_total + $items[1]['item_total']);
    }

    public function testToArrayRestituisceIDatiOriginali(): void
    {
        $data = ['quote_number' => 'P-132/2026', 'quote_total' => 122.0];

        $this->assertSame($data, (new DocumentRow($data))->toArray());
    }
}
