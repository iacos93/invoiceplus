<?php
/**
 * Minifica public/assets/core/css/custom.css in custom.min.css usando
 * sabberworm/php-css-parser (gia' in vendor/). Specchio di scripts.min.js:
 * la sorgente resta custom.css (commenti "perche'" inclusi) e in produzione
 * si serve la versione compattata, senza commenti ne' spazi superflui.
 *
 * Uso: php tools/minify_css.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Sabberworm\CSS\Parser;
use Sabberworm\CSS\Settings;
use Sabberworm\CSS\OutputFormat;

$src  = __DIR__ . '/../public/assets/core/css/custom.css';
$dest = __DIR__ . '/../public/assets/core/css/custom.min.css';

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
