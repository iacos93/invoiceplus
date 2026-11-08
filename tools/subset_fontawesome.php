<?php
/**
 * Rigenera public/assets/core/vendor/fontawesome6/css/app.min.css, il
 * sottoinsieme di Font Awesome con le sole icone che l'app usa davvero.
 *
 * La logica sta in App\Libraries\FontAwesomeSubset porque il test unitario la
 * interroga: li' un'icona usata e non presente nel foglio fa fallire la suite,
 * che e' l'unico modo per rendere sicuro alleggerire un vendor.
 *
 * Uso: php tools/subset_fontawesome.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Libraries\FontAwesomeSubset;

$root   = dirname(__DIR__);
$report = FontAwesomeSubset::build($root);
$dest   = $root . '/' . FontAwesomeSubset::OUTPUT_CSS;

file_put_contents($dest, $report['css']);

printf("icone usate dal codice : %d\n", count($report['used']));
printf("regole icona nel vendor: %d  ->  nel sottoinsieme: %d\n", $report['total'], $report['kept']);
printf("%s : %d bytes\n", basename(FontAwesomeSubset::VENDOR_CSS), $report['vendor_bytes']);
printf("%s  : %d bytes  (%.1f%% risparmio)\n", basename(FontAwesomeSubset::OUTPUT_CSS), $report['subset_bytes'],
    (1 - $report['subset_bytes'] / $report['vendor_bytes']) * 100);

$missing = FontAwesomeSubset::missingIcons($root);
if ($missing !== []) {
    // Non dovrebbe succedere appena generato: se succede, il filtro ha un buco.
    fwrite(STDERR, "ATTENZIONE -- icone usate e assenti nel foglio: " . implode(', ', array_keys($missing)) . "\n");
    exit(1);
}
