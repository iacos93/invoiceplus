<?php

use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * La pagina "Stato del sistema" si apre e riporta tutti i controlli.
 *
 * Non si verificano gli esiti (in un ambiente di test un backup mancante è
 * normale): conta che ognuno dei sei controlli venga eseguito senza far
 * fallire la pagina, perché è la pagina che deve dire cosa non va.
 *
 * @internal
 */
final class SystemStatusPageTest extends CIUnitTestCase
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
            $this->testDb->query('SELECT 1 FROM ip_settings LIMIT 1');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non disponibile: ' . $config->tests['database']);
        }

        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);

        $_SESSION['active_company_id'] = 1;

        $row          = $this->testDb->query('SELECT user_id FROM ip_users ORDER BY user_id LIMIT 1')->getRow();
        $this->utente = (int) ($row->user_id ?? 1);
    }

    protected function tearDown(): void
    {
        $this->restoreConnections();

        parent::tearDown();
    }

    public function testLaPaginaRiportaTuttiIControlli(): void
    {
        $risposta = $this->withSession($this->sessione())->get('/system/status');

        $this->assertSame(200, $risposta->response()->getStatusCode(), 'Stato del sistema: la pagina deve aprirsi');

        $html = (string) $risposta->response()->getBody();

        foreach ([
            'Versione di PHP',
            'Cartelle scrivibili',
            'Database',
            'Migrazioni',
            'Backup del database',
            'Automazioni',
        ] as $controllo) {
            $this->assertStringContainsString($controllo, $html, 'Manca il controllo: ' . $controllo);
        }

        $this->assertGreaterThanOrEqual(
            6,
            substr_count($html, 'badge text-bg-'),
            'Ogni controllo deve avere il suo stato, non solo l\'etichetta'
        );
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
