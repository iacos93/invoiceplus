<?php

use App\Models\InvoiceModel;
use App\Models\ItemModel;
use App\Models\SettingModel;
use App\Services\FatturaPaXmlService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il tracciato FatturaPA viveva dentro Invoices::generateXml: cinquecento
 * righe che nessun test attraversava, perché la generazione finiva dritta in
 * una risposta HTTP. Un errore lì non si vede in pagina, si vede come scarto
 * dello SdI — cioè giorni dopo, dal commercialista.
 *
 * Qui il servizio viene chiamato con una fattura vera della copia del
 * database: si controlla che il tracciato sia ben formato, che porti numero e
 * righe del documento e che due generazioni di seguito diano lo stesso file.
 * Non è un confronto byte per byte con un file atteso (i dati della copia
 * cambiano); è il controllo che il percorso completo non perda pezzi.
 *
 * Gli elementi si cercano per nome locale, non per prefisso: il prefisso del
 * namespace è una scelta di serializzazione, non un dato del tracciato.
 *
 * Lavora sulla copia del database perché i test unitari girano su SQLite in
 * memoria, che non ha le tabelle dell'applicazione. Se la copia non c'è, il
 * test si salta e la suite resta verde.
 *
 * @internal
 */
final class FatturaXmlServiceTest extends CIUnitTestCase
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

        $this->resetConnections();

        try {
            $this->testDb = \Config\Database::connect();
            $this->testDb->query('SELECT 1 FROM ip_invoices LIMIT 1');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non disponibile');
        }

        // Le impostazioni (denominazione emittente, valuta, numerazione) sono
        // in una cache statica condivisa nel processo: va svuotata, altrimenti
        // il test legge quelle lasciate da un test precedente.
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

    public function testIlTracciatoRiportaIDatiDellaFattura(): void
    {
        $invoice = (new InvoiceModel())->getById($this->fatturaConRighe());
        $this->assertIsArray($invoice, 'Serve una fattura con righe nella copia');

        $result = (new FatturaPaXmlService())->build((int) $invoice['invoice_id']);

        $this->assertStringEndsWith('.xml', $result['filename']);

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($result['xml']), 'Il tracciato non è XML ben formato');
        $this->assertSame('FatturaElettronica', $dom->documentElement->localName);
        $this->assertNotSame('', (string) $dom->documentElement->namespaceURI);

        $this->assertSame(
            trim((string) $invoice['invoice_number']),
            $this->elemento($dom, 'Numero'),
            'Il numero nel tracciato deve essere quello della fattura'
        );
        $this->assertNotSame('', $this->elemento($dom, 'ProgressivoInvio'));
        $this->assertNotSame('', $this->elemento($dom, 'FormatoTrasmissione'));

        // L'importo che il cessionario confronta col suo gestionale.
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d{2}$/',
            $this->elemento($dom, 'ImportoTotaleDocumento'),
            'ImportoTotaleDocumento manca o non è nella forma 0.00'
        );

        // Ogni riga del documento deve essere nel tracciato: uno sconto di
        // documento aggiunge righe SC, mai ne toglie.
        $items = (new ItemModel())->getByInvoice((int) $invoice['invoice_id']);
        $this->assertGreaterThanOrEqual(
            count($items),
            $dom->getElementsByTagNameNS('*', 'DettaglioLinee')->length,
            'Il tracciato ha meno righe della fattura'
        );
    }

    public function testLaGenerazioneEDeterministica(): void
    {
        $id = $this->fatturaConRighe();

        $prima = (new FatturaPaXmlService())->build($id);
        $dopo  = (new FatturaPaXmlService())->build($id);

        // Rigenerare non deve cambiare progressivo né contenuto: un file
        // diverso a ogni click significa un tracciato che dipende da stato
        // esterno, non dai dati della fattura.
        $this->assertSame($prima['xml'], $dopo['xml']);
        $this->assertSame($prima['filename'], $dopo['filename']);
    }

    public function testFatturaInesistenteDaPaginaNonTrovata(): void
    {
        $this->expectException(PageNotFoundException::class);

        (new FatturaPaXmlService())->build(99999999);
    }

    /** Testo del primo elemento con quel nome locale, in qualunque namespace. */
    private function elemento(DOMDocument $dom, string $nome): string
    {
        $nodo = $dom->getElementsByTagNameNS('*', $nome)->item(0);

        return $nodo === null ? '' : trim($nodo->textContent);
    }

    private function fatturaConRighe(): int
    {
        $row = $this->testDb->query(
            'SELECT i.invoice_id FROM ip_invoices i
             JOIN ip_invoice_items it ON it.invoice_id = i.invoice_id
             GROUP BY i.invoice_id ORDER BY i.invoice_id ASC LIMIT 1'
        )->getRow();

        return (int) ($row->invoice_id ?? 0);
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
