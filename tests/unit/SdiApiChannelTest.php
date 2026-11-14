<?php

use App\Libraries\Sdi\SdiApiChannel;
use App\Libraries\Sdi\SdiPayloadGuard;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il canale API del trasmittente accetta solo HTTPS e non segue redirect.
 *
 * Le credenziali Basic finiscono nel header della richiesta: su un endpoint
 * http:// viaggerebbero in chiaro, e un redirect verso un altro host le
 * porterebbe fuori dal dominio configurato. La validazione si prova senza
 * rete e senza database, sostituendo solo l'origine dell'endpoint.
 *
 * @internal
 */
final class SdiApiChannelTest extends CIUnitTestCase
{
    public function testUnEndpointHttpVieneRifiutatoPrimaDiQualunqueConnessione(): void
    {
        $channel = new class extends SdiApiChannel {
            protected function endpoint(): string
            {
                return 'http://trasmittente.example.test/invia';
            }
        };

        $result = $channel->send('<FatturaElettronica/>', 'IT01234567890_00001.xml', []);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('https', $result['message']);
        $this->assertStringNotContainsString('trasmittente.example.test', $result['message'], 'L\'URL non deve comparire nel messaggio');
    }

    public function testUnEndpointSenzaSchemaOVuotoVieneRifiutato(): void
    {
        foreach (['ftp://trasmittente.example.test/invia', 'file:///etc/passwd', 'trasmittente.example.test/invia', 'https://'] as $endpoint) {
            $this->assertNotNull(SdiApiChannel::endpointProblem($endpoint), $endpoint . ' non e\' un endpoint https valido');
        }
    }

    public function testUnEndpointHttpsValidoPassa(): void
    {
        $this->assertNull(SdiApiChannel::endpointProblem('https://trasmittente.example.test/invia'));
        $this->assertNull(SdiApiChannel::endpointProblem('https://trasmittente.example.test:8443/invia?x=1'));
    }

    public function testIlMessaggioDiRifiutoNonRipeteLEndpoint(): void
    {
        $problem = SdiApiChannel::endpointProblem('http://trasmittente.example.test/invia');

        $this->assertNotNull($problem);
        $this->assertStringNotContainsString('trasmittente.example.test', $problem);
    }

    public function testRedactProteggeAncheGliErroriDelCanale(): void
    {
        $testo = 'Failed to connect to https://trasmittente.example.test porta 443: timeout (Basic dXNlcjpwYXNz)';

        $pulito = SdiPayloadGuard::redact($testo);

        $this->assertStringNotContainsString('trasmittente.example.test', $pulito);
        $this->assertStringNotContainsString('dXNlcjpwYXNz', $pulito);
    }
}
