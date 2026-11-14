<?php

use App\Libraries\Sdi\SdiSender;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Le parti di SdiSender che non toccano il database: gli esiti mostrati
 * all'utente e il nome del file di trasmissione.
 *
 * Il nome file e' quello che lo SdI usa per riconoscere un invio: se manca il
 * trasmittente o il progressivo si ripete, la fattura viene scartata come
 * duplicata. La parte che avanza il progressivo scrive su ip_settings ed e'
 * coperta dai test d'integrazione.
 *
 * @internal
 */
final class SdiSenderTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper('ip_helpers');
    }

    private function nomeFile(string $xml, string $progressivo): string
    {
        $metodo = new ReflectionMethod(SdiSender::class, 'filenameFromXml');

        return $metodo->invoke(null, $xml, $progressivo);
    }

    public function testIlNomeFileUnisceTrasmittenteEProgressivo(): void
    {
        $xml = '<FatturaElettronicaHeader><DatiTrasmissione><IdTrasmittente>'
             . '<IdPaese>IT</IdPaese><IdCodice>01234567890</IdCodice>'
             . '</IdTrasmittente></DatiTrasmissione></FatturaElettronicaHeader>';

        $this->assertSame('IT01234567890_00042.xml', $this->nomeFile($xml, '00042'));
    }

    public function testIlNomeFileToleraGliSpaziTraITag(): void
    {
        $xml = "<IdTrasmittente>\n  <IdPaese>it</IdPaese>\n  <IdCodice>ABC123</IdCodice>\n</IdTrasmittente>";

        $this->assertSame('itABC123_7.xml', $this->nomeFile($xml, '7'));
    }

    public function testSenzaTrasmittenteIlNomeEVuotoENonInventato(): void
    {
        $this->assertSame('', $this->nomeFile('<FatturaElettronica/>', '1'));
        $this->assertSame('', $this->nomeFile('<IdTrasmittente><IdPaese>IT</IdPaese></IdTrasmittente>', '1'));
    }

    public function testOgniEsitoHaUnaClasseEUnaEtichetta(): void
    {
        $this->assertSame(array_keys(SdiSender::ESITI), array_keys(SdiSender::ESITO_CLASSES), 'Un esito senza classe verrebbe mostrato neutro');

        foreach (SdiSender::ESITI as $esito => $etichetta) {
            $this->assertSame($etichetta, SdiSender::esitoLabel($esito));
            $this->assertContains(SdiSender::esitoClass($esito), ['success', 'danger', 'warning', 'info', 'secondary']);
        }
    }

    public function testLoScartoEPericoloLaConsegnaEVerde(): void
    {
        $this->assertSame('danger', SdiSender::esitoClass('NS'));
        $this->assertSame('success', SdiSender::esitoClass('RC'));
        $this->assertSame('warning', SdiSender::esitoClass('MC'), 'Mancata consegna: da guardare, non un errore');
    }

    public function testEsitoAssenteOSconosciuto(): void
    {
        $this->assertSame('secondary', SdiSender::esitoClass(null));
        $this->assertSame('secondary', SdiSender::esitoClass(''));
        $this->assertSame('secondary', SdiSender::esitoClass('XX'));
        $this->assertSame('XX', SdiSender::esitoLabel('XX'), 'Un esito nuovo si mostra com\'e\', non sparisce');
        $this->assertNotSame('', SdiSender::esitoLabel(null));
    }

    public function testLeEtichetteDeiCanali(): void
    {
        $this->assertSame('PEC', SdiSender::channelLabel('pec'));
        $this->assertSame('altro', SdiSender::channelLabel('altro'));
    }
}
