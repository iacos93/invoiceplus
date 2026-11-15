<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * La PWA è fatta di file statici che nessun altro test apre: un'icona
 * rinominata o un manifest con una virgola di troppo non rompono nulla di
 * visibile, l'app semplicemente smette di essere installabile. Qui si
 * controlla che i pezzi ci siano e si riferiscano l'uno all'altro.
 *
 * @internal
 */
final class PwaTest extends CIUnitTestCase
{
    public function testManifestIsValidAndIconsExist(): void
    {
        $manifest = json_decode((string) file_get_contents(ROOTPATH . 'public/manifest.json'), true);

        $this->assertIsArray($manifest);
        $this->assertSame('standalone', $manifest['display']);
        // Percorsi relativi: l'app può essere installata in una sottocartella.
        $this->assertSame('./', $manifest['scope']);
        $this->assertStringStartsWith('./', $manifest['start_url']);

        $sizes = [];
        foreach ($manifest['icons'] as $icon) {
            // Il ?v= serve a far rileggere l'icona a chi ha già installato
            // l'app: il file su disco è lo stesso senza la query.
            $this->assertFileExists(ROOTPATH . 'public/' . strtok($icon['src'], '?'));
            $sizes[] = $icon['sizes'];
        }

        // Chrome chiede almeno 192 e 512 per proporre l'installazione.
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
    }

    public function testShortcutsPointToDeclaredRoutes(): void
    {
        $manifest = json_decode((string) file_get_contents(ROOTPATH . 'public/manifest.json'), true);
        $routes   = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        foreach ($manifest['shortcuts'] as $shortcut) {
            $path = ltrim($shortcut['url'], './');
            $this->assertStringContainsString("'" . $path . "'", $routes, 'Scorciatoia verso una rotta che non esiste: ' . $path);
        }
    }

    public function testServiceWorkerNeverCachesPages(): void
    {
        $sw = (string) file_get_contents(ROOTPATH . 'public/sw.js');

        $this->assertFileExists(ROOTPATH . 'public/offline.html');
        // Le pagine sono dati fiscali dietro sessione: in cache finiscono solo
        // i file di /assets. Se qualcuno aggiunge un cache.put fuori da
        // salva(), questo conteggio cambia e il test chiede di ripensarci.
        $this->assertSame(1, substr_count($sw, 'cache.put('));
        $this->assertStringContainsString("'assets/'", $sw);
    }

    public function testLayoutsIncludePwaHead(): void
    {
        foreach (['base', 'auth'] as $layout) {
            $this->assertStringContainsString(
                "layout/pwa_head",
                (string) file_get_contents(APPPATH . 'Views/layout/' . $layout . '.php')
            );
        }

        // Il portale clienti no: vedi il commento in layout/pwa_head.php.
        $this->assertStringNotContainsString(
            'layout/pwa_head',
            (string) file_get_contents(APPPATH . 'Views/layout/guest.php')
        );
    }
}
