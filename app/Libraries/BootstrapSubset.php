<?php

namespace App\Libraries;

/**
 * Sottoinsieme di Bootstrap costruito sulle classi che l'app usa davvero.
 *
 * `bootstrap.min.css` sono 227 KB, quasi tutti componenti che questa
 * applicazione non ha mai montato (carousel, spinners, progress, toasts,
 * accordion, offcanvas…) e utility che nessuna view scrive. Il browser li
 * scarica e li analizza a ogni primo caricamento.
 *
 * Il filtro lavora sul foglio vendor senza modificarlo: una regola resta se
 * **tutte** le classi del suo selettore compaiono nei sorgenti. Le classi si
 * cercano come farebbe PurgeCSS — nei letterali delle stringhe di `app/`, del
 * JS dell'interfaccia e del foglio dell'app — più il bundle di Bootstrap, che è
 * dove vivono le classi che i componenti aggiungono a runtime (`show`,
 * `collapse`, `modal-backdrop`, `tooltip-inner`…): cercarle solo nelle view le
 * farebbe sparire al primo click.
 *
 * Il rischio di un filtro così è il silenzio: una classe costruita a runtime
 * (`'bg-' . $stato`) non compare come parola intera da nessuna parte, la regola
 * sparisce e la pagina si apre senza quello stile, senza un errore. Due reti lo
 * tengono: i prefissi (un letterale che finisce con `-`, come `text-bg-` o
 * `bg-`, tiene tutta la famiglia) e `missingClasses()`, che il test unitario
 * interroga: ogni classe scritta in un attributo `class=` deve esistere nel
 * foglio servito, altrimenti la suite fallisce e dice di rigenerare.
 */
final class BootstrapSubset
{
    /** Sorgente vendor, mai modificata (resta il riferimento di licenza). */
    public const VENDOR_CSS = 'public/assets/core/vendor/bootstrap.min.css';

    /** Foglio generato, quello che le view caricano. */
    public const OUTPUT_CSS = 'public/assets/core/vendor/bootstrap.subset.min.css';

    /**
     * Dove cercare le classi: `app` copre view, controller e librerie (ci sono
     * elenchi di classi costruiti in PHP), `js` i componenti che creano markup.
     */
    private const SCAN_PATHS = ['app', 'public/assets/core/js'];

    /**
     * Il bundle di Bootstrap: le classi che i suoi componenti aggiungono da soli
     * non compaiono in nessuna view.
     */
    public const RUNTIME_JS = 'public/assets/core/vendor/bootstrap.bundle.min.js';

    /** Estensioni che contengono markup o stringhe con classi. */
    private const SCAN_EXTENSIONS = ['php', 'js', 'html'];

    /** At-rule che contengono altre regole: si filtrano dentro, non si buttano. */
    private const NESTED_RULES = ['@media', '@supports', '@container', '@layer'];

    /**
     * Classi che Bootstrap compone a runtime e che quindi non esistono, intere,
     * in nessun sorgente: il posizionamento dei tooltip è `bs-` più il tipo più
     * la posizione (`bs-tooltip-top`, `bs-popover-end`), costruito con un
     * template. Il prefisso `bs-` è riservato a questo: tenerlo tutto costa una
     * manciata di byte e toglie di mezzo una classe di rotture silenziose.
     */
    private const SAFELIST_PREFIXES = ['bs-'];

    public static function basePath(?string $root = null): string
    {
        if ($root !== null) {
            return rtrim($root, '/');
        }

        return defined('ROOTPATH') ? rtrim(ROOTPATH, '/') : dirname(__DIR__, 2);
    }

    /**
     * Costruisce il sottoinsieme e riporta cosa ha tenuto.
     *
     * @return array{css: string, used: array<string, true>, prefixes: array<string, true>, total: int, kept: int, vendor_bytes: int, subset_bytes: int}
     */
    public static function build(?string $root = null): array
    {
        $root = self::basePath($root);

        [$used, $prefixes] = self::usedClasses($root);
        $vendor            = (string) file_get_contents($root . '/' . self::VENDOR_CSS);

        [$css, $total, $kept] = self::filter($vendor, $used, $prefixes);

        return [
            'css'          => $css,
            'used'         => $used,
            'prefixes'     => $prefixes,
            'total'        => $total,
            'kept'         => $kept,
            'vendor_bytes' => strlen($vendor),
            'subset_bytes' => strlen($css),
        ];
    }

