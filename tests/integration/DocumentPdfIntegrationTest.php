<?php

use App\Models\InvoiceModel;
use App\Models\ItemModel;
use App\Models\QuoteModel;
use App\Models\SettingModel;
use App\Services\DocumentPdfBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * PDF veri, non solo HTML: righe, aliquote, cliente ed emittente arrivano dal
 * database, Dompdf impagina, il file esce.
 *
 * È la prova che i test unitari non possono dare. Lì i template si rendono con
 * dati finti e una cache di impostazioni vuota; qui si passa dal percorso
 * completo, quello che in produzione ha prodotto l'allegato da 8.534 byte.
 *
 * Lavora su una copia del database (IP_PDF_TEST_DB, predefinito
 * invoiceplane_posttest) perché i test unitari girano su SQLite in memoria,
 * che non ha le tabelle dell'applicazione. Se la copia non c'è il test si
 * salta: la suite resta verde su una macchina senza quel database.
 *
 * @internal
 */
final class DocumentPdfIntegrationTest extends CIUnitTestCase
{
    private const DB_COPY = 'invoiceplane_posttest';

    private ?\CodeIgniter\Database\BaseConnection $testDb = null;

    /** @var array<string, mixed> */
    private array $originalTests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $config = config('Database');

        // In ambiente di test ogni connessione senza gruppo esplicito usa il
        // gruppo 'tests' (lo impone Database\Config::connect()), quindi è quel
        // gruppo che va puntato alla copia. Le credenziali arrivano da
        // 'default', lette dall'ambiente: nel progetto entra solo il nome del
        // database.
        $this->originalTests = $config->tests;
        $config->tests = $config->default;
        $config->tests['database'] = getenv('IP_PDF_TEST_DB') ?: self::DB_COPY;

        $dbName = $config->tests['database'];
        $this->resetConnections();

        try {
            $this->testDb = \Config\Database::connect();
            $this->testDb->query('SELECT 1 FROM ip_invoices LIMIT 1');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non disponibile: ' . $dbName);
        }

        // Impostazioni (logo, piè di pagina, valuta) lette dal database vero:
        // la cache statica è condivisa nel processo e va svuotata.
        $prop = (new ReflectionClass(SettingModel::class))->getProperty('settingsCache');
        $prop->setValue(null, null);

        // I model del conto bancario filtrano per azienda attiva.
        $_SESSION['active_company_id'] = 1;
    }

    protected function tearDown(): void
    {
        $this->restoreConnections();

        parent::tearDown();
    }

    /** Riporta il gruppo 'tests' com'era e chiude le connessioni aperte. */
    private function restoreConnections(): void
    {
        config('Database')->tests = $this->originalTests;

        $this->resetConnections();
    }

    /**
     * Svuota la cache statica delle connessioni: senza, quella già aperta
     * (SQLite in memoria, dai test precedenti) verrebbe riusata al posto
     * della copia.
     */
    private function resetConnections(): void
    {
        $ref = new ReflectionClass(\CodeIgniter\Database\Config::class);

        $ref->getProperty('instances')->setValue(null, []);
        $ref->getProperty('factory')->setValue(null, null);
    }

    public function testLaFatturaProduceUnPdfValido(): void
    {
        $invoice = (new InvoiceModel())->getById($this->ultimaFatturaConRighe());
        $this->assertIsArray($invoice, 'Serve una fattura con righe nel database di prova');

        $items = (new ItemModel())->getByInvoice((int) $invoice['invoice_id']);
        $this->assertNotSame([], $items);

        $html = (new DocumentPdfBuilder())->invoice($invoice);

        $this->assertStringContainsString(htmlsc((string) $invoice['invoice_number']), $html);
        $this->assertStringContainsString(htmlsc(trim((string) $items[0]['item_name'])), $html);

        $pdf = pdf_create($html, 'fattura.pdf', false);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(2000, strlen($pdf));
        $this->assertStringContainsString('%%EOF', substr($pdf, -2048));
    }

    public function testIlPreventivoProduceUnPdfValido(): void
    {
        $quote = (new QuoteModel())->getById($this->ultimoPreventivoConRighe());
        $this->assertIsArray($quote, 'Serve un preventivo con righe nel database di prova');

        $html = (new DocumentPdfBuilder())->quote($quote);

        $this->assertStringContainsString(htmlsc((string) $quote['quote_number']), $html);

        $pdf = pdf_create($html, 'preventivo.pdf', false);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(2000, strlen($pdf));
        $this->assertStringContainsString('%%EOF', substr($pdf, -2048));
    }

    /**
     * La colonna sconti deve seguire le righe davvero salvate: è la divergenza
     * che l'allegato email aveva (una copia della logica non calcolava lo
     * sconto e la colonna spariva).
     */
    public function testLaColonnaScontoSegueLeRigheReali(): void
    {
        $invoice = (new InvoiceModel())->getById($this->ultimaFatturaConRighe());
        $this->assertIsArray($invoice);

        $items = (new ItemModel())->getByInvoice((int) $invoice['invoice_id']);

        $attesa = 0;
        foreach ($items as $item) {
            if ((float) ($item['item_discount'] ?? 0) != 0.0) {
                $attesa = 1;
                break;
            }
        }

        $html = (new DocumentPdfBuilder())->invoice($invoice);

        $this->assertSame($attesa, substr_count($html, 'class="c-discount num"'));
    }

    private function ultimaFatturaConRighe(): int
    {
        $row = $this->testDb->query(
            'SELECT i.invoice_id FROM ip_invoices i
             JOIN ip_invoice_items it ON it.invoice_id = i.invoice_id
             GROUP BY i.invoice_id ORDER BY i.invoice_id DESC LIMIT 1'
        )->getRow();

        return (int) ($row->invoice_id ?? 0);
    }

    private function ultimoPreventivoConRighe(): int
    {
        $row = $this->testDb->query(
            'SELECT q.quote_id FROM ip_quotes q
             JOIN ip_quote_items it ON it.quote_id = q.quote_id
             GROUP BY q.quote_id ORDER BY q.quote_id DESC LIMIT 1'
        )->getRow();

        return (int) ($row->quote_id ?? 0);
    }
}
