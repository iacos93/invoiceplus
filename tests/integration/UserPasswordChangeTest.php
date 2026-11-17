<?php

use App\Models\SettingModel;
use App\Models\UserModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;

/**
 * Cambio password dalla scheda utente: dal POST alla riga.
 *
 * Il caso di scrittura rifiutata è coperto da UserModelSaveChangePasswordTest
 * (update() simulato): lì non serve il database e si vede il valore 0 che il
 * controller ora controlla. Qui serve il database per l'altra metà della
 * promessa — che l'hash salvato sia davvero quello della password nuova, e non
 * la vecchia rimasta al suo posto.
 *
 * Ogni test gira in una transazione chiusa con rollback.
 *
 * @internal
 */
final class UserPasswordChangeTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const DB_COPY = 'invoiceplane_posttest';

    private ?BaseConnection $testDb = null;

    /** @var array<string, mixed> */
    private array $originalTests = [];

    private int $utente = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $config = config('Database');

        $this->originalTests       = $config->tests;
        $config->tests             = $config->default;
        $config->tests['database'] = getenv('IP_PDF_TEST_DB') ?: self::DB_COPY;

        $this->resetConnections();

        try {
            $this->testDb = \Config\Database::connect();
            $this->testDb->query('SELECT 1 FROM ip_users LIMIT 1');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non disponibile: ' . $config->tests['database']);
        }

        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);

        $_SESSION['active_company_id'] = 1;

        $row          = $this->testDb->query('SELECT user_id FROM ip_users ORDER BY user_id LIMIT 1')->getRow();
        $this->utente = (int) ($row->user_id ?? 1);

        $this->testDb->transBegin();
    }

    protected function tearDown(): void
    {
        if ($this->testDb !== null && $this->testDb->transDepth > 0) {
            $this->testDb->transRollback();
        }

        $this->restoreConnections();

        parent::tearDown();
    }

    public function testLaPasswordSalvataÈQuellaNuova(): void
    {
        $prima = $this->hashSalvato();

        $risposta = $this->inviaConToken('/users/change_password/' . $this->utente, [
            'user_password'  => 'CollaudoPassword1!',
            'user_passwordv' => 'CollaudoPassword1!',
        ], '/users/change_password/' . $this->utente);

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Il cambio password deve salvarsi');
        $this->assertStringContainsString('/users/form/' . $this->utente, (string) $risposta->response()->getHeaderLine('Location'));

        $dopo = $this->hashSalvato();

        $this->assertNotSame($prima, $dopo, 'La password non è stata riscritta');
        $this->assertTrue((new UserModel())->checkPassword($dopo, 'CollaudoPassword1!'), "L'hash salvato non verifica la password nuova");
        $this->assertFalse((new UserModel())->checkPassword($prima, 'CollaudoPassword1!'), "L'hash vecchio verifica già la password nuova: il test non sta provando niente");
    }

    /**
     * La conferma sbagliata non deve scrivere né dire che ha scritto.
     */
    public function testLaConfermaDiversaNonCambiaLaPassword(): void
    {
        $prima = $this->hashSalvato();

        $risposta = $this->inviaConToken('/users/change_password/' . $this->utente, [
            'user_password'  => 'CollaudoPassword1!',
            'user_passwordv' => 'CollaudoPassword2!',
        ], '/users/change_password/' . $this->utente);

        $this->assertSame(200, $risposta->response()->getStatusCode(), 'Con la conferma diversa il form deve riaprirsi');
        $this->assertSame($prima, $this->hashSalvato(), 'La password è stata scritta nonostante la conferma diversa');
    }

    private function hashSalvato(): string
    {
        return (string) $this->testDb->query(
            'SELECT user_password FROM ip_users WHERE user_id = ?',
            [$this->utente]
        )->getRow()->user_password;
    }

    /**
     * POST con un token CSRF vero, preso dalla pagina del form come farebbe
     * il browser: il flusso passa dai filtri reali.
     */
    private function inviaConToken(string $url, array $dati, string $paginaModulo): TestResponse
    {
        $pagina = $this->withSession($this->sessione())->get($paginaModulo);
        $html   = (string) $pagina->response()->getBody();

        $token = null;

        foreach ([
            '/name="csrf_test_name"[^>]*value="([^"]+)"/',
            '/value="([^"]+)"[^>]*name="csrf_test_name"/',
        ] as $schema) {
            if (preg_match($schema, $html, $trovato)) {
                $token = $trovato[1];
                break;
            }
        }

        $this->assertNotNull($token, "Nessun token CSRF nella pagina {$paginaModulo}");

        return $this->withSession($this->sessione())
            ->post($url, $dati + ['csrf_test_name' => $token]);
    }

    /** @return array<string, mixed> */
    private function sessione(): array
    {
        return [
            'user_id'           => $this->utente,
            'user_type'         => 1,
            'user_name'         => 'Admin',
            'active_company_id' => 1,
        ];
    }

    private function resetConnections(): void
    {
        $ref = new ReflectionClass(\CodeIgniter\Database\Config::class);

        $ref->getProperty('instances')->setValue(null, []);
        $ref->getProperty('factory')->setValue(null, null);
    }

    private function restoreConnections(): void
    {
        config('Database')->tests = $this->originalTests;

        $this->resetConnections();
    }
}
