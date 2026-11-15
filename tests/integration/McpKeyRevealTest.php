<?php

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Il pulsante Copia della chiave MCP deve copiare una chiave vera.
 *
 * La chiave non sta nell'HTML (è un bearer token: nel sorgente della pagina
 * sarebbe un segreto regalato), quindi dopo aver chiuso la pagina il campo è
 * vuoto. Prima l'unico modo per riaverla era rigenerarla, che sconnette tutti
 * i client già configurati: con la chiave in .env, poi, il pannello diceva
 * perfino "nessuna chiave configurata" mentre l'integrazione era attiva.
 *
 * Qui si prova il giro completo: generazione, cifratura in banca dati e
 * rivelazione — quella che alimenta il pulsante. La chiave rivelata deve
 * essere esattamente quella che il server /mcp confronta, .env compreso.
 *
 * Gira sulla copia del database, in transazione: la chiave generata non resta.
 *
 * @internal
 */
final class McpKeyRevealTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const DB_COPY = 'invoiceplane_posttest';

    // $testDb e non $db: CIUnitTestCase dichiara già $db senza tipo.
    private ?BaseConnection $testDb = null;

    /** @var array<string, mixed> */
    private array $originalTests = [];

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
    }

    protected function tearDown(): void
    {
        if ($this->testDb !== null && $this->testDb->transDepth > 0) {
            $this->testDb->transRollback();
        }

        $this->restoreConnections();

        parent::tearDown();
    }

    public function testLaChiaveGenerataSiSalvaCifrata(): void
    {
        $risposta = $this->inviaPost('/mcp/ajax/get_key');

        $this->assertTrue($risposta['json']['success'] ?? false);
        $this->assertNotSame('', (string) ($risposta['json']['key'] ?? ''));

        $salvata = (string) $this->testDb->query(
            "SELECT setting_value FROM ip_settings WHERE setting_key = 'mcp_key'"
        )->getRow()->setting_value;

        $this->assertStringStartsWith('enc2:', $salvata, 'In banca dati la chiave è cifrata');
        $this->assertSame($risposta['json']['key'], decrypt_secret($salvata));
    }

    public function testLaCopiaRestituisceLaChiaveCheIlServerAccettaDavvero(): void
    {
        $generata = $this->inviaPost('/mcp/ajax/get_key');

        $rivelata = $this->inviaPost('/mcp/ajax/reveal_key', $generata['json']['token'] ?? null);

        $this->assertTrue($rivelata['json']['success'] ?? false);

        // Ciò che il pulsante copia deve essere ciò che /mcp confronta: la
        // chiave di .env quando c'è, altrimenti quella cifrata nelle
        // impostazioni. Se qui si copiasse una cosa e il server ne aspettasse
        // un'altra, il client configurato non si collegherebbe.
        $attesa = trim((string) env('mcp.key', ''));

        if ($attesa === '') {
            $attesa = decrypt_secret((string) get_setting('mcp_key', ''));
        }

        $this->assertNotSame('', $attesa, 'Serve una chiave configurata per provare la copia');
        $this->assertSame($attesa, $rivelata['json']['key'] ?? null);
    }

    /**
     * Le impostazioni della pagina devono essere quelle della copia su cui gira
     * il test, non quelle del database di lavoro conservate nella cache
     * condivisa. Prima questo non si poteva verificare: qualunque valore
     * scritto qui in transazione restava invisibile alla pagina.
     */
    public function testLaPaginaLeggeLeImpostazioniDellaCopiaDiProva(): void
    {
        $this->testDb->query("DELETE FROM ip_settings WHERE setting_key = 'mcp_key'");
        \App\Models\SettingModel::resetCache();

        $senzaChiave = $this->apriPaginaChiave();

        // Se la chiave è in .env l'integrazione è attiva comunque: l'isolamento
        // si vede nella direzione opposta, cioè quando la si scrive nel database.
        if (trim((string) env('mcp.key', '')) === '') {
            $this->assertStringNotContainsString(
                'premi Copia per mostrarla e copiarla',
                $senzaChiave,
                'Senza chiave nel database e in .env il pannello non deve dire configurata'
            );
        }

        (new \App\Models\SettingModel())->setSetting('mcp_key', encrypt_secret('CHIAVE-DI-PROVA'));

        $this->assertStringContainsString(
            'premi Copia per mostrarla e copiarla',
            $this->apriPaginaChiave(),
            'La chiave scritta nella copia deve comparire nella pagina: è l\'impostazione di questo database'
        );
    }

    public function testSenzaChiaveConfigurataNonSiCopiaNiente(): void
    {
        $this->testDb->query("DELETE FROM ip_settings WHERE setting_key = 'mcp_key'");

        $rivelata = $this->inviaPost('/mcp/ajax/reveal_key');

        // Con la chiave in .env l'integrazione è comunque configurata: si copia
        // quella. Senza, il campo non deve contenere nulla di inventato.
        $envKey = trim((string) env('mcp.key', ''));

        if ($envKey === '') {
            $this->assertArrayNotHasKey('key', $rivelata['json'] ?? []);
            $this->assertFalse($rivelata['json']['success'] ?? true);
        } else {
            $this->assertSame($envKey, $rivelata['json']['key'] ?? null);
        }
    }

    // -----------------------------------------------------------------
    // Aiuti
    // -----------------------------------------------------------------

    /** La pagina della chiave, con il testo decodificato come lo vede il browser. */
    private function apriPaginaChiave(): string
    {
        $html = (string) $this->withSession($this->sessione())
            ->get('/mcp/settings')
            ->response()
            ->getBody();

        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * POST con un token CSRF vero preso dalla pagina, come farebbe il browser.
     *
     * @return array{json: array<string, mixed>}
     */
    private function inviaPost(string $url, ?string $token = null): array
    {
        if ($token === null) {
            $html = (string) $this->withSession($this->sessione())
                ->get('/mcp/settings')
                ->response()
                ->getBody();

            $this->assertSame(
                1,
                preg_match('/name="csrf_test_name"[^>]*value="([^"]+)"/', $html, $trovato),
                'Nessun token CSRF nella pagina della chiave'
            );

            $token = $trovato[1];
        }

        $risposta = $this->withSession($this->sessione())->post($url, ['csrf_test_name' => $token]);

        return ['json' => json_decode((string) $risposta->response()->getBody(), true) ?: []];
    }

    /** @return array<string, mixed> */
    private function sessione(): array
    {
        return [
            'user_id'           => 1,
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
