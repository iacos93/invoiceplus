<?php

namespace App\Libraries;

/**
 * I file dei font delle icone ridotti ai soli glifi che l'app disegna.
 *
 * Il sottoinsieme CSS di Font Awesome (tools/subset_fontawesome.php) toglie le
 * regole delle icone non usate, ma i font restano interi: `fa-solid-900.woff2`
 * sono 156 KB per ~145 glifi, `fa-brands-400.woff2` altri 118 KB per tre
 * marchi. Sono i file più pesanti serviti all'app dopo il foglio di stile, e
 * un woff2 non si può ritagliare a mano: serve `pyftsubset` (fonttools), che
 * ricostruisce il font tenendo i soli code point richiesti.
 *
 * Si ritagliano tutte e tre le famiglie che l'app disegna, non solo la solida:
 * la somma dei code point in uso va a ogni font, che tiene quelli che ha —
 * pyftsubset ignora gli altri invece di protestare. Così nessuna icona può
 * sparire per una famiglia dimenticata, e la verifica finale è una sola: ogni
 * icona in uso deve stare in almeno uno dei manifesti.
 *
 * Il passo dipende da uno strumento esterno, quindi i file generati sono
 * **versionati**: chi installa l'app non ha bisogno di Python, e la CI (che non
 * ha fonttools) verifica il risultato committato invece di rigenerarlo. Chi
 * aggiunge un'icona deve rilanciare `php tools/subset_icons.php`, e se non ha
 * pyftsubset lo script lo dice invece di produrre un font monco.
 *
 * Accanto a ogni font resta un manifesto con i code point che il file contiene
 * davvero (letti dal `cmap` del risultato) e l'impronta del file: è quello che
 * il test confronta con gli usi reali, così un'icona nuova senza rigenerazione
 * fa fallire la suite invece di sparire dalla pagina.
 */
final class IconFontSubset
{
    /** Cartella dei font, comune alle famiglie. */
    public const DIR = 'public/assets/core/vendor/fontawesome6/webfonts';

    /**
     * Le famiglie disegnate dall'app: file del vendor da cui partire e nome del
     * sottoinsieme. Il vendor resta intatto, com'è per ogni altro asset.
     */
    public const FAMILIES = [
        'solid' => [
            'vendor' => 'fa-solid-900.ttf',
            'output' => 'fa-solid-subset.woff2',
        ],
        'regular' => [
            'vendor' => 'fa-regular-400.ttf',
            'output' => 'fa-regular-subset.woff2',
        ],
        'brands' => [
            'vendor' => 'fa-brands-400.ttf',
            'output' => 'fa-brands-subset.woff2',
        ],
    ];

    /** Il foglio da cui si leggono i code point davvero usati. */
    public const INCLUDED_CSS = FontAwesomeSubset::OUTPUT_CSS;

    public static function basePath(?string $root = null): string
    {
        if ($root !== null) {
            return rtrim($root, '/');
        }

        return defined('ROOTPATH') ? rtrim(ROOTPATH, '/') : dirname(__DIR__, 2);
    }

    public static function vendorFont(string $family): string
    {
        return self::DIR . '/' . self::FAMILIES[$family]['vendor'];
    }

    public static function outputFont(string $family): string
    {
        return self::DIR . '/' . self::FAMILIES[$family]['output'];
    }

    public static function manifestPath(string $family): string
    {
        return self::DIR . '/' . preg_replace('/\.woff2$/', '', self::FAMILIES[$family]['output']) . '.json';
    }

    /**
     * I code point che il foglio servito assegna alle icone.
     *
     * Il foglio è già filtrato sulle icone in uso, quindi qui non serve
     * incrociare nulla: tutto quello che contiene è da tenere. Gli alias
     * condividono il code point dell'icona, e restano un solo valore. La
     * famiglia non si può dedurre dal foglio (le regole delle icone sono
     * comuni); la decide ogni font, tenendo i code point che possiede.
     *
     * @return list<string> esadecimali minuscoli, senza prefisso
     */
    public static function codepoints(?string $root = null): array
    {
        $root = self::basePath($root);
        $css  = @file_get_contents($root . '/' . self::INCLUDED_CSS);

        if ($css === false) {
            throw new \RuntimeException('Foglio Font Awesome non trovato: ' . self::INCLUDED_CSS);
        }

        preg_match_all('/content:"\\\\([0-9a-fA-F]{4,6})"/', $css, $matches);

        $codepoints = array_values(array_unique(array_map('strtolower', $matches[1])));
        sort($codepoints);

        return $codepoints;
    }

