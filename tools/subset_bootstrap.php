<?php
/**
 * Rigenera public/assets/core/vendor/bootstrap.subset.min.css, il foglio
 * Bootstrap ridotto alle classi che l'app usa davvero.
 *
 * La logica sta in App\Libraries\BootstrapSubset perché il test unitario la
 * interroga: lì una classe scritta in una view e assente dal foglio fa fallire
 * la suite, che è l'unico modo per rendere sicuro alleggerire un vendor.
 *
 * Uso: php tools/subset_bootstrap.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Libraries\BootstrapSubset;

$root   = dirname(__DIR__);
$report = BootstrapSubset::build($root);
$dest   = $root . '/' . BootstrapSubset::OUTPUT_CSS;

file_put_contents($dest, $report['css']);

printf("classi usate dal codice : %d\n", count($report['used']));
printf("prefissi dinamici       : %d%s\n", count($report['prefixes']),
    $report['prefixes'] === [] ? '' : ' (' . implode(' ', array_keys($report['prefixes'])) . ')');
printf("regole nel vendor       : %d  ->  nel sottoinsieme: %d\n", $report['total'], $report['kept']);
printf("%s : %d bytes\n", basename(BootstrapSubset::VENDOR_CSS), $report['vendor_bytes']);
printf("%s : %d bytes  (%.1f%% risparmio)\n", basename(BootstrapSubset::OUTPUT_CSS), $report['subset_bytes'],
    (1 - $report['subset_bytes'] / $report['vendor_bytes']) * 100);

$missing = BootstrapSubset::missingClasses($root);
if ($missing !== []) {
    // Non dovrebbe succedere appena generato: se succede, il filtro ha un buco.
    fwrite(STDERR, "ATTENZIONE -- classi usate e assenti nel foglio:\n");
    foreach ($missing as $classe => $files) {
        fwrite(STDERR, '  ' . $classe . ' => ' . implode(', ', $files) . "\n");
    }
    exit(1);
}
