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

        return $css;
    }
}