    /**
     * Rigenera i font e i manifesti.
     *
     * @param callable(string): void|null $report riceve una riga di resoconto per ogni passo
     *
     * @return array<string, array{font: string, codepoints: list<string>, vendor_bytes: int, subset_bytes: int}>
     */
    public static function build(?string $root = null, ?callable $report = null): array
    {
        $root = self::basePath($root);

        $binary = self::pyftsubset();

        if ($binary === null) {
            throw new \RuntimeException(
                'pyftsubset non trovato: installa fonttools (`pip install fonttools brotli`) per rigenerare i font. '
                . 'I file versionati restano validi, si rigenerano solo quando si aggiunge un\'icona.'
            );
        }

        $python     = self::python($binary);
        $codepoints = self::codepoints($root);
        $unicodes   = implode(',', array_map(static fn (string $cp): string => 'U+' . strtoupper($cp), $codepoints));

        $report ??= static function (string $line): void {
        };

        $report(sprintf('code point nel foglio : %d', count($codepoints)));

        $result = [];

        foreach (array_keys(self::FAMILIES) as $family) {
            $vendor = $root . '/' . self::vendorFont($family);
            $output = $root . '/' . self::outputFont($family);
            $tmp    = $output . '.tmp';

            if (!is_file($vendor)) {
                throw new \RuntimeException('Font vendor non trovato: ' . self::vendorFont($family));
            }

            $command = sprintf(
                '%s %s --unicodes=%s --flavor=woff2 --layout-features=%s --output-file=%s',
                escapeshellarg($binary),
                escapeshellarg($vendor),
                escapeshellarg($unicodes),
                escapeshellarg('*'),
                escapeshellarg($tmp)
            );

            exec($command . ' 2>&1', $lines, $status);

            if ($status !== 0 || !is_file($tmp)) {
                @unlink($tmp);

                throw new \RuntimeException("pyftsubset è fallito su {$family} (uscita {$status}): " . implode(' ', $lines));
            }

            if (@rename($tmp, $output) === false) {
                @unlink($tmp);

                throw new \RuntimeException('Non riesco a scrivere ' . self::outputFont($family));
            }

            $font     = (string) file_get_contents($output);
            $included = self::included($python, $output);

            file_put_contents(
                $root . '/' . self::manifestPath($family),
                json_encode([
                    'generato_da' => 'php tools/subset_icons.php',
                    'famiglia'    => $family,
                    'font'        => basename(self::outputFont($family)),
                    'sorgente'    => basename(self::vendorFont($family)),
                    'byte'        => strlen($font),
                    'sha256'      => hash('sha256', $font),
                    // Dal file, non dalla richiesta: è quello che il browser
                    // può davvero disegnare.
                    'code_point'  => $included,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
            );

            $result[$family] = [
                'font'         => self::outputFont($family),
                'codepoints'   => $included,
                'vendor_bytes' => (int) filesize($vendor),
                'subset_bytes' => strlen($font),
            ];

            $report(sprintf(
                '%-8s %7d -> %6d byte  (%d glifi)',
                $family,
                $result[$family]['vendor_bytes'],
                $result[$family]['subset_bytes'],
                count($result[$family]['codepoints'])
            ));
        }

        return $result;
    }

    /**
     * Le icone usate dal codice che nessuno dei font serviti contiene.
     *
     * Confronta i code point delle icone in uso con l'unione dei manifesti. È
     * la rete che rende sicuro togliere glifi: un'icona nuova senza
     * rigenerazione fa fallire il test invece di sparire dalla pagina.
     *
     * @return array<string,string> nome icona => code point atteso
     */
    public static function missingIcons(?string $root = null): array
    {
        $root = self::basePath($root);

        $included = [];

        foreach (array_keys(self::FAMILIES) as $family) {
            $manifest = self::manifest($family, $root);

            if ($manifest === null) {
                // Nessun manifesto: il font non è mai stato generato.
                continue;
            }

            foreach ($manifest['code_point'] as $codepoint) {
                $included[$codepoint] = true;
            }
        }

        if ($included === []) {
            return [];
        }

        $missing = [];

        foreach (FontAwesomeSubset::usedIcons($root) as $name => $count) {
            $codepoint = self::codepointOf($name, $root);

            if ($codepoint !== null && !isset($included[$codepoint])) {
                $missing[$name] = $codepoint;
            }
        }

        return $missing;
    }

    /**
     * @return array{generato_da: string, famiglia: string, font: string, sorgente: string, byte: int, sha256: string, code_point: list<string>}|null
     */
    public static function manifest(string $family, ?string $root = null): ?array
    {
        $root = self::basePath($root);
        $json = @file_get_contents($root . '/' . self::manifestPath($family));

        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Code point di un'icona secondo il foglio servito.
     */
    public static function codepointOf(string $icon, ?string $root = null): ?string
    {
        static $map = [];

        $root = self::basePath($root);

        if (!isset($map[$root])) {
            $map[$root] = [];

            $css = (string) @file_get_contents($root . '/' . self::INCLUDED_CSS);

            preg_match_all('/((?:\.fa-[a-z0-9-]+::?before,)*\.fa-[a-z0-9-]+::?before)\{content:"\\\\([0-9a-fA-F]{4,6})"\}/', $css, $rules, PREG_SET_ORDER);

            foreach ($rules as [, $selectors, $codepoint]) {
                preg_match_all('/\.fa-([a-z0-9-]+)::?before/', $selectors, $names);

                foreach ($names[1] as $name) {
                    $map[$root]['fa-' . $name] = strtolower($codepoint);
                }
            }
        }

        return $map[$root][$icon] ?? null;
    }

    /**
     * Percorso di pyftsubset, o null se non c'è.
     */
    public static function pyftsubset(): ?string
    {
        // `--help` e non `--version`: fonttools esce con 2 su `--version`,
        // quindi la versione non serve a capire se lo strumento c'è.
        foreach (['pyftsubset', 'fonttools subset'] as $candidate) {
            exec($candidate . ' --help 2>/dev/null', $out, $status);

            if ($status === 0) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Il `cmap` del font generato, come elenco di code point.
     *
     * Si legge dal file invece di fidarsi della richiesta: un code point
     * chiesto e assente dal font non è nel PDF, e il manifesto deve dire cosa
     * c'è davvero. Serve l'interprete che porta fonttools, che è quello dello
     * script `pyftsubset` (il `python3` di sistema spesso non ce l'ha).
     *
     * @return list<string>
     */
    private static function included(?string $python, string $font): array
    {
        if ($python === null) {
            throw new \RuntimeException('Serve Python con fonttools per leggere il cmap del font generato.');
        }

        $script = 'import json,sys;from fontTools.ttLib import TTFont;'
            . 'print(json.dumps(sorted(TTFont(sys.argv[1]).getBestCmap())))';

        exec(sprintf('%s -c %s %s 2>&1', escapeshellarg($python), escapeshellarg($script), escapeshellarg($font)), $lines, $status);

        if ($status !== 0) {
            throw new \RuntimeException('Lettura del cmap fallita: ' . implode(' ', $lines));
        }

        $codepoints = json_decode(implode('', $lines) ?: '[]', true);

        if (!is_array($codepoints)) {
            throw new \RuntimeException('cmap illeggibile per ' . basename($font));
        }

        return array_map(static fn (int $cp): string => dechex($cp), $codepoints);
    }

    /**
     * L'interprete Python che esegue pyftsubset (dal suo shebang).
     */
    private static function python(string $pyftsubset): ?string
    {
        $path = str_contains($pyftsubset, ' ')
            ? trim((string) shell_exec('command -v ' . escapeshellarg(explode(' ', $pyftsubset)[0]) . ' 2>/dev/null'))
            : trim((string) shell_exec('command -v ' . escapeshellarg($pyftsubset) . ' 2>/dev/null'));

        if ($path === '') {
            return null;
        }

        $first = strtok((string) @file_get_contents($path), "\n") ?: '';
        $shebang = str_starts_with($first, '#!') ? trim(substr($first, 2)) : '';

        // `#!/usr/bin/env python3` -> python3; un percorso assoluto vale com'è.
        if (str_starts_with($shebang, '/usr/bin/env ')) {
            $shebang = trim(substr($shebang, strlen('/usr/bin/env ')));
        }

        return $shebang !== '' ? $shebang : null;
    }
}
