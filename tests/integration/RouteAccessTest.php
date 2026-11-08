<?php

use App\Models\SettingModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Le route che il cliente vede davvero: la Panoramica per un utente
 * amministratore e il link pubblico di un preventivo, senza login.
 *
 * I test unitari coprono i servizi, ma non dicono se una pagina si apre: un
 * filtro, una variabile mancante o un template rotto si vedono solo passando
 * dalla richiesta completa. Questi tre casi sono il minimo che deve restare
 * verde.
 *
 * @internal
 */
final class RouteAccessTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const DB_COPY = 'invoiceplane_posttest';

    private ?\CodeIgniter\Database\BaseConnection $testDb = null;

    /** @var array<string, mixed> */
    private array $originalTests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $config = config('Database');

        $this->originalTests = $config->tests;
        $config->tests = $config->default;
        $config->tests['database'] = getenv('IP_PDF_TEST_DB') ?: self::DB_COPY;

        $this->resetConnections();

        try {
            $this->testDb = \Config\Database::connect();
            $this->testDb->query('SELECT 1 FROM ip_invoices LIMIT 1');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non disponibile');
        }

        $prop = (new ReflectionClass(SettingModel::class))->getProperty('settingsCache');
        $prop->setValue(null, null);

        $_SESSION['active_company_id'] = 1;
    }

    protected function tearDown(): void
    {
        $this->restoreConnections();

        parent::tearDown();
    }

    public function testLaPanoramicaSiAprePerUnAmministratore(): void
    {
        $result = $this->withSession([
            'user_id'           => 1,
            'user_type'         => 1,
            'user_name'         => 'Admin',
            'active_company_id' => 1,
        ])->get('/dashboard');

        $result->assertStatus(200);
        $result->assertSee(trans('dashboard'));
    }

    public function testIlLinkPubblicoDelPreventivoMostraIlDocumento(): void
    {
        $quote = $this->preventivoPubblico();

        $result = $this->get('/guest/view/quote/' . $quote['quote_url_key']);

        $result->assertStatus(200);
        $result->assertSee((string) $quote['quote_number']);
    }

    public function testUnaChiaveUrlSconosciutaDaPaginaNonTrovata(): void
    {
        // In ambiente di test il framework rilancia l'eccezione invece di
        // consegnare la pagina 404: è la stessa strada che in produzione
        // produce lo stato 404.
        $this->expectException(PageNotFoundException::class);

        $this->get('/guest/view/quote/chiave-che-non-esiste');
    }

    private function preventivoPubblico(): array
    {
        $row = $this->testDb->query(
            "SELECT quote_id, quote_number, quote_url_key
             FROM ip_quotes
             WHERE quote_url_key <> '' AND quote_url_key IS NOT NULL
               AND quote_status_id IN (2, 3, 4, 5)
               AND (quote_password IS NULL OR quote_password = '')
             ORDER BY quote_id LIMIT 1"
        )->getRowArray();

        if (!$row) {
            $this->markTestSkipped('Serve un preventivo pubblico senza password nella copia');
        }

        return $row;
    }

    /**
     * Svuota la cache statica delle connessioni: senza, quella già aperta
     * verrebbe riusata al posto della copia.
     */
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
