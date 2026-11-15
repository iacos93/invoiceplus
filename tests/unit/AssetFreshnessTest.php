<?php

use CodeIgniter\Test\CIUnitTestCase;
use Sabberworm\CSS\OutputFormat;
use Sabberworm\CSS\Parser;
use Sabberworm\CSS\Settings;

/**
 * I file generati devono corrispondere alla loro sorgente.
 *
 * In produzione (CI_ENVIRONMENT = production) layout/base.php serve
 * custom.min.css: se dopo una modifica a custom.css nessuno rigenera il
 * minificato, la pagina continua a mostrare il foglio vecchio e il difetto
 * sembra non applicato. È già successo sfiorarlo: si edita la sorgente, si
 * guarda la pagina e non cambia niente perché il browser ha ricevuto il file
 * compattato di ieri.
 *
 * Qui si rigenera in memoria con lo stesso parser e le stesse impostazioni di
 * tools/minify_css.php e si confronta con il file versionato.
 *
 * @internal
 */
final class AssetFreshnessTest extends CIUnitTestCase
{
    public function testIlMinificatoDelFoglioDiStileECoerenteConLaSorgente(): void
    {
        $src = ROOTPATH . 'public/assets/core/css/custom.css';
        $min = ROOTPATH . 'public/assets/core/css/custom.min.css';

        $this->assertFileExists($src);
        $this->assertFileExists($min);

        $settings = Settings::create()
            ->withMultibyteSupport(true)
            ->withLenientParsing(true);

        $doc    = (new Parser((string) file_get_contents($src), $settings))->parse();
        $fresco = $doc->render(OutputFormat::createCompact());

        $this->assertSame(
            $fresco,
            (string) file_get_contents($min),
            'custom.min.css non corrisponde a custom.css: esegui `php tools/minify_css.php` e versiona il risultato.'
        );
    }

    /**
     * custom.css è generato dalle sorgenti in css/src: chi modifica una
     * sorgente senza rilanciare lo script (o ritocca custom.css a mano)
     * lascerebbe l'app servire un foglio diverso da quello che si legge.
     */
    public function testCustomCssIsBuiltFromSources(): void
    {
        $dir   = ROOTPATH . 'public/assets/core/css';
        $parts = \App\Libraries\CssBundle::parts($dir . '/src');

        $this->assertNotEmpty($parts, 'Mancano le sorgenti in public/assets/core/css/src.');
        $this->assertSame(
            \App\Libraries\CssBundle::join($parts),
            (string) file_get_contents($dir . '/custom.css'),
            'custom.css non corrisponde a css/src: esegui `php tools/minify_css.php` e versiona il risultato.'
        );
    }
}
