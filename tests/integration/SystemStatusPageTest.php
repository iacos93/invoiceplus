<?php

use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * La pagina "Stato del sistema" si apre e riporta tutti i controlli.
 *
 * Non si verificano gli esiti (in un ambiente di test un backup mancante è
 * normale): conta che ognuno dei sei controlli venga eseguito senza far
 * fallire la pagina, perché è la pagina che deve dire cosa non va.
 *
 * @internal
 */
final class SystemStatusPageTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const DB_COPY = 'invoiceplane_posttest';

    private ?BaseConnection $testDb = null;

    /** @var array<string, mixed> */
    private array $originalTests = [];

    private int $utente = 1;

    /** Dump finto creato per verificare il download senza toccare i dati veri. */
    private ?string $dumpDiProva = null;

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
            $this->testDb->query('SELECT 1 FROM ip_settings LIMIT 1');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non disponibile: ' . $config->tests['database']);
        }

        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);

        $_SESSION['active_company_id'] = 1;

        $row          = $this->testDb->query('SELECT user_id FROM ip_users ORDER BY user_id LIMIT 1')->getRow();
        $this->utente = (int) ($row->user_id ?? 1);

        // Il download si prova su un dump finto e recente: in locale la
        // cartella contiene i backup veri (con dati personali) e non devono
        // uscire dai test.
        $dir = WRITEPATH . 'backups';

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->dumpDiProva = $dir . '/backup_test_' . bin2hex(random_bytes(4)) . '.dump';
        file_put_contents($this->dumpDiProva, "contenuto di prova\n");
        touch($this->dumpDiProva, time());
    }

    protected function tearDown(): void
    {
        if ($this->dumpDiProva !== null) {
            @unlink($this->dumpDiProva);
            $this->dumpDiProva = null;
        }

        $this->restoreConnections();

        parent::tearDown();
    }

    public function testLaPaginaRiportaTuttiIControlli(): void
    {
        $risposta = $this->withSession($this->sessione())->get('/system/status');

        $this->assertSame(200, $risposta->response()->getStatusCode(), 'Stato del sistema: la pagina deve aprirsi');

        // Le etichette passano da esc(): l'apostrofo di "Ambiente dell'applicazione"
        // arriva come entità HTML, quindi si confronta il testo decodificato.
        $html = html_entity_decode((string) $risposta->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach ([
            'Versione di PHP',
            'Cartelle scrivibili',
            'Database',
            'Migrazioni',
            'Backup del database',
            'Verifica del ripristino',
            'Automazioni',
            'Ambiente dell\'applicazione',
            'Spazio su disco',
            'Errori nel log',
        ] as $controllo) {
            $this->assertStringContainsString($controllo, $html, 'Manca il controllo: ' . $controllo);
        }

        $this->assertGreaterThanOrEqual(
            10,
            substr_count($html, 'badge text-bg-'),
            'Ogni controllo deve avere il suo stato, non solo l\'etichetta'
        );

        $this->assertStringContainsString('ip-status-hero', $html, 'Manca il riepilogo in testa');
        $this->assertStringContainsString('Rilevato il', $html, 'Manca l\'orario di rilevamento');
        $this->assertSame(
            10,
            substr_count($html, 'class="ip-status-row ip-status-row--'),
            'I controlli devono essere una riga ciascuno'
        );
    }

    public function testLaPaginaOffreIlDownloadDelBackup(): void
    {
        $risposta = $this->withSession($this->sessione())->get('/system/status');
        $html     = html_entity_decode((string) $risposta->response()->getBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertStringContainsString('Scarica l\'ultimo backup', $html, 'Manca il pulsante di download');
        $this->assertStringContainsString(site_url('system/status/backup'), $html, 'Il pulsante non punta al download');
    }

    /**
     * Il download serve a portare la copia fuori dal server: deve consegnare
     * proprio l'ultimo dump, con il suo nome e la sua dimensione.
     */
    public function testIlDownloadRestituisceLUltimoDump(): void
    {
        $risposta = $this->withSession($this->sessione())->get('/system/status/backup');
        $response = $risposta->response();

        // Il corpo parte solo all'invio: qui interessano gli header, che
        // dicono quale file è stato scelto e quanti byte ha.
        $response->buildHeaders();


        $this->assertSame(200, $response->getStatusCode(), 'Download del backup: atteso un file, non un errore');
        $this->assertStringContainsString('attachment', (string) $response->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString(basename((string) $this->dumpDiProva), (string) $response->getHeaderLine('Content-Disposition'));
        $this->assertSame((string) filesize((string) $this->dumpDiProva), (string) $response->getHeaderLine('Content-Length'));
    }

    public function testIlDownloadSenzaSessioneRimandaAlLogin(): void
    {
        $risposta = $this->get('/system/status/backup');

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Il download non è pubblico');
        $this->assertStringContainsString('sessions/login', (string) $risposta->response()->getHeaderLine('Location'));
    }

    /**
     * Un collegamento simbolico chiamato backup_* non deve far uscire dalla
     * cartella: il nome del file non si sceglie dall'URL, ma un link è un
     * secondo modo per arrivare altrove.
     */
    public function testUnCollegamentoFuoriDallaCartellaNonSiScarica(): void
    {
        $esterno = sys_get_temp_dir() . '/ip-fuori-' . bin2hex(random_bytes(4)) . '.dump';
        file_put_contents($esterno, 'fuori dalla cartella');
        touch($esterno, time() + 5);

        $link = WRITEPATH . 'backups/backup_zzz_' . bin2hex(random_bytes(4)) . '.dump';
        symlink($esterno, $link);

        try {
            $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);

            $this->withSession($this->sessione())->get('/system/status/backup');
        } finally {
            @unlink($link);
            @unlink($esterno);
        }
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
