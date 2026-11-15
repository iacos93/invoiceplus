<?php

use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Il logo dell'accesso servito come file, non come data URI.
 *
 * La pagina di login è pubblica: la rotta deve rispondere senza sessione e non
 * deve poter servire un file scelto da chi chiama (il nome viene dalle
 * impostazioni, non dall'URL).
 *
 * Gira sulla copia del database, in transazione: l'impostazione provvisoria non
 * resta. Stessa infrastruttura di McpKeyRevealTest.
 *
 * @internal
 */
final class MediaLogoTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const DB_COPY = 'invoiceplane_posttest';

    // $testDb e non $db: CIUnitTestCase dichiara già $db senza tipo.
    private ?BaseConnection $testDb = null;

    /** @var array<string, mixed> */
    private array $originalTests = [];

    private string $file = '';

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
            $this->testDb->query('SELECT count(*) AS n FROM ip_settings');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non disponibile: ' . $config->tests['database']);
        }

        $this->testDb->transBegin();

        $this->file = 'logo-test-' . uniqid('', true) . '.svg';

        file_put_contents(
            WRITEPATH . 'uploads/' . $this->file,
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"></svg>'
        );

        $this->setLogo($this->file);
    }

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file(WRITEPATH . 'uploads/' . $this->file)) {
            unlink(WRITEPATH . 'uploads/' . $this->file);
        }

        if ($this->testDb !== null) {
            $this->testDb->transRollback();
            $this->testDb->close();
        }

        config('Database')->tests = $this->originalTests;
        $this->resetConnections();

        parent::tearDown();
    }

    private function resetConnections(): void
    {
        $ref = new ReflectionClass(\CodeIgniter\Database\Config::class);

        $ref->getProperty('instances')->setValue(null, []);
        $ref->getProperty('factory')->setValue(null, null);
    }

    private function setLogo(string $file): void
    {
        (new SettingModel())->setSetting('login_logo', $file);

        // La cache statica è piatta e per richiesta: il test la scrive in
        // processo, la rotta la rilegge dallo stesso.
        SettingModel::resetCache();
    }

    public function testUrlDelLogoPortaLaVersioneDelFile(): void
    {
        $url = logo_url('login');

        $this->assertStringContainsString('logo/login.svg', $url);
        $this->assertStringContainsString('?v=' . filemtime(WRITEPATH . 'uploads/' . $this->file), $url);
    }

    public function testServeIlLogoCaricato(): void
    {
        $result = $this->get('logo/login.svg');

        $result->assertOK();
        $this->assertSame('image/svg+xml', $result->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('<svg', $result->getBody() ?? '');
        $this->assertStringContainsString('immutable', $result->response()->getHeaderLine('Cache-Control'));
    }

    public function testConEtagRestituisceNonModificato(): void
    {
        $prima = $this->get('logo/login.svg');
        $etag  = $prima->response()->getHeaderLine('ETag');

        $this->assertNotSame('', $etag);

        $this->withHeaders(['If-None-Match' => $etag])->get('logo/login.svg')->assertStatus(304);
    }

    public function testVarianteNonPrevista(): void
    {
        // Negli altri test l'eccezione diventa una pagina 404; qui il client di
        // test la rilancia, quindi si aspetta quella.
        $this->expectException(PageNotFoundException::class);

        $this->get('logo/passwords.svg');
    }

    public function testSenzaLogoCaricato(): void
    {
        $this->setLogo('');

        $this->expectException(PageNotFoundException::class);

        $this->get('logo/login.svg');
    }

    public function testTentativoDiPercorsoNonEsceDallaCartella(): void
    {
        // Il nome non arriva dall'URL, ma l'impostazione è pur sempre un nome:
        // un percorso relativo non deve servire un file fuori da uploads.
        $this->setLogo('../../index.php');

        $this->expectException(PageNotFoundException::class);

        $this->get('logo/login.php');
    }
}
