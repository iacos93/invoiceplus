<?php

use App\Controllers\GuestInvoices;
use App\Controllers\GuestQuotes;
use App\Controllers\GuestView;
use App\Models\SettingModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il portale guest e il link pubblico, chiamati con la sessione giusta.
 *
 * I test coprono le due cose che possono fare danno: un cliente che vede un
 * documento non suo (deve essere 404) e una decisione raccontata come avvenuta
 * quando il preventivo non era più aperto. Il database di lavoro è la copia;
 * gli stati modificati vengono ripristinati alla fine di ogni test.
 *
 * @internal
 */
final class GuestDocumentAccessTest extends CIUnitTestCase
{
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
        $this->ripristinaAssegnazioni();
        $this->restoreConnections();

        parent::tearDown();
    }

    public function testIlGuestVedeLeFattureDeiClientiAssegnati(): void
    {
        $fattura = $this->fatturaGuest();

        $controller = $this->controller(GuestInvoices::class);
        session()->set([
            'user_id'      => 1,
            'user_type'    => 2,
        ]);
        $this->assegna((int) $fattura['client_id']);

        $html = $controller->view((int) $fattura['invoice_id']);

        $this->assertIsString($html);
        $this->assertStringContainsString((string) $fattura['invoice_number'], $html);
    }

    public function testUnaFatturaDiUnAltroClienteDaPaginaNonTrovata(): void
    {
        $fattura = $this->fatturaGuest();

        $estraneo = (int) $this->testDb->query(
            'SELECT client_id FROM ip_clients WHERE client_id <> ? LIMIT 1',
            [(int) $fattura['client_id']]
        )->getRow()->client_id;

        $controller = $this->controller(GuestInvoices::class);
        session()->set([
            'user_id'      => 1,
            'user_type'    => 2,
        ]);
        $this->assegna($estraneo);

        $this->expectException(PageNotFoundException::class);

        $controller->view((int) $fattura['invoice_id']);
    }

    public function testApprovazioneDalPortaleGuestENonRipetibile(): void
    {
        $preventivo = $this->preventivoAperto();

        try {
            $controller = $this->controller(GuestQuotes::class);
            session()->set([
                'user_id'      => 1,
                'user_type'    => 2,
            ]);
            $this->assegna((int) $preventivo['client_id']);

            $response = $controller->approve((int) $preventivo['quote_id']);

            $this->assertSame(4, $this->statoPreventivo((int) $preventivo['quote_id']));
            $this->assertStringContainsString(
                '/guest/quotes/view/' . $preventivo['quote_id'],
                $response->getHeaderLine('Location')
            );

            // Secondo invio: il preventivo non è più aperto, il messaggio lo
            // dice e lo stato resta approvato.
            $controller->approve((int) $preventivo['quote_id']);

            $this->assertSame(4, $this->statoPreventivo((int) $preventivo['quote_id']));
            $this->assertNotSame('', (string) session()->getFlashdata('alert_error'));
        } finally {
            $this->ripristinaPreventivo($preventivo);
        }
    }

    public function testApprovazioneDalLinkPubblico(): void
    {
        $preventivo = $this->preventivoAperto();

        try {
            $controller = $this->controller(GuestView::class);

            $response = $controller->approveQuote((string) $preventivo['quote_url_key']);

            $this->assertSame(4, $this->statoPreventivo((int) $preventivo['quote_id']));
            $this->assertStringContainsString(
                '/guest/view/quote/' . $preventivo['quote_url_key'],
                $response->getHeaderLine('Location')
            );
        } finally {
            $this->ripristinaPreventivo($preventivo);
        }
    }

    /** @var list<array<string, mixed>>|null assegnazioni dell'utente 1 prima del test */
    private ?array $assegnazioniOriginali = null;

    /**
     * Assegna all'utente 1 quel solo cliente, scrivendolo in ip_user_clients.
     *
     * Il portale legge le assegnazioni dal database a ogni richiesta (una
     * revoca deve valere subito): metterle in sessione, come facevano questi
     * test, non apre più nessuna porta. Le righe originali tornano al loro
     * posto in tearDown().
     */
    private function assegna(int $clientId): void
    {
        $tabella = $this->testDb->table('ip_user_clients');

        $this->assegnazioniOriginali ??= $tabella->where('user_id', 1)->get()->getResultArray();

        $this->testDb->table('ip_user_clients')->where('user_id', 1)->delete();
        $this->testDb->table('ip_user_clients')->insert(['user_id' => 1, 'client_id' => $clientId]);
    }

    private function ripristinaAssegnazioni(): void
    {
        if ($this->assegnazioniOriginali === null || $this->testDb === null) {
            return;
        }

        $this->testDb->table('ip_user_clients')->where('user_id', 1)->delete();

        foreach ($this->assegnazioniOriginali as $riga) {
            $this->testDb->table('ip_user_clients')->insert($riga);
        }

        $this->assegnazioniOriginali = null;
    }

    private function controller(string $class): object
    {
        $controller = new $class();
        $controller->initController(service('request'), service('response'), service('logger'));

        return $controller;
    }

    private function fatturaGuest(): array
    {
        $row = $this->testDb->query(
            'SELECT invoice_id, invoice_number, client_id
             FROM ip_invoices
             WHERE invoice_status_id IN (3, 4)
             ORDER BY invoice_id LIMIT 1'
        )->getRowArray();

        if (!$row) {
            $this->markTestSkipped('Serve una fattura inviata/vista nella copia');
        }

        return $row;
    }

    private function preventivoAperto(): array
    {
        $row = $this->testDb->query(
            "SELECT quote_id, quote_url_key, client_id, quote_status_id
             FROM ip_quotes
             WHERE quote_status_id IN (2, 3)
               AND quote_url_key <> '' AND quote_url_key IS NOT NULL
               AND (quote_password IS NULL OR quote_password = '')
             ORDER BY quote_id LIMIT 1"
        )->getRowArray();

        if (!$row) {
            $this->markTestSkipped('Serve un preventivo aperto e pubblico nella copia');
        }

        return $row;
    }

    private function statoPreventivo(int $quoteId): int
    {
        return (int) $this->testDb->table('ip_quotes')
            ->where('quote_id', $quoteId)
            ->get()->getRow()->quote_status_id;
    }

    private function ripristinaPreventivo(array $preventivo): void
    {
        $this->testDb->table('ip_quotes')
            ->where('quote_id', (int) $preventivo['quote_id'])
            ->update(['quote_status_id' => (int) $preventivo['quote_status_id']]);
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
