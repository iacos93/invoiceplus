<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\Cookie;

/**
 * Forzare l'HTTPS deve rendere "Secure" anche i cookie, sessione compresa.
 *
 * .env.production.example dice che app.forceGlobalSecureRequests attiva
 * "redirect forzato a https e cookie Secure", ma l'attributo non veniva mai
 * impostato: il redirect c'era, il cookie di sessione no, e un browser lo
 * avrebbe spedito anche su una richiesta in chiaro.
 *
 * @internal
 */
final class CookieConfigTest extends CIUnitTestCase
{
    private const KEY = 'app.forceGlobalSecureRequests';

    protected function tearDown(): void
    {
        unset($_SERVER[self::KEY], $_ENV[self::KEY], $_SERVER['cookie.secure'], $_ENV['cookie.secure']);

        parent::tearDown();
    }

    private function cookie(?string $force, ?string $secure = null): Cookie
    {
        unset($_SERVER[self::KEY], $_ENV[self::KEY], $_SERVER['cookie.secure'], $_ENV['cookie.secure']);

        if ($force !== null) {
            $_SERVER[self::KEY] = $force;
        }
        if ($secure !== null) {
            $_SERVER['cookie.secure'] = $secure;
        }

        return new Cookie();
    }

    public function testInSviluppoIlCookieNonEDiSoloHttps(): void
    {
        $this->assertFalse($this->cookie(null)->secure, 'Il server PHP integrato non parla TLS');
        $this->assertFalse($this->cookie('0')->secure);
        $this->assertFalse($this->cookie('false')->secure);
    }

    public function testForzandoHttpsIlCookieDiventaSecure(): void
    {
        $this->assertTrue($this->cookie('1')->secure);
        $this->assertTrue($this->cookie('true')->secure);
    }

    public function testCookieSecureScrittoAManoResta(): void
    {
        $this->assertTrue($this->cookie('0', 'true')->secure, 'cookie.secure in .env non va spento dall\'assenza di forceGlobalSecureRequests');
    }

    public function testGliAltriAttributiRestanoSicuri(): void
    {
        $cookie = $this->cookie(null);

        $this->assertTrue($cookie->httponly, 'Il JavaScript non deve leggere il cookie di sessione');
        $this->assertSame('Lax', $cookie->samesite);
    }
}
