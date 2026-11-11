<?php

use App\Models\InvoiceModel;
use App\Models\ItemModel;
use App\Models\SettingModel;
use App\Services\DocumentPdfBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;

/**
 * Collaudo su una copia del database di lavoro: due cicli di fatturazione
 * completi, dal documento all'incasso, con i dati veri.
 *
 * Non fa parte della suite: dipende dalla copia locale e dai clienti che ci
 * sono dentro. Si lancia a mano:
 *
 *   createdb -T invoiceplane invoiceplane_cicli
 *   IP_PDF_TEST_DB=invoiceplane_cicli vendor/bin/phpunit tests/collaudo
 *
 * @internal
 */
final class CicliFatturazioneTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const DB_COPY = 'invoiceplane_cicli';

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
            $this->testDb->query('SELECT 1 FROM ip_invoices LIMIT 1');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non disponibile: ' . $config->tests['database']);
        }

        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);

        $_SESSION['active_company_id'] = 1;

        $row          = $this->testDb->query('SELECT user_id FROM ip_users WHERE user_active = true ORDER BY user_id LIMIT 1')->getRow();
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
     * Due cicli di fatturazione uno dopo l'altro, su due clienti veri:
     * bozza, emissione con numero, PDF, XML FatturaPA, incasso a saldo.
     */
    public function testDueCicliCompletiConDatiVeri(): void
    {
        $clienti = $this->clienti(2);
        $iva     = $this->aliquota(22);

        $numeri = [];
        $totali = [];

        foreach ($clienti as $indice => $cliente) {
            $ciclo = $indice + 1;

            // Ciclo 2: documento del mese scorso, cosi' i due cicli non sono
            // la stessa fotografia ripetuta.
            $data = date('Y-m-d', strtotime('-1 month'));

            $id = $this->creaFattura($cliente, $data);

            $this->salvaRighe($id, [
                ['item_name' => 'Consulenza', 'item_quantity' => '2', 'item_price' => '100', 'item_tax_rate_id' => $iva],
                ['item_name' => 'Materiale', 'item_quantity' => '1', 'item_price' => '50', 'item_tax_rate_id' => $iva],
            ]);

            $totali[$ciclo] = $this->totali($id);
            $this->assertSame(250.0, (float) $totali[$ciclo]['invoice_item_subtotal'], "Ciclo {$ciclo}: imponibile");
            $this->assertSame(55.0, (float) $totali[$ciclo]['invoice_item_tax_total'], "Ciclo {$ciclo}: IVA");
            $this->assertSame(305.0, (float) $totali[$ciclo]['invoice_total'], "Ciclo {$ciclo}: totale");

            $this->emetti($id);
            $numeri[$ciclo] = trim((string) $this->campo('invoice_number', $id));
            $this->assertNotSame('', $numeri[$ciclo], "Ciclo {$ciclo}: numero non assegnato");

            $this->pdf($id, "Ciclo {$ciclo} PDF");
            $this->documento('/invoices/generate_xml/' . $id, 'FatturaElettronica', "Ciclo {$ciclo} XML");

            $this->paga($id, number_format((float) $totali[$ciclo]['invoice_total'], 2, ',', '.'));

            $dopo = $this->totali($id);
            $this->assertSame((float) $totali[$ciclo]['invoice_total'], (float) $dopo['invoice_paid'], "Ciclo {$ciclo}: incassato");
            $this->assertSame(0.0, (float) $dopo['invoice_balance'], "Ciclo {$ciclo}: saldo");
            $this->assertSame(4, (int) $this->campo('invoice_status_id', $id), "Ciclo {$ciclo}: stato pagata");
        }

        $this->assertNotSame($numeri[1], $numeri[2], 'I due cicli hanno lo stesso numero');
    }

    // -----------------------------------------------------------------
    // Aiuti
    // -----------------------------------------------------------------

    /** @return list<int> */
    private function clienti(int $quanti): array
    {
        $rows = $this->testDb->query(
            'SELECT client_id FROM ip_clients
             WHERE company_id = 1 AND client_active = 1
             ORDER BY client_id LIMIT ' . $quanti
        )->getResultArray();

        $this->assertCount($quanti, $rows, 'Servono almeno due clienti attivi nell\'azienda 1');

        return array_map(static fn (array $r): int => (int) $r['client_id'], $rows);
    }

    private function creaFattura(int $cliente, string $data): int
    {
        $risposta = $this->inviaConToken('/invoices/ajax/create', [
            'client_id'            => $cliente,
            'invoice_date_created' => $data,
            'invoice_group_id'     => $this->gruppo(),
        ], '/invoices/form_create');

        $posizione = (string) $risposta->response()->getHeaderLine('Location');
        $this->assertStringContainsString('/invoices/view/', $posizione, 'Creazione fattura non riuscita');

        return (int) basename(parse_url($posizione, PHP_URL_PATH));
    }

    /**
     * @param list<array<string, mixed>> $righe
     */
    private function salvaRighe(int $fattura, array $righe): void
    {
        $risposta = $this->inviaConToken('/invoices/ajax/save', [
            'invoice_id'    => $fattura,
            'items'         => json_encode($righe),
            'deleted_items' => '',
        ], '/invoices/view/' . $fattura);

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Righe non salvate');
    }

    private function emetti(int $fattura): void
    {
        $risposta = $this->inviaConToken('/invoices/ajax/save', [
            'invoice_id'        => $fattura,
            'invoice_status_id' => 2,
        ], '/invoices/view/' . $fattura);

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Emissione non riuscita');
    }

    private function paga(int $fattura, string $importo): void
    {
        $metodo = (int) $this->testDb->query(
            'SELECT payment_method_id FROM ip_payment_methods ORDER BY payment_method_id LIMIT 1'
        )->getRow()->payment_method_id;

        $risposta = $this->inviaConToken('/payments/form', [
            'invoice_id'        => $fattura,
            'payment_date'      => date('Y-m-d'),
            'payment_amount'    => $importo,
            'payment_method_id' => $metodo,
            'payment_note'      => 'Incasso del collaudo',
        ], '/payments/form?invoice_id=' . $fattura);

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Pagamento non salvato');
    }

    /**
     * Il PDF si costruisce dal servizio, non dalla rotta: generatePdf()
     * scrive il file e chiude la risposta, e sotto PHPUnit il processo
     * morirebbe a meta' suite.
     */
    private function pdf(int $fattura, string $etichetta): void
    {
        $invoice = (new InvoiceModel())->getById($fattura);
        $this->assertIsArray($invoice, $etichetta . ': fattura non letta');

        $items = (new ItemModel())->getByInvoice($fattura);
        $this->assertNotSame([], $items, $etichetta . ': righe non lette');

        $html = (new DocumentPdfBuilder())->invoice($invoice);
        $pdf  = pdf_create($html, 'fattura.pdf', false);

        $this->assertStringStartsWith('%PDF-', $pdf, $etichetta . ': firma assente');
        $this->assertGreaterThan(2000, strlen($pdf), $etichetta . ': file troppo corto');
        $this->assertStringContainsString('%%EOF', substr($pdf, -2048), $etichetta . ': PDF troncato');
    }

    private function documento(string $url, string $firma, string $etichetta): void
    {
        $risposta = $this->withSession($this->sessione())->get($url);

        $this->assertSame(200, $risposta->response()->getStatusCode(), $etichetta . ': stato');

        $corpo = (string) $risposta->response()->getBody();
        $this->assertGreaterThan(500, strlen($corpo), $etichetta . ': corpo troppo corto');
        $this->assertStringContainsString($firma, $corpo, $etichetta . ': firma assente');
    }

    /** @return array<string, mixed> */
    private function totali(int $fattura): array
    {
        $row = $this->testDb->query('SELECT * FROM ip_invoice_amounts WHERE invoice_id = ?', [$fattura])->getRowArray();
        $this->assertNotNull($row, "Importi mancanti per la fattura {$fattura}");

        return $row;
    }

    private function campo(string $colonna, int $fattura): mixed
    {
        $row = $this->testDb->query(
            "SELECT {$colonna} FROM ip_invoices WHERE invoice_id = ?",
            [$fattura]
        )->getRowArray();

        return $row[$colonna] ?? null;
    }

    private function gruppo(): int
    {
        return (int) $this->testDb->query(
            'SELECT invoice_group_id FROM ip_invoice_groups WHERE company_id = 1 ORDER BY invoice_group_id LIMIT 1'
        )->getRow()->invoice_group_id;
    }

    private function aliquota(float $percentuale): int
    {
        $row = $this->testDb->query(
            'SELECT tax_rate_id FROM ip_tax_rates WHERE tax_rate_percent = ? ORDER BY tax_rate_id LIMIT 1',
            [$percentuale]
        )->getRow();

        $this->assertNotNull($row, "Serve un'aliquota al {$percentuale}% nella copia");

        return (int) $row->tax_rate_id;
    }

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
