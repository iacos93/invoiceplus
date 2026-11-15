<?php

namespace App\Libraries;

/**
 * Unione delle sorgenti CSS (public/assets/core/css/src) in custom.css.
 *
 * Sta in una classe, e non dentro tools/minify_css.php, perché la stessa
 * regola serve a due chiamanti: lo script che genera il file e
 * AssetFreshnessTest che verifica che il file versionato sia quello che lo
 * script produrrebbe. Due copie della regola potrebbero divergere, e il test
 * smetterebbe di controllare ciò che la build fa davvero.
 */
class CssBundle
{
    /**
     * Avviso in testa al file generato. È un commento: il minificatore lo
     * scarta, quindi non cambia custom.min.css.
     */
    public const BANNER = "/* FILE GENERATO: non modificarlo. Le sorgenti sono in css/src/*.css;\n"
        . "   dopo una modifica eseguire `php tools/minify_css.php`. */\n";

    /**
     * I file sorgente, in ordine di nome: l'ordine è la cascata.
     *
     * @return list<string>
     */
    public static function parts(string $dir): array
    {
        $files = glob(rtrim($dir, '/') . '/*.css') ?: [];
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @param list<string> $files
     */
    public static function join(array $files): string
    {
        $css = self::BANNER;

        foreach ($files as $file) {
            $css .= (string) file_get_contents($file);
        }

        // I font si richiamano per percorso relativo, senza il ?v= che gli
        // helper degli asset aggiungono alle pagine, e hanno la cache di un
        // anno (public/.htaccess): sostituendo un file, chi aveva quello vecchio
        // lo terrebbe. L'impronta del file nell'indirizzo lo rende nuovo.
        $fonts = $files === [] ? '' : dirname($files[0], 3) . '/fonts';

        return preg_replace_callback(
            '#url\("\.\./fonts/([A-Za-z0-9._-]+)"\)#',
            static function (array $m) use ($fonts): string {
                $path = $fonts . '/' . $m[1];

                return is_file($path)
                    ? 'url("../fonts/' . $m[1] . '?v=' . substr((string) md5_file($path), 0, 8) . '")'
                    : $m[0];
            },
            $css
        ) ?? $css;
    }
}
