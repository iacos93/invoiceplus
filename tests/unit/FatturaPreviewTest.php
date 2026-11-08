<?php

use App\Libraries\FatturaPreview;

/**
 * Estrazione dei campi chiave dalla fattura elettronica.
 *
 * La lettura e' indipendente dal namespace (XPath su local-name()) perche'
 * il tracciato puo' girare con prefissi diversi; qui si verifica che i campi
 * principali vengano fuori anche da un XML minimale, e che un documento
 * malformato o vuoto non sollevi nulla ma torni soltanto campi vuoti.
 *
 * @internal
 */
final class FatturaPreviewTest extends CodeIgniter\Test\CIUnitTestCase
{
    private function xml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<p:FatturaElettronica xmlns:p="http://www.fatturapa.gov.it/schema/fattura/v1.2"
  versione="FPR12">
  <FatturaElettronicaHeader>
    <DatiTrasmissione>
      <ProgressivoInvio>00001</ProgressivoInvio>
      <FormatoTrasmissione>FPR12</FormatoTrasmissione>
    </DatiTrasmissione>
    <CedentePrestatore>
      <DatiAnagrafici>
        <Anagrafica><Denominazione>Rossi Srl</Denominazione></Anagrafica>
      </DatiAnagrafici>
    </CedentePrestatore>
    <CessionarioCommittente>
      <DatiAnagrafici>
        <Anagrafica><Denominazione>Verdi SpA</Denominazione></Anagrafica>
      </DatiAnagrafici>
    </CessionarioCommittente>
  </FatturaElettronicaHeader>
  <FatturaElettronicaBody>
    <DatiGenerali>
      <DatiGeneraliDocumento>
        <TipoDocumento>TD01</TipoDocumento>
        <Numero>2025/0001</Numero>
        <Data>2025-04-10</Data>
        <ImportoTotaleDocumento>122.00</ImportoTotaleDocumento>
      </DatiGeneraliDocumento>
    </DatiGenerali>
    <DatiBeniServizi>
      <DatiRiepilogo>
        <ImponibileImporto>100.00</ImponibileImporto>
        <Imposta>22.00</Imposta>
      </DatiRiepilogo>
    </DatiBeniServizi>
  </FatturaElettronicaBody>
</p:FatturaElettronica>
XML;
    }

    public function testEstrarreCampiChiave(): void
    {
        $s = (new FatturaPreview())->summary($this->xml());

        $this->assertSame('Fattura', $s['tipo']);
        $this->assertSame('2025/0001', $s['numero']);
        $this->assertSame('2025-04-10', $s['data']);
        $this->assertSame('122.00', $s['totale']);
        $this->assertSame('100.00', $s['imponibile']);
        $this->assertSame('22.00', $s['imposta']);
        $this->assertSame('Rossi Srl', $s['cedente']);
        $this->assertSame('Verdi SpA', $s['cessionario']);
        $this->assertSame('00001', $s['progressivo']);
        $this->assertSame('FPR12', $s['formato']);
    }

    public function testXmlVuotoTornaVuoto(): void
    {
        $s = (new FatturaPreview())->summary('');

        $this->assertSame('', $s['numero']);
        $this->assertSame('', $s['totale']);
    }

    public function testXmlMalformatoNonSolleva(): void
    {
        $s = (new FatturaPreview())->summary('<<<non-xml');

        $this->assertSame('', $s['tipo']);
    }
}
