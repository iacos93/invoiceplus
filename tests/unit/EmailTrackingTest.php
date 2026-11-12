<?php

use App\Libraries\EmailTracking;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il pixel di tracciamento e la conversione del corpo in HTML.
 *
 * La libreria non tocca il database in questi percorsi: la si può provare
 * senza una copia dell'installazione. L'invio vero resta una prova manuale
 * (serve un server SMTP), ma qui si verifica che il messaggio non venga
 * rotto proprio nel passaggio al formato HTML.
 *
 * @internal
 */
final class EmailTrackingTest extends CIUnitTestCase
{
    private EmailTracking $tracking;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tracking = new EmailTracking();
    }

    /**
     * Il corpo arriva da una textarea: i caratteri che in HTML hanno un
     * significato (&, <, >) vanno codificati, altrimenti un "prezzo < 10"
     * sparisce o apre un tag, e gli a capo vanno convertiti o le righe
     * diventano un paragrafo unico.
     */
    public function testIlTestoSempliceDiventaHtmlSenzaPerdereLeRighe(): void
    {
        $html = $this->tracking->textToHtml("Gentile cliente,\n\nprezzo < 10 & IVA inclusa");

        $this->assertStringContainsString('Gentile cliente,<br>', $html);
        $this->assertStringContainsString('prezzo &lt; 10 &amp; IVA inclusa', $html);
        $this->assertStringNotContainsString('< 10', $html);
    }

    /**
     * Con un </body> il pixel va prima della chiusura: alcuni client tagliano
     * il contenuto dopo il primo metro di testo, e un pixel a metà messaggio
     * non verrebbe mai richiesto.
     */
    public function testIlPixelVaPrimaDellaChiusuraDelBody(): void
    {
        $html = $this->tracking->appendPixel(
            '<html><body><p>Fattura in allegato</p></body></html>',
            ['track_token' => str_repeat('a', 48)]
        );

        $this->assertStringContainsString('<p>Fattura in allegato</p>', $html);

        $pixel = strpos($html, '<img ');
        $chiusura = stripos($html, '</body>');

        $this->assertNotFalse($pixel, 'Il pixel non è stato aggiunto');
        $this->assertNotFalse($chiusura, 'La chiusura di body è sparita');
        $this->assertLessThan($chiusura, $pixel, 'Il pixel è finito dopo </body>');
        $this->assertStringContainsString('/t/open/' . str_repeat('a', 48) . '.gif', $html);
    }

    /** Senza un body il pixel sta in fondo, non perso nel mezzo. */
    public function testSenzaBodyIlPixelVaInFondo(): void
    {
        $html = $this->tracking->appendPixel('<p>Preventivo</p>', ['track_token' => str_repeat('b', 48)]);

        $this->assertStringStartsWith('<p>Preventivo</p>', $html);
        $this->assertStringContainsString('<img ', $html);
        $this->assertSame(1, substr_count($html, '<img '));
    }

    /**
     * Un track senza token (tracciamento spento o scrittura fallita) non deve
     * aggiungere nulla: l'email parte identica a prima.
     */
    public function testSenzaTokenIlCorpoRestaInvariato(): void
    {
        $this->assertSame('<p>Fattura</p>', $this->tracking->appendPixel('<p>Fattura</p>', []));
        $this->assertSame('<p>Fattura</p>', $this->tracking->appendPixel('<p>Fattura</p>', ['track_token' => '']));
    }
}
