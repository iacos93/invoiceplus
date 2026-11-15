<?php
/**
 * Rigenera i font delle icone con i soli glifi che l'app disegna — solido,
 * regular e marchi — insieme ai manifesti che li descrivono, in
 * public/assets/core/vendor/fontawesome6/webfonts/.
 *
 * Richiede pyftsubset (fonttools): `pip install fonttools brotli`. Non è una
 * dipendenza dell'applicazione — i file generati sono versionati e l'app non li
 * rigenera a runtime — ma serve a chi aggiunge un'icona.
 *
 * Uso: php tools/subset_icons.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Libraries\IconFontSubset;

$root = dirname(__DIR__);

try {
    $report = IconFontSubset::build($root, static function (string $line): void {
        echo $line . "\n";
    });
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

$prima = 0;
$dopo  = 0;

foreach ($report as $family => $dati) {
    $prima += $dati['vendor_bytes'];
    $dopo  += $dati['subset_bytes'];
}

printf("totale    %7d -> %6d byte  (%.1f%% risparmio)\n", $prima, $dopo, (1 - $dopo / $prima) * 100);

$missing = IconFontSubset::missingIcons($root);
if ($missing !== []) {
    // Non dovrebbe succedere appena generato: se succede, il foglio ha icone
    // senza code point e nessuno dei font può contenerle.
    fwrite(STDERR, "ATTENZIONE -- icone usate senza glifo in nessun font: " . implode(', ', array_keys($missing)) . "\n");
    exit(1);
}

echo "Il foglio servito carica i font ridotti: non serve altro.\n";
