<?php

use App\Libraries\FatturaXml;
use App\Libraries\PurchaseInvoiceImporter;

/**
 * Lettura delle fatture di acquisto: segno per tipo documento, IVA delle
 * autofatture in inversione contabile, buste .p7m dello SdI.
 *
 * Le buste reali dello SdI sono CMS in BER a lunghezza indefinita con il
 * contenuto spezzato in blocchi: qui se ne costruisce una a mano, con un
 * blocco che taglia a metà un tag, perché è esattamente il caso che il
 * vecchio taglio per sottostringa trasformava in XML illeggibile.
 *
 * @internal
 */
final class FatturaXmlTest extends CodeIgniter\Test\CIUnitTestCase
{
    private function xml(string $type, string $imponibile = '263.08', string $imposta = '57.88'): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<p:FatturaElettronica xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2" versione="FPR12">
  <FatturaElettronicaHeader>
    <CedentePrestatore>
      <DatiAnagrafici>
        <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>01234567890</IdCodice></IdFiscaleIVA>
        <Anagrafica><Denominazione>Fornitore Srl</Denominazione></Anagrafica>
      </DatiAnagrafici>
    </CedentePrestatore>
    <CessionarioCommittente>
      <DatiAnagrafici>
        <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>02369560038</IdCodice></IdFiscaleIVA>
        <CodiceFiscale>CVNFBA93T27B019C</CodiceFiscale>
        <Anagrafica><Denominazione>Cliente</Denominazione></Anagrafica>
      </DatiAnagrafici>
    </CessionarioCommittente>
  </FatturaElettronicaHeader>
  <FatturaElettronicaBody>
    <DatiGenerali>
      <DatiGeneraliDocumento>
        <TipoDocumento>{$type}</TipoDocumento>
        <Data>2026-05-07</Data>
        <Numero>NC-1</Numero>
      </DatiGeneraliDocumento>
    </DatiGenerali>
    <DatiBeniServizi>
      <DatiRiepilogo>
        <AliquotaIVA>22.00</AliquotaIVA>
        <ImponibileImporto>{$imponibile}</ImponibileImporto>
        <Imposta>{$imposta}</Imposta>
      </DatiRiepilogo>
    </DatiBeniServizi>
  </FatturaElettronicaBody>
</p:FatturaElettronica>
XML;
    }

    public function testFatturaOrdinariaRestaPositiva(): void
    {
        $parsed = (new FatturaXml())->parse($this->xml('TD01'));

        $this->assertSame('TD01', $parsed['document_type']);
        $this->assertSame(263.08, $parsed['amount']);
        $this->assertSame(320.96, $parsed['total']);
        $this->assertSame('IT02369560038', $parsed['customer_vat']);
        $this->assertSame('CVNFBA93T27B019C', $parsed['customer_tax_code']);
    }

    public function testNotaDiCreditoVaInNegativo(): void
    {
        $parsed = (new FatturaXml())->parse($this->xml('TD04'));

        $this->assertSame(-263.08, $parsed['amount']);
        $this->assertSame(-57.88, $parsed['tax']);
        $this->assertSame(-320.96, $parsed['total']);
    }

    public function testNotaDiCreditoGiaNegativaNonTornaPositiva(): void
    {
        $parsed = (new FatturaXml())->parse($this->xml('TD04', '-100.00', '-22.00'));

        $this->assertSame(-122.0, $parsed['total']);
    }

    public function testAutofatturaReverseChargeNonCaricaIva(): void
    {
        $parsed = (new FatturaXml())->parse($this->xml('TD17', '180.00', '39.60'));

        $this->assertSame(180.0, $parsed['amount']);
        $this->assertSame(0.0, $parsed['tax']);
        $this->assertSame(180.0, $parsed['total']);
    }

    public function testBustaBerIndefinitaABlocchi(): void
    {
        $xml      = "\xEF\xBB\xBF" . $this->xml('TD01');
        $envelope = $this->berEnvelope($xml, 97);
        $path     = tempnam(sys_get_temp_dir(), 'p7m');
        file_put_contents($path, $envelope);

        try {
            $documents = (new FatturaXml())->extract($path, 'fattura.xml.p7m');
        } finally {
            @unlink($path);
        }

        $this->assertCount(1, $documents);
        // Senza BOM e senza gli header ASN.1 dei blocchi in mezzo.
        $this->assertSame($this->xml('TD01'), $documents[0]['xml']);
        $this->assertSame('NC-1', (new FatturaXml())->parse($documents[0]['xml'])['number']);
    }

    public function testNormalizzazionePartitaIva(): void
    {
        $this->assertSame('02369560038', PurchaseInvoiceImporter::normalizeId('IT 02369560038'));
        $this->assertSame('02369560038', PurchaseInvoiceImporter::normalizeId('02369560038'));
        $this->assertSame('CVNFBA93T27B019C', PurchaseInvoiceImporter::normalizeId('cvnfba93t27b019c'));
        // Prefisso estero: resta com'è, non è una partita IVA italiana.
        $this->assertSame('IE3668997OH', PurchaseInvoiceImporter::normalizeId('IE3668997OH'));
    }

    /**
     * Busta CMS SignedData minimale come la produce lo SdI: lunghezze
     * indefinite e OCTET STRING costruita a blocchi di $chunk byte.
     */
    private function berEnvelope(string $content, int $chunk): string
    {
        $len = static function (int $n): string {
            if ($n < 0x80) {
                return chr($n);
            }

            $bytes = ltrim(pack('N', $n), "\x00");

            return chr(0x80 | strlen($bytes)) . $bytes;
        };

        $blocks = '';

        foreach (str_split($content, $chunk) as $piece) {
            $blocks .= "\x04" . $len(strlen($piece)) . $piece;
        }

        $oidData   = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x07\x01";
        $oidSigned = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x07\x02";
        $digestSet = "\x31\x0d\x30\x0b\x06\x09\x60\x86\x48\x01\x65\x03\x04\x02\x01";

        $encap  = "\x30\x80" . $oidData . "\xa0\x80" . "\x24\x80" . $blocks . "\x00\x00" . "\x00\x00" . "\x00\x00";
        $signed = "\x30\x80" . "\x02\x01\x01" . $digestSet . $encap . "\x31\x00" . "\x00\x00";

        return "\x30\x80" . $oidSigned . "\xa0\x80" . $signed . "\x00\x00" . "\x00\x00";
    }

    /**
     * File lotto: più FatturaElettronicaBody sotto la stessa testata. Prima
     * l'import leggeva solo il primo body e perdeva le altre fatture.
     */
    public function testLottoSiDivideInUnaFatturaPerBody(): void
    {
        $single = $this->xml('TD01');
        $body2  = str_replace(
            ['<Numero>NC-1</Numero>', '<ImponibileImporto>263.08</ImponibileImporto>', '<Imposta>57.88</Imposta>'],
            ['<Numero>NC-2</Numero>', '<ImponibileImporto>100.00</ImponibileImporto>', '<Imposta>22.00</Imposta>'],
            substr($single, strpos($single, '<FatturaElettronicaBody>'), strpos($single, '</FatturaElettronicaBody>') + strlen('</FatturaElettronicaBody>') - strpos($single, '<FatturaElettronicaBody>'))
        );
        $lotto = str_replace('</p:FatturaElettronica>', $body2 . "\n</p:FatturaElettronica>", $single);

        $reader = new FatturaXml();
        $pieces = $reader->splitBodies($lotto);

        $this->assertCount(2, $pieces);

        $first  = $reader->parse($pieces[0]);
        $second = $reader->parse($pieces[1]);

        $this->assertSame('NC-1', $first['number']);
        $this->assertSame(320.96, $first['total']);
        $this->assertSame('NC-2', $second['number']);
        $this->assertSame(122.0, $second['total']);
        // Ogni pezzo resta una fattura completa: testata e un solo body.
        $this->assertSame('Fornitore Srl', $second['supplier_name']);
        $this->assertSame(1, substr_count($pieces[1], '<FatturaElettronicaBody>'));
        $this->assertStringContainsString('xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2"', $pieces[1]);
    }

    /** Il caso normale, un body solo, torna identico. */
    public function testFatturaSingolaNonVieneToccata(): void
    {
        $xml = $this->xml('TD01');

        $this->assertSame([$xml], (new FatturaXml())->splitBodies($xml));
    }

    /**
     * Fattura emessa: cliente, righe con sconto e prezzo a più decimali,
     * riferimenti di commessa, pagamento. Gli allegati (PDF base64) vengono
     * ignorati.
     */
    public function testParseIssuedLeggeTuttoIlDocumento(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ns3:FatturaElettronica xmlns:ns3="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2" versione="FPA12">
  <FatturaElettronicaHeader>
    <DatiTrasmissione><CodiceDestinatario>UF5C73</CodiceDestinatario></DatiTrasmissione>
    <CedentePrestatore><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>02369560038</IdCodice></IdFiscaleIVA>
      <Anagrafica><Nome>Fabio</Nome><Cognome>Iacovino</Cognome></Anagrafica></DatiAnagrafici></CedentePrestatore>
    <CessionarioCommittente><DatiAnagrafici><CodiceFiscale>94065690037</CodiceFiscale><Anagrafica><Denominazione>ICS Duca d'Aosta</Denominazione></Anagrafica></DatiAnagrafici>
      <Sede><Indirizzo>B.do D'Azeglio, 1</Indirizzo><CAP>28100</CAP><Comune>Novara</Comune><Provincia>NO</Provincia><Nazione>IT</Nazione></Sede></CessionarioCommittente>
  </FatturaElettronicaHeader>
  <FatturaElettronicaBody>
    <DatiGenerali>
      <DatiGeneraliDocumento><TipoDocumento>TD01</TipoDocumento><Divisa>EUR</Divisa><Data>2026-01-26</Data><Numero>1</Numero></DatiGeneraliDocumento>
      <DatiOrdineAcquisto><IdDocumento>5494296</IdDocumento><Data>2025-07-08</Data><CodiceCIG>B7A29925E8</CodiceCIG></DatiOrdineAcquisto>
    </DatiGenerali>
    <DatiBeniServizi>
      <DettaglioLinee><NumeroLinea>1</NumeroLinea><Descrizione>Assistenza // dettaglio</Descrizione><Quantita>4.00</Quantita><PrezzoUnitario>93.00</PrezzoUnitario>
        <ScontoMaggiorazione><Tipo>SC</Tipo><Percentuale>25.00</Percentuale></ScontoMaggiorazione><PrezzoTotale>279.00</PrezzoTotale><AliquotaIVA>22.00</AliquotaIVA></DettaglioLinee>
      <DettaglioLinee><NumeroLinea>2</NumeroLinea><Descrizione>Scorporo</Descrizione><Quantita>1.00</Quantita><PrezzoUnitario>286.8852459</PrezzoUnitario><PrezzoTotale>286.89</PrezzoTotale><AliquotaIVA>22.00</AliquotaIVA></DettaglioLinee>
      <DatiRiepilogo><AliquotaIVA>22.00</AliquotaIVA><ImponibileImporto>565.89</ImponibileImporto><Imposta>124.49</Imposta><EsigibilitaIVA>S</EsigibilitaIVA></DatiRiepilogo>
    </DatiBeniServizi>
    <DatiPagamento><CondizioniPagamento>TP02</CondizioniPagamento><DettaglioPagamento><ModalitaPagamento>MP05</ModalitaPagamento><DataScadenzaPagamento>2026-02-25</DataScadenzaPagamento><ImportoPagamento>565.89</ImportoPagamento></DettaglioPagamento></DatiPagamento>
    <Allegati><NomeAttachment>f.pdf</NomeAttachment><Attachment>JVBERi0xLjQK</Attachment></Allegati>
  </FatturaElettronicaBody>
</ns3:FatturaElettronica>
XML;

        $doc = (new FatturaXml())->parseIssued($xml);

        $this->assertSame('FPA12', $doc['format']);
        $this->assertSame('02369560038', $doc['supplier']['vat']);
        $this->assertSame("ICS Duca d'Aosta", $doc['customer']['name']);
        $this->assertSame('94065690037', $doc['customer']['tax_code']);
        $this->assertSame('1', $doc['number']);
        $this->assertSame('B7A29925E8', $doc['order']['cig']);
        $this->assertSame('5494296', $doc['order']['id']);
        $this->assertCount(2, $doc['lines']);
        $this->assertSame(25.0, $doc['lines'][0]['discount_percent']);
        $this->assertSame(286.8852459, $doc['lines'][1]['price']);
        $this->assertSame('S', $doc['vat_exigibility']);
        $this->assertSame(690.38, $doc['total']);
        $this->assertSame('MP05', $doc['payment']['mode']);
        $this->assertSame('2026-02-25', $doc['payment']['due_date']);
    }
}
