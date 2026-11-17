<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Carta intestata delle pagine successive alla prima.
 *
 * Quello che conta è dove compare: su ogni pagina dopo la prima, e mai sulla
 * prima, che ha già logo ed emittente nel corpo. Il testo si legge con
 * pdftotext, l'unico modo di sapere cosa c'è davvero su ciascuna pagina.
 *
 * @internal
 */
final class PdfRunningHeadTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['ip_helpers']);
    }

    public function testIlMarcatoreTornaIndietroUguale(): void
    {
        $head = ['logo' => '', 'name' => 'Novatech -- Srl', 'details' => 'P.IVA: 123 · Via Roma 1'];

        $this->assertSame($head, pdf_running_head_parse('<p>x</p>' . pdf_running_head_marker($head)));
    }

    public function testSenzaMarcatoreNienteCartaIntestata(): void
    {
        $this->assertNull(pdf_running_head_parse('<p>report</p>'));
    }

    public function testUnLogoFuoriDaiCaricamentiVieneScartato(): void
    {
        $head = pdf_running_head_parse(pdf_running_head_marker([
            'logo' => '/etc/passwd', 'name' => 'Novatech', 'details' => '',
        ]));

        $this->assertSame('', $head['logo'] ?? null);
    }

    public function testCompareDallaSecondaPaginaENonSullaPrima(): void
    {
        $pdftotext = trim((string) shell_exec('command -v pdftotext'));
        if ($pdftotext === '') {
            $this->markTestSkipped('pdftotext non disponibile');
        }

        $righe = str_repeat('<p>Riga del documento</p>', 140);
        $html  = '<html><head><style>'
            . '@page { size: A4; margin: 30mm 15mm 22mm 15mm; } @page :first { margin-top: 16mm; }'
            . '</style></head><body><h1>Prima pagina</h1>' . $righe
            . pdf_running_head_marker(['logo' => '', 'name' => 'Intestazione Prova Srl', 'details' => 'P.IVA: 01234567890'])
            . '</body></html>';

        $file = tempnam(sys_get_temp_dir(), 'ip-head-') . '.pdf';
        file_put_contents($file, pdf_render($html));

        $pagine = explode("\f", rtrim((string) shell_exec(escapeshellarg($pdftotext) . ' ' . escapeshellarg($file) . ' -'), "\f"));
        unlink($file);

        $this->assertGreaterThan(1, count($pagine), 'Il documento di prova deve avere più pagine');
        $this->assertStringNotContainsString('Intestazione Prova Srl', $pagine[0], 'La prima pagina ha già l\'intestazione nel corpo');

        foreach (array_slice($pagine, 1) as $i => $pagina) {
            $this->assertStringContainsString('Intestazione Prova Srl', $pagina, 'Pagina ' . ($i + 2) . ' senza carta intestata');
            $this->assertStringContainsString('01234567890', $pagina);
        }
    }
}
