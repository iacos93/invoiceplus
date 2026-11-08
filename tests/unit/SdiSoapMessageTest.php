<?php

use App\Controllers\SdiCoopEndpoint;
use App\Libraries\Sdi\SdiSoapMessage;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Messaggi SOAP dello SdI senza accreditamento: buste con base64 inline e
 * in MTOM, file di notifica, risposta a RiceviFattura. Sono la parte che
 * altrimenti si scoprirebbe rotta solo nei test di interoperabilita'.
 */
final class SdiSoapMessageTest extends CIUnitTestCase
{
    private const NOTIFICA_NS = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<types:NotificaScarto xmlns:types="http://www.fatturapa.gov.it/sdi/messaggi/v1.0" versione="1.0">'
        . '<IdentificativoSdI>111</IdentificativoSdI><NomeFile>IT01234567890_00001.xml</NomeFile>'
        . '<ListaErrori><Errore><Codice>00305</Codice><Descrizione>IdFiscaleIVA del CessionarioCommittente non valido</Descrizione></Errore></ListaErrori>'
        . '</types:NotificaScarto>';

    private function inlineEnvelope(string $element, string $ns, array $fields): string
    {
        $inner = '';
        foreach ($fields as $name => $value) {
            $inner .= '<' . $name . '>' . $value . '</' . $name . '>';
        }

        return '<?xml version="1.0"?><S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"><S:Body>'
            . '<t:' . $element . ' xmlns:t="' . $ns . '">' . $inner . '</t:' . $element . '>'
            . '</S:Body></S:Envelope>';
    }

    public function testParsesInlineBase64Notification(): void
    {
        $body = $this->inlineEnvelope('notificaScarto', SdiSoapMessage::NS_TRASMISSIONE, [
            'IdentificativoSdI' => '111',
            'NomeFile'          => 'IT01234567890_00001_NS_001.xml',
            'File'              => base64_encode(self::NOTIFICA_NS),
        ]);

        $parsed = SdiSoapMessage::parse($body, 'text/xml; charset=utf-8');

        $this->assertSame('notificaScarto', $parsed['element']);
        $this->assertSame('NS', SdiSoapMessage::NOTIFICHE[strtolower($parsed['element'])]);
        $this->assertSame(self::NOTIFICA_NS, $parsed['params']['File']);
        $this->assertSame('111', $parsed['params']['IdentificativoSdI']);
    }

    public function testParsesMtomAttachment(): void
    {
        $boundary = 'uuid:abc-123';
        $envelope = '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            . '<ns2:fileSdIConMetadati xmlns:ns2="' . SdiSoapMessage::NS_RICEZIONE . '">'
            . '<IdentificativoSdI>222</IdentificativoSdI><NomeFile>IT09876543210_AB123.xml.p7m</NomeFile>'
            . '<File><xop:Include xmlns:xop="http://www.w3.org/2004/08/xop/include" href="cid:file%40sdi"/></File>'
            . '<NomeFileMetadati>IT09876543210_AB123_MT_001.xml</NomeFileMetadati>'
            . '<Metadati><xop:Include xmlns:xop="http://www.w3.org/2004/08/xop/include" href="cid:meta@sdi"/></Metadati>'
            . '</ns2:fileSdIConMetadati></soap:Body></soap:Envelope>';

        $binary = "\x30\x82\x01\x00binario\r\n\r\nfirmato";
        $body = "--{$boundary}\r\nContent-Type: application/xop+xml; charset=UTF-8\r\nContent-ID: <root@sdi>\r\n\r\n{$envelope}\r\n"
            . "--{$boundary}\r\nContent-Type: application/octet-stream\r\nContent-Transfer-Encoding: binary\r\nContent-ID: <file@sdi>\r\n\r\n{$binary}\r\n"
            . "--{$boundary}\r\nContent-Type: application/octet-stream\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <meta@sdi>\r\n\r\n" . base64_encode('<Metadati/>') . "\r\n"
            . "--{$boundary}--\r\n";

        $parsed = SdiSoapMessage::parse(
            $body,
            'multipart/related; type="application/xop+xml"; boundary="' . $boundary . '"; start="<root@sdi>"'
        );

        $this->assertSame('fileSdIConMetadati', $parsed['element']);
        $this->assertSame($binary, $parsed['params']['File']);
        $this->assertSame('<Metadati/>', $parsed['params']['Metadati']);
        $this->assertSame('IT09876543210_AB123.xml.p7m', $parsed['params']['NomeFile']);
    }

