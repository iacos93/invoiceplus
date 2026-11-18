<?php

use App\Models\InvoiceGroupModel;
use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Eliminare l'ultima fattura numerata restituisce il numero alla serie.
 *
 * Il caso da cui nasce: preventivo convertito in fattura, bozza eliminata,
 * nuova conversione. Il contatore della serie bozze restava avanti e la
 * seconda fattura prendeva il numero dopo, lasciando un buco. Un numero in
 * mezzo alla serie invece non si riusa: il contatore ha gia' emesso i
 * successivi.
 *
 * Ogni test gira in una transazione chiusa con rollback.
 *
 * @internal
 */
final class InvoiceNumberReleaseTest extends CIUnitTestCase
{
    private const DB_COPY = 'invoiceplane_posttest';

    private ?BaseConnection $testDb = null;

    /** @var array<string, mixed> */
    private array $originalTests = [];

    private int $groupId = 0;

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
            $row          = $this->testDb->query('SELECT invoice_group_id FROM ip_invoice_groups ORDER BY invoice_group_id LIMIT 1')->getRowArray();
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non disponibile: ' . $config->tests['database']);
        }

        $this->groupId = (int) ($row['invoice_group_id'] ?? 0);

        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);

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

    public function testUltimoNumeroTornaAllaSerie(): void
    {
        $model  = new InvoiceGroupModel();
        $numero = $model->generateInvoiceNumber($this->groupId);
        $dopo   = $this->nextId();

        $model->releaseNumber($this->groupId, $numero);

        $this->assertSame($dopo - 1, $this->nextId());
        $this->assertSame($numero, $model->generateInvoiceNumber($this->groupId), 'La fattura successiva deve riprendere lo stesso numero');
    }

    public function testNumeroInMezzoAllaSerieNonSiRiusa(): void
    {
        $model = new InvoiceGroupModel();
        $primo = $model->generateInvoiceNumber($this->groupId);
        $model->generateInvoiceNumber($this->groupId);
        $prima = $this->nextId();

        $model->releaseNumber($this->groupId, $primo);

        $this->assertSame($prima, $this->nextId());
    }

    private function nextId(): int
    {
        return (int) $this->testDb->query(
            'SELECT invoice_group_next_id FROM ip_invoice_groups WHERE invoice_group_id = ?',
            [$this->groupId]
        )->getRowArray()['invoice_group_next_id'];
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
