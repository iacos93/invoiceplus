<?php

namespace App\Libraries;

/**
 * Sottoinsieme di Font Awesome 6 costruito sugli usi reali dell'app.
 *
 * all.min.css e' 100 KB, di cui 86 KB sono le 1.881 regole `.fa-x:before`
 * (2.477 nomi, alias compresi). L'app ne usa 145: il resto e' peso che ogni
 * pagina paga al primo caricamento e che il browser deve comunque analizzare
 * per costruire il CSSOM.
 *
 * Il sottoinsieme non e' pero' un file da tenere allineato a mano: chi domani
 * scrive `fa-truck-fast` in una view non deve ricordarsi di rigenerarlo, e
 * soprattutto non deve vedere l'icona sparire in silenzio. Per questo la
 * generazione vive qui (una classe senza dipendenze, richiamabile dal test) e
 * `missingIcons()` diventa l'asserzione: se il foglio non contiene un nome che
 * le view usano, il test unitario fallisce e dice di rilanciare lo script.
 *
 * Le regole vengono tenute intere, alias inclusi: `.fa-cog:before,.fa-gear:before`
 * e' un solo gruppo con lo stesso code point, e spezzarlo per tenere solo il
 * nome cercato renderebbe il foglio diverso da come Font Awesome lo pubblica.
 * Se domani il codice passa da `fa-cog` a `fa-gear`, l'icona c'e' gia'.
 */
final class FontAwesomeSubset
{
    /** Sorgente vendor, mai modificata (e' anche il riferimento di licenza). */
    public const VENDOR_CSS = 'public/assets/core/vendor/fontawesome6/css/all.min.css';

    /** Foglio generato, quello che le view caricano. */
    public const OUTPUT_CSS = 'public/assets/core/vendor/fontawesome6/css/app.min.css';

    /**
     * Dove cercare le icone. `app` copre view, controller e librerie (ci sono
     * elenchi di icone costruiti in PHP), `js` i componenti che creano markup.
     */
    private const SCAN_PATHS = ['app', 'public/assets/core/js'];

    /**
     * Classi che hanno il prefisso `fa-` ma non sono icone di Font Awesome:
     * il prefisso e' una convenzione del foglio di stile dell'app, non del
     * font. Vanno elencate qui, altrimenti la verifica le segnala come icone
     * mancanti a ogni giro.
     */
    private const NOT_ICONS = [
        'fa-margin', // spaziatore delle icone dentro i bottoni (custom.css)
    ];

    public static function basePath(?string $root = null): string
    {
        if ($root !== null) {
            return rtrim($root, '/');
        }

        // ROOTPATH e' definito da CI4 (index.php o tests/bootstrap.php); il
        // fallback serve quando la classe viene usata dallo script CLI.
        return defined('ROOTPATH') ? rtrim(ROOTPATH, '/') : dirname(__DIR__, 2);
    }

    /**
     * Icone citate dal codice, con il numero di occorrenze (ordine decrescente).
     *
     * @return array<string,int>
     */
    public static function usedIcons(?string $root = null): array
    {
        $base  = self::basePath($root);
        $icons = [];

        foreach (self::SCAN_PATHS as $relative) {
            $dir = $base . '/' . $relative;
            if (!is_dir($dir)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                if (!in_array(strtolower($file->getExtension()), ['php', 'js'], true)) {
                    continue;
                }

                foreach (self::iconsIn((string) file_get_contents($file->getPathname())) as $name) {
                    if (in_array($name, self::NOT_ICONS, true)) {
                        continue;
                    }

                    $icons[$name] = ($icons[$name] ?? 0) + 1;
                }
            }
        }

        arsort($icons);

        return $icons;
    }

