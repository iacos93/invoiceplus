<?php

use App\Controllers\F24;
use App\Models\F24Model;
use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Importazione delle quietanze F24: dal PDF caricato alla riga scritta.
 *
 * L'abbinamento aggiorna la delega con data di versamento, protocollo e file
 * allegato. Quella scrittura passa dalle regole del modello, che richiedono
 * scadenza e importo: se l'update non li ricevesse — com'era prima — sarebbe
 * rifiutato in silenzio e la quietanza risulterebbe abbinata senza essere
 * allegata. Il test guarda la riga, non il solo esito dichiarato. Lo stesso
 * vale per la delega creata dalla quietanza quando nessuna corrisponde:
 * saveRecord() torna 0 se l'INSERT è rifiutato, e l'esito deve dirlo invece
 * di annunciare un F24 creato con un id 0.
 *
 * Un upload HTTP vero da CLI non esiste: is_uploaded_file() risponde sempre
 * no, e quindi nemmeno isValid() e move() funzionano su un file creato qui.
 * Il controller di prova in fondo al file sostituisce solo la lettura dei file
 * dal POST, con un PDF generato in casa e un UploadedFile che si lascia
 * spostare: parser, abbinamento, regole e scrittura restano quelli veri.
 *
 * Ogni test gira in una transazione chiusa con rollback; i file creati durante
 * il test (il PDF d'ingresso e l'allegato finito in writable/uploads/f24)
 * vengono cancellati alla fine.
 *
 * @internal
 */