    public function testRejectsGarbage(): void
    {
        $this->assertNull(SdiSoapMessage::parse('non xml', 'text/xml'));
        $this->assertNull(SdiSoapMessage::parse('<a/>', 'text/xml'));
    }

    public function testNotificationFieldsUseOriginalFileName(): void
    {
        $fields = SdiSoapMessage::notificationFields(self::NOTIFICA_NS);

        $this->assertSame('IT01234567890_00001.xml', $fields['nome_file']);
        $this->assertSame('111', $fields['identificativo']);
        $this->assertSame(['00305 IdFiscaleIVA del CessionarioCommittente non valido'], $fields['errori']);
    }

    public function testMessageExplainsCommittenteOutcome(): void
    {
        $base = ['identificativo' => '9', 'esito' => 'EC02', 'errori' => []];

        $this->assertStringContainsString('RIFIUTATA', SdiCoopEndpoint::message('NE', $base));
        $this->assertLessThanOrEqual(255, mb_strlen(SdiCoopEndpoint::message('NS', ['errori' => array_fill(0, 50, str_repeat('x', 40))] + $base)));
    }

    public function testRiceviFileRequestRoundTripsBinary(): void
    {
        $file    = "<?xml version=\"1.0\"?><Fattura>\r\n\r\n--uuid:finto</Fattura>\x00\xff";
        $request = SdiSoapMessage::riceviFileRequest('IT01234567890_00001.xml', $file);
        $parsed  = SdiSoapMessage::parse($request['body'], $request['content_type']);

        $this->assertSame('fileSdIAccoglienza', $parsed['element']);
        $this->assertSame('IT01234567890_00001.xml', $parsed['params']['NomeFile']);
        $this->assertSame($file, $parsed['params']['File']);
    }

    public function testRiceviFileResult(): void
    {
        $risposta = static fn (string $inner): string => '<S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"><S:Body>'
            . '<ns2:rispostaSdIRiceviFile xmlns:ns2="' . SdiSoapMessage::NS_TRASMISSIONE . '">' . $inner
            . '</ns2:rispostaSdIRiceviFile></S:Body></S:Envelope>';

        $ok = SdiSoapMessage::riceviFileResult($risposta('<IdentificativoSdI>42</IdentificativoSdI><DataOraRicezione>2026-11-03T08:00:00</DataOraRicezione>'), 'text/xml');
        $this->assertTrue($ok['ok']);
        $this->assertSame('42', $ok['identificativo']);

        $rifiuto = SdiSoapMessage::riceviFileResult($risposta('<IdentificativoSdI/><Errore>EI03</Errore>'), 'text/xml');
        $this->assertFalse($rifiuto['ok']);
        $this->assertSame('EI03', $rifiuto['errore']);

        $fault = SdiSoapMessage::riceviFileResult(SdiSoapMessage::fault('busta errata'), 'text/xml');
        $this->assertFalse($fault['ok']);
        $this->assertStringContainsString('busta errata', $fault['errore']);

        $this->assertFalse(SdiSoapMessage::riceviFileResult('<html>502</html>', 'text/html')['ok']);
    }

    public function testRiceviFatturaResponseShape(): void
    {
        $doc = new DOMDocument();
        $doc->loadXML(SdiSoapMessage::riceviFatturaResponse());
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('r', SdiSoapMessage::NS_RICEZIONE);

        $this->assertSame('ER01', $xpath->evaluate('string(//r:rispostaRiceviFatture/Esito)'));
    }
}