    /**
     * Nomi `fa-<icona>` contenuti in un testo.
     *
     * La ricerca e' volutamente larga (tutto il file, non solo gli attributi
     * class): in giro ci sono elenchi di icone costruiti come array PHP e
     * stringhe concatenate in JS, e un falso positivo costa qualche riga di
     * CSS, mentre un falso negativo e' un'icona invisibile in produzione.
     *
     * @return list<string>
     */
    public static function iconsIn(string $text): array
    {
        preg_match_all('/\bfa-[a-z0-9-]+\b/i', $text, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * Comporre il sottoinsieme: si tengono tutte le regole di all.min.css
     * tranne quelle che assegnano soltanto un code point a un'icona non usata.
     *
     * @param list<string> $usedIcons
     */
    public static function subsetCss(string $vendorCss, array $usedIcons, string $notice = ''): string
    {
        $keep = array_fill_keys($usedIcons, true);

        // Le regole icona sono esattamente `<selectors>{content:"\fXXX"}`:
        // niente annidamenti (`@media`, `:is()`), quindi si possono togliere a
        // livello testuale senza un parser CSS.
        $pattern = '/(?:\.fa-[a-z0-9-]+:before,)*\.fa-[a-z0-9-]+:before\{content:"\\\\[0-9a-f]+"\}/';

        $css = preg_replace_callback($pattern, static function (array $match) use ($keep): string {
            preg_match_all('/\.fa-([a-z0-9-]+):before/', $match[0], $names);

            foreach ($names[1] as $name) {
                if (isset($keep['fa-' . $name])) {
                    return $match[0];
                }
            }

            return '';
        }, $vendorCss);

        if ($css === null) {
            throw new \RuntimeException('Sottoinsieme Font Awesome: filtro CSS non riuscito.');
        }

        if ($notice === '') {
            return $css;
        }

        // La nota va dopo il commento di licenza (che deve restare in testa),
        // non prima: e' l'unico punto che i tool di Font Awesome cercano.
        $licenseEnd = strpos($css, '*/');

        return $licenseEnd === false
            ? $notice . "\n" . $css
            : substr($css, 0, $licenseEnd + 2) . "\n" . $notice . substr($css, $licenseEnd + 2);
    }

    /**
     * Genera il foglio e restituisce il resoconto per lo script CLI.
     *
     * @return array{css:string,used:array<string,int>,vendor_bytes:int,subset_bytes:int,kept:int,total:int}
     */
    public static function build(?string $root = null): array
    {
        $base   = self::basePath($root);
        $vendor = @file_get_contents($base . '/' . self::VENDOR_CSS);

        if ($vendor === false) {
            throw new \RuntimeException('Font Awesome non trovato: ' . self::VENDOR_CSS);
        }

        $used = self::usedIcons($base);

        $notice = '/* Generato da tools/subset_fontawesome.php sugli usi reali dell\'app:'
            . ' non modificare a mano, rilancia lo script. */';

        $css = self::subsetCss($vendor, array_keys($used), $notice);

        return [
            'css'          => $css,
            'used'         => $used,
            'vendor_bytes' => strlen($vendor),
            'subset_bytes' => strlen($css),
            'kept'         => preg_match_all('/\.fa-[a-z0-9-]+:before\{content:/', $css),
            'total'        => preg_match_all('/\.fa-[a-z0-9-]+:before\{content:/', $vendor),
        ];
    }

    /**
     * Icone usate dal codice ma assenti dal foglio generato: vuoto quando il
     * sottoinsieme e' aggiornato. E' l'unica cosa che rende sicuro alleggerire
     * il foglio vendor.
     *
     * @return array<string,int>
     */
    public static function missingIcons(?string $root = null): array
    {
        $base   = self::basePath($root);
        $subset = @file_get_contents($base . '/' . self::OUTPUT_CSS);

        if ($subset === false) {
            return self::usedIcons($base);
        }

        $missing = [];

        foreach (self::usedIcons($base) as $name => $count) {
            if (preg_match('/\.' . preg_quote($name, '/') . '(?![a-z0-9-])/i', $subset) !== 1) {
                $missing[$name] = $count;
            }
        }

        return $missing;
    }
}
