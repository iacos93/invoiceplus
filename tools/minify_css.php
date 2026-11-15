<?php
/**
 * Costruisce il foglio di stile dell'app.
 *
 * Le sorgenti sono i file di public/assets/core/css/src/, uno per area
 * (token, guscio, liste, documenti...). Lo script li unisce in ordine di nome
 * in custom.css e ne ricava custom.min.css con sabberworm/php-css-parser
 * (gia' in vendor/).
 *
 * Perche' a pezzi: custom.css era un file solo da quasi 14.000 righe, e una
 * sostituzione sbagliata ne ha cancellata meta' in un colpo. File da un
 * migliaio di righe si leggono, si confrontano e si rovinano uno alla volta.
 *
 * Perche' in ordine di nome e non per componente: il foglio e' cresciuto per
 * strati e le regole piu' in basso correggono quelle piu' in alto. L'ordine
 * dei file E' la cascata: rinominarli o spostare una regola da un file
 * all'altro puo' cambiare cio' che vince. Il prefisso numerico lo fissa.
 *
 * custom.css resta nel repository perche' le viste lo servono cosi' com'e'
 * fuori produzione, ma e' un file generato: si modifica src/ e si rilancia
 * lo script. AssetFreshnessTest fallisce se i tre non coincidono.
 *
 * Uso: php tools/minify_css.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Sabberworm\CSS\Parser;
use Sabberworm\CSS\Settings;
use Sabberworm\CSS\OutputFormat;

$dir  = __DIR__ . '/../public/assets/core/css';
$src  = $dir . '/custom.css';
$dest = $dir . '/custom.min.css';

require __DIR__ . '/../app/Libraries/CssBundle.php';

$parts = App\Libraries\CssBundle::parts($dir . '/src');
file_put_contents($src, App\Libraries\CssBundle::join($parts));
printf("sorgenti   : %d file in css/src\n", count($parts));

$settings = Settings::create()
    ->withMultibyteSupport(true)
    ->withLenientParsing(true);

$parser = new Parser(file_get_contents($src), $settings);
$doc    = $parser->parse();

$format = OutputFormat::createCompact();
$out    = $doc->render($format);

file_put_contents($dest, $out);

$before = filesize($src);
$after  = strlen($out);
printf("custom.css : %d bytes\n", $before);
printf("custom.min : %d bytes  (%.1f%% risparmio)\n", $after, (1 - $after / $before) * 100);
