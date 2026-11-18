<?php

use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * "Segna come inviata" e' un clic, non un effetto dello scarico del PDF.
 *
 * Il PDF si genera con una GET e l'export ZIP lo fa in blocco: quando era
 * generatePdf() a marcare il documento, esportare le fatture le segnava tutte
 * come spedite. La marcatura ora passa da un POST esplicito; questo test lo
 * esercita dalla pagina del documento, con sessione, filtri e token CSRF
 * veri, e controlla anche che la GET non basti e che l'azienda sbagliata non
 * arrivi al record.
 *
 * Gira sulla copia del database e annulla tutto in una transazione.
 *
 * @internal
 */
final class MarkSentEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const DB_COPY = 'invoiceplane_posttest';

    private ?BaseConnection $testDb = null;

    /** @var array<string, mixed> */
    private array $originalTests = [];

    private int $utente = 1;
    private int $azienda = 1;
    private int $fattura = 0;
    private int $preventivo = 0;

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
            $this->testDb->query('SELECT 1 FROM ip_invoices LIMIT 1');
            $this->testDb->query('SELECT 1 FROM ip_quotes LIMIT 1');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non disponibile: ' . $config->tests['database']);
        }

        SettingModel::resetCache();

        $this->utente  = (int) ($this->testDb->query('SELECT user_id FROM ip_users ORDER BY user_id LIMIT 1')->getRow()->user_id ?? 1);
        $this->azienda = (int) ($this->testDb->query('SELECT company_id FROM ip_companies ORDER BY company_id LIMIT 1')->getRow()->company_id ?? 1);

        $_SESSION['active_company_id'] = $this->azienda;

        $this->testDb->transBegin();

        $this->fattura    = $this->bozzaFattura();
        $this->preventivo = $this->bozzaPreventivo();
    }

    protected function tearDown(): void
    {
        if ($this->testDb !== null && $this->testDb->transDepth > 0) {
            $this->testDb->transRollback();
        }

        SettingModel::resetCache();
        $this->restoreConnections();

        parent::tearDown();
    }

    public function testLaSchedaDellaBozzaOffreLaMarcaturaSoloConLImpostazioneAttiva(): void
    {
        $this->impostazione('mark_invoices_sent_pdf', '1');

        $html = $this->html('/invoices/view/' . $this->fattura);
        $this->assertStringContainsString('action="' . site_url('invoices/mark_sent/' . $this->fattura) . '"', $html);
        $this->assertStringContainsString('method="post"', $html);

        $this->impostazione('mark_invoices_sent_pdf', '0');

        $this->assertStringNotContainsString(
            'invoices/mark_sent/' . $this->fattura,
            $this->html('/invoices/view/' . $this->fattura),
            'Con l\'impostazione spenta non deve comparire la marcatura esplicita'
        );
    }

    public function testIlPostSegnaLaBozzaComeInviata(): void
    {
        $this->impostazione('mark_invoices_sent_pdf', '1');

        $risposta = $this->inviaConToken(
            '/invoices/mark_sent/' . $this->fattura,
            '/invoices/view/' . $this->fattura
        );

        $this->assertSame(302, $risposta->response()->getStatusCode());
        $this->assertStringContainsString('/invoices/view/' . $this->fattura, $risposta->response()->getHeaderLine('Location'));
        $this->assertSame(2, $this->statoFattura(), 'La bozza deve diventare "inviata"');
    }

    public function testLaGetNonSegnaNiente(): void
    {
        // La rotta accetta solo POST: una GET (prefetch, link, crawler) non
        // arriva nemmeno al controller.
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        try {
            $this->withSession($this->sessione())->get('/invoices/mark_sent/' . $this->fattura);
        } finally {
            $this->assertSame(1, $this->statoFattura(), 'Una GET non deve cambiare lo stato');
        }
    }

    public function testUnaBozzaDiUnAltraAziendaNonSiSegna(): void
    {
        $altraAzienda = (int) ($this->testDb->query(
            'SELECT company_id FROM ip_companies WHERE company_id <> ? ORDER BY company_id LIMIT 1',
            [$this->azienda]
        )->getRow()->company_id ?? 0);

        if ($altraAzienda === 0) {
            $this->markTestSkipped('La copia ha una sola azienda');
        }

        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        try {
            $this->inviaConToken(
                '/invoices/mark_sent/' . $this->fattura,
                '/dashboard',
                ['active_company_id' => $altraAzienda]
            );
        } finally {
            $this->assertSame(1, $this->statoFattura(), 'La fattura di un\'altra azienda non va toccata');
        }
    }

    public function testIlPostSegnaIlPreventivoComeInviato(): void
    {
        $this->impostazione('mark_quotes_sent_pdf', '1');

        $risposta = $this->inviaConToken(
            '/quotes/mark_sent/' . $this->preventivo,
            '/quotes/view/' . $this->preventivo
        );

        $this->assertSame(302, $risposta->response()->getStatusCode());
        $this->assertStringContainsString('/quotes/view/' . $this->preventivo, $risposta->response()->getHeaderLine('Location'));
        $this->assertSame(2, $this->statoPreventivo());
    }

    public function testLaGetNonSegnaIlPreventivo(): void
    {
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

        try {
            $this->withSession($this->sessione())->get('/quotes/mark_sent/' . $this->preventivo);
        } finally {
            $this->assertSame(1, $this->statoPreventivo());
        }
    }

    /**
     * Prende una fattura emessa dell'azienda e la riporta a bozza, dentro la
     * transazione: lo stato "bozza" non esiste nei dati di prova, ma e' quello
     * in cui la marcatura ha senso.
     */
    private function bozzaFattura(): int
    {
        $riga = $this->testDb->query(
            'SELECT invoice_id FROM ip_invoices WHERE company_id = ? ORDER BY invoice_id LIMIT 1',
            [$this->azienda]
        )->getRow();

        if ($riga === null) {
            $this->markTestSkipped('Nessuna fattura dell\'azienda nella copia');
        }

        $id = (int) $riga->invoice_id;

        $this->testDb->query(
            'UPDATE ip_invoices SET invoice_status_id = 1, is_read_only = 0 WHERE invoice_id = ?',
            [$id]
        );

        return $id;
    }

    private function bozzaPreventivo(): int
    {
        $riga = $this->testDb->query(
            'SELECT quote_id FROM ip_quotes WHERE company_id = ? ORDER BY quote_id LIMIT 1',
            [$this->azienda]
        )->getRow();

        if ($riga === null) {
            $this->markTestSkipped('Nessun preventivo dell\'azienda nella copia');
        }

        $id = (int) $riga->quote_id;

        $this->testDb->query('UPDATE ip_quotes SET quote_status_id = 1 WHERE quote_id = ?', [$id]);

        return $id;
    }

    private function statoFattura(): int
    {
        return (int) $this->testDb->query(
            'SELECT invoice_status_id FROM ip_invoices WHERE invoice_id = ?',
            [$this->fattura]
        )->getRow()->invoice_status_id;
    }

    private function statoPreventivo(): int
    {
        return (int) $this->testDb->query(
            'SELECT quote_status_id FROM ip_quotes WHERE quote_id = ?',
            [$this->preventivo]
        )->getRow()->quote_status_id;
    }

    private function impostazione(string $chiave, string $valore): void
    {
        (new SettingModel())->setSetting($chiave, $valore);
        SettingModel::resetCache();
    }

    private function html(string $url, array $sovrascritture = []): string
    {
        $risposta = $this->withSession($this->sessione($sovrascritture))->get($url);

        $this->assertSame(200, $risposta->response()->getStatusCode(), "La pagina {$url} non si apre");

        return (string) $risposta->response()->getBody();
    }

    /**
     * POST con il token CSRF preso dalla pagina, come farebbe il browser.
     */
    private function inviaConToken(string $url, string $paginaModulo, array $sovrascritture = []): \CodeIgniter\Test\TestResponse
    {
        $html = $this->html($paginaModulo, $sovrascritture);

        $this->assertMatchesRegularExpression(
            '/name="csrf_test_name"[^>]*value="([^"]+)"/',
            $html,
            "Nessun token CSRF nella pagina {$paginaModulo}"
        );

        preg_match('/name="csrf_test_name"[^>]*value="([^"]+)"/', $html, $trovato);

        return $this->withSession($this->sessione($sovrascritture))->post($url, ['csrf_test_name' => $trovato[1]]);
    }

    /**
     * @param array<string, mixed> $sovrascritture
     *
     * @return array<string, mixed>
     */
    private function sessione(array $sovrascritture = []): array
    {
        return $sovrascritture + [
            'user_id'           => $this->utente,
            'user_type'         => 1,
            'user_name'         => 'Admin',
            'active_company_id' => $this->azienda,
        ];
    }

    private function resetConnections(): void
    {
        $ref = new ReflectionClass(\CodeIgniter\Database\Config::class);

        // Le connessioni si chiudono prima di dimenticarle: lasciate aperte
        // restano vive fino alla fine della suite, e su MariaDB, con un test
        // in piu', esauriscono max_connections e gli ultimi test si saltano.
        foreach ($ref->getProperty('instances')->getValue() as $connection) {
            $connection->close();
        }

        $ref->getProperty('instances')->setValue(null, []);
        $ref->getProperty('factory')->setValue(null, null);
    }

    private function restoreConnections(): void
    {
        $config = config('Database');
        $config->tests = $this->originalTests;

        $this->resetConnections();
    }
}
