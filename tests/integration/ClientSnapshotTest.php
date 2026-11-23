<?php

use App\Models\ClientModel;
use App\Models\DeliveryNoteModel;
use App\Models\InvoiceModel;
use App\Models\QuoteModel;
use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * L'intestazione di un documento è quella del giorno in cui nasce.
 *
 * Prima di questa copia il nome, l'indirizzo e i dati fiscali si rileggevano
 * dall'anagrafica a ogni apertura: correggere il nome di un cliente riscriveva
 * il passato, e la stessa fattura mostrava due intestazioni diverse a seconda
 * di quando la si apriva. Qui si verifica che la rinomina non tocchi fatture,
 * preventivi e DDT già emessi, che il documento nato senza copia (anteriore
 * alla migrazione) mostri l'anagrafica, e che la copia segua invece il cliente
 * quando lo si cambia davvero sul documento. Le bozze invece seguono
 * l'anagrafica fino all'invio, che ferma l'intestazione di quel momento.
 *
 * Ogni test gira in una transazione chiusa con rollback sulla copia del
 * database, come le altre prove di scrittura.
 *
 * @internal
 */
final class ClientSnapshotTest extends CIUnitTestCase
{
    private const DB_COPY = 'invoiceplane_posttest';

    private ?BaseConnection $testDb = null;

    /** @var array<string, mixed> */
    private array $originalTests = [];

    private int $utente = 1;

