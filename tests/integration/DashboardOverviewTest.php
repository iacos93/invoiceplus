<?php

use App\Models\SettingModel;
use App\Services\DashboardOverview;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * La Panoramica era un metodo da duecentosettanta righe dentro il controller:
 * i suoi numeri si potevano vedere solo aprendo la pagina, e una query che
 * cambiava risultato passava inosservata finché non la si guardava a occhio.
 *
 * Qui il calcolo si interroga direttamente sulla copia del database e si
 * controllano le relazioni fra i numeri (il totale è la somma degli stati non
 * in bozza, il donut somma i valori positivi, il massimo del grafico non è mai
 * zero, i delta non esistono per "tutti gli anni"). Se la copia non c'è, il
 * test si salta.
 *
 * @internal
 */
final class DashboardOverviewTest extends CIUnitTestCase
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

    public function testITotaliSonoLaSommaDegliStatiSenzaBozze(): void
    {
        $dati = (new DashboardOverview())->overview((int) date('Y'));

        $atteso = 0.0;
        $conteggio = 0;
        foreach ($dati['invoice_status_totals'] as $riga) {
            if ((int) ($riga['invoice_status_id'] ?? 0) === 1) {
                continue;
            }
            $atteso += (float) ($riga['sum_total'] ?? 0);
            $conteggio += (int) ($riga['num_total'] ?? 0);
        }

        $this->assertSame(round($atteso, 2), round((float) $dati['invoice_total'], 2));
        $this->assertSame($conteggio, (int) $dati['invoice_count']);

        $donut = array_sum(array_column($dati['donut_data'], 'value'));
        $this->assertSame(round($donut, 2), round((float) $dati['donut_total'], 2));
    }

    public function testLeSerieHannoSeiMesiEIlMassimoNonEZero(): void
    {
        $dati = (new DashboardOverview())->overview(0);

        $this->assertCount(6, $dati['chart_series']);
        $this->assertCount(6, $dati['quote_series']);
        $this->assertCount(6, $dati['expense_series']);
        $this->assertCount(6, $dati['profit_series']);
        $this->assertCount(6, $dati['iva_series']);
        $this->assertGreaterThanOrEqual(1.0, (float) $dati['chart_max']);

        // "Tutti gli anni" non ha un anno precedente: niente delta.
        $this->assertNull($dati['invoice_delta']);
        $this->assertNull($dati['profit_delta']);

        // Il tasso di incasso è una percentuale (il model moltiplica per 100):
        // un tasso fuori da 0-100 significa che i saldi non tornano.
        $this->assertGreaterThanOrEqual(0.0, (float) $dati['collection_rate']);
        $this->assertLessThanOrEqual(100.0, (float) $dati['collection_rate']);
    }

    public function testLUtileEDifferenzaFraRicaviNettiECostiNetti(): void
    {
        $dati = (new DashboardOverview())->overview(0);

        $this->assertSame(
            round((float) $dati['invoice_net'] - (float) $dati['expenses_net'], 2),
            round((float) $dati['profit_total'], 2)
        );
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
