<?php

use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;

/**
 * Le scritture sugli incassi, oltre al percorso felice già coperto da
 * InvoiceWritesTest: eliminazione, modifica, importo oltre il saldo e
 * fattura inesistente.
 *
 * Questi sono i casi in cui un errore non si vede subito: il pagamento
 * sparisce o si somma due volte, e il saldo della fattura resta sbagliato
 * senza che nessuno apra quella scheda.
 *
 * Ogni test gira in una transazione chiusa con rollback.
 *
 * @internal
 */
final class PaymentWritesTest extends CIUnitTestCase
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
            $this->testDb->query('SELECT 1 FROM ip_payments LIMIT 1');
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
     * Eliminare un incasso deve riaprire la fattura: se gli importi non
     * vengono ricalcolati, il documento resta "pagato" senza avere più
     * nessun pagamento che lo giustifichi.
     */
    public function testEliminazioneIncassoRiapreLaFattura(): void
    {
        $fattura = $this->fatturaDaIncassare(200.0);

        $this->paga($fattura, '50,00');

        $totali = $this->totali($fattura);
        $this->assertSame(50.0, (float) $totali['invoice_paid']);
        $this->assertSame(150.0, (float) $totali['invoice_balance']);

        $pagamento = $this->id(
            'SELECT payment_id FROM ip_payments WHERE invoice_id = ' . $fattura . ' ORDER BY payment_id DESC LIMIT 1'
        );
        $this->assertNotNull($pagamento, 'Incasso non salvato');

        $risposta = $this->inviaConToken('/payments/delete/' . $pagamento, [], '/payments');

        $this->assertSame(302, $risposta->response()->getStatusCode());
        $this->assertSame(0, $this->conta('ip_payments', 'payment_id = ' . $pagamento), 'Incasso non eliminato');

        $totali = $this->totali($fattura);
        $this->assertSame(0.0, (float) $totali['invoice_paid'], 'Pagato: deve tornare a zero');
        $this->assertSame(200.0, (float) $totali['invoice_balance'], 'Saldo: deve tornare al totale');
        $this->assertSame(2, (int) $this->campo('invoice_status_id', $fattura), 'La fattura deve tornare aperta');
    }

    /**
     * Modificare l'importo sostituisce il pagamento, non ne aggiunge un
     * secondo: il saldo deve seguire il nuovo valore.
     */
    public function testModificaIncassoSostituisceLImporto(): void
    {
        $fattura = $this->fatturaDaIncassare(200.0);

        $this->paga($fattura, '50,00');

        $pagamento = $this->id(
            'SELECT payment_id FROM ip_payments WHERE invoice_id = ' . $fattura . ' ORDER BY payment_id DESC LIMIT 1'
        );

        $risposta = $this->inviaConToken('/payments/form/' . $pagamento, [
            'invoice_id'     => $fattura,
            'payment_date'   => date('Y-m-d'),
            'payment_amount' => '80,00',
            'payment_note'   => 'Incasso corretto',
        ], '/payments/form/' . $pagamento);

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Modifica: atteso un redirect');
        $this->assertSame(1, $this->conta('ip_payments', 'invoice_id = ' . $fattura), 'Modifica: non deve nascere un secondo pagamento');

        $totali = $this->totali($fattura);
        $this->assertSame(80.0, (float) $totali['invoice_paid'], 'Pagato: deve essere il nuovo importo, non la somma');
        $this->assertSame(120.0, (float) $totali['invoice_balance'], 'Saldo: deve seguire il nuovo importo');
    }

    /**
     * Un incasso oltre il saldo viene rifiutato e non lascia né il pagamento
     * né una fattura con saldo negativo.
     */
    public function testIncassoOltreIlSaldoNonSiScrive(): void
    {
        $fattura = $this->fatturaDaIncassare(200.0);

        $prima = $this->conta('ip_payments');

        $risposta = $this->inviaConToken('/payments/form', [
            'invoice_id'     => $fattura,
            'payment_date'   => date('Y-m-d'),
            'payment_amount' => '300,00',
        ], '/payments/form?invoice_id=' . $fattura);

        $this->assertSame(200, $risposta->response()->getStatusCode(), 'Oltre il saldo: il form deve riaprirsi');
        $this->assertSame($prima, $this->conta('ip_payments'), 'Oltre il saldo: nessun pagamento deve essere scritto');

        $totali = $this->totali($fattura);
        $this->assertSame(0.0, (float) $totali['invoice_paid']);
        $this->assertSame(200.0, (float) $totali['invoice_balance']);
    }

    /**
     * Fattura inesistente: il POST non deve scrivere un pagamento orfano,
     * che nessuna scheda mostrerebbe e che resterebbe per sempre nella
     * tabella.
     */
    public function testIncassoSuFatturaInesistenteNonScrive(): void
    {
        $prima = $this->conta('ip_payments');

        $risposta = $this->inviaConToken('/payments/form', [
            'invoice_id'     => 99999999,
            'payment_date'   => date('Y-m-d'),
            'payment_amount' => '10,00',
        ], '/payments/form');

        $this->assertSame(302, $risposta->response()->getStatusCode());
        $this->assertStringContainsString('/payments', (string) $risposta->response()->getHeaderLine('Location'));
        $this->assertSame($prima, $this->conta('ip_payments'), 'Fattura inesistente: nessun pagamento deve essere scritto');
    }

    // -----------------------------------------------------------------
    // Aiuti
    // -----------------------------------------------------------------

    /**
     * Fattura emessa con una riga senza aliquota: il totale è quello della
     * riga, così i valori attesi restano leggibili.
     */
    private function fatturaDaIncassare(float $importo): int
    {
        $risposta = $this->inviaConToken('/invoices/ajax/create', [
            'client_id'            => $this->cliente(),
            'invoice_date_created' => date('Y-m-d'),
            'invoice_group_id'     => $this->id(
                'SELECT invoice_group_id FROM ip_invoice_groups WHERE company_id = 1 ORDER BY invoice_group_id LIMIT 1'
            ),
        ], '/invoices/form_create');

        $posizione = (string) $risposta->response()->getHeaderLine('Location');
        $this->assertStringContainsString('/invoices/view/', $posizione, 'Creazione fattura non riuscita');

        $fattura = (int) basename(parse_url($posizione, PHP_URL_PATH));

        $this->inviaConToken('/invoices/ajax/save', [
            'invoice_id'    => $fattura,
            'items'         => json_encode([[
                'item_name'     => 'Voce da incassare',
                'item_quantity' => '1',
                'item_price'    => (string) $importo,
                'item_tax_rate_id' => 0,
            ]]),
            'deleted_items' => '',
        ], '/invoices/view/' . $fattura);

        $this->inviaConToken('/invoices/ajax/save', [
            'invoice_id'        => $fattura,
            'invoice_status_id' => 2,
        ], '/invoices/view/' . $fattura);

        $this->assertSame(200.0, (float) $this->totali($fattura)['invoice_total'], 'Preparazione: totale inatteso');

        return $fattura;
    }

    private function paga(int $fattura, string $importo): void
    {
        $risposta = $this->inviaConToken('/payments/form', [
            'invoice_id'        => $fattura,
            'payment_date'      => date('Y-m-d'),
            'payment_amount'    => $importo,
            'payment_method_id' => $this->id('SELECT payment_method_id FROM ip_payment_methods ORDER BY payment_method_id LIMIT 1'),
        ], '/payments/form?invoice_id=' . $fattura);

        $this->assertSame(
            302,
            $risposta->response()->getStatusCode(),
            'Incasso non salvato. Flash: ' . json_encode($_SESSION['alert_error'] ?? null)
        );
    }

    /** @return array<string, mixed> */
    private function totali(int $fattura): array
    {
        $row = $this->testDb->query(
            'SELECT * FROM ip_invoice_amounts WHERE invoice_id = ?',
            [$fattura]
        )->getRowArray();

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

    private function conta(string $tabella, string $dove = ''): int
    {
        $sql = 'SELECT COUNT(*) AS n FROM ' . $tabella . ($dove !== '' ? ' WHERE ' . $dove : '');

        return (int) $this->testDb->query($sql)->getRow()->n;
    }

    private function id(string $sql): ?int
    {
        $row = $this->testDb->query($sql)->getRow();

        return $row === null ? null : (int) array_values((array) $row)[0];
    }

    private function cliente(): int
    {
        $id = $this->id(
            'SELECT client_id FROM ip_clients
             WHERE company_id = 1 AND COALESCE(client_default_tax_rate_id, 0) = 0
             ORDER BY client_id LIMIT 1'
        );

        $this->assertNotNull($id, 'Serve un cliente senza aliquota predefinita nell\'azienda 1');

        return $id;
    }

    /**
     * POST con un token CSRF vero, preso dalla pagina del form come farebbe
     * il browser: il flusso passa dai filtri reali.
     */
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
