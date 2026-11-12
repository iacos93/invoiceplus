<?php

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * La rotta del pixel risponde sempre con l'immagine, anche senza sessione.
 *
 * È pubblica per forza: la chiama il programma di posta del destinatario, che
 * non ha un login. Il token usato qui è volutamente invalido, così la rotta
 * non interroga il database e il test vale anche senza la copia dei dati.
 * Un token sconosciuto deve comunque produrre la stessa risposta: un 404
 * direbbe a chi prova token a caso quali esistono.
 *
 * @internal
 */
final class EmailTrackingPixelTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testUnTokenInvalidoRiceveComunqueIlPixel(): void
    {
        $result = $this->get('/t/open/non-un-token.gif');

        $this->assertSame(200, $result->response()->getStatusCode(), 'La rotta del pixel non risponde');
        $this->assertStringStartsWith('image/gif', $result->response()->getHeaderLine('Content-Type'));

        $body = $result->response()->getBody();

        $this->assertStringStartsWith('GIF89a', $body, 'Il corpo non è una GIF');
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
    }

    public function testIlPixelRispondeAncheSenzaEstensione(): void
    {
        // Il percorso è costruito con .gif, ma l'estensione non deve essere
        // obbligatoria: un client che la toglie merita comunque l'immagine.
        $result = $this->get('/t/open/non-un-token');

        $this->assertSame(200, $result->response()->getStatusCode());
        $this->assertStringStartsWith('GIF89a', $result->response()->getBody());
    }
}
