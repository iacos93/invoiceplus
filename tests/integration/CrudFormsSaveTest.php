<?php

use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;

/**
 * I form che scrivono: dal POST alla riga, passando dai filtri veri.
 *
 * saveRecord() ora restituisce 0 quando il modello rifiuta la scrittura, e ogni
 * form che lo chiama è stato adeguato. Questi test tengono fermo che il
 * percorso normale non si sia rotto nel farlo: creazione e modifica salvano
 * davvero, e quando il modello rifiuta non compare un «creato correttamente».
 *
 * Le entità coperte sono quelle generiche che condividono la stessa forma di
 * controller (lista + form GET/POST); fatture, preventivi, pagamenti e documenti
 * hanno già i loro test di scrittura.
 *
 * Ogni test gira in una transazione chiusa con rollback.
 *
 * @internal
 */
final class CrudFormsSaveTest extends CIUnitTestCase
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
            $this->testDb->query('SELECT 1 FROM ip_units LIMIT 1');
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

    public function testUnitaSiCreaESiModifica(): void
    {
        $creazione = $this->inviaConToken('/units/form', [
            'unit_name'      => 'Collaudo Unit',
            'unit_name_plrl' => 'Collaudo Units',
            'unit_type'      => 'pezzi',
        ], '/units/form');

        $this->assertSame(302, $creazione->response()->getStatusCode(), 'Unità: la creazione deve salvare');

        $riga = $this->testDb->query(
            'SELECT unit_id, unit_name_plrl FROM ip_units WHERE unit_name = ? LIMIT 1',
            ['Collaudo Unit']
        )->getRowArray();

        $this->assertNotNull($riga, 'Unità: riga non creata');

        $modifica = $this->inviaConToken('/units/form/' . (int) $riga['unit_id'], [
            'unit_name'      => 'Collaudo Unit',
            'unit_name_plrl' => 'Collaudo Plurali',
            'unit_type'      => 'pezzi',
        ], '/units/form/' . (int) $riga['unit_id']);

        $this->assertSame(302, $modifica->response()->getStatusCode(), 'Unità: la modifica deve salvare');

        $dopo = $this->testDb->query(
            'SELECT unit_name_plrl FROM ip_units WHERE unit_id = ?',
            [(int) $riga['unit_id']]
        )->getRowArray();

        $this->assertSame('Collaudo Plurali', $dopo['unit_name_plrl'], 'Unità: modifica non scritta');
    }

    public function testFamigliaSiCrea(): void
    {
        $risposta = $this->inviaConToken('/families/form', [
            'family_name' => 'Famiglia Collaudo',
        ], '/families/form');

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Famiglia: la creazione deve salvare');

        $conta = (int) $this->testDb->query(
            'SELECT COUNT(*) AS n FROM ip_families WHERE family_name = ?',
            ['Famiglia Collaudo']
        )->getRow()->n;

        $this->assertSame(1, $conta, 'Famiglia: riga non creata');
    }

    public function testAliquotaSiCrea(): void
    {
        $risposta = $this->inviaConToken('/tax_rates/form', [
            'tax_rate_name'    => 'Collaudo 7%',
            'tax_rate_percent' => '7',
            'tax_rate_natura'  => '',
        ], '/tax_rates/form');

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Aliquota: la creazione deve salvare');

        $riga = $this->testDb->query(
            'SELECT tax_rate_percent FROM ip_tax_rates WHERE tax_rate_name = ? LIMIT 1',
            ['Collaudo 7%']
        )->getRowArray();

        $this->assertNotNull($riga, 'Aliquota: riga non creata');
        $this->assertSame(7.0, (float) $riga['tax_rate_percent']);
    }

    public function testCategoriaSpesaSiCrea(): void
    {
        $risposta = $this->inviaConToken('/expense_categories/form', [
            'expense_category_name'       => 'Categoria Collaudo',
            'expense_category_deductible' => '1',
        ], '/expense_categories/form');

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Categoria: la creazione deve salvare');

        $conta = (int) $this->testDb->query(
            'SELECT COUNT(*) AS n FROM ip_expense_categories WHERE expense_category_name = ?',
            ['Categoria Collaudo']
        )->getRow()->n;

        $this->assertSame(1, $conta, 'Categoria: riga non creata');
    }

    public function testMetodoPagamentoSiCreaESiModifica(): void
    {
        $creazione = $this->inviaConToken('/payment_methods/form', [
            'payment_method_name' => 'Collaudo Bonifico',
        ], '/payment_methods/form');

        $this->assertSame(302, $creazione->response()->getStatusCode(), 'Metodo: la creazione deve salvare');

        $riga = $this->testDb->query(
            'SELECT payment_method_id FROM ip_payment_methods WHERE payment_method_name = ? LIMIT 1',
            ['Collaudo Bonifico']
        )->getRowArray();

        $this->assertNotNull($riga, 'Metodo: riga non creata');

        // In modifica la regola is_unique esclude la riga stessa tramite il
        // segnaposto payment_method_id: se saltasse, salvare senza rinominare
        // fallirebbe contro se stesso e il form direbbe che non ha salvato.
        $modifica = $this->inviaConToken('/payment_methods/form/' . (int) $riga['payment_method_id'], [
            'payment_method_name' => 'Collaudo Bonifico',
        ], '/payment_methods/form/' . (int) $riga['payment_method_id']);

        $this->assertSame(302, $modifica->response()->getStatusCode(), 'Metodo: la modifica deve salvare');
    }

    /**
     * Quando il modello rifiuta, il form resta aperto con l'errore e non
     * annuncia niente: è la stessa promessa del form fornitori.
     */
    public function testMetodoPagamentoDuplicatoNonSiSalvaEAvvisa(): void
    {
        $this->inviaConToken('/payment_methods/form', [
            'payment_method_name' => 'Collaudo Unico',
        ], '/payment_methods/form');

        $prima = (int) $this->testDb->query(
            'SELECT COUNT(*) AS n FROM ip_payment_methods WHERE payment_method_name = ?',
            ['Collaudo Unico']
        )->getRow()->n;

        $risposta = $this->inviaConToken('/payment_methods/form', [
            'payment_method_name' => 'Collaudo Unico',
        ], '/payment_methods/form');

        $this->assertSame(200, $risposta->response()->getStatusCode(), 'Duplicato: il form deve riaprirsi');

        $html = (string) $risposta->response()->getBody();

        $this->assertStringNotContainsString('Metodo di pagamento creato correttamente', $html, 'Duplicato: non deve annunciare il salvataggio');

        $dopo = (int) $this->testDb->query(
            'SELECT COUNT(*) AS n FROM ip_payment_methods WHERE payment_method_name = ?',
            ['Collaudo Unico']
        )->getRow()->n;

        $this->assertSame($prima, $dopo, 'Duplicato: non deve nascere una seconda riga');
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
