<?php

use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Con «Tutti gli anni» le tre liste mostrano i mesi di un anno solo.
 *
 * Il conto sbagliato nasceva nel controller, che passava l'anno del filtro —
 * zero — a getMonthlyTotals(): i dodici importi diventavano la somma per anno
 * di ciascun mese, e il dicembre dell'anno prima finiva accanto ai mesi
 * dell'anno in corso. Qui si guarda la pagina vera: la barra deve portare
 * l'anno in corso, che è quello a cui si riferiscono gli importi.
 *
 * Legge la copia del database (`invoiceplane_posttest`); senza, salta e la
 * suite resterebbe verde avendo verificato meno.
 *
 * @internal
 */
final class PeriodNavYearPageTest extends CIUnitTestCase
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

        // Le fatture di prova nascono e spariscono con il test.
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
     * Le tre liste che condividono la barra periodo.
     *
     * @return array<string, array{0: string}>
     */
    public static function liste(): array
    {
        return [
            'fatture'     => ['/invoices/status/all'],
            'preventivi'  => ['/quotes/status/all'],
            'spese'       => ['/expenses'],
        ];
    }

    /**
     * Con «Tutti gli anni» nell'URL la barra parla dell'anno in corso: è quello
     * a cui appartengono gli importi sotto i mesi, e il clic su un mese lo
     * porta con sé.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('liste')]
    public function testSenzaAnnoLaBarraSegueQuelloInCorso(string $url): void
    {
        $anno = (int) date('Y');

        $risposta = $this->withSession($this->sessione())->get($url . '?year=0');

        $this->assertSame(200, $risposta->response()->getStatusCode(), $url . ': la lista deve aprirsi');

        $html = (string) $risposta->response()->getBody();

        $this->assertStringContainsString(
            'data-year="' . $anno . '"',
            $html,
            $url . ': la barra non segue l\'anno in corso con «Tutti gli anni»'
        );

        $this->assertMatchesRegularExpression(
            '/data-month="12"\s+href="[^"]*year=' . $anno . '&amp;month=12"/',
            $html,
            $url . ': il mese non porta l\'anno degli importi'
        );
    }

    /**
     * L'importo sotto il mese è quello dell'anno in corso: con due dicembre di
     * anni diversi la barra non li somma più.
     *
     * È il caso segnalato: in fattura la barra mostrava il dicembre dell'anno
     * prima accanto ai mesi dell'anno in corso.
     */
    public function testIlDicembreMostratoNonSommaGliAnni(): void
    {
        $anno = (int) date('Y');

        $this->creaFattura(($anno - 1) . '-12-15', 900.0);
        $this->creaFattura($anno . '-12-15', 100.0);

        $risposta = $this->withSession($this->sessione())->get('/invoices/status/all?year=0');

        $html    = (string) $risposta->response()->getBody();
        $dicembre = $this->importoDelMese($html, 12);

        $this->assertStringContainsString('100', $dicembre, 'Il dicembre mostrato non è quello dell\'anno in corso');
        $this->assertStringNotContainsString('1.000', $dicembre, 'Il dicembre mostrato somma gli anni');
    }

    /**
     * Con un anno scelto non cambia niente: la barra resta su quell'anno.
     */
    public function testConAnnoSceltoLaBarraRestaSuQuello(): void
    {
        $anno = (int) $this->testDb->query(
            'SELECT MIN(EXTRACT(YEAR FROM invoice_date_created)) AS anno FROM ip_invoices WHERE company_id = 1'
        )->getRow()->anno;

        $this->assertGreaterThan(0, $anno, 'La copia non ha fatture dell\'azienda 1 da usare come anno');

        $risposta = $this->withSession($this->sessione())->get('/invoices/status/all?year=' . $anno);

        $html = (string) $risposta->response()->getBody();

        $this->assertStringContainsString('data-year="' . $anno . '"', $html, 'La barra non segue l\'anno scelto');
        $this->assertStringContainsString('year=' . $anno . '&amp;month=12', $html, 'Il mese non porta l\'anno scelto');
    }

    /**
     * Una fattura emessa con il suo totale: serve solo alla barra periodo.
     */
    private function creaFattura(string $data, float $totale): void
    {
        $this->testDb->query(
            'INSERT INTO ip_invoices (user_id, client_id, invoice_group_id, invoice_status_id, invoice_date_created, company_id) '
            . 'VALUES (?, (SELECT MIN(client_id) FROM ip_clients WHERE company_id = 1), '
            . '(SELECT MIN(invoice_group_id) FROM ip_invoice_groups WHERE company_id = 1), 2, ?, 1)',
            [$this->utente, $data]
        );

        $id = (int) $this->testDb->insertID();

        $this->testDb->query(
            'INSERT INTO ip_invoice_amounts (invoice_id, invoice_sign, invoice_item_subtotal, invoice_item_tax_total, '
            . 'invoice_tax_total, invoice_total, invoice_paid, invoice_balance, invoice_discount_total, '
            . 'invoice_fund_amount, invoice_withholding_amount) VALUES (?, 1, ?, 0, 0, ?, 0, ?, 0, 0, 0)',
            [$id, $totale, $totale, $totale]
        );
    }

    /**
     * Il testo della voce del mese nella barra (nome e importo).
     */
    private function importoDelMese(string $html, int $mese): string
    {
        if (preg_match('/data-month="' . $mese . '"[^>]*>(.*?)<\/a>/s', $html, $trovato) !== 1) {
            return '';
        }

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($trovato[1])));
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
