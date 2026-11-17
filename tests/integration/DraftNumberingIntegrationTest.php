<?php

use App\Models\InvoiceModel;
use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * La numerazione delle bozze non deve consumare il progressivo ufficiale.
 *
 * Una bozza prende il numero dalla serie bozze (T-1, T-2...); il numero vero si
 * assegna quando esce dalla bozza, e il contatore della serie ufficiale non si
 * muove prima. In lista e in anteprima una bozza senza numero si legge
 * «Bozza #id»: prima al suo posto compariva il solo id, che ha la forma di un
 * numero e veniva letto — e citato — come tale.
 *
 * Ogni test gira in una transazione chiusa con rollback.
 *
 * @internal
 */
final class DraftNumberingIntegrationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const DB_COPY = 'invoiceplane_posttest';

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

        $row          = $this->testDb->query('SELECT user_id FROM ip_users ORDER BY user_id LIMIT 1')->getRow();
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
     * Bozza da serie dedicata: provvisorio T-n, serie ufficiale ferma; il
     * numero vero arriva solo uscendo dalla bozza.
     */
    public function testBozzaNonConsumaIlProgressivoUfficiale(): void
    {
        // La copia di sviluppo ha "numero anche per le bozze" attivo: qui si
        // prova la configurazione di produzione, dove la bozza non prende il
        // progressivo ufficiale.
        (new SettingModel())->setSetting('generate_invoice_number_for_draft', 0);
        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);

        $ufficiale = $this->gruppoUfficiale();
        $bozze     = $this->serieBozze();

        if ($ufficiale === null || $bozze === null) {
            $this->markTestSkipped('Servono una serie ufficiale e una serie bozze distinte');
        }

        $primaUfficiale = $this->nextId($ufficiale);
        $primaBozze     = $this->nextId($bozze);

        $draftId = (new InvoiceModel())->create([
            'client_id'         => $this->unCliente(),
            'invoice_status_id' => 1,
        ]);

        $this->assertGreaterThan(0, $draftId);

        $bozza = $this->testDb->query(
            'SELECT invoice_number, invoice_number_is_draft FROM ip_invoices WHERE invoice_id = ?',
            [$draftId]
        )->getRowArray();

        $this->assertSame($primaUfficiale, $this->nextId($ufficiale), 'La serie ufficiale non deve muoversi per una bozza');
        $this->assertSame($primaBozze + 1, $this->nextId($bozze), 'La bozza consuma un numero dalla serie bozze');
        $this->assertStringStartsWith('T-', (string) $bozza['invoice_number'], 'La bozza prende il numero dalla serie bozze');
        $this->assertTrue(db_bool($bozza['invoice_number_is_draft']), 'Il numero della bozza va marcato come provvisorio');

        (new InvoiceModel())->finalizeNumber($draftId);

        $definitivo = $this->testDb->query(
            'SELECT invoice_number, invoice_number_is_draft FROM ip_invoices WHERE invoice_id = ?',
            [$draftId]
        )->getRowArray();

        $this->assertSame($primaUfficiale + 1, $this->nextId($ufficiale), 'Uscendo dalla bozza si consuma un solo progressivo');
        $this->assertNotSame('', (string) $definitivo['invoice_number']);
        $this->assertStringStartsNotWith('T-', (string) $definitivo['invoice_number'], 'Il numero definitivo viene dalla serie ufficiale');
        $this->assertFalse(db_bool($definitivo['invoice_number_is_draft']));
    }

    /**
     * Il caso di produzione: bozza rimasta senza numero. In lista e in
     * anteprima si legge «Bozza #id», mai il solo id.
     */
    public function testLaBozzaSenzaNumeroSiLeggeComeTale(): void
    {
        (new SettingModel())->setSetting('generate_invoice_number_for_draft', 0);
        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);

        $draftId = (new InvoiceModel())->create([
            'client_id'         => $this->unCliente(),
            'invoice_status_id' => 1,
        ]);

        (new InvoiceModel())->update($draftId, [
            'invoice_number'          => '',
            'invoice_number_is_draft' => false,
        ]);

        $sessione = [
            'user_id'           => $this->utente,
            'user_type'         => 1,
            'user_name'         => 'Admin',
            'active_company_id' => 1,
        ];

        $anteprima = $this->withSession($sessione)->get('/invoices/view/' . $draftId);

        $this->assertSame(200, $anteprima->response()->getStatusCode());

        $htmlAnteprima = (string) $anteprima->response()->getBody();

        $this->assertStringContainsString('Bozza #' . $draftId, $htmlAnteprima, 'Anteprima: la bozza senza numero si legge come tale');
        $this->assertStringNotContainsString('Fattura ' . $draftId, $htmlAnteprima, 'Anteprima: il solo id non deve passare per un numero');

        $lista = $this->withSession($sessione)->get('/invoices/status/all');

        $this->assertSame(200, $lista->response()->getStatusCode());

        $htmlLista = (string) $lista->response()->getBody();

        $this->assertStringNotContainsString('<span class="ip-num">' . $draftId . '</span>', $htmlLista, 'Lista: il solo id non deve passare per un numero');
    }

    private function gruppoUfficiale(): ?int
    {
        $row = $this->testDb->query(
            'SELECT default_invoice_group FROM ip_companies WHERE company_id = 1'
        )->getRowArray();

        $id = (int) ($row['default_invoice_group'] ?? 0);

        return $id > 0 ? $id : null;
    }

    private function serieBozze(): ?int
    {
        $row = $this->testDb->query(
            'SELECT draft_invoice_group FROM ip_companies WHERE company_id = 1'
        )->getRowArray();

        $id = (int) ($row['draft_invoice_group'] ?? 0);

        return $id > 0 && $id !== $this->gruppoUfficiale() ? $id : null;
    }

    private function nextId(int $groupId): int
    {
        $row = $this->testDb->query(
            'SELECT invoice_group_next_id FROM ip_invoice_groups WHERE invoice_group_id = ?',
            [$groupId]
        )->getRowArray();

        return (int) ($row['invoice_group_next_id'] ?? -1);
    }

    private function unCliente(): int
    {
        $row = $this->testDb->query('SELECT client_id FROM ip_clients WHERE company_id = 1 ORDER BY client_id LIMIT 1')->getRowArray();

        if ($row === null) {
            $this->markTestSkipped('Serve un cliente nell\'azienda 1');
        }

        return (int) $row['client_id'];
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
