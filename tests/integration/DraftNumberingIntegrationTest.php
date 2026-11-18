<?php

use App\Models\ClientModel;
use App\Models\CompanyModel;
use App\Models\InvoiceGroupModel;
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
 * L'azienda del test si crea qui: quella seminata punta la serie bozze alla
 * serie ufficiale, è il caso che il codice deve neutralizzare e non un
 * prerequisito di cui fidarsi (in CI mancava e il test saltava).
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

    private int $azienda = 0;

    private int $gruppoUfficiale = 0;

    private int $serieBozze = 0;

    private int $cliente = 0;

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

        $this->resetCacheImpostazioni();

        $this->testDb->transBegin();

        // La copia di sviluppo ha "numero anche per le bozze" attivo; qui si
        // prova la configurazione di produzione, dove la bozza non prende il
        // progressivo ufficiale.
        (new SettingModel())->setSetting('generate_invoice_number_for_draft', 0);
        $this->resetCacheImpostazioni();
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
        $this->preparaAzienda();

        $draftId = (new InvoiceModel())->create([
            'client_id'         => $this->cliente,
            'invoice_status_id' => 1,
        ]);

        $this->assertGreaterThan(0, $draftId);

        $bozza = $this->riga($draftId);

        $this->assertSame(41, $this->nextId($this->gruppoUfficiale), 'La serie ufficiale non deve muoversi per una bozza');
        $this->assertSame(2, $this->nextId($this->serieBozze), 'La bozza consuma un numero dalla serie bozze');
        $this->assertSame('T-0001', (string) $bozza['invoice_number'], 'La bozza prende il numero dalla serie bozze');
        $this->assertTrue(db_bool($bozza['invoice_number_is_draft']), 'Il numero della bozza va marcato come provvisorio');

        (new InvoiceModel())->finalizeNumber($draftId);

        $definitivo = $this->riga($draftId);

        $this->assertSame(42, $this->nextId($this->gruppoUfficiale), 'Uscendo dalla bozza si consuma un solo progressivo');
        $this->assertSame('0041', (string) $definitivo['invoice_number'], 'Il numero definitivo viene dalla serie ufficiale');
        $this->assertFalse(db_bool($definitivo['invoice_number_is_draft']));
    }

    /**
     * Il caso di produzione: bozza rimasta senza numero. In lista e in
     * anteprima si legge «Bozza #id», mai il solo id.
     */
    public function testLaBozzaSenzaNumeroSiLeggeComeTale(): void
    {
        $this->preparaAzienda();

        $draftId = (new InvoiceModel())->create([
            'client_id'         => $this->cliente,
            'invoice_status_id' => 1,
        ]);

        (new InvoiceModel())->update($draftId, [
            'invoice_number'          => '',
            'invoice_number_is_draft' => false,
        ]);

        $sessione = $this->sessione();

        $anteprima = $this->withSession($sessione)->get('/invoices/view/' . $draftId);

        $this->assertSame(200, $anteprima->response()->getStatusCode());

        $htmlAnteprima = (string) $anteprima->response()->getBody();

        $this->assertStringContainsString('Bozza #' . $draftId, $htmlAnteprima, 'Anteprima: la bozza senza numero si legge come tale');
        $this->assertStringNotContainsString('Fattura ' . $draftId, $htmlAnteprima, 'Anteprima: il solo id non deve passare per un numero');

        $lista = $this->withSession($sessione)->get('/invoices/status/all');

        $this->assertSame(200, $lista->response()->getStatusCode());

        $htmlLista = (string) $lista->response()->getBody();

        $this->assertStringContainsString('Bozza #' . $draftId, $htmlLista, 'Lista: la bozza senza numero si legge come tale');
        $this->assertStringNotContainsString('<span class="ip-num">' . $draftId . '</span>', $htmlLista, 'Lista: il solo id non deve passare per un numero');
    }

    /**
     * Azienda usa-e-getta con due serie distinte, così il test non dipende
     * dalla configurazione seminata (bolzze = serie ufficiale) né da cache.
     */
    private function preparaAzienda(): void
    {
        $companies = new CompanyModel();

        $this->azienda = (int) $companies->insert([
            'company_name'         => 'Azienda bozze di prova',
            'company_country'      => 'IT',
            'company_is_default'   => false,
            'company_is_active'    => true,
            'company_date_created' => date('Y-m-d H:i:s'),
        ]);

        $gruppi = new InvoiceGroupModel();

        $this->gruppoUfficiale = (int) $gruppi->insert([
            'invoice_group_name'              => 'Fatture di prova',
            'invoice_group_identifier_format' => '{{{id}}}',
            'invoice_group_next_id'           => 41,
            'invoice_group_left_pad'          => 4,
            'company_id'                      => $this->azienda,
        ]);

        $this->serieBozze = (int) $gruppi->insert([
            'invoice_group_name'              => 'Bozze di prova',
            'invoice_group_identifier_format' => 'T-{{{id}}}',
            'invoice_group_next_id'           => 1,
            'invoice_group_left_pad'          => 4,
            'company_id'                      => $this->azienda,
        ]);

        $companies->update($this->azienda, [
            'default_invoice_group' => $this->gruppoUfficiale,
            'draft_invoice_group'   => $this->serieBozze,
        ]);

        $this->cliente = (int) (new ClientModel())->insert([
            'client_name'         => 'Cliente bozze di prova',
            'client_active'       => true,
            'client_date_created' => date('Y-m-d H:i:s'),
            'company_id'          => $this->azienda,
        ]);

        $_SESSION['active_company_id'] = $this->azienda;
        session()->set('active_company_id', $this->azienda);
    }

    /** @return array<string, mixed> */
    private function riga(int $invoiceId): array
    {
        return (array) $this->testDb->query(
            'SELECT invoice_number, invoice_number_is_draft FROM ip_invoices WHERE invoice_id = ?',
            [$invoiceId]
        )->getRowArray();
    }

    private function nextId(int $groupId): int
    {
        $row = $this->testDb->query(
            'SELECT invoice_group_next_id FROM ip_invoice_groups WHERE invoice_group_id = ?',
            [$groupId]
        )->getRowArray();

        return (int) ($row['invoice_group_next_id'] ?? -1);
    }

    /** @return array<string, mixed> */
    private function sessione(): array
    {
        return [
            'user_id'           => 1,
            'user_type'         => 1,
            'user_name'         => 'Admin',
            'active_company_id' => $this->azienda,
        ];
    }

    private function resetCacheImpostazioni(): void
    {
        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);
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
