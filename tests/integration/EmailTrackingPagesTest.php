<?php

use App\Models\SettingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * La parte visibile del tracciamento email.
 *
 * Il tracciamento da solo non serve a nessuno: serve vedere, nella pagina del
 * documento, quali email sono partite e se sono state aperte, e poterlo
 * spegnere da "Email e mittenti". Qui si aprono le pagine vere sulla copia del
 * database, così un template rotto o un campo che non viene salvato non passa
 * inosservato.
 *
 * @internal
 */
final class EmailTrackingPagesTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const DB_COPY = 'invoiceplane_posttest';

    private ?\CodeIgniter\Database\BaseConnection $testDb = null;

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
            $this->testDb->query('SELECT 1 FROM ip_email_tracks LIMIT 1');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database (o tabella email_tracks) non disponibile: ' . $e->getMessage());
        }

        $this->resetSettingsCache();

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

        $this->resetSettingsCache();
        $this->restoreConnections();

        parent::tearDown();
    }

    public function testLaFatturaMostraLeEmailInviate(): void
    {
        $id = $this->primoDocumento('ip_invoices', 'invoice_id');
        $this->inviaTracciata('invoice', $id);

        $vista = $this->withSession($this->sessione())->get('/invoices/view/' . $id);
        $this->assertSame(200, $vista->response()->getStatusCode(), 'La fattura non si apre');

        $html = (string) $vista->response()->getBody();

        $this->assertStringContainsString('Email inviate', $html, 'Manca la scheda degli invii');
        $this->assertStringContainsString('cliente@example.com', $html, 'Manca il destinatario dell\'invio');
        $this->assertStringContainsString('Prima apertura il', $html, 'Un\'email aperta non risulta aperta');
        $this->assertStringContainsString('2 aperture', $html, 'Manca il numero di aperture');
        $this->assertStringContainsString('Il conteggio è indicativo', $html, 'Manca l\'avviso sui limiti del tracciamento');
    }

    public function testIlPreventivoMostraLeEmailInviate(): void
    {
        $id = $this->primoDocumento('ip_quotes', 'quote_id');
        $this->inviaTracciata('quote', $id);

        $vista = $this->withSession($this->sessione())->get('/quotes/view/' . $id);
        $this->assertSame(200, $vista->response()->getStatusCode(), 'Il preventivo non si apre');

        $html = (string) $vista->response()->getBody();

        $this->assertStringContainsString('Email inviate', $html, 'Manca la scheda degli invii');
        $this->assertStringContainsString('cliente@example.com', $html, 'Manca il destinatario dell\'invio');
        $this->assertStringContainsString('Prima apertura il', $html, 'Un\'email aperta non risulta aperta');
        $this->assertStringContainsString('2 aperture', $html, 'Manca il numero di aperture');
        $this->assertStringContainsString('Il conteggio è indicativo', $html, 'Manca l\'avviso sui limiti del tracciamento');
    }

    public function testLElencoEmailHaLInterruttore(): void
    {
        $pagina = $this->withSession($this->sessione())->get('/email_senders');
        $this->assertSame(200, $pagina->response()->getStatusCode(), 'La pagina Email e mittenti non si apre');

        $html = (string) $pagina->response()->getBody();

        $this->assertMatchesRegularExpression(
            '/name="settings\[email_tracking_enabled\]"[^>]*value="1"/',
            $html,
            'Manca la casella per attivare il tracciamento'
        );
        $this->assertStringContainsString(
            'name="settings[email_tracking_enabled]" value="0"',
            $html,
            'Manca il valore nascosto: togliendo la spunta il tracciamento resterebbe attivo'
        );
    }

    /** Il form generico di Email e mittenti deve salvare davvero la scelta. */
    public function testLInterruttoreVieneSalvato(): void
    {
        $token = $this->tokenCsrf();

        $this->withSession($this->sessione())->post('/email_senders', [
            'settings' => ['email_tracking_enabled' => '0'],
            'csrf_test_name' => $token,
        ]);

        $this->resetSettingsCache();
        $this->assertSame('0', (string) (new SettingModel())->setting('email_tracking_enabled', ''));

        $token = $this->tokenCsrf();

        $this->withSession($this->sessione())->post('/email_senders', [
            'settings' => ['email_tracking_enabled' => '1'],
            'csrf_test_name' => $token,
        ]);

        $this->resetSettingsCache();
        $this->assertSame('1', (string) (new SettingModel())->setting('email_tracking_enabled', ''));
    }

    private function tokenCsrf(): string
    {
        $pagina = $this->withSession($this->sessione())->get('/email_senders');
        $html   = (string) $pagina->response()->getBody();

        $this->assertMatchesRegularExpression(
            '/name="csrf_test_name"[^>]*value="([^"]+)"/',
            $html,
            'Nessun token CSRF nella pagina Email e mittenti'
        );

        preg_match('/name="csrf_test_name"[^>]*value="([^"]+)"/', $html, $trovato);

        return $trovato[1];
    }

    /** Riga di un invio riuscito e aperto due volte, per la pagina del documento. */
    private function inviaTracciata(string $tipo, int $documentoId): void
    {
        $adesso = date('Y-m-d H:i:s');

        $this->testDb->table('ip_email_tracks')->insert([
            'track_token'         => bin2hex(random_bytes(24)),
            'track_document_type' => $tipo,
            'track_document_id'   => $documentoId,
            'track_company_id'    => $this->azienda,
            'track_recipient'     => 'cliente@example.com',
            'track_subject'       => 'Documento in allegato',
            'track_sent_at'       => $adesso,
            'track_opened_at'     => $adesso,
            'track_open_count'    => 2,
            'track_created_at'    => $adesso,
        ]);
    }

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

    private function resetSettingsCache(): void
    {
        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);
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
