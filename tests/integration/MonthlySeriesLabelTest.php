<?php

use App\Models\ExpenseModel;
use App\Models\InvoiceAmountModel;
use App\Models\QuoteAmountModel;
use App\Models\SettingModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Le serie mensili di dashboard e report etichettano i mesi con
 * month_label(). Prima ogni model costruiva il proprio IntlDateFormatter:
 * togliendo quelle copie è rimasta per un attimo una riga che usava la
 * variabile appena eliminata, e nessun test unitario se ne accorgeva perché
 * le serie leggono dal database.
 *
 * Qui si chiamano i tre model sulla copia del database: le etichette devono
 * essere nomi di mese veri, non stringhe vuote né numeri. Se la copia non
 * c'è, il test si salta.
 *
 * @internal
 */
final class MonthlySeriesLabelTest extends CIUnitTestCase
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
        $this->restoreConnections();

        parent::tearDown();
    }

    public function testLeEtichetteSonoNomiDiMese(): void
    {
        $serie = [
            'fatture'    => (new InvoiceAmountModel())->getMonthlySeries(3),
            'preventivi' => (new QuoteAmountModel())->getMonthlySeries(3),
            'spese'      => (new ExpenseModel())->getMonthlySeries(3),
        ];

        foreach ($serie as $nome => $righe) {
            $this->assertCount(3, $righe, "$nome: la serie deve avere una riga per mese");

            foreach ($righe as $riga) {
                $this->assertArrayHasKey('label', $riga, "$nome: manca l'etichetta");
                $this->assertMatchesRegularExpression(
                    '/^[A-Z]\p{L}{2,}$/u',
                    (string) $riga['label'],
                    "$nome: etichetta non valida (" . $riga['label'] . ')'
                );
            }
        }
    }

    public function testLaSerieCopreTuttiIMesiRichiesti(): void
    {
        $serie = (new InvoiceAmountModel())->getMonthlySeries(12);

        $this->assertCount(12, $serie);
        $this->assertSame(['invoiced', 'paid', 'label'], array_keys($serie[0]));
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
