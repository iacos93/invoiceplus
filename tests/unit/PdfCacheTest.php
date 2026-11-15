<?php

namespace Tests\Unit;

use App\Libraries\PdfCache;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Cache dei PDF stampati.
 *
 * La chiave è l'HTML: la prova che conta è che lo stesso documento non venga
 * ristampato e che uno diverso non riusi il vecchio file, perché un PDF
 * fiscale vecchio servito al posto di quello aggiornato è un danno.
 *
 * @internal
 */
final class PdfCacheTest extends CIUnitTestCase
{
    private const PDF = "%PDF-1.7\ncontenuto finto\n%%EOF";

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/ip-pdfcache-' . uniqid('', true);

        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->dir);

        parent::tearDown();
    }

    public function testLoStessoHtmlVieneStampatoUnaVoltaSola(): void
    {
        $cache = new PdfCache($this->dir);
        $stampe = 0;

        $render = static function (string $html) use (&$stampe): string {
            $stampe++;

            return self::PDF . $html;
        };

        $primo  = $cache->remember('<html>fattura 1</html>', $render);
        $secondo = $cache->remember('<html>fattura 1</html>', $render);

        $this->assertSame(1, $stampe);
        $this->assertSame($primo, $secondo);
        $this->assertCount(1, glob($this->dir . '/*.pdf') ?: []);
    }

    public function testUnDocumentoDiversoHaIlSuoFile(): void
    {
        $cache  = new PdfCache($this->dir);
        $stampe = 0;

        $render = static function (string $html) use (&$stampe): string {
            $stampe++;

            return self::PDF . $html;
        };

        $primo = $cache->remember('<html>fattura 1</html>', $render);
        $altro = $cache->remember('<html>fattura 1, riga cambiata</html>', $render);

        $this->assertSame(2, $stampe);
        $this->assertNotSame($primo, $altro);
        $this->assertCount(2, glob($this->dir . '/*.pdf') ?: []);
    }

    public function testICommentiDiDebugDelleVisteNonCambianoLaChiave(): void
    {
        $cache = new PdfCache($this->dir);

        // In sviluppo CodeIgniter numera le viste con un commento che cambia a
        // ogni composizione: lo stesso documento deve restare lo stesso file.
        $primo = "<!-- DEBUG-VIEW START 4 APPPATH/Views/invoice_templates/default.pdf.php -->\n<html>fattura 4</html>";
        $secondo = "<!-- DEBUG-VIEW START 9 APPPATH/Views/invoice_templates/default.pdf.php -->\n<html>fattura 4</html>";

        $this->assertSame($cache->file($primo), $cache->file($secondo));
    }

    public function testUnFileRottoNonVieneServito(): void
    {
        $cache = new PdfCache($this->dir);
        $html  = '<html>fattura 2</html>';

        // Disco pieno a metà scrittura: il file c'è ma non è un PDF.
        file_put_contents($cache->file($html), '%PDF-1.7 tronca');

        $this->assertNull($cache->get($html));

        $riesumato = $cache->remember($html, static fn (string $h): string => self::PDF . $h);

        $this->assertStringStartsWith('%PDF', $riesumato);
        $this->assertSame($riesumato, $cache->get($html));
    }

    public function testLaCartellaNonCresceSenzaLimite(): void
    {
        $cache = new PdfCache($this->dir);

        for ($i = 1; $i <= 5; $i++) {
            $cache->put('<html>documento ' . $i . '</html>', self::PDF);
            // mtime distinto anche su file system con timestamp grossolani.
            touch($cache->file('<html>documento ' . $i . '</html>'), time() - (10 - $i) * 60);
        }

        $this->assertSame(2, $cache->prune(3));
        $this->assertCount(3, glob($this->dir . '/*.pdf') ?: []);

        // Restano i tre più recenti.
        $this->assertFileExists($cache->file('<html>documento 5</html>'));
        $this->assertFileDoesNotExist($cache->file('<html>documento 1</html>'));
    }

    public function testSenzaCartellaScrivibileIlPdfEsceLoStesso(): void
    {
        // Un file al posto della cartella: mkdir() non può riuscire e la cache
        // deve solo rinunciare, non impedire la stampa.
        file_put_contents($this->dir . '/blocco', 'x');

        $cache = new PdfCache($this->dir . '/blocco');

        $pdf = $cache->remember('<html>fattura 3</html>', static fn (string $h): string => self::PDF . $h);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertNull($cache->get('<html>fattura 3</html>'));
    }
}
