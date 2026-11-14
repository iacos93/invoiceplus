<?php

namespace Tests\Unit;

use App\Libraries\Sdi\SdiCoopChannel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il canale SDICoop deve accettare solo endpoint https.
 *
 * La chiamata porta il certificato client del trasmittente e il file della
 * fattura: su un endpoint http:// finirebbero in chiaro, e schemi come file://
 * o ftp:// non devono mai essere aperti da cURL. La validazione si prova senza
 * rete e senza database.
 *
 * @internal
 */
final class SdiCoopEndpointTest extends CIUnitTestCase
{
    public function testUnEndpointHttpsValidoPassa(): void
    {
        $this->assertNull(SdiCoopChannel::endpointProblem('https://sdi.example.it/ricevi'));
        $this->assertNull(SdiCoopChannel::endpointProblem('https://sdi.example.it:8443/ricevi?x=1'));
    }

    public function testUnEndpointHttpVieneRifiutato(): void
    {
        $problem = SdiCoopChannel::endpointProblem('http://sdi.example.it/ricevi');

        $this->assertNotNull($problem);
        $this->assertStringContainsString('https', $problem);
        $this->assertStringNotContainsString('sdi.example.it', $problem, 'L\'URL non deve comparire nel messaggio');
    }

    public function testUnEndpointVuotoVieneRifiutato(): void
    {
        $problem = SdiCoopChannel::endpointProblem('');

        $this->assertNotNull($problem);
        $this->assertStringContainsString('Endpoint SdIRiceviFile', $problem);
        $this->assertStringContainsString('Impostazioni > SdI > SDICoop', $problem);
    }

    public function testUnEndpointMalformatoOSenzaSchemaVieneRifiutato(): void
    {
        foreach (['sdi.example.it/ricevi', 'file:///etc/passwd', 'https://', 'http://'] as $endpoint) {
            $this->assertNotNull(SdiCoopChannel::endpointProblem($endpoint), $endpoint . ' non e\' un endpoint https valido');
        }
    }
}