    private int $gruppo = 0;

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
            // La colonna arriva dalla migrazione AddClientSnapshotToDocuments:
            // sulla copia non applicata il test non ha niente da verificare.
            $this->testDb->query('SELECT client_snapshot FROM ip_invoices LIMIT 1');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database senza client_snapshot: ' . $config->tests['database']);
        }

        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);

        $_SESSION['active_company_id'] = 1;

        $row          = $this->testDb->query('SELECT user_id FROM ip_users ORDER BY user_id LIMIT 1')->getRow();
        $this->utente = (int) ($row->user_id ?? 1);

        $gruppo = $this->testDb->query(
            'SELECT invoice_group_id FROM ip_invoice_groups WHERE company_id = 1 ORDER BY invoice_group_id LIMIT 1'
        )->getRow();

        $this->gruppo = (int) ($gruppo->invoice_group_id ?? 0);

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
     * Rinomina e cambio d'indirizzo in anagrafica: la fattura già emessa
     * continua a mostrare chi c'era quando è nata.
     */
    public function testRinominareIlClienteNonToccaLaFatturaGiaEmessa(): void
    {
        $cliente = $this->cliente('Alfa Srl', 'Via Roma 1', 'IT01234567890');
        $fattura = $this->fatturaEmessa($cliente);

        $prima = (new InvoiceModel())->getById($fattura);

        $this->assertSame('Alfa Srl', $prima['client_name'], 'Fattura appena creata: intestazione dal cliente');
        $this->assertSame('Via Roma 1', $prima['client_address_1']);

        (new ClientModel())->saveRecord($cliente, [
            'client_name'      => 'Beta Srl',
            'client_address_1' => 'Via Nuova 9',
        ]);

        $dopo = (new InvoiceModel())->getById($fattura);

        $this->assertSame('Alfa Srl', $dopo['client_name'], 'Rinomina: la fattura emessa non deve cambiare nome');
        $this->assertSame('Via Roma 1', $dopo['client_address_1'], 'Rinomina: nemmeno l\'indirizzo deve seguire l\'anagrafica');
        $this->assertSame('Beta Srl', (new ClientModel())->find($cliente)['client_name'], 'Anagrafica: il nuovo nome va salvato');
    }

    /** Preventivi e DDT seguono la stessa regola della fattura. */
    public function testPreventivoEDdtRestanoAlNominativoDiQuandoSonoNati(): void
    {
        $cliente = $this->cliente('Alfa Srl', 'Via Roma 1', 'IT01234567890');

        $preventivo = (int) (new QuoteModel())->saveRecord(null, [
            'client_id'           => $cliente,
            'user_id'             => $this->utente,
            'invoice_group_id'    => $this->gruppo,
            'quote_status_id'     => 2,
            'quote_date_created'  => date('Y-m-d'),
            'quote_number'        => '',
        ]);

        $ddt = (int) (new DeliveryNoteModel())->saveRecord(null, [
            'client_id'           => $cliente,
            'invoice_group_id'    => $this->gruppo,
            'delivery_note_date'  => date('Y-m-d'),
            'delivery_note_number' => '',
        ]);

        $this->assertGreaterThan(0, $preventivo, 'Preventivo non creato');
        $this->assertGreaterThan(0, $ddt, 'DDT non creato');

        (new ClientModel())->saveRecord($cliente, ['client_name' => 'Beta Srl']);

        $this->assertSame('Alfa Srl', (new QuoteModel())->getById($preventivo)['client_name'], 'Preventivo: nome cambiato dalla rinomina');
        $this->assertSame('Alfa Srl', (new DeliveryNoteModel())->getById($ddt)['client_name'], 'DDT: nome cambiato dalla rinomina');
    }

    /**
     * Spostare la fattura su un altro cliente è un cambio vero: la copia si
     * riscrive, altrimenti il documento mostrerebbe il cliente sbagliato.
     */
    public function testCambiareIlClienteDellaFatturaAggiornaLaCopia(): void
    {
        $alfa  = $this->cliente('Alfa Srl', 'Via Roma 1', 'IT01234567890');
        $gamma = $this->cliente('Gamma Srl', 'Corso Italia 2', 'IT09876543210');

        $fattura = $this->fatturaEmessa($alfa);

        (new InvoiceModel())->update($fattura, ['client_id' => $gamma]);

        $dopo = (new InvoiceModel())->getById($fattura);

        $this->assertSame('Gamma Srl', $dopo['client_name'], 'Cambio cliente: la copia deve seguire il nuovo cliente');
        $this->assertSame('Corso Italia 2', $dopo['client_address_1']);
    }

    /**
     * Un documento anteriore alla migrazione, o con la copia illeggibile,
     * non resta vuoto: mostra l'anagrafica come faceva prima.
     */
    public function testDocumentoSenzaCopiaMostraLAnagrafica(): void
    {
        $cliente = $this->cliente('Alfa Srl', 'Via Roma 1', 'IT01234567890');
        $fattura = $this->fattura($cliente);

        $this->testDb->table('ip_invoices')
            ->where('invoice_id', $fattura)
            ->update(['client_snapshot' => null]);

        $this->assertSame('Alfa Srl', (new InvoiceModel())->getById($fattura)['client_name']);
    }

    /**
     * Una bozza non è ancora uscita: se nel frattempo si corregge il cliente
     * (la partita IVA sbagliata, l'indirizzo), deve partire con i dati giusti.
     */
    public function testLaBozzaSegueLAnagrafica(): void
    {
        $cliente = $this->cliente('Alfa Srl', 'Via Roma 1', 'IT01234567890');
        $fattura = $this->fattura($cliente);

        (new ClientModel())->saveRecord($cliente, [
            'client_name'   => 'Beta Srl',
            'client_vat_id' => 'IT11111111111',
        ]);

        $bozza = (new InvoiceModel())->getById($fattura);

        $this->assertSame('Beta Srl', $bozza['client_name'], 'Bozza: deve mostrare il nome corretto in anagrafica');
        $this->assertSame('IT11111111111', $bozza['client_vat_id'], 'Bozza: deve partire con la partita IVA corretta');
    }

    /**
     * L'intestazione si ferma quando la bozza diventa inviata, con i dati di
     * quel momento, non con quelli del giorno in cui la bozza è nata; dopo,
     * un'altra correzione in anagrafica non la tocca più.
     */
    public function testLInvioFermaLIntestazioneDiQuelMomento(): void
    {
        $cliente = $this->cliente('Alfa Srl', 'Via Roma 1', 'IT01234567890');
        $fattura = $this->fattura($cliente);

        (new ClientModel())->saveRecord($cliente, ['client_name' => 'Beta Srl']);
        (new InvoiceModel())->update($fattura, ['invoice_status_id' => 2]);
        (new ClientModel())->saveRecord($cliente, ['client_name' => 'Gamma Srl']);

        $this->assertSame(
            'Beta Srl',
            (new InvoiceModel())->getById($fattura)['client_name'],
            'Invio: deve restare il nome in anagrafica al momento dell\'invio'
        );
    }

    /** Salva anche la copia sul documento, non solo sull'anagrafica. */
    public function testLaFatturaPortaLaCopiaDelCliente(): void
    {
        $cliente = $this->cliente('Alfa Srl', 'Via Roma 1', 'IT01234567890');
        $fattura = $this->fattura($cliente);

        $copia = json_decode(
            (string) $this->testDb->table('ip_invoices')
                ->select('client_snapshot')
                ->where('invoice_id', $fattura)
                ->get()
                ->getRowArray()['client_snapshot'],
            true
        );

        $this->assertIsArray($copia, 'client_snapshot non è JSON');
        $this->assertSame($cliente, (int) $copia['client_id']);
        $this->assertSame('Alfa Srl', $copia['client_name']);
        $this->assertSame('IT01234567890', $copia['client_vat_id']);
    }

    private function cliente(string $nome, string $indirizzo, string $partita): int
    {
        $id = (int) (new ClientModel())->saveRecord(null, [
            'client_name'      => $nome,
            'client_address_1' => $indirizzo,
            'client_city'      => 'Milano',
            'client_zip'       => '20100',
            'client_country'   => 'IT',
            'client_vat_id'    => $partita,
            'client_tax_code'  => '01234567890',
            'company_id'       => 1,
        ]);

        $this->assertGreaterThan(0, $id, 'Cliente non creato: ' . (new ClientModel())->saveErrorSummary());

        return $id;
    }

    private function resetConnections(): void
    {
        $ref = new ReflectionClass(\CodeIgniter\Database\Config::class);

        // Le connessioni si chiudono prima di dimenticarle: lasciate aperte
        // restano vive fino alla fine della suite.
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

    private function fattura(int $cliente): int
    {
        $id = (new InvoiceModel())->create([
            'client_id'        => $cliente,
            'user_id'          => $this->utente,
            'invoice_group_id' => $this->gruppo,
        ]);

        $this->assertGreaterThan(0, $id, 'Fattura non creata');

        return $id;
    }

    /** Fattura creata e subito segnata come inviata: la copia è ferma. */
    private function fatturaEmessa(int $cliente): int
    {
        $id = $this->fattura($cliente);

        $this->assertTrue((new InvoiceModel())->update($id, ['invoice_status_id' => 2]), 'Fattura non segnata come inviata');

        return $id;
    }
}
