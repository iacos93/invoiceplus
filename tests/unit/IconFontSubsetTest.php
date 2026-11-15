<?php

use App\Libraries\FontAwesomeSubset;
use App\Libraries\IconFontSubset;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * I font delle icone ridotti ai glifi in uso.
 *
 * Il foglio CSS era già filtrato, i file dei font no: 156 KB per ~145 glifi
 * (solid) e 118 KB per tre marchi (brands). Il ritaglio lo fa `pyftsubset`, che
 * qui non c'è (non è una dipendenza dell'applicazione), quindi i risultati sono
 * versionati e questi test difendono i file committati: se un'icona nuova non
 * c'è in nessuno dei font, la suite lo dice invece di lasciarla sparire.
 *
 * @internal
 */
final class IconFontSubsetTest extends CIUnitTestCase
{
    /**
     * @return list<array{0: string}>
     */
    public static function famiglie(): array
    {
        return array_map(static fn (string $family): array => [$family], array_keys(IconFontSubset::FAMILIES));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('famiglie')]
    public function testOgniFamigliaHaUnManifestoCoerenteColFile(string $family): void
    {
        $manifest = IconFontSubset::manifest($family, ROOTPATH);

        $this->assertIsArray($manifest, 'Manca ' . IconFontSubset::manifestPath($family));

        $font = (string) file_get_contents(ROOTPATH . IconFontSubset::outputFont($family));

        $this->assertStringStartsWith('wOF2', $font);
        $this->assertSame(strlen($font), $manifest['byte'], $family . ': dimensione nel manifesto diversa dal file');
        $this->assertSame(hash('sha256', $font), $manifest['sha256'], $family . ': impronta nel manifesto diversa dal file');
        $this->assertNotEmpty($manifest['code_point'], $family . ': nessun glifo');
        $this->assertLessThan(40000, strlen($font), $family . ': il font non è stato ridotto');
    }

    public function testIlFontServitoContieneLeIconeUsate(): void
    {
        foreach (array_keys(IconFontSubset::FAMILIES) as $family) {
            $this->assertFileExists(ROOTPATH . IconFontSubset::outputFont($family));
        }

        $missing = IconFontSubset::missingIcons(ROOTPATH);

        $this->assertSame([], $missing,
            'Icone usate e assenti da tutti i font ridotti: ' . json_encode($missing)
            . ' — esegui `php tools/subset_icons.php` (serve pyftsubset: pip install fonttools brotli).');
    }

    /**
     * I tre marchi del calendario devono stare nel sottoinsieme dei marchi, che
     * è il file che esisteva solo per loro.
     */
    public function testIlSottoinsiemeDeiMarchiHaISoliTreMarchi(): void
    {
        $manifest = IconFontSubset::manifest('brands', ROOTPATH);

        $this->assertIsArray($manifest);

        sort($manifest['code_point']);

        // f179 Apple, f1a0 Google, f3ca Microsoft.
        $this->assertSame(['f179', 'f1a0', 'f3ca'], $manifest['code_point']);
        $this->assertLessThan(2048, (int) $manifest['byte'], 'Il font dei marchi dovrebbe pesare meno di 2 KB');
    }

    public function testIlFoglioCaricaIFontRidotti(): void
    {
        $css = (string) file_get_contents(ROOTPATH . FontAwesomeSubset::OUTPUT_CSS);

        foreach (['fa-solid-subset.woff2', 'fa-regular-subset.woff2', 'fa-brands-subset.woff2'] as $sottoinsieme) {
            $this->assertStringContainsString($sottoinsieme, $css);
        }

        // I font pieni non devono più essere serviti, né come woff2 né come ttf.
        foreach (['fa-solid-900', 'fa-regular-400', 'fa-brands-400'] as $vendor) {
            $this->assertStringNotContainsString($vendor . '.woff2', $css);
            $this->assertStringNotContainsString($vendor . '.ttf', $css);
        }
    }
}
