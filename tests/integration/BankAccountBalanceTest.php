<?php

use App\Models\BankAccountModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il saldo di un conto di saldo: quello che c'era all'apertura, più gli
 * incassi, meno le spese.
 *
 * È il numero su cui si regge la prima nota, quindi vale la pena provarlo sul
 * database vero e non su un array: la somma passa da due query con perimetri
 * diversi (gli incassi prendono l'azienda dalla fattura, le spese ce l'hanno
 * in casa) ed è lì che si sbaglia.
 *
 * Ogni test gira in una transazione chiusa con rollback.
 *
 * @internal
 */
final class BankAccountBalanceTest extends CIUnitTestCase
{
    private const DB_COPY = 'invoiceplane_posttest';

    // $testDb e non $db: CIUnitTestCase dichiara già $db senza tipo, e
    // ridefinirlo (tipizzato o privato) è un errore fatale in PHP.
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
            $this->testDb->query('SELECT bank_account_opening_balance FROM ip_bank_accounts LIMIT 1');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non aggiornata: ' . $config->tests['database']);
        }

        $_SESSION['active_company_id'] = 1;

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

    public function testIlSaldoSommaAperturaIncassiESpese(): void
    {
        $conto = $this->conto('Conto saldo', 1000.0, '2026-01-01');

        $this->incasso($conto, 250.0);
        $this->spesa($conto, 100.0);

        $saldo = $this->saldo($conto);

        $this->assertSame(1000.0, $saldo['opening']);
        $this->assertSame(250.0, $saldo['in']);
        $this->assertSame(100.0, $saldo['out']);
        $this->assertSame(1150.0, $saldo['balance'], 'Apertura + incassi - spese');
        $this->assertTrue($saldo['declared']);
        $this->assertSame('2026-01-01', $saldo['date']);
    }

    public function testSenzaSaldoInizialeIlTotaleSonoISoliMovimenti(): void
    {
        $conto = $this->conto('Conto senza apertura', null, null);

        $this->incasso($conto, 80.0);

        $saldo = $this->saldo($conto);

        $this->assertFalse($saldo['declared'], 'Un saldo iniziale mai scritto non è zero dichiarato');
        $this->assertSame(80.0, $saldo['balance']);
        $this->assertNull($saldo['date']);
    }

    /**
     * I movimenti precedenti alla data di apertura restano fuori dal totale:
     * sono già dentro il saldo iniziale, e sommarli li conterebbe due volte.
     */
    public function testIMovimentiPrimaDellAperturaRestanoFuori(): void
    {
        $conto = $this->conto('Conto con apertura', 1000.0, '2026-06-01');

        $this->incasso($conto, 300.0, '2026-05-20'); // prima dell'apertura
        $this->incasso($conto, 200.0, '2026-06-10'); // dopo

        $saldo = $this->saldo($conto);

        $this->assertSame(200.0, $saldo['in'], 'Solo l\'incasso successivo all\'apertura entra nel totale');
        $this->assertSame(1200.0, $saldo['balance']);
    }

    /**
     * Il movimento di estratto conto non entra nella somma: è la stessa banca
     * fotografata, non soldi in più. Contarlo due volte gonfierebbe il saldo.
     */
    public function testIMovimentiBancariNonSiSommano(): void
    {
        $conto = $this->conto('Conto banca', 100.0, null);

        $this->incasso($conto, 50.0);

        $this->testDb->query(
            'INSERT INTO ip_bank_transactions (bank_account_id, transaction_date, transaction_amount, transaction_description, transaction_hash, is_reconciled, company_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$conto, '2026-02-01', 50.0, 'Bonifico cliente', 'prova-' . $conto, false, 1]
        );

        $this->assertSame(150.0, $this->saldo($conto)['balance'], 'Il saldo può restare 150');
    }

    /**
     * La lista è paginata: saldi e conteggi si chiedono solo per i conti
     * mostrati, e il filtro non deve far comparire gli altri.
     */
    public function testIlFiltroCalcolaSoloIContiRichiesti(): void
    {
        $uno = $this->conto('Conto filtro uno', 100.0, '2026-01-01');
        $due = $this->conto('Conto filtro due', 200.0, '2026-01-01');

        $model = new BankAccountModel();

        $saldi = $model->balances([$uno]);

        $this->assertArrayHasKey($uno, $saldi);
        $this->assertArrayNotHasKey($due, $saldi, 'Il conto non richiesto non va calcolato');
        $this->assertSame([], $model->balances([]), 'Nessun conto da mostrare, nessuna query da fare');

        $this->testDb->query(
            'UPDATE ip_invoices SET bank_account_id = ? WHERE invoice_id = (SELECT invoice_id FROM ip_invoices WHERE company_id = 1 ORDER BY invoice_id LIMIT 1)',
            [$uno]
        );

        $conteggi = $model->usageCounts([$uno]);

        $this->assertGreaterThan(0, $conteggi[$uno]['invoices'] ?? 0);
        $this->assertArrayNotHasKey($due, $conteggi, 'I conteggi non richiesti non vanno aggregati');
        $this->assertSame([], $model->usageCounts([]));
    }

    /**
     * Il form manda il saldo nel formato locale e la data all'italiana: quello
     * che finisce nel database deve essere il numero e la data del motore.
     */
    public function testIlFormConverteSaldoEData(): void
    {
        $model = new BankAccountModel();

        $dati = $model->dbArrayFromPost([
            'bank_account_name'            => 'Conto form',
            'bank_account_opening_balance' => '1.234,56',
            'bank_account_opening_date'    => '01/01/2026',
        ]);

        $this->assertSame(1234.56, (float) $dati['bank_account_opening_balance']);
        $this->assertSame('2026-01-01', $dati['bank_account_opening_date']);

        // Campo lasciato vuoto: resta nullo, non zero. «Non dichiarato» e
        // «dichiarato zero» sono due cose diverse in un saldo.
        $vuoti = $model->dbArrayFromPost([
            'bank_account_name'            => 'Conto vuoto',
            'bank_account_opening_balance' => '',
            'bank_account_opening_date'    => '',
        ]);

        $this->assertNull($vuoti['bank_account_opening_balance']);
        $this->assertNull($vuoti['bank_account_opening_date']);
    }

    // -----------------------------------------------------------------
    // Aiuti
    // -----------------------------------------------------------------

    private function conto(string $nome, ?float $apertura, ?string $data): int
    {
        $this->testDb->query(
            'INSERT INTO ip_bank_accounts (bank_account_name, bank_account_opening_balance, bank_account_opening_date, bank_account_is_default, company_id)
             VALUES (?, ?, ?, ?, ?)',
            [$nome, $apertura, $data, false, 1]
        );

        return (int) $this->testDb->insertID();
    }

    private function incasso(int $conto, float $importo, string $data = '2026-02-01'): void
    {
        $fattura = $this->testDb->query(
            'SELECT invoice_id FROM ip_invoices WHERE company_id = 1 ORDER BY invoice_id LIMIT 1'
        )->getRowArray();

        $this->assertNotNull($fattura, 'Serve una fattura dell\'azienda 1 per provare il saldo');

        $this->testDb->query(
            'INSERT INTO ip_payments (invoice_id, payment_method_id, payment_date, payment_amount, bank_account_id)
             VALUES (?, ?, ?, ?, ?)',
            [(int) $fattura['invoice_id'], 1, $data, $importo, $conto]
        );
    }

    private function spesa(int $conto, float $importo): void
    {
        $this->testDb->query(
            'INSERT INTO ip_expenses (expense_date, supplier_name, expense_amount, expense_tax, expense_total, expense_paid, expense_source, bank_account_id, company_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            ['2026-02-02', 'Fornitore', $importo, 0, $importo, 0, 'prova', $conto, 1]
        );
    }

    /** @return array{opening: float, date: ?string, in: float, out: float, balance: float, declared: bool} */
    private function saldo(int $conto): array
    {
        $saldi = (new BankAccountModel())->balances();

        $this->assertArrayHasKey($conto, $saldi, 'Il conto appena creato deve comparire nei saldi');

        return $saldi[$conto];
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
