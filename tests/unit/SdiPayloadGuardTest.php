<?php

use App\Libraries\Sdi\SdiPayloadGuard;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Limiti di sicurezza sui payload SDICoop.
 *
 * Sono numeri e decisioni pure: si verificano senza database, senza rete e
 * senza istanziare i controller, che avrebbero bisogno delle impostazioni.
 *
 * @internal
 */
final class SdiPayloadGuardTest extends CIUnitTestCase
{
    /** @var list<string> cartelle usa e getta da pulire in tearDown */
    private array $cartelle = [];

    public function testBodyDichiaratoOltreIlLimiteSiRifiutaSenzaLeggerlo(): void
    {
        $problem = SdiPayloadGuard::bodyProblem((string) (SdiPayloadGuard::MAX_BODY_BYTES + 1));

        $this->assertNotNull($problem, 'Un Content-Length oltre il tetto basta a rifiutare la richiesta');
        $this->assertStringContainsString('20 MB', $problem);
    }

    public function testBodyRealeOltreIlLimiteSiRifiuta(): void
    {
        // Header assente o sbagliato: conta la lunghezza effettiva.
        $this->assertNotNull(SdiPayloadGuard::bodyProblem(null, SdiPayloadGuard::MAX_BODY_BYTES + 1));
        $this->assertNotNull(SdiPayloadGuard::bodyProblem('', SdiPayloadGuard::MAX_BODY_BYTES + 1));
        $this->assertNotNull(SdiPayloadGuard::bodyProblem('non-un-numero', SdiPayloadGuard::MAX_BODY_BYTES + 1));
    }

    public function testBodyAlLimiteOPiuPiccoloPassa(): void
    {
        $this->assertNull(SdiPayloadGuard::bodyProblem((string) SdiPayloadGuard::MAX_BODY_BYTES, SdiPayloadGuard::MAX_BODY_BYTES));
        $this->assertNull(SdiPayloadGuard::bodyProblem('1024', 1024));
    }

    public function testUnContentLengthNonNumericoNonBloccaMaNonServeALimite(): void
    {
        $this->assertNull(SdiPayloadGuard::bodyProblem('chunked'));
        $this->assertNotNull(SdiPayloadGuard::bodyProblem('chunked', SdiPayloadGuard::MAX_BODY_BYTES + 1));
    }

    public function testIlSingoloXmlHaUnTettoPiuBassoDelBody(): void
    {
        $this->assertNull(SdiPayloadGuard::xmlProblem(SdiPayloadGuard::MAX_XML_BYTES));
        $this->assertSame(
            'File XML oltre il limite di 10 MB',
            SdiPayloadGuard::xmlProblem(SdiPayloadGuard::MAX_XML_BYTES + 1)
        );
    }

    public function testUnoZipConTroppeVociSiRifiuta(): void
    {
        $voci = [];

        for ($i = 0; $i <= SdiPayloadGuard::MAX_ZIP_ENTRIES; $i++) {
            $voci['voce-' . $i . '.txt'] = 'contenuto ' . $i;
        }

        $problem = $this->problemaZip($voci);

        $this->assertNotNull($problem);
        $this->assertStringContainsString((string) (SdiPayloadGuard::MAX_ZIP_ENTRIES + 1) . ' voci', $problem);
    }

    public function testUnaZipBombSiRifiutaPerIlRapportoDiCompressione(): void
    {
        // 2 MB di zeri: compressi pesano pochi KB, rapporto ben oltre 100:1
        // ma sotto i limiti di dimensione. E' il caso che deve fermarsi.
        $problem = $this->problemaZip(['bomba.xml' => str_repeat("\0", 2 * 1024 * 1024)]);

        $this->assertNotNull($problem);
        $this->assertStringContainsString('zip bomb', $problem);
        $this->assertStringContainsString(':1', $problem);
    }

    public function testUnoZipNormalePassa(): void
    {
        // Righe varie, come in una fattura vera: un XML reale comprime
        // intorno a 10-20:1. Ripetere la stessa riga all'infinito non e' il
        // caso che questo limite deve accettare.
        $righe = '';

        for ($i = 1; $i <= 40; $i++) {
            $righe .= '<Riga><Numero>' . $i . '</Numero><Descrizione>Servizio numero ' . $i . '</Descrizione>'
                . '<Importo>' . (100 + $i * 13) . '.00</Importo></Riga>';
        }

        $this->assertNull($this->problemaZip([
            'IT01234567890_00001.xml' => '<?xml version="1.0"?><FatturaElettronica>' . $righe . '</FatturaElettronica>',
            'elenco.csv'              => implode("\n", range(1, 100)),
        ]));
    }

