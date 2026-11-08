<?php

namespace Tests\Unit;

use App\Libraries\FontAwesomeSubset;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Le view non caricano piu' il Font Awesome vendor ma un sottoinsieme generato
 * dagli usi reali (tools/subset_fontawesome.php, 100 KB -> 23 KB). Il rischio
 * di quella scelta e' concentrato in un punto: un'icona citata nel codice e
 * assente dal foglio non da' errore, diventa uno spazio vuoto in pagina.
 *
 * Questo test trasforma quel caso in un guasto di suite, e copre le due strade
 * con cui puo' succedere: un'icona nuova aggiunta senza rigenerare il foglio, e
 * un filtro del generatore che si mangi regole che servono.
 */
final class FontAwesomeSubsetTest extends CIUnitTestCase
{
    public function testIlFoglioContieneOgniIconaUsataDalCodice(): void
    {
        $missing = FontAwesomeSubset::missingIcons();

        $this->assertSame([], $missing, 'Icone usate e assenti da app.min.css: '
            . implode(', ', array_keys($missing))
            . ' — rilancia `php tools/subset_fontawesome.php`.');
    }

    public function testIlSottoinsiemeRestaMoltoPiuPiccoloDelVendor(): void
    {
        $report = FontAwesomeSubset::build();

        // Se il risparmio sparisce, il filtro ha smesso di filtrare (per
        // esempio una release di Font Awesome cambia il formato delle regole
        // icona): meglio accorgersene qui che scoprirlo dalle metriche.
        $this->assertLessThan(
            $report['vendor_bytes'] * 0.5,
            $report['subset_bytes'],
            'Il sottoinsieme non sta risparmiando abbastanza: il formato delle regole icona e\' cambiato?'
        );
        $this->assertGreaterThan(0, $report['kept'], 'Nessuna regola icona nel sottoinsieme.');
    }

    public function testLeViewCaricanoIlSottoinsiemeENonIlVendor(): void
    {
        $layouts = glob(APPPATH . 'Views/{layout,welcome}/*.php', GLOB_BRACE) ?: [];
        $this->assertNotEmpty($layouts, 'Nessun layout trovato: il test sta guardando la cartella sbagliata.');

        foreach ($layouts as $layout) {
            $php = (string) file_get_contents($layout);

            $this->assertStringNotContainsString('v4-shims', $php, basename($layout) . ' carica ancora v4-shims.css.');
            if (str_contains($php, 'fontawesome6')) {
                $this->assertStringContainsString('fontawesome6/css/app.min.css', $php,
                    basename($layout) . ' cita fontawesome6 ma non il sottoinsieme app.min.css.');
                $this->assertStringNotContainsString('fontawesome6/css/all.min.css', $php,
                    basename($layout) . ' carica ancora il foglio vendor all.min.css.');
            }
        }
    }

    public function testIlCommentoDiLicenzaRestaInTesta(): void
    {
        // Font Awesome Free si puo' ridistribuire solo tenendo la nota di
        // licenza: il generatore la lascia come primo commento del foglio.
        $css = (string) file_get_contents(FontAwesomeSubset::basePath() . '/' . FontAwesomeSubset::OUTPUT_CSS);

        $this->assertStringStartsWith('/*!', $css);
        $this->assertStringContainsString('Font Awesome Free', $css);
    }
}
