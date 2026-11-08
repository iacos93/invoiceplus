<?php

use App\Libraries\CsvReader;
use App\Libraries\CsvWriter;
use App\Services\ContactExport;
use App\Services\ContactImporter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Giro completo esporta → reimporta delle anagrafiche.
 *
 * Il file che l'applicazione scrive deve poter rientrare: qui si parte dalle
 * intestazioni vere di ContactExport, si compone il CSV con CsvWriter e si
 * controlla che l'import riconosca la colonna del nome. Era il caso rotto —
 * "Nome / Ragione Sociale" e "Fornitore / Ragione Sociale" normalizzate non
 * coincidevano con nessuna chiave del tracciato, quindi il modello scaricato
 * dal pulsante "Scarica template" veniva rifiutato come file senza nome.
 *
 * @internal
 */
final class ContactCsvRoundTripTest extends CIUnitTestCase
{
    public function testIlModelloClientiSiRiconosceInLettura(): void
    {
        $export = new ContactExport();
        $csv    = CsvWriter::toString($export->clientHeaders(), [$export->clientSample()]);

        $rows    = CsvReader::rowsFromString($csv);
        $headers = array_shift($rows);
        $map     = $this->map('mapClientHeaders', $headers);

        $this->assertArrayHasKey('client_name', $map);
        $this->assertNotSame('', (string) ($rows[0][$map['client_name']] ?? ''));
    }

    public function testIlModelloFornitoriSiRiconosceInLettura(): void
    {
        $export = new ContactExport();
        $csv    = CsvWriter::toString($export->supplierHeaders(), [$export->supplierSample()]);

        $rows    = CsvReader::rowsFromString($csv);
        $headers = array_shift($rows);
        $map     = $this->map('mapSupplierHeaders', $headers);

        $this->assertArrayHasKey('supplier_name', $map);
        $this->assertNotSame('', (string) ($rows[0][$map['supplier_name']] ?? ''));
    }

    /** Le colonne e la riga di esempio devono restare lunghe uguali. */
    public function testRigaDiEsempioHaUnaCellaPerColonna(): void
    {
        $export = new ContactExport();

        $this->assertCount(count($export->clientHeaders()), $export->clientSample());
        $this->assertCount(count($export->supplierHeaders()), $export->supplierSample());
    }

    /** Le etichette combinate scritte a mano devono restare riconosciute. */
    public function testRiconosceLeEtichetteCombinate(): void
    {
        foreach (['Nome / Ragione Sociale', 'Cliente', 'Client'] as $label) {
            $map = $this->map('mapClientHeaders', [$label]);

            $this->assertArrayHasKey('client_name', $map, 'Intestazione cliente non riconosciuta: ' . $label);
        }

        foreach (['Fornitore / Ragione Sociale', 'Fornitore', 'Supplier'] as $label) {
            $map = $this->map('mapSupplierHeaders', [$label]);

            $this->assertArrayHasKey('supplier_name', $map, 'Intestazione fornitore non riconosciuta: ' . $label);
        }
    }

    /**
     * La mappa delle intestazioni è privata di proposito — è un dettaglio
     * dell'importatore — ma è anche l'unica cosa da verificare senza database.
     *
     * @param list<string> $headers
     *
     * @return array<string, int>
     */
    private function map(string $method, array $headers): array
    {
        // Senza setAccessible(): da PHP 8.1 invoke() raggiunge anche i metodi
        // privati, e la chiamata emetteva una deprecazione a ogni esecuzione.
        $reflection = new ReflectionMethod(ContactImporter::class, $method);

        return $reflection->invoke(new ContactImporter(), $headers);
    }
}