final class F24ReceiptImportTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const DB_COPY = 'invoiceplane_posttest';

    private ?BaseConnection $testDb = null;

    /** @var array<string, mixed> */
    private array $originalTests = [];

    /** File da cancellare a fine test: PDF d'ingresso e allegati nuovi. */
    private array $daCancellare = [];

    /** Allegati già presenti in writable/uploads/f24 prima del test. */
    private array $allegatiPrima = [];

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
            $this->testDb->query('SELECT 1 FROM ip_f24 LIMIT 1');
        } catch (\Throwable) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non disponibile: ' . $config->tests['database']);
        }

        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);

        $_SESSION['active_company_id'] = 1;

        $row          = $this->testDb->query('SELECT user_id FROM ip_users ORDER BY user_id LIMIT 1')->getRow();
        $this->utente = (int) ($row->user_id ?? 1);

        $this->allegatiPrima = $this->allegatiSalvati();

        $this->testDb->transBegin();
    }

    protected function tearDown(): void
    {
        if ($this->testDb !== null && $this->testDb->transDepth > 0) {
            $this->testDb->transRollback();
        }

        if ($this->testDb !== null) {
            $this->testDb->close();
            $this->testDb = null;
        }

        // Solo i file nati in questa prova: la cartella è quella vera.
        foreach (array_diff($this->allegatiSalvati(), $this->allegatiPrima) as $allegato) {
            unlink($allegato);
        }

        foreach ($this->daCancellare as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->restoreConnections();

        parent::tearDown();
    }

    /**
     * La quietanza di una delega aperta: data di versamento, protocollo e file
     * finiscono sulla riga giusta, e scadenza e importo restano com'erano.
     */
    public function testQuietanzaAbbinataSiScriveSullaDelega(): void
    {
        $scadenza   = date('Y-m-d');
        $importo    = 1234.56;
        $versamento = date('Y-m-d', strtotime('-3 days'));
        $protocollo = '24051612345678901-000001';

        $f24Id = $this->delegaAperta($importo, $scadenza);

        $this->withRoutes([
            ['GET', 'f24/import', '\F24ImportazioneDiProva::import'],
            ['POST', 'f24/import', '\F24ImportazioneDiProva::import'],
        ]);

        F24ImportazioneDiProva::$file = [
            $this->uploadDiProva($this->quietanzaPdf($importo, $versamento, $protocollo)),
        ];

        $risposta = $this->postImport();

        $html = (string) $risposta->response()->getBody();

        $this->assertSame(200, $risposta->response()->getStatusCode(), 'L\'importazione deve tornare la pagina d\'esito');
        $this->assertStringContainsString(esc('Abbinata'), $html, 'L\'esito non dice che la quietanza è stata abbinata');
        $this->assertStringContainsString('1 / 1', $html, 'L\'esito non conta la quietanza registrata');

        $riga = $this->testDb->query(
            'SELECT f24_due_date, f24_amount, f24_paid_date, f24_receipt_file, f24_receipt_protocol FROM ip_f24 WHERE f24_id = ?',
            [$f24Id]
        )->getRowArray();

        $this->assertNotNull($riga, 'La delega non esiste più');
        $this->assertSame($scadenza, substr((string) $riga['f24_due_date'], 0, 10), 'La scadenza è cambiata: l\'update doveva lasciarla com\'era');
        $this->assertSame($importo, round((float) $riga['f24_amount'], 2), 'L\'importo è cambiato: l\'update doveva lasciarlo com\'era');
        $this->assertSame($versamento, substr((string) $riga['f24_paid_date'], 0, 10), 'La data di versamento non è stata scritta');
        $this->assertSame($protocollo, $riga['f24_receipt_protocol'], 'Il protocollo della quietanza non è stato scritto');

        $allegato = (string) $riga['f24_receipt_file'];
        $this->assertNotSame('', $allegato, 'La quietanza risulta abbinata ma il file non è stato allegato');

        $percorso = WRITEPATH . 'uploads/f24/' . $allegato;
        $this->assertFileExists($percorso, 'Il nome dell\'allegato è in tabella ma il file non c\'è');

        $testo = App\Libraries\F24ReceiptParser::textFromPdf($percorso);
        $this->assertStringContainsString($protocollo, $testo, 'L\'allegato salvato non è il PDF caricato');
    }

    /**
     * Una quietanza rifiutata non ferma quelle caricate con lei: quella buona
     * si scrive, quella rifiutata resta senza protocollo e senza allegato con
     * il motivo in pagina, e il suo file viene cancellato.
     *
     * Le regole del modello non possono rifiutare questo update: il parser
     * non produce protocolli oltre i 59 caratteri, quindi il limite di 60 non
     * scatta mai. Il rifiuto che resta è quello del database, che non lascia
     * messaggi di validazione: il motivo è quindi il riepilogo di ripiego.
     */
    public function testAbbinamentoRifiutatoRestaNonAbbinatoConIlMotivo(): void
    {
        $rifiutataId = $this->delegaAperta(1234.56, date('Y-m-d'));
        $accoltaId   = $this->delegaAperta(2345.67, date('Y-m-d', strtotime('+5 days')));
        $versamento  = date('Y-m-d', strtotime('-3 days'));

        $this->withRoutes([
            ['GET', 'f24/import', '\F24ImportazioneRifiutata::import'],
            ['POST', 'f24/import', '\F24ImportazioneRifiutata::import'],
        ]);

        F24ImportazioneDiProva::$file = [
            $this->uploadDiProva($this->quietanzaPdf(1234.56, $versamento, F24ModelCheRifiuta::PROTOCOLLO)),
            $this->uploadDiProva($this->quietanzaPdf(2345.67, $versamento, '24051612345678901-000002')),
        ];

        $html = (string) $this->postImport()->response()->getBody();

        $this->assertStringContainsString('1 / 2', $html, 'L\'esito conta come registrate le quietanze che non lo sono');
        $this->assertStringContainsString(esc('Da abbinare a mano'), $html, 'La quietanza rifiutata risulta abbinata');
        $this->assertStringContainsString('Abbinamento non registrato: salvataggio rifiutato dal database', $html, 'L\'esito non spiega perché la quietanza è rimasta fuori');

        $rifiutata = $this->riga($rifiutataId);

        $this->assertNotNull($rifiutata, 'La delega rifiutata non esiste più');
        $this->assertNull($rifiutata['f24_receipt_protocol'], 'Il protocollo è stato scritto nonostante il rifiuto');
        $this->assertNull($rifiutata['f24_receipt_file'], 'L\'allegato è stato scritto nonostante il rifiuto');
        $this->assertNull($rifiutata['f24_paid_date'], 'La data di versamento è stata scritta nonostante il rifiuto');

        $accolta = $this->riga($accoltaId);

        $this->assertNotNull($accolta, 'La delega da abbinare non esiste più');
        $this->assertSame('24051612345678901-000002', $accolta['f24_receipt_protocol'], 'La quietanza buona non è stata abbinata dopo quella rifiutata');
        $this->assertSame($versamento, substr((string) $accolta['f24_paid_date'], 0, 10), 'La data di versamento della quietanza buona non è stata scritta');
        $this->assertNotSame('', (string) $accolta['f24_receipt_file'], 'La quietanza buona risulta abbinata senza allegato');
        $this->assertFileExists(WRITEPATH . 'uploads/f24/' . $accolta['f24_receipt_file'], 'L\'allegato della quietanza buona non c\'è');

        $this->assertCount(
            1,
            array_diff($this->allegatiSalvati(), $this->allegatiPrima),
            'In writable/uploads/f24 doveva restare il solo allegato della quietanza accolta'
        );
    }

    /** I campi della quietanza di una delega, per le asserzioni sul rifiuto. */
    private function riga(int $f24Id): ?array
    {
        return $this->testDb->query(
            'SELECT f24_paid_date, f24_receipt_file, f24_receipt_protocol FROM ip_f24 WHERE f24_id = ?',
            [$f24Id]
        )->getRowArray();
    }

    /**
     * Se la creazione dell'F24 dalla quietanza viene rifiutata, l'esito non
     * deve dire «F24 creato» con un collegamento a un id 0: la quietanza
     * resta da abbinare a mano, con il motivo, e il file appena salvato non
     * rimane orfano in writable/.
     */
    public function testCreazioneRifiutataRestaNonAbbinataConIlMotivo(): void
    {
        // Importo che nessuna delega della copia può avere: la quietanza non
        // trova un F24 corrispondente e passa dal ramo di creazione.
        $importo = 998877.61;

        $this->withRoutes([
            ['GET', 'f24/import', '\F24ImportazioneCreazioneRifiutata::import'],
            ['POST', 'f24/import', '\F24ImportazioneCreazioneRifiutata::import'],
        ]);

        F24ImportazioneDiProva::$file = [
            $this->uploadDiProva($this->quietanzaPdf($importo, date('Y-m-d', strtotime('-3 days')), '24051612345678902-000002')),
        ];

        $html = (string) $this->postImport()->response()->getBody();

        $this->assertStringContainsString(esc('Da abbinare a mano'), $html, 'La quietanza non registrata risulta creata');
        $this->assertStringNotContainsString(esc('F24 creato'), $html, 'La quietanza non registrata porta il badge della creazione');
        $this->assertStringContainsString('La scadenza non è una data valida', $html, 'L\'esito non spiega perché la quietanza è rimasta fuori');
        $this->assertStringContainsString('0 / 1', $html, 'L\'esito conta come registrata una quietanza che non lo è');
        $this->assertStringNotContainsString('f24/form/0', $html, 'L\'esito collega un id 0');

        $righe = (int) $this->testDb->query(
            'SELECT COUNT(*) AS n FROM ip_f24 WHERE f24_amount = ?',
            [$importo]
        )->getRow()->n;

        $this->assertSame(0, $righe, 'La quietanza rifiutata ha comunque creato un F24');

        $this->assertSame(
            [],
            array_values(array_diff($this->allegatiSalvati(), $this->allegatiPrima)),
            'La quietanza non registrata ha lasciato un file orfano in writable/uploads/f24'
        );
    }

    /** Una delega aperta, senza quietanza, con l'importo e la scadenza dati. */
    private function delegaAperta(float $importo, string $scadenza): int
    {
        $this->testDb->query(
            'INSERT INTO ip_f24 (company_id, f24_due_date, f24_amount, f24_note) VALUES (1, ?, ?, ?)',
            [$scadenza, $importo, 'Collaudo quietanza']
        );

        return (int) $this->testDb->insertID();
    }

    /** Un PDF con le etichette che il parser riconosce. */
    private function quietanzaPdf(float $importo, string $versamento, string $protocollo): string
    {
        $html = '<h1>QUIETANZA DI VERSAMENTO</h1>'
            . '<p>IMPORTO VERSATO ' . number_format($importo, 2, ',', '.') . '</p>'
            . '<p>DATA DEL VERSAMENTO ' . date_from_mysql($versamento) . '</p>'
            . '<p>PROTOCOLLO TELEMATICO ' . $protocollo . '</p>';

        $percorso = tempnam(sys_get_temp_dir(), 'quietanza');
        $this->assertNotFalse($percorso, 'Non riesco a creare il PDF temporaneo');

        file_put_contents($percorso, pdf_render($html));
        $this->daCancellare[] = $percorso;

        return $percorso;
    }

    /** Il PDF come upload valido per il controller (senza upload HTTP). */
    private function uploadDiProva(string $percorso): UploadedFile
    {
        return new QuietanzaDiProva($percorso, 'quietanza.pdf', 'application/pdf', (int) filesize($percorso), UPLOAD_ERR_OK);
    }

    /** POST /f24/import con un token CSRF preso dalla pagina. */
    private function postImport()
    {
        $pagina = (string) $this->withSession($this->sessione())->get('/f24/import')->response()->getBody();

        preg_match('/name="csrf_test_name"[^>]*value="([^"]+)"/', $pagina, $trovato);

        $this->assertNotSame('', $trovato[1] ?? '', 'Nessun token CSRF nella pagina di importazione');

        return $this->withSession($this->sessione())->post('/f24/import', ['csrf_test_name' => $trovato[1]]);
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

    /** @return list<string> */
    private function allegatiSalvati(): array
    {
        $cartella = WRITEPATH . 'uploads/f24';

        return is_dir($cartella) ? array_values(array_filter(glob($cartella . '/*') ?: [], 'is_file')) : [];
    }

    private function resetConnections(): void
    {
        $ref = new ReflectionClass(\CodeIgniter\Database\Config::class);

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

/**
 * Il controller vero dell'importazione, con i file presi da qui invece che dal
 * POST: la sola cosa che un test CLI non può costruire è l'upload.
 *
 * Non final: i controller di prova qui sotto lo estendono per sostituire il
 * solo modello, lasciando intatta l'importazione.
 */
class F24ImportazioneDiProva extends F24
{
    /** @var list<UploadedFile> */
    public static array $file = [];

    protected function receiptFiles(): array
    {
        return self::$file;
    }
}

/**
 * Il controller dell'importazione con un modello che rifiuta una quietanza
 * sola, come il database che non scrive la riga.
 */
final class F24ImportazioneRifiutata extends F24ImportazioneDiProva
{
    public function __construct()
    {
        $this->model = new F24ModelCheRifiuta();
    }
}

/**
 * Il modello che non scrive la quietanza con il protocollo indicato e lascia
 * passare le altre: così lo stesso caricamento contiene un rifiuto e un
 * successo, e si vede che il primo non ferma il secondo.
 */
final class F24ModelCheRifiuta extends F24Model
{
    public const PROTOCOLLO = '24051612345678901-000001';

    public function update($id = null, $row = null): bool
    {
        $row = (array) $row;

        if (($row['f24_receipt_protocol'] ?? null) === self::PROTOCOLLO) {
            return false;
        }

        return parent::update($id, $row);
    }
}

/**
 * Il controller dell'importazione con un modello che rifiuta la creazione,
 * come farebbe una regola di validazione sulla riga nuova.
 */
final class F24ImportazioneCreazioneRifiutata extends F24ImportazioneDiProva
{
    public function __construct()
    {
        $this->model = new F24ModelCheRifiutaLaCreazione();
    }
}

/** Il modello che non inserisce: saveRecord() torna 0 e un errore lo spiega. */
final class F24ModelCheRifiutaLaCreazione extends F24Model
{
    public function insert($row = null, bool $returnID = true)
    {
        return false;
    }

    /** Come quando una regola di validazione rifiuta la riga. */
    public function errors(bool $forceDB = false): array
    {
        return ['f24_due_date' => 'La scadenza non è una data valida'];
    }
}

/**
 * Un file caricato che si lascia validare e spostare come un upload vero.
 */
final class QuietanzaDiProva extends UploadedFile
{
    public function isValid(): bool
    {
        return true;
    }

    public function move(string $targetPath, ?string $name = null, bool $overwrite = false)
    {
        $destinazione = rtrim($targetPath, '/') . '/' . ($name ?? $this->getClientName());

        copy($this->getPathname(), $destinazione);

        return $this;
    }
}
