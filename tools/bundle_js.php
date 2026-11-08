<?php
/**
 * Raggruppa e minifica il JavaScript dell'interfaccia.
 *
 * Prima ogni file ip-*.js era una richiesta a se': la pagina amministrativa ne
 * faceva otto (skeleton, pjax, righe cliccabili, azioni multiple, contatore,
 * riordino, righe documento, scripts) per un totale di 126 KB di sorgenti
 * non minificati. Sono tutti IIFE indipendenti, senza export: si possono
 * concatenare nell'ordine in cui il layout li caricava, e l'ordine e' anche
 * l'unica cosa che conta (ip-pjax riscrive #main-content e si aspetta che gli
 * altri moduli si aggancino a `ip:page-ready`, non a un ordine di caricamento).
 *
 * Il separatore `;` fra i file non e' cosmetico: se un domani un file finisce
 * senza punto e virgola, concatenato al successivo diventa una chiamata a
 * catena invece di due istruzioni.
 *
 * Uso: php tools/bundle_js.php
 * Richiede terser (npx): la stessa dipendenza gia' usata per scripts.min.js.
 */

$root   = dirname(__DIR__);
$jsDir  = $root . '/public/assets/core/js';

/** Ordine = ordine di caricamento nel layout (base.php / guest.php). */
$bundle = [
    'ip-skeleton.js',
    'ip-pjax.js',
    'ip-table-rows.js',
    'ip-bulk-actions.js',
    'ip-desc-counter.js',
    'ip-items-reorder.js',
    'ip-doc-items.js',
    'scripts.min.js',
];

/** File minificati singolarmente perche' devono restare bloccanti. */
$standalone = ['ip-components.js'];

function terser(string $source, string $dest): void
{
    $cmd = sprintf('npx --yes terser %s -c -m -o %s 2>&1', escapeshellarg($source), escapeshellarg($dest));
    exec($cmd, $out, $code);

    if ($code !== 0) {
        fwrite(STDERR, "terser fallito:\n" . implode("\n", $out) . "\n");
        exit(1);
    }
}

function kb(int $bytes): string
{
    return sprintf('%6.1f KB', $bytes / 1024);
}

$parts = [];
foreach ($bundle as $file) {
    $path = $jsDir . '/' . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "manca {$file}\n");
        exit(1);
    }
    $parts[] = "/* ---- {$file} ---- */\n" . file_get_contents($path);
}

$joined = "\n;\n" . implode("\n;\n", $parts);
$tmp    = tempnam(sys_get_temp_dir(), 'ipjs') . '.js';
file_put_contents($tmp, $joined);

$dest = $jsDir . '/ip-app.min.js';
terser($tmp, $dest);
unlink($tmp);

printf("bundle  %d file (%s) -> ip-app.min.js (%s), -%.0f%%\n",
    count($bundle), kb(strlen($joined)), kb((int) filesize($dest)),
    (1 - filesize($dest) / strlen($joined)) * 100);

foreach ($standalone as $file) {
    $src  = $jsDir . '/' . $file;
    $min  = $jsDir . '/' . preg_replace('/\.js$/', '.min.js', $file);
    terser($src, $min);
    printf("singolo %s (%s) -> %s (%s), -%.0f%%\n", $file, kb((int) filesize($src)),
        basename($min), kb((int) filesize($min)), (1 - filesize($min) / filesize($src)) * 100);
}
