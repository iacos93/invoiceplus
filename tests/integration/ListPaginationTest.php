<?php

use App\Models\PaymentModel;
use App\Models\QuoteModel;
use App\Models\SettingModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Le liste di preventivi e pagamenti devono leggere una pagina, non l'archivio.
 *
 * Fatture, spese e clienti erano già paginati; queste due caricavano ogni riga
 * con tutti i join a ogni apertura, e il costo cresceva con gli anni di
 * storico. Qui si fissa il patto sulla copia del database: una pagina non
 * supera la dimensione chiesta, il totale del pager è quello dell'intero
 * elenco (è il numero mostrato accanto al titolo) e la seconda pagina non
 * ripete la prima.
 *
 * Se la copia non c'è, il test si salta.
 *
 * @internal
 */
final class ListPaginationTest extends CIUnitTestCase
{
    private const DB_COPY = 'invoiceplane_posttest';

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
            \Config\Database::connect()->query('SELECT 1 FROM ip_invoices LIMIT 1');
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

    public function testIPreventiviArrivanoUnaPaginaAllaVolta(): void
    {
        $tutti = count((new QuoteModel())->getAllWithStatus('all'));

        $model = new QuoteModel();
        $prima = $model->getAllWithStatus('all', 0, 0, '', 2, 1);

        $this->assertLessThanOrEqual(2, count($prima));
        $this->assertSame($tutti, $model->pager->getTotal('ip_quotes'), 'Il conteggio accanto al titolo deve restare quello dell\'intero elenco');

        if ($tutti > 2) {
            $seconda = (new QuoteModel())->getAllWithStatus('all', 0, 0, '', 2, 2);

            $this->assertSame(
                [],
                array_intersect(array_column($prima, 'quote_id'), array_column($seconda, 'quote_id')),
                'La seconda pagina non deve ripetere la prima'
            );
        }
    }

    public function testIPagamentiArrivanoUnaPaginaAllaVolta(): void
    {
        $tutti = (new PaymentModel())->getAllOrdered();

        $model = new PaymentModel();
        $prima = $model->getPage(2, 1);

        $this->assertLessThanOrEqual(2, count($prima));
        $this->assertSame(count($tutti), $model->pager->getTotal('ip_payments'));

        // Stesso ordine dell'elenco completo: paginare non deve cambiare
        // quale incasso si vede per primo.
        $this->assertSame(
            array_slice(array_column($tutti, 'payment_id'), 0, 2),
            array_column($prima, 'payment_id')
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
