<?php

use App\Models\InvoiceModel;
use App\Models\SdiTransmissionModel;
use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;

/**
 * Una fattura bloccata non si modifica da nessuna parte.
 *
 * Il flag is_read_only veniva scritto ma non lo leggeva nessuna pagina: si
 * poteva "bloccare" una fattura e continuare a cambiarla. Qui si verifica che
 * pagine e endpoint lo rispettino, che gli esiti dello SdI lo muovano nel
 * verso giusto e che lo sblocco sia consentito solo dove lo SdI non ha la
 * fattura per buona.
 *
 * Gira sulla copia del database dentro una transazione annullata alla fine.
 *
 * @internal
 */
final class InvoiceLockTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const DB_COPY = 'invoiceplane_posttest';

    private ?BaseConnection $testDb = null;

    /** @var array<string, mixed> */
    private array $originalTests = [];

    private int $utente = 1;
    private int $fattura = 0;

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

        $_SESSION['active_company_id'] = 1;

        $riga = $this->testDb->query('SELECT user_id FROM ip_users ORDER BY user_id LIMIT 1')->getRow();
        $this->utente = (int) ($riga->user_id ?? 1);

        $this->testDb->transBegin();

        $fattura = $this->testDb->query(
            'SELECT invoice_id FROM ip_invoices WHERE (company_id = 1 OR company_id IS NULL) AND invoice_status_id > 1 ORDER BY invoice_id LIMIT 1'
        )->getRow();

        if ($fattura === null) {
            $this->testDb->transRollback();
            $this->restoreConnections();
            $this->markTestSkipped('Nessuna fattura emessa nella copia');
        }

        $this->fattura = (int) $fattura->invoice_id;

        // Parte sempre da sbloccata e senza trasmissioni.
        $this->testDb->query('UPDATE ip_invoices SET is_read_only = 0 WHERE invoice_id = ?', [$this->fattura]);
        $this->testDb->query('DELETE FROM ip_sdi_transmissions WHERE invoice_id = ?', [$this->fattura]);
    }

    protected function tearDown(): void
    {
        if ($this->testDb !== null && $this->testDb->transDepth > 0) {
            $this->testDb->transRollback();
        }

        $this->restoreConnections();

        parent::tearDown();
    }

    private function bloccata(): bool
    {
        return (int) $this->testDb->query('SELECT is_read_only FROM ip_invoices WHERE invoice_id = ?', [$this->fattura])->getRow()->is_read_only === 1;
    }

    private function trasmissione(string $canale, string $esito): int
    {
        $this->testDb->query(
            'INSERT INTO ip_sdi_transmissions (invoice_id, filename, channel, esito, sent_at) VALUES (?, ?, ?, ?, ?)',
            [$this->fattura, 'IT0_' . bin2hex(random_bytes(3)) . '.xml', $canale, 'pending', date('Y-m-d H:i:s')]
        );

        return (int) $this->testDb->insertID();
    }

    public function testUnaFatturaSbloccataSiApre(): void
    {
        $risposta = $this->withSession($this->sessione())->get('/invoices/edit/' . $this->fattura);

        $this->assertSame(200, $risposta->response()->getStatusCode());
    }

    public function testUnaFatturaBloccataNonApreLaModifica(): void
    {
        (new InvoiceModel())->setLocked($this->fattura, true);

        $risposta = $this->withSession($this->sessione())->get('/invoices/edit/' . $this->fattura);

        $this->assertSame(302, $risposta->response()->getStatusCode());
        $this->assertStringContainsString('/invoices/view/' . $this->fattura, $risposta->response()->getHeaderLine('Location'));
    }

    public function testLaSchedaDiUnaBloccataMostraBloccataENonModifica(): void
    {
        (new InvoiceModel())->setLocked($this->fattura, true);

        $html = (string) $this->withSession($this->sessione())->get('/invoices/view/' . $this->fattura)->response()->getBody();

        $this->assertStringContainsString('Bloccata', $html);
        $this->assertStringNotContainsString('href="' . site_url('invoices/edit/' . $this->fattura) . '"', $html, 'Nessun collegamento alla modifica');
    }

    public function testNemmenoIlCambioClienteAttraversaIlBlocco(): void
    {
        $model = new InvoiceModel();
        $prima = (int) $this->testDb->query('SELECT client_id FROM ip_invoices WHERE invoice_id = ?', [$this->fattura])->getRow()->client_id;
        $altro = $this->testDb->query('SELECT client_id FROM ip_clients WHERE client_id <> ? AND (company_id = 1 OR company_id IS NULL) LIMIT 1', [$prima])->getRow();

        if ($altro === null) {
            $this->markTestSkipped('Serve un secondo cliente nella copia');
        }

        $model->setLocked($this->fattura, true);

        $risposta = $this->inviaConToken('/invoices/ajax/change_client', ['invoice_id' => $this->fattura, 'client_id' => (int) $altro->client_id]);

        $this->assertFalse(json_decode((string) $risposta->response()->getBody(), true)['success']);
        $this->assertSame($prima, (int) $this->testDb->query('SELECT client_id FROM ip_invoices WHERE invoice_id = ?', [$this->fattura])->getRow()->client_id);
    }

    public function testUnaBloccataNonSiElimina(): void
    {
        // Una bozza si puo' sempre eliminare: se resta al suo posto e' per il
        // blocco, non per la regola "solo le bozze".
        $this->testDb->query('UPDATE ip_invoices SET invoice_status_id = 1 WHERE invoice_id = ?', [$this->fattura]);
        (new InvoiceModel())->setLocked($this->fattura, true);

        $this->inviaConToken('/invoices/delete/' . $this->fattura, []);

        $this->assertSame(1, (int) $this->testDb->query('SELECT COUNT(*) AS n FROM ip_invoices WHERE invoice_id = ?', [$this->fattura])->getRow()->n);
    }

    public function testLoScartoSbloccaEUnaConsegnaBlocca(): void
    {
        $model = new SdiTransmissionModel();
        $id    = $this->trasmissione('sdicoop', 'pending');

        $model->setEsito($id, 'pending');
        $this->assertTrue($this->bloccata(), 'In attesa su un canale automatico: la fattura e\' dello SdI');

        $model->setEsito($id, 'NS', 'scartata');
        $this->assertFalse($this->bloccata(), 'Scartata: va corretta e rinviata');

        $model->setEsito($id, 'RC');
        $this->assertTrue($this->bloccata(), 'Consegnata: si blocca');
    }

    public function testIlCanaleManualeNonBloccaFinoAUnEsitoVero(): void
    {
        $model = new SdiTransmissionModel();
        $id    = $this->trasmissione('manual', 'pending');

        $model->setEsito($id, 'pending');
        $this->assertFalse($this->bloccata(), 'XML solo scaricato: non si sa se e\' partito');

        $model->setEsito($id, 'RC');
        $this->assertTrue($this->bloccata(), 'Ricevuta registrata a mano: ora si');
    }

    public function testSiSbloccaSoloDoveLoSdiNonHaLaFattura(): void
    {
        $model = new InvoiceModel();
        $model->setLocked($this->fattura, true);

        $this->assertTrue($model->canUnlock($this->fattura), 'Mai trasmessa');

        $id = $this->trasmissione('sdicoop', 'pending');
        (new SdiTransmissionModel())->setEsito($id, 'RC');
        $this->assertFalse($model->canUnlock($this->fattura), 'Consegnata: nota di credito');

        $this->testDb->query("UPDATE ip_sdi_transmissions SET esito = 'IMP' WHERE sdi_transmission_id = ?", [$id]);
        $this->assertTrue($model->canUnlock($this->fattura), 'Importata da un altro gestionale');
    }

    public function testLAzioneSbloccaFunzionaDoveConsentito(): void
    {
        (new InvoiceModel())->setLocked($this->fattura, true);

        $this->inviaConToken('/invoices/unlock/' . $this->fattura, []);

        $this->assertFalse($this->bloccata());
    }

    public function testLAzioneSbloccaNonApreUnaConsegnata(): void
    {
        $id = $this->trasmissione('sdicoop', 'pending');
        (new SdiTransmissionModel())->setEsito($id, 'RC');
        $this->assertTrue($this->bloccata());

        $this->inviaConToken('/invoices/unlock/' . $this->fattura, []);

        $this->assertTrue($this->bloccata(), 'Una consegnata resta bloccata anche se si prova a forzare la richiesta');
    }

    private function inviaConToken(string $url, array $dati): TestResponse
    {
        $pagina = $this->withSession($this->sessione())->get('/invoices/form_create');
        $html   = (string) $pagina->response()->getBody();

        $token = null;

        foreach (['/name="csrf_test_name"[^>]*value="([^"]+)"/', '/value="([^"]+)"[^>]*name="csrf_test_name"/'] as $schema) {
            if (preg_match($schema, $html, $trovato)) {
                $token = $trovato[1];
                break;
            }
        }

        $this->assertNotNull($token, 'Nessun token CSRF nella pagina di creazione');

        return $this->withSession($this->sessione())->post($url, $dati + ['csrf_test_name' => $token]);
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