    /**
     * Classi e prefissi presenti nei sorgenti.
     *
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    public static function usedClasses(?string $root = null): array
    {
        $root   = self::basePath($root);
        $used   = [];
        $prefix = [];

        foreach (self::SCAN_PATHS as $path) {
            foreach (self::files($root . '/' . $path) as $file) {
                self::collect((string) file_get_contents($file), $used, $prefix);
            }
        }

        // Anche il foglio dell'app: qua e là estende selettori di Bootstrap.
        foreach (glob($root . '/public/assets/core/css/src/*.css') ?: [] as $file) {
            self::collect((string) file_get_contents($file), $used, $prefix);
        }

        if (is_file($root . '/' . self::RUNTIME_JS)) {
            self::collect((string) file_get_contents($root . '/' . self::RUNTIME_JS), $used, $prefix);
        }

        ksort($used);
        ksort($prefix);

        return [$used, $prefix];
    }

    /**
     * Classi scritte in un attributo `class=` e assenti dal foglio servito.
     *
     * È l'asserzione del test: le classi costruite a runtime restano fuori da
     * questo controllo (non sono letterali), ma tutto quello che si scrive a
     * mano in una view deve esserci — altrimenti il sottoinsieme ha tagliato
     * qualcosa che serviva e va rigenerato.
     *
     * @return array<string, list<string>> classe => file che la usano
     */
    public static function missingClasses(?string $root = null): array
    {
        $root = self::basePath($root);

        $subset = (string) file_get_contents($root . '/' . self::OUTPUT_CSS);
        $vendor = (string) file_get_contents($root . '/' . self::VENDOR_CSS);

        $missing = [];

        foreach (self::files($root . '/app') as $file) {
            $source = (string) file_get_contents($file);

            preg_match_all('/class="([^"]*)"/', $source, $matches);

            foreach ($matches[1] as $attribute) {
                foreach (preg_split('/\s+/', $attribute) ?: [] as $classe) {
                    // Nelle view l'attributo contiene anche PHP: si guardano solo
                    // le parole che sono davvero nomi di classe.
                    if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $classe) !== 1) {
                        continue;
                    }

                    // Se Bootstrap non conosce la classe (le nostre `ip-*`) non è
                    // compito di questo foglio fornirla.
                    if (preg_match('/\.' . preg_quote($classe, '/') . '(?![\w-])/', $vendor) !== 1) {
                        continue;
                    }

                    // E se il vendor la nomina solo come discendente di un
                    // altro selettore (`.nav-fill .nav-item`) non c'è una
                    // regola propria da cercare: `nav-item` da solo non ha
                    // stile in Bootstrap 5, e pretenderlo segnalerebbe un
                    // taglio che non c'è.
                    if (preg_match('/(^|[,{}])\.' . preg_quote($classe, '/') . '(?![\w-])/', $vendor) !== 1) {
                        continue;
                    }

                    if (preg_match('/\.' . preg_quote($classe, '/') . '(?![\w-])/', $subset) !== 1) {
                        $missing[$classe][] = self::relative($file, $root);
                    }
                }
            }
        }

        foreach ($missing as $classe => $files) {
            $missing[$classe] = array_values(array_unique($files));
        }

        ksort($missing);

        return $missing;
    }

    /**
     * Filtra il foglio.
     *
     * @param array<string, true> $used
     * @param array<string, true> $prefixes
     *
     * @return array{0: string, 1: int, 2: int} css, regole lette, regole tenute
     */
    public static function filter(string $css, array $used, array $prefixes): array
    {
        // L'intestazione di licenza davanti alla prima regola va conservata: è
        // il riferimento alla licenza MIT di Bootstrap, e il foglio resta
        // ridistribuito.
        $start  = strpos($css, '{');
        $header = '';
        $body   = $css;

        if ($start !== false) {
            $cut    = strrpos(substr($css, 0, $start), '*/');
            $header = $cut === false ? '' : substr($css, 0, $cut + 2);
            $body   = substr($css, strlen($header));
        }

        [$out, $total, $kept] = self::filterBlock($body, $used, $prefixes);

        return [$header . $out, $total, $kept];
    }

    /**
     * @param array<string, true> $used
     * @param array<string, true> $prefixes
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private static function filterBlock(string $css, array $used, array $prefixes): array
    {
        $out   = '';
        $total = 0;
        $kept  = 0;
        $i     = 0;
        $len   = strlen($css);

        while ($i < $len) {
            $open = strpos($css, '{', $i);

            if ($open === false) {
                $out .= substr($css, $i);

                break;
            }

            $prelude = substr($css, $i, $open - $i);
            $close   = self::matchingBrace($css, $open);

            if ($close === null) {
                $out .= substr($css, $i);

                break;
            }

            $inner = substr($css, $open + 1, $close - $open - 1);
            $head  = trim($prelude);

            if (str_starts_with($head, '@')) {
                $name = strtolower(strtok($head, ' ') ?: $head);

                if (in_array($name, self::NESTED_RULES, true)) {
                    [$nested, $n, $k] = self::filterBlock($inner, $used, $prefixes);
                    $total += $n;
                    $kept += $k;

                    if (trim($nested) !== '') {
                        $out .= $prelude . '{' . $nested . '}';
                    }
                } else {
                    // @font-face, @keyframes, @charset: si tengono interi.
                    $out .= $prelude . '{' . $inner . '}';
                }
            } else {
                $total++;

                $selectors = self::keepSelectors($prelude, $used, $prefixes);

                if ($selectors !== '') {
                    $kept++;
                    $out .= $selectors . '{' . $inner . '}';
                }
            }

            $i = $close + 1;
        }

        return [$out, $total, $kept];
    }

    /**
     * Il prelude della regola senza i selettori le cui classi non servono.
     *
     * Un selettore senza classi (elementi, attributi, `:root`) resta: costa
     * poco e non è la parte che pesa.
     *
     * @param array<string, true> $used
     * @param array<string, true> $prefixes
     */
    private static function keepSelectors(string $prelude, array $used, array $prefixes): string
    {
        $parts = preg_split('/\s*,\s*/', trim($prelude)) ?: [];
        $kept  = [];

        foreach ($parts as $selector) {
            if (self::selectorUsed($selector, $used, $prefixes)) {
                $kept[] = $selector;
            }
        }

        return implode(',', $kept);
    }

    /**
     * @param array<string, true> $used
     * @param array<string, true> $prefixes
     */
    private static function selectorUsed(string $selector, array $used, array $prefixes): bool
    {
        preg_match_all('/\.(-?[_a-zA-Z][_a-zA-Z0-9-]*)/', $selector, $matches);

        foreach ($matches[1] as $classe) {
            if (isset($used[$classe])) {
                continue;
            }

            // `'bg-' . $stato` nei sorgenti: il letterale finisce con '-', e
            // tutta la famiglia può essere quella costruita a runtime.
            $dynamic = false;

            foreach ([...array_keys($prefixes), ...self::SAFELIST_PREFIXES] as $prefix) {
                if (str_starts_with($classe, $prefix)) {
                    $dynamic = true;

                    break;
                }
            }

            if (!$dynamic) {
                return false;
            }
        }

        return true;
    }

    /**
     * Indice della '}' che chiude la '{' a $open, saltando stringhe e parentesi.
     */
    private static function matchingBrace(string $css, int $open): ?int
    {
        $depth = 0;
        $len   = strlen($css);
        $quote = null;

        for ($i = $open; $i < $len; $i++) {
            $char = $css[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * Raccoglie classi e prefissi dalle stringhe di un sorgente.
     *
     * @param array<string, true> $used
     * @param array<string, true> $prefixes
     */
    private static function collect(string $source, array &$used, array &$prefixes): void
    {
        // Solo i letterali: il codice e i commenti contengono parole che non sono
        // classi, e allargare la ricerca lì dentro terrebbe mezzo foglio.
        foreach (self::literals($source) as $literal) {
            // Classi separate da spazio, e i nomi composti come `btn-primary`
            // restano interi.
            foreach (preg_split('/[\s;,(){}<>=+\[\]]+/', $literal) ?: [] as $token) {
                $token = trim($token, " \t\r\n\0\x0B\"'`");

                if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $token) !== 1) {
                    continue;
                }

                $used[$token] = true;
            }

            // Prefissi: `'text-bg-' . $x` tiene text-bg-primary, text-bg-danger…
            if (preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*-$/', $literal) === 1) {
                $prefixes[$literal] = true;
            }
        }
    }

    /**
     * I contenuti delle stringhe fra apici semplici o doppi, in ordine.
     *
     * La scansione è lineare e non un'espressione regolare: la forma ovvia —
     * `"([^"\\]*(?:\\.[^"\\]*)*)"` — su un file da 40 KB esaurisce il limite di
     * backtracking, `preg_match_all()` restituisce false e le classi di quella
     * view spariscono senza un errore. È successo al primo giro: la classe
     * mancante era `alert-dismissible`, in una pagina che la scriveva a mano.
     *
     * Una stringa non attraversa il ritorno a capo (in PHP e in HTML non
     * succede) e fermarsi lì evita che un apice spaiato mangi il resto del file.
     *
     * @return list<string>
     */
    private static function literals(string $source): array
    {
        $literals = [];
        $len      = strlen($source);

        for ($i = 0; $i < $len; $i++) {
            $char = $source[$i];

            if ($char !== '"' && $char !== "'") {
                continue;
            }

            $quote  = $char;
            $buffer = '';

            for ($i++; $i < $len; $i++) {
                $current = $source[$i];

                if ($current === '\\') {
                    $buffer .= $source[$i + 1] ?? '';
                    $i++;

                    continue;
                }

                if ($current === $quote || $current === "\n") {
                    break;
                }

                $buffer .= $current;
            }

            $literals[] = $buffer;
        }

        return $literals;
    }

    /**
     * @return list<string>
     */
    private static function files(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            // I file già minificati sono copie di sorgenti che leggiamo:
            // leggerli allungherebbe il giro senza aggiungere classi.
            if (str_ends_with($file->getFilename(), '.min.js')) {
                continue;
            }

            if (in_array($file->getExtension(), self::SCAN_EXTENSIONS, true)) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function relative(string $file, string $root): string
    {
        return ltrim(str_replace($root, '', $file), '/');
    }
}
