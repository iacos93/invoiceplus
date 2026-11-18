<?php

use App\Filters\AuthFilter;
use App\Filters\GuestFilter;
use App\Libraries\SessionUserGuard;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Una sessione aperta da un utente poi eliminato o disattivato deve essere
 * respinta dai filtri auth e guest alla richiesta successiva.
 *
 * @internal
 */
final class SessionUserGuardTest extends CIUnitTestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();
        helper('ip_helpers');
        $this->users = [
            1  => ['user_id' => 1, 'user_type' => 1, 'user_active' => 't'],
            2  => ['user_id' => 2, 'user_type' => 2, 'user_active' => 1],
            3  => ['user_id' => 3, 'user_type' => 1, 'user_active' => 'f'],
        ];
        SessionUserGuard::$loader = fn (int $id): ?array => $this->users[$id] ?? null;
    }

    protected function tearDown(): void
    {
        SessionUserGuard::$loader = null;
        $_SESSION = [];
        parent::tearDown();
    }

    private function runFilter(object $filter, array $session): mixed
    {
        $_SESSION = $session;

        return $filter->before(service('request'));
    }

    public function testAdminEsistenteEAttivoPassa(): void
    {
        $this->assertNull($this->runFilter(new AuthFilter(), ['user_type' => 1, 'user_id' => 1]));
    }

    public function testAdminEliminatoVieneRespintoESessioneDistrutta(): void
    {
        $r = $this->runFilter(new AuthFilter(), ['user_type' => 1, 'user_id' => 11, 'user_name' => 'x']);

        $this->assertInstanceOf(RedirectResponse::class, $r);
        $this->assertStringEndsWith('/sessions/login', $r->getHeaderLine('Location'));
        $this->assertNull(session('user_id'));
    }

    public function testAdminDisattivatoVieneRespinto(): void
    {
        $this->assertInstanceOf(RedirectResponse::class, $this->runFilter(new AuthFilter(), ['user_type' => 1, 'user_id' => 3]));
    }

    public function testCambioDiTipoInDatabaseVieneRespinto(): void
    {
        // Un ex amministratore declassato a cliente non tiene l'area admin.
        $this->assertInstanceOf(RedirectResponse::class, $this->runFilter(new AuthFilter(), ['user_type' => 1, 'user_id' => 2]));
    }

    public function testGuestEsistentePassaEdEliminatoNo(): void
    {
        $this->assertNull($this->runFilter(new GuestFilter(), ['user_type' => 2, 'user_id' => 2]));
        $this->assertInstanceOf(RedirectResponse::class, $this->runFilter(new GuestFilter(), ['user_type' => 2, 'user_id' => 99]));
    }

    public function testSessioneSenzaUserIdVieneRespinta(): void
    {
        $this->assertInstanceOf(RedirectResponse::class, $this->runFilter(new AuthFilter(), ['user_type' => 1]));
    }
}
