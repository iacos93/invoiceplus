<?php

use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;

/**
 * Le scritture sulle spese: creazione con totale derivato, tendine vuote,
 * fornitore digitato a mano, modifica e rifiuto senza fornitore.
 *
 * La spesa è il ciclo passivo: un errore qui non compare in nessun documento
 * fiscale, quindi nessuno se ne accorge finché l'utile del mese non torna.
 *
 * Ogni test gira in una transazione chiusa con rollback.
 *
 * @internal
 */
final class ExpenseWritesTest extends CIUnitTestCase
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
            $this->testDb->query('SELECT 1 FROM ip_expenses LIMIT 1');
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
     * Il totale lasciato vuoto si ricava da imponibile + imposta: una spesa
     * da zero euro falserebbe l'utile senza dare errori.
     */
    public function testTotaleVuotoSiRicavaDaImponibileEImposta(): void
    {
        $prima = $this->conta();

        $risposta = $this->inviaConToken('/expenses/form', [
            'expense_date'   => date('Y-m-d'),
            'supplier_name'  => 'Fornitore Collaudo Totale',
            'supplier_vat'   => '01234567890',
            'expense_amount' => '100,00',
            'expense_tax'    => '22,00',
            'expense_total'  => '',
            'expense_paid'   => '0',
            'expense_note'   => 'Spesa del test',
        ], '/expenses/form');

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Spesa non salvata');

        $this->assertSame($prima + 1, $this->conta(), 'Spesa: non è stata scritta');

        $spesa = $this->testDb->query(
            'SELECT * FROM ip_expenses WHERE supplier_name = ? ORDER BY expense_id DESC LIMIT 1',
            ['Fornitore Collaudo Totale']
        )->getRowArray();

        $this->assertNotNull($spesa, 'Spesa: riga non trovata');
        $this->assertSame(100.0, (float) $spesa['expense_amount']);
        $this->assertSame(22.0, (float) $spesa['expense_tax']);
        $this->assertSame(122.0, (float) $spesa['expense_total'], 'Totale: atteso 100 + 22');
        $this->assertSame(1, (int) $spesa['company_id'], 'Spesa: azienda non assegnata');
    }

    /**
     * Fornitore digitato a mano senza sceglierlo dalla tendina: deve nascere
     * in anagrafica e la spesa deve puntarci, altrimenti la scheda fornitore
     * resta senza movimenti.
     */
    public function testFornitoreDigitatoAManoVieneCreatoECollegato(): void
    {
        $risposta = $this->inviaConToken('/expenses/form', [
            'expense_date'   => date('Y-m-d'),
            'supplier_name'  => 'Fornitore Collaudo Nuovo',
            'supplier_vat'   => '09876543210',
            'expense_amount' => '50,00',
            'expense_tax'    => '0,00',
            'expense_total'  => '50,00',
            'expense_paid'   => '0',
        ], '/expenses/form');

        $this->assertSame(302, $risposta->response()->getStatusCode());

        $spesa = $this->testDb->query(
            'SELECT supplier_id FROM ip_expenses WHERE supplier_name = ? LIMIT 1',
            ['Fornitore Collaudo Nuovo']
        )->getRowArray();

        $this->assertNotNull($spesa['supplier_id'], 'Fornitore: la spesa deve essere collegata');

        $fornitore = $this->testDb->query(
            'SELECT supplier_name, supplier_vat_id, company_id FROM ip_suppliers WHERE supplier_id = ?',
            [(int) $spesa['supplier_id']]
        )->getRowArray();

        $this->assertNotNull($fornitore, 'Fornitore: non è stato creato in anagrafica');
        $this->assertSame('Fornitore Collaudo Nuovo', $fornitore['supplier_name']);
        $this->assertSame('09876543210', $fornitore['supplier_vat_id']);
        $this->assertSame(1, (int) $fornitore['company_id']);
    }

    /**
     * Le tendine lasciate su "Nessuno" arrivano come stringa vuota: su
     * PostgreSQL '' in una colonna integer fa fallire l'insert, quindi vanno
     * salvate come NULL. Con un fornitore scelto dalla tendina, stavolta.
     */
    public function testTendineVuoteSiSalvanoComeNull(): void
    {
        $fornitore = $this->testDb->query(
            'SELECT supplier_id FROM ip_suppliers WHERE company_id = 1 ORDER BY supplier_id LIMIT 1'
        )->getRow();

        if ($fornitore === null) {
            $this->markTestSkipped('Serve un fornitore nell\'azienda 1');
        }

        $risposta = $this->inviaConToken('/expenses/form', [
            'supplier_id'         => (int) $fornitore->supplier_id,
            'expense_date'        => date('Y-m-d'),
            'supplier_name'       => 'Fornitore da tendina',
            'expense_category_id' => '',
            'bank_account_id'     => '',
            'costing_account_id'  => '',
            'expense_amount'      => '10,00',
            'expense_tax'         => '0,00',
            'expense_total'       => '10,00',
            'expense_paid'        => '0',
        ], '/expenses/form');

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Tendine vuote: la spesa deve salvarsi');

        $spesa = $this->testDb->query(
            'SELECT expense_category_id, bank_account_id, costing_account_id
             FROM ip_expenses WHERE supplier_name = ? LIMIT 1',
            ['Fornitore da tendina']
        )->getRowArray();

        $this->assertNotNull($spesa, 'Tendine vuote: spesa non trovata');
        $this->assertNull($spesa['expense_category_id'], 'Categoria vuota: attesa NULL');
        $this->assertNull($spesa['bank_account_id'], 'Conto vuoto: atteso NULL');
        $this->assertNull($spesa['costing_account_id'], 'Voce di costo vuota: attesa NULL');
    }

    /**
     * La modifica aggiorna la spesa esistente, non ne crea una seconda.
     */
    public function testModificaAggiornaSenzaDuplicare(): void
    {
        $this->inviaConToken('/expenses/form', [
            'expense_date'   => date('Y-m-d'),
            'supplier_name'  => 'Fornitore Collaudo Modifica',
            'expense_amount' => '30,00',
            'expense_tax'    => '0,00',
            'expense_total'  => '30,00',
            'expense_paid'   => '0',
        ], '/expenses/form');

        $id = (int) $this->testDb->query(
            'SELECT expense_id FROM ip_expenses WHERE supplier_name = ? ORDER BY expense_id DESC LIMIT 1',
            ['Fornitore Collaudo Modifica']
        )->getRow()->expense_id;

        $prima = $this->conta();

        $risposta = $this->inviaConToken('/expenses/form/' . $id, [
            'expense_date'   => date('Y-m-d'),
            'supplier_name'  => 'Fornitore Collaudo Modifica',
            'expense_amount' => '45,00',
            'expense_tax'    => '0,00',
            'expense_total'  => '45,00',
            'expense_paid'   => '0',
        ], '/expenses/form/' . $id);

        $this->assertSame(302, $risposta->response()->getStatusCode());
        $this->assertSame($prima, $this->conta(), 'Modifica: non deve nascere una seconda spesa');

        $totale = $this->testDb->query(
            'SELECT expense_total FROM ip_expenses WHERE expense_id = ?',
            [$id]
        )->getRow()->expense_total;

        $this->assertSame(45.0, (float) $totale, 'Modifica: il totale non è stato aggiornato');
    }

    /**
     * Senza fornitore il form si riapre e non si scrive niente.
     */
    public function testSenzaFornitoreNonSalva(): void
    {
        $prima = $this->conta();

        $risposta = $this->inviaConToken('/expenses/form', [
            'expense_date'   => date('Y-m-d'),
            'supplier_name'  => '',
            'expense_amount' => '10,00',
            'expense_tax'    => '0,00',
            'expense_total'  => '10,00',
            'expense_paid'   => '0',
        ], '/expenses/form');

        $this->assertSame(200, $risposta->response()->getStatusCode(), 'Senza fornitore: il form deve riaprirsi');
        $this->assertSame($prima, $this->conta(), 'Senza fornitore: nessuna spesa deve essere scritta');
    }

    // -----------------------------------------------------------------
    // Aiuti
    // -----------------------------------------------------------------

    private function conta(): int
    {
        return (int) $this->testDb->query('SELECT COUNT(*) AS n FROM ip_expenses')->getRow()->n;
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