    public function testUnNomeFileGiaPresenteNonVieneSovrascritto(): void
    {
        $dir = $this->cartellaTemporanea();
        $originale = $dir . '/IT01234567890_00001.xml';

        file_put_contents($originale, 'primo contenuto');

        $destinazione = SdiPayloadGuard::destination($dir, 'IT01234567890_00001.xml', 'secondo contenuto');

        $this->assertTrue($destinazione['write'], 'Il contenuto diverso va scritto in una copia nuova');
        $this->assertTrue($destinazione['renamed'], 'Il nome deve cambiare per non sovrascrivere');
        $this->assertNotSame($originale, $destinazione['path']);
        $this->assertFileDoesNotExist($destinazione['path']);

        file_put_contents($destinazione['path'], 'secondo contenuto');

        $this->assertSame('primo contenuto', file_get_contents($originale), 'La copia originale resta intatta');
        $this->assertStringContainsString('.xml', $destinazione['path'], 'L\'estensione resta in coda');
    }

    public function testLaStessaConsegnaIdenticaNonRiscriveIlFile(): void
    {
        $dir = $this->cartellaTemporanea();
        $originale = $dir . '/IT01234567890_00001.xml';

        file_put_contents($originale, 'stesso contenuto');

        $destinazione = SdiPayloadGuard::destination($dir, 'IT01234567890_00001.xml', 'stesso contenuto');

        $this->assertFalse($destinazione['write'], 'Nessuna scrittura: il file e\' gia\' li\'');
        $this->assertTrue($destinazione['duplicate']);
        $this->assertFalse($destinazione['renamed']);
        $this->assertSame($originale, $destinazione['path']);
    }

    public function testUnaConsegnaRipetutaDiUnFileRinominatoNonNeCreaUnAltro(): void
    {
        $dir = $this->cartellaTemporanea();
        $nome = 'IT01234567890_00001.xml';

        file_put_contents($dir . '/' . $nome, 'primo contenuto');

        $prima = SdiPayloadGuard::destination($dir, $nome, 'secondo contenuto');
        file_put_contents($prima['path'], 'secondo contenuto');

        $seconda = SdiPayloadGuard::destination($dir, $nome, 'secondo contenuto');

        $this->assertSame($prima['path'], $seconda['path']);
        $this->assertFalse($seconda['write'], 'La seconda consegna identica riusa la copia con suffisso');
    }

    public function testSuffissoConservaLestensioneP7m(): void
    {
        $this->assertMatchesRegularExpression(
            '/^IT01234567890_00001\.xml\.[a-f0-9]{12}\.p7m$/',
            SdiPayloadGuard::suffixedName('IT01234567890_00001.xml.p7m', 'contenuto')
        );
    }

    public function testRedactToglieUrlECredenziali(): void
    {
        $testo = 'cURL error 60 su https://trasmittente.example.test/invia?x=1 con Basic dXNlcjpwYXNz';

        $pulito = SdiPayloadGuard::redact($testo);

        $this->assertStringNotContainsString('trasmittente.example.test', $pulito);
        $this->assertStringNotContainsString('dXNlcjpwYXNz', $pulito);
        $this->assertStringContainsString('[endpoint]', $pulito);
        $this->assertStringContainsString('Basic [redacted]', $pulito);
    }

    /**
     * @param array<string, string> $voci
     */
    private function problemaZip(array $voci): ?string
    {
        $path = $this->cartellaTemporanea() . '/prova.zip';
        $zip  = new ZipArchive();

        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));

        foreach ($voci as $nome => $contenuto) {
            $zip->addFromString($nome, $contenuto);
        }

        $zip->close();

        $lettura = new ZipArchive();
        $this->assertTrue($lettura->open($path));
        $problem = SdiPayloadGuard::zipProblem($lettura);
        $lettura->close();

        return $problem;
    }

    /**
     * Cartella usa e getta registrata per la pulizia in tearDown.
     */
    private function cartellaTemporanea(): string
    {
        $dir = sys_get_temp_dir() . '/sdi-guard-' . bin2hex(random_bytes(6));

        mkdir($dir, 0775, true);
        $this->cartelle[] = $dir;

        return $dir;
    }

    protected function tearDown(): void
    {
        foreach ($this->cartelle as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($dir);
        }

        $this->cartelle = [];

        parent::tearDown();
    }
}
