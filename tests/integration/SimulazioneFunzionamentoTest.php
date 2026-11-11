<?php

use App\Models\SettingModel;
use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Giro completo dell'applicazione sulla copia del database: ogni area si apre
 * da amministratore, i documenti escono validi, il portale pubblico mostra i
 * suoi documenti e i controlli di accesso reggono.
 *
 * Non sostituisce i test mirati: serve a intercettare gli errori che nessun
 * unit test vede, come una classe non importata che rompe una pagina intera.
 * Per questo apre le pagine invece di chiamare i metodi dei controller, e
 * ricava gli id dal database invece di fissarli nel codice.
 *
 * Gira su invoiceplane_posttest (IP_PDF_TEST_DB per cambiarla) e non scrive
 * nulla. Se la copia non c'è il test si salta, come gli altri test di
 * integrazione: la suite resta verde su una macchina senza quel database.
 *
 * @internal
 */
final class SimulazioneFunzionamentoTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const DB_COPY = 'invoiceplane_posttest';

    private ?\CodeIgniter\Database\BaseConnection $testDb = null;

    /** @var array<string, mixed> */
    private array $originalTests = [];

    private int $utente = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $config = config('Database');

        $this->originalTests = $config->tests;
        $config->tests = $config->default;
        $config->tests['database'] = getenv('IP_PDF_TEST_DB') ?: self::DB_COPY;

        $this->resetConnections();

        try {
            $this->testDb = \Config\Database::connect();
            $this->testDb->query('SELECT 1 FROM ip_invoices LIMIT 1');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non disponibile: ' . $config->tests['database']);
        }

        // Le impostazioni (logo, valuta, piè di pagina) sono in cache statica.
        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);

        $_SESSION['active_company_id'] = 1;

        $row = $this->testDb->query('SELECT user_id FROM ip_users ORDER BY user_id LIMIT 1')->getRow();
        $this->utente = (int) ($row->user_id ?? 1);
    }

    protected function tearDown(): void
    {
        $this->restoreConnections();

        parent::tearDown();
    }

    public function testTutteLeAreeSiAprono(): void
    {
        $pagine = [
            '/', 'dashboard', 'welcome', 'search/global',
            'invoices', 'invoices/archive', 'invoices/recurring', 'invoices/import',
            'invoices/form_create', 'quotes', 'quotes/form_create',
            'clients', 'clients/form', 'clients/export', 'clients/export_template',
            'suppliers', 'suppliers/form', 'suppliers/export', 'suppliers/export_template',
            'payments', 'payments/form', 'payments/online_logs',
            'expenses', 'expenses/form', 'expenses/import',
            'products', 'products/form', 'projects', 'projects/form', 'tasks', 'tasks/form',
            'delivery_notes', 'delivery_notes/form',
            'reports', 'reports/invoice_aging', 'reports/payment_history',
            'reports/sales_by_client', 'reports/sales_by_year',
            'finance', 'management', 'management/accounts', 'management/assign',
            'management/budget', 'management/cashflow', 'f24', 'f24/form', 'f24/import',
            'riba', 'reconciliation', 'calendar', 'schedule',
            'companies', 'companies/form', 'users', 'users/form', 'user_clients/create',
            'settings', 'sdi/settings', 'mcp/settings', 'security',
            'custom_fields', 'custom_fields/form', 'custom_values',
            'tax_rates', 'tax_rates/form', 'payment_methods', 'payment_methods/form',
            'units', 'units/form', 'families', 'families/form',
            'invoice_groups', 'invoice_groups/form', 'expense_categories', 'expense_categories/form',
            'email_templates', 'email_templates/form', 'email_senders', 'email_senders/form',
            'bank_accounts', 'bank_accounts/form', 'fiscal_years', 'fiscal_years/form',
            'import', 'import/form', 'exports', 'exports/invoices', 'exports/expenses',
            'imports_exports',
            'filter/filter_invoices', 'filter/filter_quotes', 'filter/filter_clients', 'filter/filter_payments',
        ];

        foreach ($pagine as $url) {
            $this->apri('/' . ltrim($url, '/'));
        }
    }

    public function testLePagineConDatiRealiSiAprono(): void
    {
        $fattura    = $this->id('SELECT i.invoice_id FROM ip_invoices i JOIN ip_invoice_items it ON it.invoice_id = i.invoice_id ORDER BY i.invoice_id DESC LIMIT 1');
        $preventivo = $this->id('SELECT q.quote_id FROM ip_quotes q JOIN ip_quote_items it ON it.quote_id = q.quote_id ORDER BY q.quote_id DESC LIMIT 1');
        $cliente    = $this->id('SELECT client_id FROM ip_clients ORDER BY client_id DESC LIMIT 1');
        $pagamento  = $this->id('SELECT payment_id FROM ip_payments ORDER BY payment_id DESC LIMIT 1');
        $spesa      = $this->id('SELECT expense_id FROM ip_expenses ORDER BY expense_id DESC LIMIT 1');
        $prodotto   = $this->id('SELECT product_id FROM ip_products ORDER BY product_id DESC LIMIT 1');
        $progetto   = $this->id('SELECT project_id FROM ip_projects ORDER BY project_id DESC LIMIT 1');
        $attivita   = $this->id('SELECT task_id FROM ip_tasks ORDER BY task_id DESC LIMIT 1');
        $fornitore  = $this->id('SELECT supplier_id FROM ip_suppliers ORDER BY supplier_id DESC LIMIT 1');
        $ddt        = $this->id('SELECT delivery_note_id FROM ip_delivery_notes ORDER BY delivery_note_id DESC LIMIT 1');
        $aliquota   = $this->id('SELECT tax_rate_id FROM ip_tax_rates ORDER BY tax_rate_id DESC LIMIT 1');
        $unita      = $this->id('SELECT unit_id FROM ip_units ORDER BY unit_id DESC LIMIT 1');
        $metodo     = $this->id('SELECT payment_method_id FROM ip_payment_methods ORDER BY payment_method_id DESC LIMIT 1');
        $categoria  = $this->id('SELECT expense_category_id FROM ip_expense_categories ORDER BY expense_category_id DESC LIMIT 1');
        $famiglia   = $this->id('SELECT family_id FROM ip_families ORDER BY family_id DESC LIMIT 1');
        $annoFisc   = $this->id('SELECT fiscal_id FROM ip_fiscal_years ORDER BY fiscal_id DESC LIMIT 1');
        $conto      = $this->id('SELECT bank_account_id FROM ip_bank_accounts ORDER BY bank_account_id DESC LIMIT 1');
        $template   = $this->id('SELECT email_template_id FROM ip_email_templates ORDER BY email_template_id DESC LIMIT 1');
        $gruppo     = $this->id('SELECT invoice_group_id FROM ip_invoice_groups WHERE company_id = 1 ORDER BY invoice_group_id ASC LIMIT 1');

        $url = array_filter([
            $fattura ? "invoices/view/{$fattura}" : null,
            $fattura ? "invoices/edit/{$fattura}" : null,
            $fattura ? "invoices/preview_sheet/{$fattura}" : null,
            $preventivo ? "quotes/view/{$preventivo}" : null,
            $preventivo ? "quotes/edit/{$preventivo}" : null,
            $cliente ? "clients/view/{$cliente}" : null,
            $cliente ? "clients/form/{$cliente}" : null,
            $pagamento ? "payments/form/{$pagamento}" : null,
            $spesa ? "expenses/form/{$spesa}" : null,
            $spesa ? "expenses/preview_xml/{$spesa}" : null,
            $prodotto ? "products/form/{$prodotto}" : null,
            $progetto ? "projects/view/{$progetto}" : null,
            $progetto ? "projects/form/{$progetto}" : null,
            $attivita ? "tasks/form/{$attivita}" : null,
            $fornitore ? "suppliers/view/{$fornitore}" : null,
            $fornitore ? "suppliers/form/{$fornitore}" : null,
            $ddt ? "delivery_notes/view/{$ddt}" : null,
            $ddt ? "delivery_notes/preview_sheet/{$ddt}" : null,
            $aliquota ? "tax_rates/form/{$aliquota}" : null,
            $unita ? "units/form/{$unita}" : null,
            $gruppo ? "invoice_groups/form/{$gruppo}" : null,
            $metodo ? "payment_methods/form/{$metodo}" : null,
            $categoria ? "expense_categories/form/{$categoria}" : null,
            $famiglia ? "families/form/{$famiglia}" : null,
            $annoFisc ? "fiscal_years/form/{$annoFisc}" : null,
            $conto ? "bank_accounts/form/{$conto}" : null,
            $template ? "email_templates/form/{$template}" : null,
            'companies/form/1',
            'users/form/' . $this->utente,
            'user_clients/user/' . $this->utente,
            'calendar/' . date('Y') . '/' . date('n'),
        ]);

        $this->assertNotSame([], $url, 'La copia non contiene documenti da aprire');

        foreach ($url as $u) {
            $this->apri('/' . $u);
        }
    }

    public function testIDocumentiEsconoValidi(): void
    {
        $fattura    = $this->id('SELECT i.invoice_id FROM ip_invoices i JOIN ip_invoice_items it ON it.invoice_id = i.invoice_id ORDER BY i.invoice_id DESC LIMIT 1');
        $preventivo = $this->id('SELECT q.quote_id FROM ip_quotes q JOIN ip_quote_items it ON it.quote_id = q.quote_id ORDER BY q.quote_id DESC LIMIT 1');

        $this->assertNotNull($fattura, 'Serve una fattura con righe nella copia');
        $this->assertNotNull($preventivo, 'Serve un preventivo con righe nella copia');

        // I PDF passano da pdf_create(..., true) che fa stream() ed exit: in
        // questo processo chiuderebbero il test. Sono verificati byte per byte
        // da DocumentPdfIntegrationTest, che li genera in memoria.
        $this->documento("invoices/preview_xml/{$fattura}", 'FatturaElettronica', 'XML FatturaPA');
        $this->documento("invoices/generate_xml/{$fattura}", 'FatturaElettronica', 'XML FatturaPA (download)');
        $this->documento("invoices/generate_zugferd_xml/{$fattura}", 'CrossIndustryDocument', 'XML ZUGFeRD');

        $this->documento('clients/export', ';', 'CSV clienti');
        // Il modello è un CSV di poche righe: la soglia dei documenti grandi
        // non vale qui, basta la riga di intestazione.
        $this->documento('clients/export_template', ';', 'CSV modello clienti', 100);
    }

    public function testGliArchiviEsconoComeDownload(): void
    {
        // Il download non ha corpo finché non lo si invia: il file si legge dal
        // disco solo in quel momento (un anno di PDF non entrerebbe in
        // memoria). Quindi non ha senso controllarne il contenuto qui: si
        // verificano gli header, cioè che l'archivio sia stato davvero
        // costruito e non sia il redirect di "periodo vuoto".
        $risposta = $this->withSession($this->sessione())->get('/exports/xml');
        $response = $risposta->response();

        // Il nome del file diventa header solo all'invio: qui lo si fa costruire
        // a mano, altrimenti la verifica guarderebbe un header ancora vuoto.
        $response->buildHeaders();

        $this->assertSame(200, $response->getStatusCode(), 'Archivio XML: atteso un download, non un redirect');
        $this->assertStringContainsString(
            'application/zip',
            (string) $response->getHeaderLine('Content-Type'),
            'Archivio XML: content type inatteso'
        );
        $this->assertStringContainsString(
            '.zip',
            (string) $response->getHeaderLine('Content-Disposition'),
            'Archivio XML: nome del file mancante'
        );
    }

    public function testIlPortalePubblicoFunziona(): void
    {
        $preventivo = $this->testDb->query(
            "SELECT quote_number, quote_url_key FROM ip_quotes
             WHERE quote_url_key <> '' AND quote_url_key IS NOT NULL
               AND quote_status_id IN (2,3,4,5)
               AND (quote_password IS NULL OR quote_password = '')
             ORDER BY quote_id LIMIT 1"
        )->getRowArray();

        $fattura = $this->testDb->query(
            "SELECT invoice_number, invoice_url_key FROM ip_invoices
             WHERE invoice_url_key <> '' AND invoice_url_key IS NOT NULL
               AND (invoice_password IS NULL OR invoice_password = '')
             ORDER BY invoice_id LIMIT 1"
        )->getRowArray();

        if ($preventivo) {
            $this->contiene(
                '/guest/view/quote/' . $preventivo['quote_url_key'],
                (string) $preventivo['quote_number'],
                'portale: preventivo pubblico'
            );
        }

        if ($fattura) {
            $this->contiene(
                '/guest/view/invoice/' . $fattura['invoice_url_key'],
                (string) $fattura['invoice_number'],
                'portale: fattura pubblica'
            );
        }

        // Le pagine del portale cliente richiedono il login del cliente.
        $this->assertSame(302, $this->get('/guest')->response()->getStatusCode());
    }

    public function testIControlliDiAccessoReggono(): void
    {
        $this->assertSame(302, $this->get('/dashboard')->response()->getStatusCode(), 'Senza sessione la dashboard deve rimandare al login');

        try {
            $this->get('/guest/view/quote/chiave-inesistente');
            $this->fail('Una chiave URL sconosciuta deve dare 404');
        } catch (\CodeIgniter\Exceptions\PageNotFoundException $e) {
            $this->assertTrue(true);
        }

        // Il filtro CSRF non lancia: intercetta e rimanda indietro con un
        // messaggio in flashdata (Config\Security::$redirect = true).
        $stato = $this->withSession($this->sessione())->post('/invoices/ajax/save', [])->response()->getStatusCode();
        $this->assertSame(302, $stato, 'Un POST senza token CSRF deve essere respinto');
    }

    /**
     * Il flusso di lavoro vero e proprio: accesso, cliente, fattura con riga,
     * incasso, XML e conversione di un preventivo.
     *
     * Tutto dentro una transazione, chiusa con rollback: la copia del database
     * deve restare identica a com'era, quindi il test si puo' ripetere.
     */
    public function testFlussoDiLavoroCompleto(): void
    {
        $prima = $this->conteggi();

        $this->testDb->transBegin();

        try {
            $clienteId     = $this->accediECreaCliente();
            $fatturaId     = $this->creaFatturaConRiga($clienteId);
            $numeroFattura = (string) $this->testDb->query(
                'SELECT invoice_number FROM ip_invoices WHERE invoice_id = ?', [$fatturaId]
            )->getRow()->invoice_number;

            $this->incassaFattura($fatturaId);
            $this->verificaXml($fatturaId, $numeroFattura);
            $this->convertiPreventivo($clienteId);

            // La scheda cliente deve mostrare la fattura appena emessa.
            $scheda = $this->withSession($this->sessione())->get('/clients/view/' . $clienteId);
            $this->assertSame(200, $scheda->response()->getStatusCode());
            $this->assertStringContainsString($numeroFattura, (string) $scheda->response()->getBody());
        } finally {
            $this->testDb->transRollback();
        }

        // Rollback: nessun residuo nella copia.
        $this->assertSame($prima, $this->conteggi(), 'Il rollback deve lasciare la copia invariata');
    }

    /** Accesso con email e password, poi creazione di un cliente. */
    private function accediECreaCliente(): int
    {
        $utente = null;

        foreach ($this->testDb->query('SELECT user_id, user_email, user_type, user_active FROM ip_users ORDER BY user_id')->getResultArray() as $riga) {
            if ((int) $riga['user_type'] === 1 && db_bool($riga['user_active'])) {
                $utente = $riga;
                break;
            }
        }

        $this->assertNotNull($utente, 'Serve un amministratore attivo nella copia');

        // Password nota solo per la durata del test: l'update sta nella
        // transazione, quindi il rollback rimette l'hash originale.
        // Scrittura diretta e non Model::update(): update() rifà la
        // validazione del form utente (email unica compresa) e con i soli
        // campi password l'aggiornamento viene scartato in silenzio.
        $userModel = new UserModel();
        // MFA spento per la prova: qui interessa il percorso di accesso
        // normale, la verifica del secondo fattore ha i suoi test.
        $this->testDb->table('ip_users')->where('user_id', (int) $utente['user_id'])->update([
            'user_psalt'       => $userModel->salt(),
            'user_password'    => password_hash('Prova123!', PASSWORD_DEFAULT),
            'user_mfa_enabled' => false,
        ]);

        $accesso = $this->inviaConToken('/sessions/login', [
            'email'     => $utente['user_email'],
            'password'  => 'Prova123!',
            'btn_login' => '1',
        ], '/sessions/login');

        $ricaricato = $userModel->findByEmail((string) $utente['user_email']);
        $this->assertTrue(
            $userModel->checkPassword((string) ($ricaricato['user_password'] ?? ''), 'Prova123!'),
            'Accesso: la password di prova non e\' stata scritta correttamente'
        );

        $this->assertSame(302, $accesso->response()->getStatusCode(), 'Accesso: atteso il redirect dopo il login');
        $this->assertStringContainsString(
            '/dashboard',
            (string) $accesso->response()->getHeaderLine('Location'),
            'Accesso: redirect inatteso. Flash: ' . json_encode($_SESSION['alert_error'] ?? null)
        );

        $nome = 'Cliente Prova ' . date('His');

        $creazione = $this->inviaConToken('/clients/form', [
            'client_name'    => $nome,
            'client_surname' => 'Test',
            'client_type'    => 'company',
            'client_email'   => 'cliente.prova@example.com',
            'client_country' => 'IT',
            'client_active'  => '1',
            'btn_submit'     => '1',
        ], '/clients/form');

        $posizione = (string) $creazione->response()->getHeaderLine('Location');
        $this->assertStringContainsString('/clients/view/', $posizione, 'Cliente: il salvataggio non ha portato alla scheda. Corpo: '
            . mb_substr((string) $creazione->response()->getBody(), 0, 300));

        $clienteId = (int) basename(parse_url($posizione, PHP_URL_PATH));
        $cliente   = $this->testDb->query('SELECT client_name FROM ip_clients WHERE client_id = ?', [$clienteId])->getRowArray();

        $this->assertSame($nome, $cliente['client_name'] ?? null, 'Cliente: nome non salvato');

        return $clienteId;
    }

    /** Fattura creata dal form rapido, poi la riga salvata dalla schermata di modifica. */
    private function creaFatturaConRiga(int $clienteId): int
    {
        $gruppo = $this->testDb->query(
            'SELECT invoice_group_id FROM ip_invoice_groups WHERE company_id = 1 ORDER BY invoice_group_id LIMIT 1'
        )->getRow();

        $this->assertNotNull($gruppo, 'Serve un gruppo di numerazione per l\'azienda 1');

        $creazione = $this->inviaConToken('/invoices/ajax/create', [
            'client_id'            => $clienteId,
            'invoice_date_created' => date('Y-m-d'),
            'invoice_group_id'     => (int) $gruppo->invoice_group_id,
            'invoice_status_id'    => 2,
        ], '/invoices/form_create');

        $posizione = (string) $creazione->response()->getHeaderLine('Location');
        $this->assertStringContainsString('/invoices/view/', $posizione, 'Fattura: creazione non riuscita. Corpo: '
            . mb_substr((string) $creazione->response()->getBody(), 0, 300));

        $fatturaId = (int) basename(parse_url($posizione, PHP_URL_PATH));

        $riga = $this->inviaConToken('/invoices/ajax/save', [
            'invoice_id'    => $fatturaId,
            'items'         => json_encode([[
                'item_name'       => 'Consulenza di prova',
                'item_quantity'   => '2',
                'item_price'      => '100',
                'item_tax_rate_id' => 0,
            ]]),
            'deleted_items' => '',
        ], '/invoices/view/' . $fatturaId);

        $this->assertSame(302, $riga->response()->getStatusCode(), 'Riga fattura: atteso un redirect');

        $importi = $this->testDb->query(
            'SELECT invoice_total, invoice_balance FROM ip_invoice_amounts WHERE invoice_id = ?', [$fatturaId]
        )->getRowArray();

        $this->assertSame(200.0, (float) $importi['invoice_total'], 'Riga fattura: totale non calcolato');
        $this->assertSame(200.0, (float) $importi['invoice_balance'], 'Riga fattura: saldo non calcolato');

        // La bozza va emessa: solo una fattura inviata puo' passare a
        // "pagata" con l'incasso, e il numero definitivo nasce qui.
        $invio = $this->inviaConToken('/invoices/ajax/save', [
            'invoice_id'        => $fatturaId,
            'invoice_status_id' => 2,
        ], '/invoices/view/' . $fatturaId);

        $this->assertSame(302, $invio->response()->getStatusCode(), 'Invio fattura: atteso un redirect');

        $numero = (string) $this->testDb->query(
            'SELECT invoice_number FROM ip_invoices WHERE invoice_id = ?', [$fatturaId]
        )->getRow()->invoice_number;
        $this->assertNotSame('', trim($numero), 'Invio fattura: numero non assegnato');

        return $fatturaId;
    }

    /** Incasso del saldo: la fattura deve passare a pagata. */
    private function incassaFattura(int $fatturaId): void
    {
        $metodo = $this->testDb->query('SELECT payment_method_id FROM ip_payment_methods ORDER BY payment_method_id LIMIT 1')->getRow();

        $incasso = $this->inviaConToken('/payments/form', [
            'invoice_id'        => $fatturaId,
            'payment_date'      => date('Y-m-d'),
            'payment_amount'    => '200,00',
            'payment_method_id' => $metodo->payment_method_id ?? 0,
            'payment_note'      => 'Incasso di prova',
        ], '/payments/form?invoice_id=' . $fatturaId);

        $this->assertSame(302, $incasso->response()->getStatusCode(), 'Pagamento: atteso il redirect alla lista');

        $pagamento = $this->testDb->query(
            'SELECT COUNT(*) AS n FROM ip_payments WHERE invoice_id = ?', [$fatturaId]
        )->getRow();

        $this->assertSame(1, (int) $pagamento->n, 'Pagamento: nessuna riga salvata. Flash: '
            . json_encode($_SESSION['alert_error'] ?? null));

        $fattura = $this->testDb->query(
            'SELECT invoice_status_id FROM ip_invoices WHERE invoice_id = ?', [$fatturaId]
        )->getRow();
        $saldo = $this->testDb->query(
            'SELECT invoice_balance FROM ip_invoice_amounts WHERE invoice_id = ?', [$fatturaId]
        )->getRow();

        $this->assertSame(4, (int) $fattura->invoice_status_id, 'Pagamento: la fattura non risulta pagata');
        $this->assertSame(0.0, (float) $saldo->invoice_balance, 'Pagamento: il saldo non e\' andato a zero');
    }

    /** L'XML della fattura appena emessa deve contenere i dati del cliente. */
    private function verificaXml(int $fatturaId, string $numero): void
    {
        $xml = $this->withSession($this->sessione())->get('/invoices/generate_xml/' . $fatturaId);
        $corpo = (string) $xml->response()->getBody();

        $this->assertSame(200, $xml->response()->getStatusCode(), 'XML: stato inatteso');
        $this->assertStringContainsString('FatturaElettronica', $corpo, 'XML: radice assente');
        $this->assertStringContainsString('Cliente Prova', $corpo, 'XML: cliente assente');
        $this->assertStringContainsString('Consulenza di prova', $corpo, 'XML: riga assente');
    }

    /** Preventivo creato dal form e convertito in fattura. */
    private function convertiPreventivo(int $clienteId): void
    {
        $gruppo = $this->testDb->query(
            'SELECT invoice_group_id FROM ip_invoice_groups WHERE company_id = 1 ORDER BY invoice_group_id LIMIT 1'
        )->getRow();

        $creazione = $this->inviaConToken('/quotes/ajax/create', [
            'client_id'          => $clienteId,
            'quote_date_created' => date('Y-m-d'),
            'invoice_group_id'   => (int) $gruppo->invoice_group_id,
            'items'              => json_encode([[
                'item_name'     => 'Preventivo di prova',
                'item_quantity' => '1',
                'item_price'    => '150',
            ]]),
        ], '/quotes/form_create');

        $posizione = (string) $creazione->response()->getHeaderLine('Location');
        $this->assertStringContainsString('/quotes/view/', $posizione, 'Preventivo: creazione non riuscita. Corpo: '
            . mb_substr((string) $creazione->response()->getBody(), 0, 300));

        $preventivoId = (int) basename(parse_url($posizione, PHP_URL_PATH));

        $conversione = $this->inviaConToken('/quotes/ajax/quote_to_invoice', [
            'quote_id' => $preventivoId,
        ], '/quotes/view/' . $preventivoId);

        $this->assertSame(302, $conversione->response()->getStatusCode(), 'Conversione: atteso un redirect');

        $fattura = $this->testDb->query(
            'SELECT invoice_id, client_id, invoice_group_id FROM ip_invoices WHERE client_id = ? ORDER BY invoice_id DESC LIMIT 1',
            [$clienteId]
        )->getRowArray();

        $this->assertNotNull($fattura, 'Conversione: nessuna fattura creata');
        $this->assertSame($clienteId, (int) $fattura['client_id'], 'Conversione: cliente errato');

        $righe = $this->testDb->query(
            'SELECT COUNT(*) AS n FROM ip_invoice_items WHERE invoice_id = ?', [(int) $fattura['invoice_id']]
        )->getRow();
        $this->assertGreaterThan(0, (int) $righe->n, 'Conversione: la fattura non ha righe');

        $vista = $this->withSession($this->sessione())->get('/invoices/view/' . (int) $fattura['invoice_id']);
        $this->assertSame(200, $vista->response()->getStatusCode(), 'Conversione: la fattura non si apre');
    }

    /**
     * POST con un token CSRF vero, preso dalla pagina del form come farebbe il
     * browser: cosi' il flusso passa dai filtri reali, non da una protezione
     * disattivata per comodita'.
     */
    private function inviaConToken(string $url, array $dati, string $paginaModulo): \CodeIgniter\Test\TestResponse
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

    /** @return array<string, int> */
    private function conteggi(): array
    {
        $out = [];

        foreach (['ip_clients', 'ip_invoices', 'ip_invoice_items', 'ip_payments', 'ip_quotes', 'ip_users'] as $tabella) {
            $out[$tabella] = (int) $this->testDb->query('SELECT COUNT(*) AS n FROM ' . $tabella)->getRow()->n;
        }

        return $out;
    }

    /** Apre una pagina da amministratore e verifica che non sia un errore. */
    private function apri(string $url): void
    {
        try {
            $risposta = $this->withSession($this->sessione())->get($url);
            $stato = $risposta->response()->getStatusCode();
            $corpo = (string) $risposta->response()->getBody();

            $this->assertLessThan(400, $stato, "GET {$url}: stato {$stato}");
            $this->assertDoesNotMatchRegularExpression(
                '/Whoops|Fatal error|Uncaught|Undefined (variable|array key)|Call to a member function/i',
                $corpo,
                "GET {$url}: la pagina contiene un messaggio di errore"
            );
        } catch (\Throwable $e) {
            $this->fail("GET {$url}: " . get_class($e) . ' — ' . $e->getMessage());
        }
    }

    private function documento(string $url, string $firma, string $etichetta, int $minimo = 500): void
    {
        try {
            $risposta = $this->withSession($this->sessione())->get($url);

            // response()->getBody() è il corpo vero: TestResponse::getBody()
            // passa dall'HTML parser e riscrive un XML come pagina HTML.
            $corpo = (string) $risposta->response()->getBody();

            $this->assertSame(200, $risposta->response()->getStatusCode(), "{$etichetta} ({$url}): stato inatteso");
            $this->assertGreaterThan($minimo, strlen($corpo), "{$etichetta} ({$url}): corpo troppo corto");
            $this->assertStringContainsString($firma, $corpo, "{$etichetta} ({$url}): firma {$firma} assente");
        } catch (\Throwable $e) {
            $this->fail("{$etichetta} ({$url}): " . get_class($e) . ' — ' . $e->getMessage());
        }
    }

    private function contiene(string $url, string $atteso, string $etichetta): void
    {
        try {
            $risposta = $this->get($url);

            $this->assertSame(200, $risposta->response()->getStatusCode(), "{$etichetta}: stato inatteso");
            $this->assertStringContainsString($atteso, $risposta->response()->getBody(), "{$etichetta}: numero documento assente");
        } catch (\Throwable $e) {
            $this->fail("{$etichetta}: " . get_class($e) . ' — ' . $e->getMessage());
        }
    }

    /**
     * Valore della prima riga, oppure null se la tabella è vuota.
     *
     * L'errore SQL non viene più inghiottito e restituito come "nessun dato":
     * era così che un riferimento a una colonna inesistente restava invisibile
     * per mesi. Una query sbagliata faceva sparire in silenzio la pagina
     * corrispondente dall'elenco da aprire, e il test restava verde mentre il
     * log si riempiva di errori. Un'eccezione qui fa fallire il test, che è
     * quello che deve succedere.
     */
    private function id(string $sql): ?int
    {
        $row = $this->testDb->query($sql)->getRow();

        return $row ? (int) array_values((array) $row)[0] : null;
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
