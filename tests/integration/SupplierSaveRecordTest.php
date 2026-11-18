<?php

use App\Models\SettingModel;
use App\Models\SupplierModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * SupplierModel::saveRecord() deve dire quando non ha salvato.
 *
 * Il form fornitori, l'import delle fatture passive e suppliers:enrich passano
 * tutti di qui. Con l'id restituito anche a salvataggio fallito, il form
 * annunciava «Fornitore aggiornato correttamente» su una riga rimasta com'era e
 * l'import collegava la spesa a un fornitore mai creato; l'unica traccia
 * dell'errore restava in errors(), che nessuno leggeva.
 *
 * Ogni test gira in una transazione chiusa con rollback.
 *
 * @internal
 */
final class SupplierSaveRecordTest extends CIUnitTestCase
{
    private const DB_COPY = 'invoiceplane_posttest';

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
            $this->testDb->query('SELECT 1 FROM ip_suppliers LIMIT 1');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non disponibile: ' . $config->tests['database']);
        }

        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);

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

    public function testCreazioneRiuscitaRestituisceLId(): void
    {
        $id = (new SupplierModel())->saveRecord(null, [
            'supplier_name'   => 'Fornitore Collaudo SaveRecord',
            'supplier_active' => true,
        ]);

        $this->assertGreaterThan(0, $id, 'Creazione: atteso un id');

        $riga = $this->testDb->query(
            'SELECT * FROM ip_suppliers WHERE supplier_id = ?',
            [$id]
        )->getRowArray();

        $this->assertNotNull($riga, 'Creazione: riga non trovata');
        $this->assertSame('Fornitore Collaudo SaveRecord', $riga['supplier_name']);
        $this->assertSame(1, (int) $riga['company_id'], 'Creazione: azienda non assegnata');
    }

    /**
     * Email con due indirizzi: la validazione la rifiuta, e il modello non deve
     * far finta di aver scritto. Il valore già in archivio resta quello di prima.
     */
    public function testAggiornamentoRifiutatoRestituisceZero(): void
    {
        $model = new SupplierModel();

        $id = $model->saveRecord(null, [
            'supplier_name'  => 'Fornitore Collaudo SaveRecord',
            'supplier_email' => 'ordini@collaudo.test',
            'supplier_active' => true,
        ]);
        $this->assertGreaterThan(0, $id);

        $esito = (new SupplierModel())->saveRecord($id, [
            'supplier_name'  => 'Fornitore Collaudo SaveRecord',
            'supplier_email' => 'support@collaudo.test invoice@collaudo.test',
        ]);

        $this->assertSame(0, $esito, 'Aggiornamento rifiutato: atteso 0, non l\'id');

        $riga = $this->testDb->query(
            'SELECT supplier_email FROM ip_suppliers WHERE supplier_id = ?',
            [$id]
        )->getRowArray();

        $this->assertSame('ordini@collaudo.test', $riga['supplier_email'], 'Email già scritta: non doveva cambiare');
    }

    public function testAggiornamentoRiuscitoRestituisceLId(): void
    {
        $model = new SupplierModel();

        $id = $model->saveRecord(null, [
            'supplier_name'   => 'Fornitore Collaudo SaveRecord',
            'supplier_active' => true,
        ]);
        $this->assertGreaterThan(0, $id);

        $esito = (new SupplierModel())->saveRecord($id, ['supplier_city' => 'Collaudo Città']);

        $this->assertSame($id, $esito, 'Aggiornamento riuscito: atteso l\'id');

        $riga = $this->testDb->query(
            'SELECT supplier_city FROM ip_suppliers WHERE supplier_id = ?',
            [$id]
        )->getRowArray();

        $this->assertSame('Collaudo Città', $riga['supplier_city']);
    }

    /**
     * In creazione l'errore non produce nemmeno una riga: nessun id da
     * restituire, e nessun fornitore a metà in anagrafica.
     */
    public function testCreazioneRifiutataNonRestituisceUnId(): void
    {
        $prima = (int) $this->testDb->query('SELECT COUNT(*) AS n FROM ip_suppliers')->getRow()->n;

        $esito = (new SupplierModel())->saveRecord(null, [
            'supplier_name'  => 'Fornitore Collaudo SaveRecord',
            'supplier_email' => 'non-una-email',
            'supplier_active' => true,
        ]);

        $this->assertSame(0, $esito);

        $dopo = (int) $this->testDb->query('SELECT COUNT(*) AS n FROM ip_suppliers')->getRow()->n;
        $this->assertSame($prima, $dopo, 'Creazione rifiutata: non doveva nascere nessuna riga');
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
