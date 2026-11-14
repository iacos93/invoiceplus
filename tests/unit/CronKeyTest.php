<?php

use App\Controllers\Cron;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Da dove viene la cron key: header prima del path.
 *
 * L'URL segreto finisce nei log di accesso del webserver e nella cronologia
 * dei runner; gli header no. Il path resta accettato per i cron gia'
 * installati, ma se c'e' un header valido deve vincere lui.
 *
 * @internal
 */
final class CronKeyTest extends CIUnitTestCase
{
    public function testHeaderXDedicatedPreferitoAlPath(): void
    {
        $this->assertSame(
            'chiave-header',
            Cron::keyFromHeaders(['X-Cron-Key' => 'chiave-header'], 'chiave-path')
        );
    }

    public function testHeaderVinceAncheSuAuthorization(): void
    {
        $this->assertSame(
            'chiave-header',
            Cron::keyFromHeaders([
                'X-Cron-Key'    => 'chiave-header',
                'Authorization' => 'Bearer chiave-bearer',
            ], 'chiave-path')
        );
    }

    public function testAuthorizationBearerUsatoSeMancaXCronKey(): void
    {
        $this->assertSame(
            'chiave-bearer',
            Cron::keyFromHeaders(['Authorization' => 'Bearer chiave-bearer'], 'chiave-path')
        );
    }

    public function testBearerConSpaziEAltroCaso(): void
    {
        $this->assertSame(
            'chiave-bearer',
            Cron::keyFromHeaders(['authorization' => 'bearer   chiave-bearer  '], 'chiave-path')
        );
    }

    public function testRipiegaSulPathSenzaHeader(): void
    {
        $this->assertSame('chiave-path', Cron::keyFromHeaders([], 'chiave-path'));
        $this->assertSame('chiave-path', Cron::keyFromHeaders(['X-Cron-Key' => ''], 'chiave-path'));
    }

    public function testAuthorizationNonBearerNonVieneUsata(): void
    {
        $this->assertSame(
            'chiave-path',
            Cron::keyFromHeaders(['Authorization' => 'Basic dXNlcjpwYXNz'], 'chiave-path')
        );
    }

    public function testNomiHeaderSonoCaseInsensitiveETagliati(): void
    {
        $this->assertSame(
            'chiave-header',
            Cron::keyFromHeaders(['  X-CRON-KEY  ' => '  chiave-header  '])
        );
        $this->assertSame(
            'chiave-bearer',
            Cron::keyFromHeaders(['AUTHORIZATION' => 'Bearer chiave-bearer'])
        );
    }

    public function testSenzaNessunaChiaveTornaStringaVuota(): void
    {
        $this->assertSame('', Cron::keyFromHeaders([]));
    }
}
