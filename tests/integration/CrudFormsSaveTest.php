<?php

use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * I form che scrivono: dal POST alla riga, passando dai filtri veri.
 *
 * saveRecord() ora restituisce 0 quando il modello rifiuta la scrittura, e ogni
 * form che lo chiama è stato adeguato. Questi test tengono fermo che il
 * percorso normale non si sia rotto nel farlo: creazione e modifica salvano
 * davvero, e quando il modello rifiuta non compare un «creato correttamente».
 *
 * Le entità coperte sono quelle generiche che condividono la stessa forma di
 * controller (lista + form GET/POST): quelle di base (unità, famiglie, aliquote,
 * categorie di spesa, metodi di pagamento) e le altre dieci adeguate a
 * saveFormRecord() (prodotti, attività, progetti, modelli email, gruppi fatture,
 * spese, clienti, documenti di trasporto, utenti, F24, parametri fiscali).
 * Fatture, preventivi, pagamenti e DDT verso fattura hanno già i loro test di
 * scrittura.
 *
 * Il caso in cui il modello dice di no è provato via HTTP con un controller di
 * prova (vedi in fondo al file) che monta il controller vero e un modello che
 * rifiuta: un rifiuto del database servirebbe solo su una colonna vincolata, e
 * cambierebbe da motore a motore.
 *
 * Ogni test gira in una transazione chiusa con rollback.
 *
 * @internal
 */
