<?php

use App\Libraries\FatturaXml;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * I messaggi di libxml, tradotti.
 *
 * Sulla pagina di verifica arrivavano in inglese e con la forma che dice il
 * contrario di quello che serve: "Element 'Nazione': This element is not
 * expected. Expected is ( Indirizzo )" parla dell'elemento presente e non di
 * quello mancante, che è l'unica informazione utile.
 *
 * @internal
 */
final class FatturaXmlMessagesTest extends CIUnitTestCase
{
    private function traduci(string $message): string
    {
        $metodo = new ReflectionMethod(FatturaXml::class, 'italianMessage');

        return (string) $metodo->invoke(null, $message);
    }

    public function testElementoNonAttesoConPrevisto(): void
    {
        $this->assertSame(
            "Elemento 'Nazione' non atteso: qui è previsto Indirizzo.",
            $this->traduci("Element 'Nazione': This element is not expected. Expected is ( Indirizzo ).")
        );
    }

    public function testElementoNonAttesoDaSolo(): void
    {
        $this->assertSame(
            "Elemento 'Foo' non atteso.",
            $this->traduci("Element 'Foo': This element is not expected.")
        );
    }

    public function testElementoObbligatorioMancante(): void
    {
        $this->assertSame(
            "All'elemento 'FatturaElettronica' manca FatturaElettronicaHeader.",
            $this->traduci("Element 'FatturaElettronica': Missing child element(s). Expected is ( FatturaElettronicaHeader ).")
        );
    }

    public function testValoreFuoriFormato(): void
    {
        // libxml antepone la sfaccettatura che ha fallito.
        $this->assertSame(
            "Il valore 'IT123' non rispetta il formato di [0-9]{11}.",
            $this->traduci("[facet 'pattern'] The value 'IT123' is not accepted by the pattern '[0-9]{11}'.")
        );
    }

    public function testValoreFuoriInsieme(): void
    {
        $this->assertSame(
            "Il valore 'XX' non è tra quelli ammessi.",
            $this->traduci("The value 'XX' is not an element of the set { IT, FR }.")
        );
    }

    public function testAttributoObbligatorioMancante(): void
    {
        $this->assertSame(
            "All'elemento 'FatturaElettronica' manca l'attributo 'versione'.",
            $this->traduci("Element 'FatturaElettronica': The attribute 'versione' is required but missing.")
        );
    }

    public function testFormaNonRiconosciutaRestaComE(): void
    {
        // Meglio l'originale di una traduzione inventata.
        $this->assertSame(
            'Qualcosa che libxml non dice.',
            $this->traduci('Qualcosa che libxml non dice.')
        );
    }

    public function testLaValidazioneRestituisceMessaggiItaliani(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<p:FatturaElettronica versione="FPR12" xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2">'
            . '<Foo/>'
            . '</p:FatturaElettronica>';

        $result = (new FatturaXml())->validate($xml);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('non atteso', implode(' | ', $result['errors']));
        $this->assertStringNotContainsString('is not expected', implode(' | ', $result['errors']));
    }
}
