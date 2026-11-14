<?php

use App\Libraries\UploadGuard;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * I controlli sui file caricati stanno in un punto solo: qui si fissano i
 * casi che prima passavano — un PDF rinominato .csv, un file enorme letto
 * interamente in memoria, un'estensione fuori allowlist — oltre al formato
 * dell'url_key usato come prefisso degli allegati.
 *
 * I file sono veri file temporanei: il MIME lo decide finfo sul contenuto,
 * non l'etichetta del multipart.
 *
 * @internal
 */
final class UploadGuardTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /** Scrive un file temporaneo con il contenuto indicato. */
    private function write(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'guard');

        if ($path === false) {
            $this->fail('Impossibile creare il file temporaneo');
        }

        file_put_contents($path, $content);
        $this->files[] = $path;

        return $path;
    }

    /**
     * File sparso della dimensione richiesta: il controllo del tetto deve
     * scattare prima di qualunque lettura, quindi il contenuto non serve.
     */
    private function sparse(int $size): string
    {
        $path = tempnam(sys_get_temp_dir(), 'guard');

        if ($path === false) {
            $this->fail('Impossibile creare il file temporaneo');
        }

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            $this->fail('Impossibile aprire il file temporaneo');
        }

        ftruncate($handle, $size);
        fclose($handle);
        $this->files[] = $path;

        return $path;
    }

    public function testCsvOltreIlLimiteVieneRifiutato(): void
    {
        $path   = $this->sparse(UploadGuard::maxBytes(UploadGuard::KIND_CSV) + 1);
        $reason = UploadGuard::check(UploadGuard::KIND_CSV, $path, 'clienti.csv');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('supera il limite', $reason);
        $this->assertStringContainsString('5 MB', $reason);
    }

    public function testXmlOltreIlLimiteVieneRifiutato(): void
    {
        $path   = $this->sparse(UploadGuard::maxBytes(UploadGuard::KIND_XML) + 1);
        $reason = UploadGuard::check(UploadGuard::KIND_XML, $path, 'lotto.xml');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('supera il limite', $reason);
        $this->assertStringContainsString('10 MB', $reason);
    }

    public function testPdfOltreIlLimiteVieneRifiutato(): void
    {
        $path   = $this->sparse(UploadGuard::maxBytes(UploadGuard::KIND_PDF) + 1);
        $reason = UploadGuard::check(UploadGuard::KIND_PDF, $path, 'quietanza.pdf');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('supera il limite', $reason);
        $this->assertStringContainsString('10 MB', $reason);
    }

    public function testPdfRinominatoCsvVieneRifiutato(): void
    {
        $path = $this->write("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");

        $reason = UploadGuard::check(UploadGuard::KIND_CSV, $path, 'documento.csv');

        $this->assertNotNull($reason, 'Un PDF con estensione .csv non deve passare');
        $this->assertStringContainsString('application/pdf', $reason);
        $this->assertStringContainsString('.csv', $reason);
    }

    public function testCsvRiconosciutoComeTestoSempliceVieneAccettato(): void
    {
        $path = $this->write("nome;email\nMario Rossi;mario@example.it\n");

        // Il caso reale è proprio questo: finfo può vedere "text/plain" e non
        // "text/csv", ma il file è un CSV legittimo.
        $this->assertTrue(UploadGuard::mimeAllowed(UploadGuard::KIND_CSV, 'text/plain', 'csv'));
        $this->assertNull(UploadGuard::check(UploadGuard::KIND_CSV, $path, 'clienti.csv'));
    }

    public function testEstensioneNonAmmessaVieneRifiutata(): void
    {
        $path   = $this->write('<?php echo "non sono un csv";');
        $reason = UploadGuard::check(UploadGuard::KIND_CSV, $path, 'script.php');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('Estensione non ammessa', $reason);
        $this->assertStringContainsString('.php', $reason);
    }

    public function testXmlValidoVieneAccettato(): void
    {
        $path = $this->write('<?xml version="1.0" encoding="UTF-8"?><FatturaElettronica><FatturaElettronicaHeader/></FatturaElettronica>');

        $this->assertNull(UploadGuard::check(UploadGuard::KIND_XML, $path, 'IT01234567890_00001.xml'));
    }

    public function testPdfValidoVieneAccettato(): void
    {
        $path = $this->write("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");

        $this->assertNull(UploadGuard::check(UploadGuard::KIND_PDF, $path, 'quietanza.pdf'));
    }

    public function testTxtAccettatoPerImportContatti(): void
    {
        $path = $this->write("nome;email\nMario Rossi;mario@example.it\n");

        $this->assertNull(UploadGuard::check(UploadGuard::KIND_CSV, $path, 'clienti.txt'));
    }

    public function testXmlConBomVieneAccettato(): void
    {
        // Il BOM (o una riga vuota prima della dichiarazione) fa vedere il
        // file come "text/plain": è comunque un CAMT.053 valido e va letto.
        $path = $this->write("\xEF\xBB\xBF\n<?xml version=\"1.0\" encoding=\"UTF-8\"?><Document><BkToCstmrStmt/></Document>");

        $this->assertTrue(UploadGuard::mimeAllowed(UploadGuard::KIND_XML, 'text/plain', 'xml'));
        $this->assertNull(UploadGuard::check(UploadGuard::KIND_XML, $path, 'estratto.xml'));
    }

    public function testPdfRinominatoXmlVieneRifiutato(): void
    {
        $path = $this->write("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");

        $reason = UploadGuard::check(UploadGuard::KIND_XML, $path, 'estratto.xml');

        $this->assertNotNull($reason);
        $this->assertStringContainsString('application/pdf', $reason);
    }

    public function testP7mInBase64VieneAccettatoComeXml(): void
    {
        // Alcuni intermediari consegnano le buste firmate in base64: finfo
        // le vede come testo, ma restano .p7m legittimi.
        $path = $this->write("-----BEGIN PKCS7-----\nMIIB...base64...\n-----END PKCS7-----\n");

        $this->assertTrue(UploadGuard::mimeAllowed(UploadGuard::KIND_XML, 'text/plain', 'p7m'));
        $this->assertNull(UploadGuard::check(UploadGuard::KIND_XML, $path, 'IT01234567890_00001.xml.p7m'));
    }

    public function testFileVuotoCsvVieneAccettatoEPoiLoGiudicaIlParser(): void
    {
        $path = $this->write('');

        $this->assertNull(UploadGuard::check(UploadGuard::KIND_CSV, $path, 'vuoto.csv'));
        $this->assertNotNull(UploadGuard::check(UploadGuard::KIND_PDF, $path, 'vuoto.pdf'));
    }

    /* ------------------------------------------------------- url_key */

    public function testUrlKeyDiDocumentoHaTrentadueCaratteriEsadecimali(): void
    {
        $this->assertTrue(UploadGuard::isValidUrlKey(str_repeat('a', 32)));
        $this->assertTrue(UploadGuard::isValidUrlKey('0123456789abcdef0123456789abcdef'));
        $this->assertTrue(UploadGuard::isValidUrlKey(str_repeat('A', 32)), 'Il formato accetta anche le maiuscole');
    }

    public function testUrlKeyConFormatoSbagliatoVieneRifiutato(): void
    {
        $this->assertFalse(UploadGuard::isValidUrlKey(''));
        $this->assertFalse(UploadGuard::isValidUrlKey(str_repeat('a', 31)));
        $this->assertFalse(UploadGuard::isValidUrlKey(str_repeat('a', 33)));
        $this->assertFalse(UploadGuard::isValidUrlKey(str_repeat('g', 32)), 'La g non è esadecimale');
        $this->assertFalse(UploadGuard::isValidUrlKey(str_repeat('a', 30) . '/..'));
        $this->assertFalse(UploadGuard::isValidUrlKey("aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n"));
    }
}
