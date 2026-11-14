<?php

use App\Libraries\PublicEndpointThrottle;
use CodeIgniter\Cache\Handlers\FileHandler;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il tetto agli endpoint pubblici (MCP, cron, SDICoop).
 *
 * Si prova la meccanica del contatore senza toccare la cache condivisa: ogni
 * test ha una cache su file usa e getta, cosi' un'esecuzione non lascia
 * blocchi alle successive. Il guard() vero si spegne da solo in testing, ed
 * e' proprio quello che si verifica qui: la suite non deve accumulare stato.
 *
 * @internal
 */
final class PublicEndpointThrottleTest extends CIUnitTestCase
{
    private string $dir = '';

    private FileHandler $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/endpoint-throttle-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->dir, 0777, true);

        $config                    = new \Config\Cache();
        $config->handler           = 'file';
        $config->file['storePath'] = $this->dir;
        $config->prefix            = '';

        $this->cache = new FileHandler($config);
        $this->cache->initialize();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function throttle(): PublicEndpointThrottle
    {
        return new PublicEndpointThrottle($this->cache);
    }

    public function testBloccaSoloDopoIlLimiteDelloScope(): void
    {
        $throttle = $this->throttle();
        $ip       = '203.0.113.7';
        $limite   = PublicEndpointThrottle::limit(PublicEndpointThrottle::SCOPE_MCP);

        for ($i = 0; $i < $limite - 1; $i++) {
            $throttle->hit(PublicEndpointThrottle::SCOPE_MCP, $ip);
        }

        $this->assertFalse($throttle->blocked(PublicEndpointThrottle::SCOPE_MCP, $ip), 'Il limite non e\' ancora raggiunto');

        $throttle->hit(PublicEndpointThrottle::SCOPE_MCP, $ip);

        $this->assertTrue($throttle->blocked(PublicEndpointThrottle::SCOPE_MCP, $ip), 'La richiesta al limite blocca');
        $this->assertSame($limite, $throttle->attempts(PublicEndpointThrottle::SCOPE_MCP, $ip));
    }

    public function testRetryAfterE_LaFinestraDelloScope(): void
    {
        $throttle = $this->throttle();

        $this->assertSame(
            PublicEndpointThrottle::window(PublicEndpointThrottle::SCOPE_CRON),
            $throttle->retryAfter(PublicEndpointThrottle::SCOPE_CRON)
        );
    }

    public function testClearAzzeraIlContatore(): void
    {
        $throttle = $this->throttle();
        $ip       = '203.0.113.8';

        for ($i = 0; $i < 5; $i++) {
            $throttle->hit(PublicEndpointThrottle::SCOPE_SDI, $ip);
        }

        $this->assertSame(5, $throttle->attempts(PublicEndpointThrottle::SCOPE_SDI, $ip));

        $throttle->clear(PublicEndpointThrottle::SCOPE_SDI, $ip);

        $this->assertSame(0, $throttle->attempts(PublicEndpointThrottle::SCOPE_SDI, $ip));
        $this->assertFalse($throttle->blocked(PublicEndpointThrottle::SCOPE_SDI, $ip));
    }

    public function testScopeDiversiNonCondividonoIlContatore(): void
    {
        $throttle = $this->throttle();
        $ip       = '203.0.113.9';

        for ($i = 0; $i < PublicEndpointThrottle::limit(PublicEndpointThrottle::SCOPE_MCP); $i++) {
            $throttle->hit(PublicEndpointThrottle::SCOPE_MCP, $ip);
        }

        $this->assertTrue($throttle->blocked(PublicEndpointThrottle::SCOPE_MCP, $ip));
        $this->assertFalse($throttle->blocked(PublicEndpointThrottle::SCOPE_SDI, $ip), 'Un MCP bloccato non ferma i cron/SdI');
        $this->assertFalse($throttle->blocked(PublicEndpointThrottle::SCOPE_CRON, $ip));
    }

    public function testIpDiversiNonCondividonoIlContatore(): void
    {
        $throttle = $this->throttle();

        for ($i = 0; $i < PublicEndpointThrottle::limit(PublicEndpointThrottle::SCOPE_CRON); $i++) {
            $throttle->hit(PublicEndpointThrottle::SCOPE_CRON, '198.51.100.1');
        }

        $this->assertTrue($throttle->blocked(PublicEndpointThrottle::SCOPE_CRON, '198.51.100.1'));
        $this->assertFalse($throttle->blocked(PublicEndpointThrottle::SCOPE_CRON, '198.51.100.2'));
    }

    public function testLaChiaveInCacheNonContieneScopeNeIp(): void
    {
        $key = PublicEndpointThrottle::key(PublicEndpointThrottle::SCOPE_SDI, '203.0.113.10');

        $this->assertStringNotContainsString('sdi', $key);
        $this->assertStringNotContainsString('203.0.113.10', $key);
        $this->assertStringStartsWith('public_endpoint_', $key);
    }

    public function testInTestingIlThrottleEDisattivato(): void
    {
        $this->assertFalse(PublicEndpointThrottle::enabled(), 'La suite non deve essere fermata dal tetto');

        // Anche con il contatore pieno, guard() lascia passare: i test di
        // integrazione ripetono gli endpoint e la cache su file sopravvive
        // fra un'esecuzione e l'altra.
        $throttle = $this->throttle();
        $ip       = '203.0.113.11';

        for ($i = 0; $i < PublicEndpointThrottle::limit(PublicEndpointThrottle::SCOPE_MCP); $i++) {
            $throttle->hit(PublicEndpointThrottle::SCOPE_MCP, $ip);
        }

        $this->assertNull(PublicEndpointThrottle::guard(PublicEndpointThrottle::SCOPE_MCP, $ip));
    }
}