final class CrudFormsSaveTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const DB_COPY = 'invoiceplane_posttest';

    private ?BaseConnection $testDb = null;

    /** @var array<string, mixed> */
    private array $originalTests = [];

    private int $utente = 1;

    private ?int $progettoCollaudo = null;

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

        $row          = $this->testDb->query('SELECT user_id FROM ip_users ORDER BY user_id LIMIT 1')->getRow();
        $this->utente = (int) ($row->user_id ?? 1);

        $this->testDb->transBegin();
    }

    protected function tearDown(): void
    {
        if ($this->testDb !== null && $this->testDb->transDepth > 0) {
            $this->testDb->transRollback();
        }

        // La connessione si chiude qui e non si lascia al garbage collector:
        // ogni prova ne apre una nuova, e su MariaDB il contatore delle
        // connessioni aperte resta alto finché gli oggetti non vengono
        // liberati. Con le altre prove del file la suite intera arrivava a
        // «Too many connections», e i test che venivano dopo — fornitori,
        // stato del sistema, anno — si saltavano da soli: in CI un salto vale
        // come un guasto, quindi il rosso finiva su prove che non c'entravano.
        if ($this->testDb !== null) {
            $this->testDb->close();
            $this->testDb = null;
        }

        $this->restoreConnections();

        parent::tearDown();
    }

    public function testUnitaSiCreaESiModifica(): void
    {
        $creazione = $this->inviaConToken('/units/form', [
            'unit_name'      => 'Collaudo Unit',
            'unit_name_plrl' => 'Collaudo Units',
            'unit_type'      => 'pezzi',
        ], '/units/form');

        $this->assertSame(302, $creazione->response()->getStatusCode(), 'Unità: la creazione deve salvare');

        $riga = $this->testDb->query(
            'SELECT unit_id, unit_name_plrl FROM ip_units WHERE unit_name = ? LIMIT 1',
            ['Collaudo Unit']
        )->getRowArray();

        $this->assertNotNull($riga, 'Unità: riga non creata');

        $modifica = $this->inviaConToken('/units/form/' . (int) $riga['unit_id'], [
            'unit_name'      => 'Collaudo Unit',
            'unit_name_plrl' => 'Collaudo Plurali',
            'unit_type'      => 'pezzi',
        ], '/units/form/' . (int) $riga['unit_id']);

        $this->assertSame(302, $modifica->response()->getStatusCode(), 'Unità: la modifica deve salvare');

        $dopo = $this->testDb->query(
            'SELECT unit_name_plrl FROM ip_units WHERE unit_id = ?',
            [(int) $riga['unit_id']]
        )->getRowArray();

        $this->assertSame('Collaudo Plurali', $dopo['unit_name_plrl'], 'Unità: modifica non scritta');
    }

    public function testFamigliaSiCrea(): void
    {
        $risposta = $this->inviaConToken('/families/form', [
            'family_name' => 'Famiglia Collaudo',
        ], '/families/form');

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Famiglia: la creazione deve salvare');

        $conta = (int) $this->testDb->query(
            'SELECT COUNT(*) AS n FROM ip_families WHERE family_name = ?',
            ['Famiglia Collaudo']
        )->getRow()->n;

        $this->assertSame(1, $conta, 'Famiglia: riga non creata');
    }

    public function testAliquotaSiCrea(): void
    {
        $risposta = $this->inviaConToken('/tax_rates/form', [
            'tax_rate_name'    => 'Collaudo 7%',
            'tax_rate_percent' => '7',
            'tax_rate_natura'  => '',
        ], '/tax_rates/form');

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Aliquota: la creazione deve salvare');

        $riga = $this->testDb->query(
            'SELECT tax_rate_percent FROM ip_tax_rates WHERE tax_rate_name = ? LIMIT 1',
            ['Collaudo 7%']
        )->getRowArray();

        $this->assertNotNull($riga, 'Aliquota: riga non creata');
        $this->assertSame(7.0, (float) $riga['tax_rate_percent']);
    }

    public function testCategoriaSpesaSiCrea(): void
    {
        $risposta = $this->inviaConToken('/expense_categories/form', [
            'expense_category_name'       => 'Categoria Collaudo',
            'expense_category_deductible' => '1',
        ], '/expense_categories/form');

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Categoria: la creazione deve salvare');

        $conta = (int) $this->testDb->query(
            'SELECT COUNT(*) AS n FROM ip_expense_categories WHERE expense_category_name = ?',
            ['Categoria Collaudo']
        )->getRow()->n;

        $this->assertSame(1, $conta, 'Categoria: riga non creata');
    }

    public function testMetodoPagamentoSiCreaESiModifica(): void
    {
        $creazione = $this->inviaConToken('/payment_methods/form', [
            'payment_method_name' => 'Collaudo Bonifico',
        ], '/payment_methods/form');

        $this->assertSame(302, $creazione->response()->getStatusCode(), 'Metodo: la creazione deve salvare');

        $riga = $this->testDb->query(
            'SELECT payment_method_id FROM ip_payment_methods WHERE payment_method_name = ? LIMIT 1',
            ['Collaudo Bonifico']
        )->getRowArray();

        $this->assertNotNull($riga, 'Metodo: riga non creata');

        // In modifica la regola is_unique esclude la riga stessa tramite il
        // segnaposto payment_method_id: se saltasse, salvare senza rinominare
        // fallirebbe contro se stesso e il form direbbe che non ha salvato.
        $modifica = $this->inviaConToken('/payment_methods/form/' . (int) $riga['payment_method_id'], [
            'payment_method_name' => 'Collaudo Bonifico',
        ], '/payment_methods/form/' . (int) $riga['payment_method_id']);

        $this->assertSame(302, $modifica->response()->getStatusCode(), 'Metodo: la modifica deve salvare');
    }

    /**
     * Quando il modello rifiuta, il form resta aperto con l'errore e non
     * annuncia niente: è la stessa promessa del form fornitori.
     */
    public function testMetodoPagamentoDuplicatoNonSiSalvaEAvvisa(): void
    {
        $this->inviaConToken('/payment_methods/form', [
            'payment_method_name' => 'Collaudo Unico',
        ], '/payment_methods/form');

        $prima = (int) $this->testDb->query(
            'SELECT COUNT(*) AS n FROM ip_payment_methods WHERE payment_method_name = ?',
            ['Collaudo Unico']
        )->getRow()->n;

        $risposta = $this->inviaConToken('/payment_methods/form', [
            'payment_method_name' => 'Collaudo Unico',
        ], '/payment_methods/form');

        $this->assertSame(200, $risposta->response()->getStatusCode(), 'Duplicato: il form deve riaprirsi');

        $html = (string) $risposta->response()->getBody();

        $this->assertStringNotContainsString('Metodo di pagamento creato correttamente', $html, 'Duplicato: non deve annunciare il salvataggio');

        $dopo = (int) $this->testDb->query(
            'SELECT COUNT(*) AS n FROM ip_payment_methods WHERE payment_method_name = ?',
            ['Collaudo Unico']
        )->getRow()->n;

        $this->assertSame($prima, $dopo, 'Duplicato: non deve nascere una seconda riga');
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

    /**
     * Le altre entità adeguate a saveFormRecord(): dove si scrive, che campo
     * si guarda, e le frasi che il controller usa per dire com'è andata.
     *
     * `rifiuto` è il controller di prova che monta lo stesso controller vero
     * con un modello che rifiuta la scrittura: così il ramo d'errore si prova
     * via HTTP senza dipendere da un errore del database, che cambierebbe da
     * motore a motore (o non arriverebbe affatto).
     *
     * @return array<string, array{etichetta: string, tabella: string, chiave: string, modello: class-string, form: string, campo: string, successo: string, errore: string, rifiuto: class-string, filtro: string}>
     */
    public static function entitaScriventi(): array
    {
        $entita = [
            'prodotti' => [
                'etichetta' => 'Prodotti',
                'tabella'   => 'ip_products',
                'chiave'    => 'product_id',
                'modello'   => \App\Models\ProductModel::class,
                'form'      => '/products/form',
                'campo'     => 'product_name',
                'successo'  => 'Prodotto creato correttamente',
                'errore'    => 'Prodotto non salvato',
                'rifiuto'   => '\ProdottiCheRifiuta',
                'filtro'    => 'company_id = 1',
            ],
            'attivita' => [
                'etichetta' => 'Attività',
                'tabella'   => 'ip_tasks',
                'chiave'    => 'task_id',
                'modello'   => \App\Models\TaskModel::class,
                'form'      => '/tasks/form',
                'campo'     => 'task_name',
                'successo'  => "Attivita' creata correttamente",
                'errore'    => "Attivita' non salvata",
                'rifiuto'   => '\AttivitaCheRifiuta',
                'filtro'    => 'project_id IN (SELECT project_id FROM ip_projects WHERE client_id IN (SELECT client_id FROM ip_clients WHERE company_id = 1))',
            ],
            'progetti' => [
                'etichetta' => 'Progetti',
                'tabella'   => 'ip_projects',
                'chiave'    => 'project_id',
                'modello'   => \App\Models\ProjectModel::class,
                'form'      => '/projects/form',
                'campo'     => 'project_name',
                'successo'  => 'Progetto creato correttamente',
                'errore'    => 'Progetto non salvato',
                'rifiuto'   => '\ProgettiCheRifiuta',
                'filtro'    => 'client_id IN (SELECT client_id FROM ip_clients WHERE company_id = 1)',
            ],
            'modelli_email' => [
                'etichetta' => 'Modelli email',
                'tabella'   => 'ip_email_templates',
                'chiave'    => 'email_template_id',
                'modello'   => \App\Models\EmailTemplateModel::class,
                'form'      => '/email_templates/form',
                'campo'     => 'email_template_title',
                'successo'  => 'Modello email creato correttamente',
                'errore'    => 'Modello email non salvato',
                'rifiuto'   => '\ModelliEmailCheRifiuta',
                'filtro'    => '1 = 1',
            ],
            'gruppi_fatture' => [
                'etichetta' => 'Gruppi fatture',
                'tabella'   => 'ip_invoice_groups',
                'chiave'    => 'invoice_group_id',
                'modello'   => \App\Models\InvoiceGroupModel::class,
                'form'      => '/invoice_groups/form',
                'campo'     => 'invoice_group_name',
                'successo'  => 'Gruppo fatture creato correttamente',
                'errore'    => 'Gruppo fatture non salvato',
                'rifiuto'   => '\GruppiFattureCheRifiuta',
                'filtro'    => 'company_id = 1',
            ],
            'spese' => [
                'etichetta' => 'Spese',
                'tabella'   => 'ip_expenses',
                'chiave'    => 'expense_id',
                'modello'   => \App\Models\ExpenseModel::class,
                'form'      => '/expenses/form',
                'campo'     => 'expense_note',
                'successo'  => 'Spesa registrata correttamente',
                'errore'    => 'Spesa non salvata',
                'rifiuto'   => '\SpeseCheRifiuta',
                'filtro'    => 'company_id = 1',
            ],
            'clienti' => [
                'etichetta' => 'Clienti',
                'tabella'   => 'ip_clients',
                'chiave'    => 'client_id',
                'modello'   => \App\Models\ClientModel::class,
                'form'      => '/clients/form',
                'campo'     => 'client_name',
                'successo'  => 'Cliente creato correttamente',
                'errore'    => 'Cliente non salvato',
                'rifiuto'   => '\ClientiCheRifiuta',
                'filtro'    => 'company_id = 1',
            ],
            'documenti_trasporto' => [
                'etichetta' => 'Documenti di trasporto',
                'tabella'   => 'ip_delivery_notes',
                'chiave'    => 'delivery_note_id',
                'modello'   => \App\Models\DeliveryNoteModel::class,
                'form'      => '/delivery_notes/form',
                'campo'     => 'delivery_note_notes',
                'successo'  => 'Documento di trasporto salvato correttamente',
                'errore'    => 'Documento di trasporto non salvato',
                'rifiuto'   => '\DocumentiTrasportoCheRifiuta',
                'filtro'    => 'company_id = 1',
            ],
            'utenti' => [
                'etichetta' => 'Utenti',
                'tabella'   => 'ip_users',
                'chiave'    => 'user_id',
                'modello'   => \App\Models\UserModel::class,
                'form'      => '/users/form',
                'campo'     => 'user_name',
                'successo'  => 'Utente creato correttamente',
                'errore'    => 'Utente non salvato',
                'rifiuto'   => '\UtentiCheRifiuta',
                'filtro'    => '1 = 1',
            ],
            'f24' => [
                'etichetta' => 'F24',
                'tabella'   => 'ip_f24',
                'chiave'    => 'f24_id',
                'modello'   => \App\Models\F24Model::class,
                'form'      => '/f24/form',
                'campo'     => 'f24_note',
                'successo'  => 'F24 salvato correttamente',
                'errore'    => 'F24 non salvato',
                'rifiuto'   => '\F24CheRifiuta',
                'filtro'    => 'company_id = 1',
            ],
            'parametri_fiscali' => [
                'etichetta' => 'Parametri fiscali',
                'tabella'   => 'ip_fiscal_years',
                'chiave'    => 'fiscal_id',
                'modello'   => \App\Models\FiscalYearModel::class,
                'form'      => '/fiscal_years/form',
                'campo'     => 'fiscal_year',
                'successo'  => 'salvati correttamente',
                'errore'    => 'Parametri fiscali non salvati',
                'rifiuto'   => '\ParametriFiscaliCheRifiuta',
                'filtro'    => '1 = 1',
            ],
        ];

        // PHPUnit 13 spande i dataset: ogni entità deve restare un solo argomento.
        return array_map(static fn (array $e): array => [$e], $entita);
    }

    /**
     * Creazione e modifica vere: il POST passa dai filtri e dal modello veri,
     * la riga nasce e poi cambia sullo stesso id.
     *
     * @param array{etichetta: string, tabella: string, chiave: string, modello: class-string, form: string, campo: string, successo: string, errore: string, rifiuto: class-string, filtro: string} $entita
     */
    #[DataProvider('entitaScriventi')]
    public function testEntitaSiCreaESiModifica(array $entita): void
    {
        $base = 'Collaudo ' . bin2hex(random_bytes(3));

        $creato = $this->datiDelForm($entita, $this->valoreDelCampo($entita, $base, true), true);

        $risposta = $this->inviaConToken($entita['form'], $creato, $entita['form']);

        $this->assertSame(302, $risposta->response()->getStatusCode(), $entita['etichetta'] . ': la creazione deve salvare e tornare alla lista');

        $id = (int) $this->testDb->query(
            'SELECT MAX(' . $entita['chiave'] . ') AS id FROM ' . $entita['tabella']
        )->getRow()->id;

        $this->assertGreaterThan(0, $id, $entita['etichetta'] . ': riga non creata');

        $nato = $this->testDb->query(
            'SELECT ' . $entita['campo'] . ' AS valore FROM ' . $entita['tabella'] . ' WHERE ' . $entita['chiave'] . ' = ?',
            [$id]
        )->getRowArray();

        $this->assertSame(
            $this->valoreDelCampo($entita, $base, true),
            (string) ($nato['valore'] ?? ''),
            $entita['etichetta'] . ': la creazione non ha scritto i valori del form'
        );

        $modifica = $this->datiDelForm($entita, $this->valoreDelCampo($entita, $base, false), false);

        $risposta = $this->inviaConToken($entita['form'] . '/' . $id, $modifica, $entita['form'] . '/' . $id);

        $this->assertSame(302, $risposta->response()->getStatusCode(), $entita['etichetta'] . ': la modifica deve salvare');

        $dopo = $this->testDb->query(
            'SELECT ' . $entita['campo'] . ' AS valore FROM ' . $entita['tabella'] . ' WHERE ' . $entita['chiave'] . ' = ?',
            [$id]
        )->getRowArray();

        $this->assertSame(
            $this->valoreDelCampo($entita, $base, false),
            (string) ($dopo['valore'] ?? ''),
            $entita['etichetta'] . ': modifica non scritta'
        );
    }

    /**
     * Il valore che il campo sotto esame deve avere dopo creazione e modifica.
     *
     * @param array{tabella: string} $entita
     */
    private function valoreDelCampo(array $entita, string $base, bool $creazione): string
    {
        // L'anno fiscale è numerico (e unico per azienda): si usano due anni
        // liberi invece di un nome inventato.
        if ($entita['tabella'] === 'ip_fiscal_years') {
            return $creazione ? '2099' : '2098';
        }

        return $base . ($creazione ? ' A' : ' B');
    }

    /**
     * Il modello rifiuta: il form resta aperto con l'avviso, nessuna riga nasce
     * e non compare il «creato correttamente».
     *
     * Gira in un processo proprio perché il controller di prova sostituisce le
     * rotte: senza, la sostituzione resterebbe in piedi per i test successivi.
     *
     * @param array{etichetta: string, tabella: string, chiave: string, modello: class-string, form: string, campo: string, successo: string, errore: string, rifiuto: class-string, filtro: string} $entita
     */
    #[RunInSeparateProcess]
    #[DataProvider('entitaScriventi')]
    public function testModelloCheRifiutaTieneApertoIlForm(array $entita): void
    {
        $this->withRoutes([
            ['GET', $entita['form'], $entita['rifiuto'] . '::form'],
            ['POST', $entita['form'], $entita['rifiuto'] . '::form'],
        ]);

        $prima = $this->contaRighe($entita);

        $risposta = $this->inviaConToken(
            $entita['form'],
            $this->datiDelForm($entita, $this->valoreDelCampo($entita, 'Rifiutato', true), true),
            $entita['form']
        );

        $this->assertSame(200, $risposta->response()->getStatusCode(), $entita['etichetta'] . ': il form deve riaprirsi');

        $html = (string) $risposta->response()->getBody();

        // Il layout stampa gli avvisi con esc(): l'apostrofo di «Attivita'»
        // arriva come entità HTML, quindi si confronta già passato da esc().
        $this->assertStringContainsString(esc($entita['errore']), $html, $entita['etichetta'] . ': manca l\'avviso del rifiuto');
        $this->assertStringNotContainsString(esc($entita['successo']), $html, $entita['etichetta'] . ': non deve annunciare il salvataggio');
        $this->assertSame($prima, $this->contaRighe($entita), $entita['etichetta'] . ': nessuna riga deve nascere');
    }

    /**
     * Un prodotto in omaggio: 0,00 è un prezzo come gli altri e si salva.
     *
     * Prima dbArrayFromPost() lo trattava come campo vuoto — empty('0') è vero
     * — lo mandava a NULL e la validazione rifiutava il salvataggio con «Il
     * prezzo e' obbligatorio».
     */
    public function testPrezzoZeroSiSalva(): void
    {
        // Il provider incapsula ogni entità per PHPUnit 13: qui serve la riga.
        $entita = self::entitaScriventi()['prodotti'][0];

        $dati = $this->datiDelForm($entita, 'Collaudo Prezzo Zero', true);
        $dati['product_price'] = '0';

        $risposta = $this->inviaConToken($entita['form'], $dati, $entita['form']);

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Prezzo zero: il prodotto deve salvarsi e tornare alla lista');

        $riga = $this->testDb->query(
            'SELECT product_price FROM ip_products WHERE product_name = ?',
            ['Collaudo Prezzo Zero']
        )->getRowArray();

        $this->assertNotNull($riga, 'Prezzo zero: riga non creata');
        $this->assertSame(0.0, (float) $riga['product_price'], 'Prezzo zero: il prezzo salvato non è zero');
    }

    /**
     * Il caso 0 di saveFormRecord() con un modello vero: nessun controller di
     * prova, il rifiuto arriva dalla validazione del modello.
     *
     * Una data di fine «0» passa la validazione del controller — per la regola
     * required «0» è un valore — e la conversione della data la manda a NULL,
     * dove required la rifiuta. Il form deve riaprirsi con l'avviso, senza
     * annunciare il salvataggio.
     */
    public function testDataImpossibileTieneApertoIlFormSenzaAnnunciare(): void
    {
        $entita = self::entitaScriventi()['attivita'][0];

        $dati = $this->datiDelForm($entita, 'Collaudo Data Impossibile', true);
        $dati['task_finish_date'] = '0';

        $prima = $this->contaRighe($entita);

        $risposta = $this->inviaConToken($entita['form'], $dati, $entita['form']);

        $this->assertSame(200, $risposta->response()->getStatusCode(), 'Data impossibile: il form deve riaprirsi');

        $html = (string) $risposta->response()->getBody();

        $this->assertStringContainsString(esc($entita['errore']), $html, 'Data impossibile: manca l\'avviso del rifiuto');
        $this->assertStringNotContainsString(esc($entita['successo']), $html, 'Data impossibile: non deve annunciare il salvataggio');
        $this->assertSame($prima, $this->contaRighe($entita), 'Data impossibile: nessuna riga deve nascere');
    }

    /**
     * Un'attività senza progetto non si salva.
     *
     * Il form offriva «Nessun progetto», ma ip_tasks.project_id non ammette
     * NULL: quel salvataggio si fermava sul database (su PostgreSQL con una
     * pagina di errore) e, anche se la colonna lo accettasse, una riga senza
     * progetto non apparterrebbe a nessuna azienda e la vedrebbero tutte. Qui
     * il form non lo offre più e il server rifiuta sia il campo vuoto sia un
     * progetto che non è di questa azienda.
     */
    public function testAttivitaSenzaProgettoRestaNelFormSenzaSalvare(): void
    {
        $entita = self::entitaScriventi()['attivita'][0];

        $prima = $this->contaRighe($entita);

        $ultimaPagina = null;

        foreach ([
            ''       => 'Il progetto è obbligatorio',
            '999999' => 'Il progetto scelto non esiste o non è di questa azienda',
        ] as $progetto => $atteso) {
            $dati = $this->datiDelForm($entita, 'Collaudo Senza Progetto', true);
            $dati['project_id'] = (string) $progetto;

            $risposta = $this->inviaConToken($entita['form'], $dati, $entita['form']);
            $ultimaPagina = (string) $risposta->response()->getBody();

            $this->assertSame(200, $risposta->response()->getStatusCode(), 'Progetto «' . $progetto . '»: il form deve riaprirsi');
            $this->assertStringContainsString(esc($atteso), $ultimaPagina, 'Progetto «' . $progetto . '»: manca il motivo');
            $this->assertStringNotContainsString(esc($entita['successo']), $ultimaPagina, 'Progetto «' . $progetto . '»: non deve annunciare il salvataggio');
        }

        // La scelta è obbligatoria anche nella pagina: niente più opzione
        // vuota, e il campo è segnato required.
        $this->assertMatchesRegularExpression('/name="project_id"[^>]*required/', (string) $ultimaPagina, 'Il selettore del progetto deve essere obbligatorio');
        $this->assertStringNotContainsString('<option value="">', (string) $ultimaPagina, 'Il form non deve più offrire «Nessun progetto»');
        $this->assertSame($prima, $this->contaRighe($entita), 'Nessuna attività deve nascere senza progetto');
    }

    /**
     * Il valore personalizzato si crea davvero, e finisce sotto il campo da cui
     * si è partiti: custom_values_field non è un campo del form e la colonna
     * non ammette NULL.
     */
    public function testValorePersonalizzatoSiCrea(): void
    {
        $fieldId = $this->campoPersonalizzatoDiCollaudo();
        $url     = 'custom_values/create/' . $fieldId;

        $risposta = $this->inviaConToken($url, ['custom_values_value' => 'Collaudo valore'], $url);

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Valore personalizzato: la creazione deve salvare');

        $riga = $this->testDb->query(
            'SELECT custom_values_field FROM ip_custom_values WHERE custom_values_value = ?',
            ['Collaudo valore']
        )->getRowArray();

        $this->assertNotNull($riga, 'Valore personalizzato: riga non creata');
        $this->assertSame($fieldId, (int) $riga['custom_values_field'], 'Valore personalizzato: salvato sotto un altro campo');
    }

    /**
     * Il modello rifiuta: la pagina non annuncia il salvataggio, dice perché e
     * resta sul form.
     *
     * È il caso che prima sfuggiva: create() scriveva e annunciava senza
     * guardare l'esito, quindi un valore rifiutato dal database risultava
     * creato.
     */
    #[RunInSeparateProcess]
    public function testValorePersonalizzatoRifiutatoRestaNelFormSenzaAnnunciare(): void
    {
        $fieldId = $this->campoPersonalizzatoDiCollaudo();
        $url     = 'custom_values/create/' . $fieldId;

        $this->withRoutes([
            ['GET', 'custom_values/create/(:num)', '\ValoriPersonalizzatiCheRifiuta::create/$1'],
            ['POST', 'custom_values/create/(:num)', '\ValoriPersonalizzatiCheRifiuta::create/$1'],
        ]);

        $prima = $this->contaValori();

        $risposta = $this->inviaConToken($url, ['custom_values_value' => 'Collaudo rifiutato'], $url);

        $this->assertSame(200, $risposta->response()->getStatusCode(), 'Rifiuto: il form deve riaprirsi');

        $html = (string) $risposta->response()->getBody();

        $this->assertStringContainsString(esc('Valore personalizzato non salvato'), $html, 'Rifiuto: manca l\'avviso del rifiuto');
        $this->assertStringNotContainsString(esc('Valore personalizzato creato correttamente'), $html, 'Rifiuto: non deve annunciare il salvataggio');
        $this->assertSame($prima, $this->contaValori(), 'Rifiuto: nessun valore deve nascere');
    }

    /**
     * Un campo personalizzato di collaudo: il seed del CI non ne ha, e i valori
     * personalizzati non possono esistere senza un campo. Resta nella
     * transazione, che viene annullata.
     */
    private function campoPersonalizzatoDiCollaudo(): int
    {
        $this->testDb->query(
            "INSERT INTO ip_custom_fields (custom_field_table, custom_field_label, custom_field_type) VALUES ('ip_client_custom', 'Campo di collaudo', 'DROPDOWN')"
        );

        return (int) $this->testDb->insertID();
    }

    private function contaValori(): int
    {
        return (int) $this->testDb->query('SELECT COUNT(*) AS n FROM ip_custom_values')->getRow()->n;
    }

    /**
     * Il form F24 valida davvero: senza scadenza la riga non arriva al
     * database.
     *
     * ip_f24.f24_due_date non ammette NULL e non ha un valore predefinito,
     * quindi quel salvataggio si fermava sull'INSERT: su PostgreSQL una pagina
     * d'errore al posto del form con l'avviso sul campo.
     */
    public function testF24SenzaScadenzaRestaNelFormConAvvisoSulCampo(): void
    {
        $entita = self::entitaScriventi()['f24'][0];

        $dati = $this->datiDelForm($entita, 'Collaudo Scadenza', true);
        $dati['f24_due_date'] = '';

        $prima = $this->contaRighe($entita);

        $risposta = $this->inviaConToken($entita['form'], $dati, $entita['form']);

        $this->assertSame(200, $risposta->response()->getStatusCode(), 'Scadenza vuota: il form deve riaprirsi');

        $html = (string) $risposta->response()->getBody();

        $this->assertStringContainsString(esc('La scadenza è obbligatoria'), $html, 'Scadenza vuota: manca l\'avviso sul campo');
        $this->assertStringNotContainsString(esc($entita['successo']), $html, 'Scadenza vuota: non deve annunciare il salvataggio');
        $this->assertSame($prima, $this->contaRighe($entita), 'Scadenza vuota: nessuna riga deve nascere');
    }

    /**
     * Anche l'importo è obbligatorio: prima un campo vuoto diventava 0 nel
     * controller e si salvava come una delega a saldo zero.
     */
    public function testF24SenzaImportoRestaNelFormConAvvisoSulCampo(): void
    {
        $entita = self::entitaScriventi()['f24'][0];

        $dati = $this->datiDelForm($entita, 'Collaudo Importo', true);
        $dati['f24_amount'] = '';

        $prima = $this->contaRighe($entita);

        $risposta = $this->inviaConToken($entita['form'], $dati, $entita['form']);

        $this->assertSame(200, $risposta->response()->getStatusCode(), 'Importo vuoto: il form deve riaprirsi');

        $html = (string) $risposta->response()->getBody();

        $this->assertStringContainsString(esc("L'importo è obbligatorio"), $html, 'Importo vuoto: manca l\'avviso sul campo');
        $this->assertStringNotContainsString(esc($entita['successo']), $html, 'Importo vuoto: non deve annunciare il salvataggio');
        $this->assertSame($prima, $this->contaRighe($entita), 'Importo vuoto: nessuna riga deve nascere');
    }

    /**
     * Zero è un importo, non un campo vuoto: la delega interamente compensata
     * si salva, e il prezzo di questa regola non è averla resa obbligatoria.
     */
    public function testF24ConImportoZeroSiSalva(): void
    {
        $entita = self::entitaScriventi()['f24'][0];

        $dati = $this->datiDelForm($entita, 'Collaudo Importo Zero', true);
        $dati['f24_amount'] = '0,00';

        $risposta = $this->inviaConToken($entita['form'], $dati, $entita['form']);

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Importo zero: la delega deve salvarsi');

        $riga = $this->testDb->query(
            'SELECT f24_amount FROM ip_f24 WHERE f24_note = ?',
            ['Collaudo Importo Zero']
        )->getRowArray();

        $this->assertNotNull($riga, 'Importo zero: riga non creata');
        $this->assertSame(0.0, (float) $riga['f24_amount'], 'Importo zero: l\'importo salvato non è zero');
    }

    /**
     * «Nessun conto» si salva come NULL.
     *
     * La colonna è un intero e il form manda la stringa vuota: PostgreSQL la
     * rifiuta con una pagina d'errore, MariaDB la converte in 0 — un conto che
     * non esiste, che poi l'abbinamento bancario prova a usare.
     */
    public function testF24SenzaContoDiAddebitoSiSalva(): void
    {
        $entita = self::entitaScriventi()['f24'][0];

        $dati = $this->datiDelForm($entita, 'Collaudo Senza Conto', true);
        $dati['bank_account_id'] = '';

        $risposta = $this->inviaConToken($entita['form'], $dati, $entita['form']);

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Senza conto: la delega deve salvarsi');

        $riga = $this->testDb->query(
            'SELECT bank_account_id FROM ip_f24 WHERE f24_note = ?',
            ['Collaudo Senza Conto']
        )->getRowArray();

        $this->assertNotNull($riga, 'Senza conto: riga non creata');
        $this->assertNull($riga['bank_account_id'], 'Senza conto: il conto deve restare vuoto, non zero');
    }

    /**
     * I dati del form presi da una riga vera con il campo sotto esame cambiato:
     * così il POST passa la validazione del controller senza dover elencare a
     * mano i campi obbligatori di ogni entità.
     *
     * @param array{etichetta: string, tabella: string, chiave: string, modello: class-string, form: string, campo: string, successo: string, errore: string, rifiuto: class-string, filtro: string} $entita
     *
     * @return array<string, mixed>
     */
    private function datiDelForm(array $entita, string $valore, bool $creazione): array
    {
        $modello = new $entita['modello']();
        $regole  = $modello->validationRulesLegacy();

        $riga = $this->testDb->query(
            'SELECT * FROM ' . $entita['tabella'] . ' WHERE ' . $entita['filtro'] . ' ORDER BY ' . $entita['chiave'] . ' LIMIT 1'
        )->getRowArray() ?: [];

        $dati = $this->campiFissi($entita['tabella']);

        foreach (array_keys($regole) as $campo) {
            if (! array_key_exists($campo, $dati)) {
                $dati[$campo] = $riga[$campo] ?? '';
            }
        }

        $dati[$entita['campo']] = $valore;

        return $this->ritocchiPerEntita($entita['tabella'], $dati, $creazione);
    }

    /**
     * I pochi campi che non possono essere copiati dalla riga di partenza:
     * valori unici e stati che bloccherebbero la scrittura.
     *
     * @param array<string, mixed> $dati
     *
     * @return array<string, mixed>
     */
    private function ritocchiPerEntita(string $tabella, array $dati, bool $creazione): array
    {
        switch ($tabella) {
            case 'ip_clients':
                // Nel database la colonna è un flag (0/1), nel form sono le
                // tre scelte auto/yes/no: copiando il valore grezzo la
                // validazione rifiuta un form che il browser non invierebbe
                // mai (e il messaggio finisce su un campo che la vista non
                // mostra, quindi la pagina torna senza dire niente).
                $dati['client_split_payment'] = 'no';

                // La data di nascita è in tabella ma non ha un campo nel form:
                // copiarla vuota la manderebbe come stringa '' in una colonna
                // DATE, che MariaDB rifiuta (PostgreSQL no, quindi il guasto
                // comparirebbe su un motore solo).
                unset($dati['client_birthdate']);
                break;

            case 'ip_users':
                $dati['user_email']      = 'collaudo.' . bin2hex(random_bytes(4)) . '@example.test';
                $dati['user_password']   = $creazione ? 'Collaudo123!' : '';
                $dati['user_passwordv']  = $creazione ? 'Collaudo123!' : '';
                $dati['user_language']   = $dati['user_language'] ?: 'it';
                break;

            case 'ip_expenses':
                // Indice unico su (company_id, expense_source_ref): una spesa
                // inserita a mano non ha un riferimento esterno.
                unset($dati['expense_source_ref']);
                break;

        }

        return $dati;
    }

    /**
     * I campi che non si possono copiare da una riga esistente: le tabelle
     * dell'azienda possono essere vuote, e allora i form vanno riempiti con
     * valori validi scritti qui (l'anno fiscale lo sceglie valoreDelCampo()).
     *
     * @return array<string, mixed>
     */
    private function campiFissi(string $tabella): array
    {
        $oggi = date('Y-m-d');

        return match ($tabella) {
            'ip_products' => [
                'product_name'        => 'Collaudo',
                'product_description' => '',
                'product_sku'         => '',
                'product_price'       => '10.00',
                'purchase_price'      => '',
                'provider_name'       => '',
                'family_id'           => $this->primoId('ip_families', 'family_id'),
                'tax_rate_id'         => $this->primoId('ip_tax_rates', 'tax_rate_id'),
                'unit_id'             => $this->primoId('ip_units', 'unit_id'),
            ],
            'ip_tasks' => [
                'task_name'        => 'Collaudo',
                'task_description' => '',
                'task_price'       => '1.00',
                // La data si manda come la mostra il form (formato impostato
                // nelle impostazioni), non in ISO: il controller la converte
                // con date_to_mysql() prima di salvare.
                'task_finish_date' => date_from_mysql($oggi),
                'task_status'      => 1,
                // project_id è NOT NULL nel database: l'attività va agganciata
                // a un progetto vero, non basta il permesso della validazione.
                'project_id'       => $this->progettoDiCollaudo(),
                // Un id vero e non la stringa vuota: la colonna è intera e
                // MariaDB rifiuta '' invece di convertirlo (PostgreSQL no,
                // quindi il problema si vede su un motore solo).
                'tax_rate_id'      => $this->primoId('ip_tax_rates', 'tax_rate_id'),
            ],
            'ip_f24' => [
                // La tabella è vuota nel seed del CI: senza questi valori il
                // form ripescherebbe solo stringhe vuote e l'INSERT violerebbe
                // il NOT NULL di f24_due_date.
                'f24_due_date'  => date_from_mysql($oggi),
                'f24_amount'    => '100,00',
                'f24_period'    => '2 trimestre 2026',
                'f24_paid_date' => '',
                'bank_account_id' => $this->primoId('ip_bank_accounts', 'bank_account_id'),
                'f24_note'      => 'Collaudo',
                'f24_file'      => '',
                'f24_receipt_protocol' => '',
            ],
            'ip_projects' => [
                'project_name' => 'Collaudo',
                'client_id'    => $this->primoId('ip_clients', 'client_id', 'company_id = 1'),
            ],
            'ip_delivery_notes' => [
                'client_id'            => $this->primoId('ip_clients', 'client_id', 'company_id = 1'),
                'delivery_note_date'   => $oggi,
                'invoice_group_id'     => $this->primoId('ip_invoice_groups', 'invoice_group_id', 'company_id = 1'),
                'invoice_id'           => '',
                'delivery_note_number' => '',
                'transport_datetime'   => '',
                'transport_reason'     => '',
                'goods_appearance'     => '',
                'packages'             => '',
                'weight'               => '',
                'carrier'              => '',
                'transport_charge'     => '',
                'shipping_address'     => '',
                'delivery_note_notes'  => '',
            ],
            default => [],
        };
    }

    /**
     * Un progetto dell'azienda 1 per le attività del test: in ip_tasks.project_id
     * il database non accetta NULL. Resta nella transazione, che viene annullata.
     */
    private function progettoDiCollaudo(): string
    {
        if ($this->progettoCollaudo === null) {
            $this->testDb->query(
                'INSERT INTO ip_projects (client_id, project_name) VALUES (?, ?)',
                [$this->primoId('ip_clients', 'client_id', 'company_id = 1'), 'Collaudo attività']
            );

            $this->progettoCollaudo = (int) $this->testDb->insertID();
        }

        return (string) $this->progettoCollaudo;
    }

    /**
     * Il primo id di una tabella (dell'azienda 1 quando serve), per i riferimenti
     * obbligatori dei form.
     */
    private function primoId(string $tabella, string $colonna, string $filtro = '1 = 1'): string
    {
        $riga = $this->testDb->query(
            'SELECT MIN(' . $colonna . ') AS id FROM ' . $tabella . ' WHERE ' . $filtro
        )->getRow();

        return (string) ($riga->id ?? '');
    }

    /**
     * @param array{tabella: string} $entita
     */
    private function contaRighe(array $entita): int
    {
        return (int) $this->testDb->query('SELECT COUNT(*) AS n FROM ' . $entita['tabella'])->getRow()->n;
    }
}

