<?php

use App\Controllers\Sessions;
use CodeIgniter\Cache\Handlers\FileHandler;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * Il tetto ai codici del secondo fattore non deve azzerarsi ripartendo dal
 * login.
 *
 * Quello in sessione (5 tentativi) si azzera a ogni accesso con password
 * riuscito: chi conosce la password poteva ripartire e provare altri cinque
 * codici, senza fine. Il contatore per utente vive nella cache.
 *
 * @internal
 */
final class MfaThrottleTest extends CIUnitTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/mfa-throttle-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->dir, 0777, true);

        $config            = new \Config\Cache();
        $config->handler   = 'file';
        $config->file['storePath'] = $this->dir;
        $config->prefix    = '';

        $handler = new FileHandler($config);
        $handler->initialize();

        Services::injectMock('cache', $handler);
    }

    protected function tearDown(): void
    {
        Services::reset();

        foreach (glob($this->dir . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function chiama(string $metodo, int $userId): mixed
    {
        $m = new ReflectionMethod(Sessions::class, $metodo);

        return $m->invoke(new Sessions(), $userId);
    }

    public function testDopoDieciCodiciSbagliatiL_UtenteEBloccato(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->chiama('registerMfaFailure', 7);
        }
        $this->assertFalse($this->chiama('mfaBlocked', 7), 'Nove errori non bastano');

        $this->chiama('registerMfaFailure', 7);
        $this->assertTrue($this->chiama('mfaBlocked', 7), 'Il decimo blocca');
    }

    public function testIlBloccoNonPassaAdUnAltroUtente(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->chiama('registerMfaFailure', 7);
        }

        $this->assertTrue($this->chiama('mfaBlocked', 7));
        $this->assertFalse($this->chiama('mfaBlocked', 8));
    }

    public function testUnCodiceGiustoAzzeraIlConteggio(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->chiama('registerMfaFailure', 7);
        }

        $this->chiama('clearMfaFailures', 7);

        $this->assertFalse($this->chiama('mfaBlocked', 7));
    }

    public function testUtenteNonValidoNonScriveNienteNeBlocca(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->chiama('registerMfaFailure', 0);
        }

        $this->assertFalse($this->chiama('mfaBlocked', 0));
    }
}
