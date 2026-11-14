<?php

use App\Libraries\FatturaXml;
use App\Libraries\Sdi\SdiPayloadGuard;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * I limiti di sicurezza di FatturaXml: file singolo e archivio ZIP.
 *
 * Un pacchetto di fatture e' un file che arriva da fuori (upload, endpoint
 * SDICoop): numero di voci, dimensione decompressa e rapporto di compressione
 * vanno controllati PRIMA di decomprimere, altrimenti poche decine di KB
 * bastano a esaurire la memoria. Qui si verifica che il rifiuto sia esplicito
 * (lastError) e che non resti nessun documento da importare.
 *
 * @internal
 */
final class FatturaXmlLimitsTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $file = [];

    protected function tearDown(): void
    {
        foreach ($this->file as $path) {
            @unlink($path);
        }

        $this->file = [];

        parent::tearDown();
    }

    /** @param array<string, string> $voci */
    private function zip(array $voci): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sdi-limit') . '.zip';
        $this->file[] = $path;

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        foreach ($voci as $nome => $contenuto) {
            $zip->addFromString($nome, $contenuto);
        }

        $zip->close();

        return $path;
    }

    private function fattura(string $numero): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<p:FatturaElettronica xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2" versione="FPR12">'
            . '<FatturaElettronicaHeader><CedentePrestatore><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>09876543210</IdCodice></IdFiscaleIVA>'
            . '<Anagrafica><Denominazione>Fornitore</Denominazione></Anagrafica></DatiAnagrafici></CedentePrestatore>'
            . '<CessionarioCommittente><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>01234567890</IdCodice></IdFiscaleIVA>'
            . '<Anagrafica><Denominazione>Committente</Denominazione></Anagrafica></DatiAnagrafici></CessionarioCommittente></FatturaElettronicaHeader>'
            . '<FatturaElettronicaBody><DatiGenerali><DatiGeneraliDocumento><TipoDocumento>TD01</TipoDocumento><Data>2026-05-07</Data><Numero>'
            . $numero . '</Numero></DatiGeneraliDocumento></DatiGenerali><DatiBeniServizi><DatiRiepilogo><AliquotaIVA>22.00</AliquotaIVA>'
            . '<ImponibileImporto>100.00</ImponibileImporto><Imposta>22.00</Imposta></DatiRiepilogo></DatiBeniServizi></FatturaElettronicaBody>'
            . '</p:FatturaElettronica>';
    }

    public function testUnoZipConTroppeVociVieneRifiutatoConIlMotivo(): void
    {
        $voci = [];

        for ($i = 0; $i <= SdiPayloadGuard::MAX_ZIP_ENTRIES; $i++) {
            $voci['IT01234567890_' . $i . '.xml'] = $this->fattura('L-' . $i);
        }

        $lettore = new FatturaXml();
        $trovate = $lettore->extract($this->zip($voci), 'lotto.zip');

        $this->assertSame([], $trovate, 'Nessuna voce deve essere estratta');
        $this->assertNotNull($lettore->lastError());
        $this->assertStringContainsString('voci', $lettore->lastError());
    }

    public function testUnaZipBombVieneRifiutataPrimaDiEstrarre(): void
    {
        $lettore = new FatturaXml();
        $trovate = $lettore->extract(
            $this->zip([
                'normale.xml' => $this->fattura('OK-1'),
                'bomba.xml'   => str_repeat("\0", 2 * 1024 * 1024),
            ]),
            'sospetto.zip'
        );

        $this->assertSame([], $trovate, 'L\'archivio si rifiuta in blocco: non si estrae la parte "normale"');
        $this->assertNotNull($lettore->lastError());
        $this->assertStringContainsString('zip bomb', $lettore->lastError());
    }

    public function testUnXmlOltreIlTettoNonVieneLetto(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sdi-xml');
        $this->file[] = $path;

        // File oltre il limite: non deve arrivare a file_get_contents ne' al
        // parser; basta la dimensione.
        file_put_contents($path, str_repeat(' ', SdiPayloadGuard::MAX_XML_BYTES + 1));

        $lettore = new FatturaXml();
        $trovate = $lettore->extract($path, 'IT01234567890_ENORME.xml');

        $this->assertSame([], $trovate);
        $this->assertSame('File XML oltre il limite di 10 MB', $lettore->lastError());
    }

    public function testUnPacchettoNormaleNonLasciaErrori(): void
    {
        $lettore = new FatturaXml();
        $trovate = $lettore->extract(
            $this->zip([
                'IT01234567890_00001.xml' => $this->fattura('N-1'),
                'elenco.csv'              => "id;numero\n1;N-1\n",
            ]),
            'pacchetto.zip'
        );

        $this->assertCount(1, $trovate);
        $this->assertNull($lettore->lastError(), 'Un pacchetto regolare non deve lasciare un errore');
    }
}