/**
 * I controller veri con un modello che rifiuta la scrittura.
 *
 * Servono a provare via HTTP il ramo d'errore di saveFormRecord(): la
 * validazione passa, il modello dice di no, e il form deve restare aperto con
 * l'avviso. Ogni classe sostituisce solo il modello, il resto è il controller
 * di produzione.
 */
final class ProdottiCheRifiuta extends \App\Controllers\Products
{
    public function __construct()
    {
        parent::__construct();

        $this->productModel = new class extends \App\Models\ProductModel {
            public function saveRecord(?int $id, array $dbArray)
            {
                return 0;
            }
        };
    }
}

final class AttivitaCheRifiuta extends \App\Controllers\Tasks
{
    public function __construct()
    {
        parent::__construct();

        $this->taskModel = new class extends \App\Models\TaskModel {
            public function saveRecord(?int $id, array $dbArray)
            {
                return 0;
            }
        };
    }
}

final class ProgettiCheRifiuta extends \App\Controllers\Projects
{
    public function __construct()
    {
        parent::__construct();

        $this->projectModel = new class extends \App\Models\ProjectModel {
            public function saveRecord(?int $id, array $dbArray)
            {
                return 0;
            }
        };
    }
}

final class ModelliEmailCheRifiuta extends \App\Controllers\EmailTemplates
{
    public function __construct()
    {
        parent::__construct();

        $this->emailTemplateModel = new class extends \App\Models\EmailTemplateModel {
            public function saveRecord(?int $id, array $dbArray)
            {
                return 0;
            }
        };
    }
}

