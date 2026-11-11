<?php

use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;

/**
 * Le scritture sui preventivi, dalla creazione del form.
 *
 * La conversione preventivo → fattura è già coperta da
 * SimulazioneFunzionamentoTest, ma la creazione no: se il POST perde la
 * testata o le righe, la conversione non ha niente da copiare e il danno si
 * vede solo due schermate dopo.
 *
 * Ogni test gira in una transazione chiusa con rollback.
 *
 * @internal
 */
final class QuoteWritesTest extends CIUnitTestCase
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
            $this->testDb->query('SELECT 1 FROM ip_quotes LIMIT 1');
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

    /** Testata e righe salvate in un colpo solo, come dal form. */
    public function testCreazioneSalvaTestataERighe(): void
    {
        $cliente = $this->cliente();

        $risposta = $this->inviaConToken('/quotes/ajax/create', [
            'client_id'          => $cliente,
            'quote_date_created' => date('Y-m-d'),
            'invoice_group_id'   => $this->gruppo(),
            'items'              => json_encode([[
                'item_name'     => 'Preventivo di prova',
                'item_quantity' => '1',
                'item_price'    => '150',
            ]]),
        ], '/quotes/form_create');

        $posizione = (string) $risposta->response()->getHeaderLine('Location');
        $this->assertStringContainsString(
            '/quotes/view/',
            $posizione,
            'Creazione preventivo non riuscita. Corpo: ' . mb_substr((string) $risposta->response()->getBody(), 0, 300)
        );

        $id = (int) basename(parse_url($posizione, PHP_URL_PATH));

        $preventivo = $this->testDb->query(
            'SELECT client_id, quote_status_id FROM ip_quotes WHERE quote_id = ?',
            [$id]
        )->getRowArray();

        $this->assertSame($cliente, (int) $preventivo['client_id'], 'Preventivo: cliente non salvato');
        $this->assertSame(1, (int) $preventivo['quote_status_id'], 'Preventivo: deve nascere come bozza');

        $righe = (int) $this->testDb->query(
            'SELECT COUNT(*) AS n FROM ip_quote_items WHERE quote_id = ?',
            [$id]
        )->getRow()->n;
        $this->assertSame(1, $righe, 'Preventivo: riga non salvata');

        $importi = $this->testDb->query(
            'SELECT quote_item_subtotal, quote_total FROM ip_quote_amounts WHERE quote_id = ?',
            [$id]
        )->getRowArray();

        $this->assertSame(150.0, (float) $importi['quote_item_subtotal'], 'Preventivo: imponibile non calcolato');
        $this->assertSame(150.0, (float) $importi['quote_total'], 'Preventivo: totale non calcolato');
    }

    /**
     * Senza cliente si torna al form con un messaggio. Il model, da solo,
     * non basta: cleanValidationRules() scarta le regole dei campi assenti
     * dal POST, quindi la chiave va portata a null esplicitamente.
     */
    public function testCreazioneSenzaClienteNonScriveNiente(): void
    {
        $prima = (int) $this->testDb->query('SELECT COUNT(*) AS n FROM ip_quotes')->getRow()->n;

        $risposta = $this->inviaConToken('/quotes/ajax/create', [
            'quote_date_created' => date('Y-m-d'),
            'invoice_group_id'   => $this->gruppo(),
        ], '/quotes/form_create');

        $this->assertSame(302, $risposta->response()->getStatusCode());
        $this->assertStringContainsString(
            '/quotes/form_create',
            (string) $risposta->response()->getHeaderLine('Location'),
            'Senza cliente si deve tornare al form'
        );

        $dopo = (int) $this->testDb->query('SELECT COUNT(*) AS n FROM ip_quotes')->getRow()->n;
        $this->assertSame($prima, $dopo, 'Nessun preventivo deve essere scritto');
        $this->assertNotEmpty($_SESSION['alert_error'] ?? null, 'Il form deve spiegare perché non è stato salvato');
    }

    /**
     * Cliente senza aliquota predefinita: QuoteModel::create() ne copia la
     * preferenza su ip_quote_tax_rates, e un'imposta di documento si
     * sommerebbe alle righe portando il totale oltre i 150 attesi.
     */
    private function cliente(): int
    {
        $row = $this->testDb->query(
            'SELECT client_id FROM ip_clients
             WHERE company_id = 1 AND COALESCE(client_default_tax_rate_id, 0) = 0
             ORDER BY client_id LIMIT 1'
        )->getRow();

        $this->assertNotNull($row, 'Serve un cliente senza aliquota predefinita nell\'azienda 1');

        return (int) $row->client_id;
    }

    private function gruppo(): int
    {
        $row = $this->testDb->query(
            'SELECT invoice_group_id FROM ip_invoice_groups WHERE company_id = 1 ORDER BY invoice_group_id LIMIT 1'
        )->getRow();

        $this->assertNotNull($row, 'Serve un gruppo di numerazione per l\'azienda 1');

        return (int) $row->invoice_group_id;
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
