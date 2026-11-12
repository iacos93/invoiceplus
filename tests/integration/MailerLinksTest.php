<?php

use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * L'invio per email deve essere raggiungibile dal documento.
 *
 * Il Mailer esisteva già (rotte, form, allegato PDF) ma non aveva un punto
 * d'ingresso su fatture e preventivi: si poteva arrivarci solo da scadenzario
 * e solleciti. Chi apriva la fattura non trovava "Invia", e concludeva che la
 * funzione non ci fosse.
 *
 * Qui si verifica il collegamento, non l'invio: quello richiede un server
 * SMTP e resta una prova manuale.
 *
 * @internal
 */
final class MailerLinksTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const DB_COPY = 'invoiceplane_posttest';

    private ?BaseConnection $testDb = null;

    /** @var array<string, mixed> */
    private array $originalTests = [];

    private int $utente = 1;
    private int $azienda = 1;

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
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non disponibile: ' . $config->tests['database']);
        }

        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);

        $azienda = $this->testDb->query('SELECT company_id FROM ip_companies ORDER BY company_id LIMIT 1')->getRow();
        $this->azienda = (int) ($azienda->company_id ?? 1);

        $utente       = $this->testDb->query('SELECT user_id FROM ip_users ORDER BY user_id LIMIT 1')->getRow();
        $this->utente = (int) ($utente->user_id ?? 1);

        $_SESSION['active_company_id'] = $this->azienda;

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

    public function testLaFatturaCollegaLInvioPerEmail(): void
    {
        $id = $this->primoDocumento('ip_invoices', 'invoice_id');

        $vista = $this->withSession($this->sessione())->get('/invoices/view/' . $id);
        $this->assertSame(200, $vista->response()->getStatusCode(), 'La fattura non si apre');

        $html = (string) $vista->response()->getBody();
        $this->assertStringContainsString(
            '/mailer/invoice/' . $id,
            $html,
            'La pagina della fattura non offre il collegamento per inviarla via email'
        );

        $invio = $this->withSession($this->sessione())->get('/mailer/invoice/' . $id);
        $this->assertSame(200, $invio->response()->getStatusCode(), 'La schermata di invio email non si apre');

        // Con un mittente configurato si vede il form; senza, la pagina dice
        // dove configurarlo. In entrambi i casi si arriva da qualche parte.
        $this->assertMatchesRegularExpression(
            '/id="mailer_form"|email_senders/',
            (string) $invio->response()->getBody(),
            'La schermata di invio non mostra né il modulo né come configurare un mittente'
        );
    }

    public function testIlPreventivoCollegaLInvioPerEmail(): void
    {
        $id = $this->primoDocumento('ip_quotes', 'quote_id');

        $vista = $this->withSession($this->sessione())->get('/quotes/view/' . $id);
        $this->assertSame(200, $vista->response()->getStatusCode(), 'Il preventivo non si apre');

        $html = (string) $vista->response()->getBody();
        $this->assertStringContainsString(
            '/mailer/quote/' . $id,
            $html,
            'La pagina del preventivo non offre il collegamento per inviarlo via email'
        );

        $invio = $this->withSession($this->sessione())->get('/mailer/quote/' . $id);
        $this->assertSame(200, $invio->response()->getStatusCode(), 'La schermata di invio email non si apre');

        $this->assertMatchesRegularExpression(
            '/id="mailer_form"|email_senders/',
            (string) $invio->response()->getBody(),
            'La schermata di invio non mostra né il modulo né come configurare un mittente'
        );
    }

    /** Il collegamento deve esserci anche dall'elenco, senza aprire il documento. */
    public function testGliElenchiColleganoLInvioPerEmail(): void
    {
        // Quale documento finisca in prima pagina dipende da filtri e
        // paginazione: qui conta che l'azione sia offerta, non su quale riga.
        $html = (string) $this->withSession($this->sessione())->get('/invoices/status/all')->response()->getBody();
        $this->assertMatchesRegularExpression('#/mailer/invoice/\d+#', $html, 'Elenco fatture: manca l\'invio email');

        $html = (string) $this->withSession($this->sessione())->get('/quotes/status/all')->response()->getBody();
        $this->assertMatchesRegularExpression('#/mailer/quote/\d+#', $html, 'Elenco preventivi: manca l\'invio email');
    }

    /** Primo documento dell'azienda attiva, che è quello che le pagine mostrano. */
    private function primoDocumento(string $tabella, string $chiave): int
    {
        $riga = $this->testDb->query(
            "SELECT {$chiave} AS id FROM {$tabella} WHERE company_id = ? ORDER BY {$chiave} DESC LIMIT 1",
            [$this->azienda]
        )->getRow();

        $this->assertNotNull($riga, "Nessun documento in {$tabella} per l'azienda di prova");

        return (int) $riga->id;
    }

    /** @return array<string, mixed> */
    private function sessione(): array
    {
        return [
            'user_id'           => $this->utente,
            'user_type'         => 1,
            'user_name'         => 'Admin',
            'active_company_id' => $this->azienda,
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