final class GruppiFattureCheRifiuta extends \App\Controllers\InvoiceGroups
{
    public function __construct()
    {
        parent::__construct();

        $this->invoiceGroupModel = new class extends \App\Models\InvoiceGroupModel {
            public function saveRecord(?int $id, array $dbArray)
            {
                return 0;
            }
        };
    }
}

final class SpeseCheRifiuta extends \App\Controllers\Expenses
{
    public function __construct()
    {
        parent::__construct();

        $this->expenseModel = new class extends \App\Models\ExpenseModel {
            public function saveRecord(?int $id, array $dbArray)
            {
                return 0;
            }
        };
    }
}

final class ClientiCheRifiuta extends \App\Controllers\Clients
{
    public function __construct()
    {
        parent::__construct();

        $this->clientModel = new class extends \App\Models\ClientModel {
            public function saveRecord(?int $id, array $dbArray)
            {
                return 0;
            }
        };
    }
}

final class DocumentiTrasportoCheRifiuta extends \App\Controllers\DeliveryNotes
{
    public function __construct()
    {
        parent::__construct();

        $this->model = new class extends \App\Models\DeliveryNoteModel {
            public function saveRecord(?int $id, array $dbArray)
            {
                return 0;
            }
        };
    }
}

final class UtentiCheRifiuta extends \App\Controllers\Users
{
    public function __construct()
    {
        parent::__construct();

        $this->userModel = new class extends \App\Models\UserModel {
            public function saveRecord(?int $id, array $dbArray)
            {
                return 0;
            }
        };
    }
}

final class F24CheRifiuta extends \App\Controllers\F24
{
    public function __construct()
    {
        parent::__construct();

        $this->model = new class extends \App\Models\F24Model {
            public function saveRecord(?int $id, array $dbArray)
            {
                return 0;
            }
        };
    }
}

final class ParametriFiscaliCheRifiuta extends \App\Controllers\FiscalYears
{
    public function __construct()
    {
        parent::__construct();

        $this->model = new class extends \App\Models\FiscalYearModel {
            public function saveRecord(?int $id, array $dbArray)
            {
                return 0;
            }
        };
    }
}


/**
 * Il controller dei valori personalizzati con un modello che rifiuta la
 * scrittura: prova via HTTP il ramo d'errore della creazione, che prima
 * annunciava il valore creato senza guardare l'esito.
 */
final class ValoriPersonalizzatiCheRifiuta extends \App\Controllers\CustomValues
{
    public function __construct()
    {
        parent::__construct();

        $this->customValueModel = new class extends \App\Models\CustomValueModel {
            public function saveRecord(?int $id, array $dbArray)
            {
                return 0;
            }
        };
    }
}
