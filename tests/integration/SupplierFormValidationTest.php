<?php

use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;

/**
 * Il form fornitori non deve annunciare un salvataggio che non c'è stato.
 *
 * La regola del modello è coperta da SupplierSaveRecordTest; qui si guarda
 * quello che vede chi compila: con un'email non valida la pagina resta la
 * stessa, con i dati inseriti e l'errore accanto al campo, e in anagrafica non
 * nasce né cambia niente. Senza questo test il tratto era verificato solo sul
 * modello, e il controller poteva reintrodurre un «salvato correttamente» su una
 * riga mai scritta senza che la suite se ne accorgesse.
 *
 * Ogni test gira in una transazione chiusa con rollback.
 *
 * @internal
 */
final class SupplierFormValidationTest extends CIUnitTestCase
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
            $this->testDb->query('SELECT 1 FROM ip_suppliers LIMIT 1');
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

    /**
     * Un'email con due indirizzi è ciò che arriva dalle fatture elettroniche:
     * è il caso che in produzione aveva fatto sparire indirizzo e CAP.
     */
    public function testEmailNonValidaRiapreIlFormSenzaSalvare(): void
    {
        $prima = $this->conta();

        $risposta = $this->inviaConToken('/suppliers/form', [
            'supplier_name'     => 'Fornitore Collaudo Email',
            'supplier_vat_id'   => '01234567890',
            'supplier_tax_code' => '01234567890',
            'supplier_email'    => 'support@collaudo.test invoice@collaudo.test',
            'supplier_country'  => 'IT',
            'supplier_active'   => '1',
        ], '/suppliers/form');

        $this->assertSame(200, $risposta->response()->getStatusCode(), 'Email non valida: il form deve riaprirsi, non rimandare altrove');

        $html = (string) $risposta->response()->getBody();

        $this->assertStringContainsString('name="supplier_email"', $html, 'Email non valida: attesa la pagina del form');
        $this->assertStringContainsString('value="Fornitore Collaudo Email"', $html, 'Email non valida: i dati inseriti devono restare');
        $this->assertStringContainsString('value="support@collaudo.test invoice@collaudo.test"', $html, 'Email non valida: il valore rifiutato resta visibile per correggerlo');
        $this->assertStringContainsString('Indirizzo email non valido.', $html, 'Email non valida: manca il messaggio accanto al campo');

        $this->assertStringNotContainsString('Fornitore creato correttamente', $html, 'Email non valida: non deve annunciare il salvataggio');
        $this->assertStringNotContainsString('ip-toast--success', $html, 'Email non valida: nessun avviso di successo');

        $this->assertSame($prima, $this->conta(), 'Email non valida: nessun fornitore deve nascere in anagrafica');
    }

    /**
     * Stessa cosa in modifica: la riga resta com'era e l'errore compare senza
     * dire che è andata.
     */
    public function testModificaConEmailNonValidaNonTocchaLaRiga(): void
    {
        $fornitore = $this->testDb->query(
            'SELECT supplier_id, supplier_name, supplier_email FROM ip_suppliers WHERE company_id = 1 ORDER BY supplier_id LIMIT 1'
        )->getRowArray();

        if ($fornitore === null) {
            $this->markTestSkipped('Serve un fornitore nell\'azienda 1');
        }

        $risposta = $this->inviaConToken('/suppliers/form/' . (int) $fornitore['supplier_id'], [
            'supplier_name'     => $fornitore['supplier_name'],
            'supplier_vat_id'   => '',
            'supplier_tax_code' => '',
            'supplier_email'    => 'support@collaudo.test invoice@collaudo.test',
            'supplier_country'  => 'IT',
            'supplier_active'   => '1',
        ], '/suppliers/form/' . (int) $fornitore['supplier_id']);

        $this->assertSame(200, $risposta->response()->getStatusCode(), 'Email non valida: il form deve riaprirsi');

        $html = (string) $risposta->response()->getBody();

        $this->assertStringContainsString('Indirizzo email non valido.', $html, 'Email non valida: manca il messaggio accanto al campo');
        $this->assertStringNotContainsString('Fornitore aggiornato correttamente', $html, 'Email non valida: non deve annunciare il salvataggio');

        $dopo = $this->testDb->query(
            'SELECT supplier_email, supplier_country FROM ip_suppliers WHERE supplier_id = ?',
            [(int) $fornitore['supplier_id']]
        )->getRowArray();

        $this->assertSame(
            (string) $fornitore['supplier_email'],
            (string) $dopo['supplier_email'],
            'Email non valida: l\'email in archivio non doveva cambiare'
        );
    }

    private function conta(): int
    {
        return (int) $this->testDb->query('SELECT COUNT(*) AS n FROM ip_suppliers')->getRow()->n;
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
        config('Database')->tests = $this->originalTests;

        $this->resetConnections();
    }
}
