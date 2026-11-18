<?php

use App\Models\SettingModel;
use App\Models\UnitModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il contratto di IpBaseModel::saveRecord(): id se ha scritto, 0 se no.
 *
 * Il ramo update restituiva l'id anche quando update() falliva, quindi i form
 * che si fidano del valore annunciavano un salvataggio mai avvenuto. Qui si
 * fissa il contratto su un modello qualsiasi (unità di misura), non su
 * SupplierModel che ha un override proprio.
 *
 * Ogni test gira in una transazione chiusa con rollback.
 *
 * @internal
 */
final class IpBaseModelSaveRecordTest extends CIUnitTestCase
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
            $this->testDb->query('SELECT 1 FROM ip_units LIMIT 1');
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
        $id = (new UnitModel())->saveRecord(null, [
            'unit_name'      => 'Collaudo',
            'unit_name_plrl' => 'Collaudo',
            'unit_type'      => 'pezzi',
        ]);

        $this->assertGreaterThan(0, $id, 'Creazione: atteso un id');

        $riga = $this->testDb->query('SELECT unit_name FROM ip_units WHERE unit_id = ?', [$id])->getRowArray();

        $this->assertSame('Collaudo', $riga['unit_name']);
    }

    public function testAggiornamentoRiuscitoRestituisceLId(): void
    {
        $model = new UnitModel();

        $id = $model->saveRecord(null, [
            'unit_name'      => 'Collaudo',
            'unit_name_plrl' => 'Collaudo',
        ]);
        $this->assertGreaterThan(0, $id);

        $esito = (new UnitModel())->saveRecord($id, ['unit_name_plrl' => 'Collaudi']);

        $this->assertSame($id, $esito, 'Aggiornamento riuscito: atteso l\'id');

        $riga = $this->testDb->query('SELECT unit_name_plrl FROM ip_units WHERE unit_id = ?', [$id])->getRowArray();
        $this->assertSame('Collaudi', $riga['unit_name_plrl']);
    }

    /**
     * Il difetto: la riga veniva rifiutata e la classe restituiva lo stesso
     * l'id, quindi il form annunciava il salvataggio.
     */
    public function testAggiornamentoRifiutatoRestituisceZero(): void
    {
        $model = new UnitModel();

        $id = $model->saveRecord(null, [
            'unit_name'      => 'Collaudo',
            'unit_name_plrl' => 'Collaudo',
        ]);
        $this->assertGreaterThan(0, $id);

        // unit_name vuoto: la regola required lo rifiuta, e la colonna resta com'era.
        $rifiutato = new UnitModel();
        $esito     = $rifiutato->saveRecord($id, ['unit_name' => '']);

        $this->assertSame(0, $esito, 'Aggiornamento rifiutato: atteso 0, non l\'id');
        $this->assertStringContainsString('obbligatorio', $rifiutato->saveErrorSummary(), 'Il motivo del rifiuto deve arrivare al form');

        $riga = $this->testDb->query('SELECT unit_name FROM ip_units WHERE unit_id = ?', [$id])->getRowArray();
        $this->assertSame('Collaudo', $riga['unit_name'], 'Riga rifiutata: non doveva cambiare');
    }

    public function testCreazioneRifiutataRestituisceZero(): void
    {
        $prima = (int) $this->testDb->query('SELECT COUNT(*) AS n FROM ip_units')->getRow()->n;

        $esito = (new UnitModel())->saveRecord(null, ['unit_name' => '', 'unit_name_plrl' => '']);

        $this->assertSame(0, $esito);

        $dopo = (int) $this->testDb->query('SELECT COUNT(*) AS n FROM ip_units')->getRow()->n;
        $this->assertSame($prima, $dopo, 'Creazione rifiutata: non doveva nascere nessuna riga');
    }

    /**
     * Senza un errore di validazione da mostrare, il riepilogo non inventa un
     * dettaglio: dice che ha rifiutato il database.
     */
    public function testRiepilogoErroreSenzaValidazioneParlaDelDatabase(): void
    {
        $this->assertSame('salvataggio rifiutato dal database', (new UnitModel())->saveErrorSummary());
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
