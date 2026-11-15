<?php

use App\Models\PaymentMethodModel;
use App\Models\PaymentModel;
use App\Models\ProductModel;
use App\Models\ProjectModel;
use App\Models\QuoteModel;
use App\Models\SettingModel;
use App\Models\SupplierModel;
use App\Models\TaskModel;
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
     * La pagina chiede i conteggi solo per i metodi che mostra. Il filtro deve
     * dare gli stessi numeri del conteggio completo su quegli id: un nome di
     * colonna sbagliato nel whereIn (payment_method contro payment_method_id)
     * non lo vedrebbe né PHPStan né un test sul percorso senza filtro.
     */
    public function testIConteggiFiltratiDeiMetodiCoincidonoConQuelliCompleti(): void
    {
        $model = new PaymentMethodModel();
        $tutti = $model->usageCounts();
        $pagina = array_slice(array_keys($tutti), 0, 3);

        $filtrati = $model->usageCounts($pagina);

        foreach ($pagina as $id) {
            $this->assertSame($tutti[$id], $filtrati[$id] ?? null, "Conteggio del metodo {$id}");
        }

        $this->assertSame(
            [],
            array_diff(array_keys($filtrati), $pagina),
            'Il filtro non deve aggregare metodi che la pagina non mostra'
        );

        $this->assertSame([], $model->usageCounts([]), 'Nessun metodo da mostrare, nessuna query da fare');
    }

    /**
     * Le quattro liste che paginavano tutto (fornitori, attività, progetti,
     * prodotti): una pagina non supera la dimensione chiesta, il totale del
     * pager è quello dell'intero elenco e la seconda pagina non ripete la
     * prima. I totali accessori (spese per fornitore, attività per progetto)
     * devono restare quelli dell'elenco completo.
     */
    public function testLeListeFornitoriAttivitaProgettiProdottiArrivanoUnaPaginaAllaVolta(): void
    {
        // Fornitori: pagina + totali per fornitore uguali alla lista completa.
        $fornitori = new SupplierModel();
        $tutti     = $fornitori->getAllOrdered();
        $prima     = $fornitori->getPage('all', 2, 1);

        $this->assertLessThanOrEqual(2, count($prima));
        $this->assertSame(count($tutti), $fornitori->pager->getTotal('ip_suppliers'));

        if ($tutti !== []) {
            $idPrimo = (int) $tutti[0]['supplier_id'];
            $nomePrimo = $tutti[0]['supplier_name'];

            $this->assertSame($nomePrimo, $prima[0]['supplier_name'], 'Stesso ordine dell\'elenco completo');
            $this->assertSame(
                (int) ($fornitori->getByIdWithTotals($idPrimo)['expense_count'] ?? 0),
                (int) $prima[0]['expense_count'],
                "Spese del fornitore {$idPrimo} invariate dopo la due passate"
            );
        }
    }

    public function testLeAttivitaArrivanoUnaPaginaAllaVolta(): void
    {
        $tutti = (new TaskModel())->getAllWithProject('all');
        $model = new TaskModel();
        $prima = $model->getAllWithProject('all', 2, 1);

        $this->assertLessThanOrEqual(2, count($prima));
        $this->assertSame(count($tutti), $model->pager->getTotal('ip_tasks'));

        if ($tutti !== []) {
            $this->assertSame(
                array_slice(array_column($tutti, 'task_id'), 0, 2),
                array_column($prima, 'task_id'),
                'Stesso ordine dell\'elenco completo'
            );
        }
    }

    public function testIProgettiArrivanoUnaPaginaAllaVolta(): void
    {
        $model = new ProjectModel();
        $tutti = $model->getAllWithClient(); // senza per-page: elenco completo
        $prima = $model->getAllWithClient(2, 1);

        $this->assertLessThanOrEqual(2, count($prima));
        $this->assertSame(count($tutti), $model->pager->getTotal('ip_projects'));

        // Le attività aggregate per progetto (e le 5 strisce di stato) devono
        // restare quelle dell'elenco completo.
        $tuttiPerId = [];

        foreach ($tutti as $riga) {
            $tuttiPerId[(int) $riga['project_id']] = $riga;
        }

        foreach ($prima as $progetto) {
            $id   = (int) $progetto['project_id'];
            $altro = $tuttiPerId[$id] ?? null;

            $this->assertNotNull($altro, "Il progetto {$id} della pagina è anche nell'elenco completo");
            $this->assertSame((int) ($altro['task_count'] ?? 0), (int) ($progetto['task_count'] ?? 0), "Attività del progetto {$id}");
            $this->assertSame($altro['project_last_task_date'] ?? null, $progetto['project_last_task_date'] ?? null, "Ultima attività del progetto {$id}");
            $this->assertSame((int) ($altro['task_overdue_count'] ?? 0), (int) ($progetto['task_overdue_count'] ?? 0), "Scadute del progetto {$id}");
        }
    }

    public function testIProdottiArrivanoUnaPaginaAllaVolta(): void
    {
        $tutti = (new ProductModel())->getAllWithJoins();
        $model = new ProductModel();
        $prima = $model->getAllWithJoins(2, 1);

        $this->assertLessThanOrEqual(2, count($prima));
        $this->assertSame(count($tutti), $model->pager->getTotal('ip_products'));

        if ($tutti !== []) {
            $this->assertSame(
                array_slice(array_column($tutti, 'product_id'), 0, 2),
                array_column($prima, 'product_id'),
                'Stesso ordine dell\'elenco completo'
            );
        }
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
