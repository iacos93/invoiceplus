<?php

use App\Libraries\FatturaXml;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il pacchetto che si scarica dall'area riservata dell'Agenzia delle Entrate.
 *
 * Non contiene solo fatture: ci sono l'elenco in CSV, i file dei metadati e, se
 * il pacchetto passa da un Mac, le cartelle di sistema. Importarlo deve
 * produrre le fatture e basta, senza errori per tutto il resto, perche' e' il
 * modo normale di portare le fatture ricevute dentro l'applicazione quando la
 * ricezione passa dall'AdE e non da un canale proprio.
 *
 * @internal
 */
final class FatturaXmlZipTest extends CIUnitTestCase
{
    private string $zip = '';

    protected function tearDown(): void
    {
        if ($this->zip !== '') {
            @unlink($this->zip);
        }

        parent::tearDown();
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

    /** @param array<string, string> $voci nome => contenuto */
    private function pacchetto(array $voci): string
    {
        $this->zip = tempnam(sys_get_temp_dir(), 'ade') . '.zip';

        $zip = new ZipArchive();
        $zip->open($this->zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($voci as $nome => $contenuto) {
            str_ends_with($nome, '/') ? $zip->addEmptyDir(rtrim($nome, '/')) : $zip->addFromString($nome, $contenuto);
        }

        $zip->close();

        return $this->zip;
    }

    public function testDalPacchettoEsconoSoloLeFatture(): void
    {
        $path = $this->pacchetto([
            'Fatture/'                                  => '',
            'Fatture/IT09876543210_AAAAA.xml'           => $this->fattura('A-1'),
            'Fatture/IT09876543210_AAAAA_metadato.xml'  => '<?xml version="1.0"?><Metadati><IdentificativoSdI>1</IdentificativoSdI></Metadati>',
            'IT09876543210_BBBBB.xml'                   => $this->fattura('B-1'),
            'elenco_fatture.csv'                        => "id;numero\n1;A-1\n",
            '__MACOSX/Fatture/._IT09876543210_AAAAA.xml' => "\x00\x05\x16\x07",
            '.DS_Store'                                 => "\x00\x00\x00\x01Bud1",
        ]);

        $trovate = (new FatturaXml())->extract($path, 'pacchetto.zip');

        $this->assertSame(
            ['IT09876543210_AAAAA.xml', 'IT09876543210_BBBBB.xml'],
            array_column($trovate, 'name'),
            'Restano le due fatture, con il solo nome del file'
        );
        $this->assertStringContainsString('<Numero>A-1</Numero>', $trovate[0]['xml']);
    }

    public function testUnPacchettoSenzaFattureDaListaVuota(): void
    {
        $path = $this->pacchetto(['elenco.csv' => "id;numero\n", 'nota.txt' => 'niente']);

        $this->assertSame([], (new FatturaXml())->extract($path, 'vuoto.zip'));
    }

    public function testUnaFatturaSolaNonSiTocca(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ade') . '.xml';
        file_put_contents($file, $this->fattura('S-1'));

        try {
            $trovate = (new FatturaXml())->extract($file, 'IT09876543210_SOLA.xml');
        } finally {
            @unlink($file);
        }

        $this->assertSame(['IT09876543210_SOLA.xml'], array_column($trovate, 'name'));
    }
}
