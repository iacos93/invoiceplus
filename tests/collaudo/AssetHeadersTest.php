<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Collaudo delle intestazioni che escono da Apache: compressione e cache.
 *
 * Qui ci sono solo le richieste vere, quelle che nessun test della suite può
 * fare: `public/.htaccess` lo legge Apache, non `php spark serve`, e in CI non
 * c'è nessun server in piedi. Le guardie sulle stesse regole — che il file
 * contenga il deflate, l'esclusione dei file già compressi, la cache
 * immutabile — stanno in `tests/unit/HtaccessAssetRulesTest.php` e girano
 * sempre, anche in CI.
 *
 * Serve un Apache che serva questo codice con `AllowOverride All`. Per averne
 * uno usa e getta, senza configurare niente:
 *
 *     php tools/apache_headers_check.php
 *
 * che avvia httpd e php-fpm su una porta libera, lancia questo test e spegne
 * tutto. In sua assenza (nessuna `IP_APACHE_BASE_URL`) le richieste si saltano
 * e il messaggio dice come procurarsi il server.
 *
 * Non fa parte della suite: ha bisogno di un server. Si lancia a mano insieme
 * al resto del collaudo.
 *
 * @internal
 */
final class AssetHeadersTest extends CIUnitTestCase
{
    private function baseUrl(): string
    {
        $url = trim((string) getenv('IP_APACHE_BASE_URL'));

        if ($url === '') {
            $this->markTestSkipped('Nessun Apache: lancia `php tools/apache_headers_check.php`, che avvia un httpd usa e getta e rifà questi test.');
        }

        return rtrim($url, '/');
    }

    /**
     * @param list<string> $intestazioni
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function chiedi(string $percorso, array $intestazioni = []): array
    {
        $ch = curl_init($this->baseUrl() . $percorso);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => $intestazioni,
        ]);

        $risposta = (string) curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $teste    = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $headers = [];

        foreach (explode("\r\n", substr($risposta, 0, $teste)) as $riga) {
            if (str_contains($riga, ':')) {
                [$nome, $valore] = explode(':', $riga, 2);
                $headers[strtolower(trim($nome))] = trim($valore);
            }
        }

        return ['status' => $status, 'headers' => $headers, 'body' => substr($risposta, $teste)];
    }

    public function testIlCssArrivaCompressoEInCache(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('Estensione curl assente');
        }

        $file = ROOTPATH . 'public/assets/core/css/custom.min.css';
        $this->assertFileExists($file);

        $compresso = $this->chiedi('/assets/core/css/custom.min.css', ['Accept-Encoding: gzip']);

        $this->assertSame(200, $compresso['status']);
        $this->assertSame('gzip', $compresso['headers']['content-encoding'] ?? '', 'Il CSS deve arrivare compresso');
        $this->assertStringContainsString('immutable', $compresso['headers']['cache-control'] ?? '');
        $this->assertStringContainsString('max-age=31536000', $compresso['headers']['cache-control'] ?? '');
        $this->assertStringContainsString('accept-encoding', strtolower($compresso['headers']['vary'] ?? ''));
        $this->assertLessThan(filesize($file) / 2, strlen($compresso['body']), 'La compressione deve dimezzare almeno il file');

        // Senza Accept-Encoding il file intero: la compressione è negoziata.
        $intero = $this->chiedi('/assets/core/css/custom.min.css');

        $this->assertArrayNotHasKey('content-encoding', $intero['headers']);
        $this->assertSame(filesize($file), strlen($intero['body']));
    }

    public function testIlFontGiaCompressoNonVieneRicompresso(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('Estensione curl assente');
        }

        $file = ROOTPATH . 'public/assets/core/fonts/sora-latin.woff2';
        $this->assertFileExists($file);

        $risposta = $this->chiedi('/assets/core/fonts/sora-latin.woff2', ['Accept-Encoding: gzip']);

        $this->assertSame(200, $risposta['status']);
        $this->assertArrayNotHasKey('content-encoding', $risposta['headers'], 'Un woff2 è già compresso: riderlo gonfia soltanto');
        $this->assertStringContainsString('immutable', $risposta['headers']['cache-control'] ?? '');
    }

    public function testIlServiceWorkerNonHaCacheLunga(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('Estensione curl assente');
        }

        $this->assertFileExists(ROOTPATH . 'public/sw.js');

        $risposta = $this->chiedi('/sw.js');

        $this->assertSame(200, $risposta['status']);

        $cache = $risposta['headers']['cache-control'] ?? '';

        // Ha estensione .js come gli altri asset, ma un indirizzo fisso e
        // niente ?v=: con la cache di un anno un aggiornamento dell'app non gli
        // arriverebbe mai.
        $this->assertStringContainsString('no-cache', $cache);
        $this->assertStringNotContainsString('immutable', $cache);
        $this->assertStringNotContainsString('31536000', $cache);
        $this->assertArrayNotHasKey('expires', $risposta['headers'], 'Il service worker non deve avere una scadenza lunga');
    }

    public function testUnaRispostaNonAssetNonHaCacheLunga(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('Estensione curl assente');
        }

        // Una cartella senza indice: risponde Apache da sé, senza PHP, e le
        // regole di `Expires` che toccano a una pagina valgono anche qui. È
        // questa metà che gira in CI, dove dentro Apache il PHP non c'è.
        $risposta = $this->chiedi('/assets/');

        $this->assertContains($risposta['status'], [403, 404], 'Una cartella senza indice non deve elencare i file');

        $cache = $risposta['headers']['cache-control'] ?? '';

        $this->assertStringNotContainsString('immutable', $cache);
        $this->assertStringNotContainsString('max-age=31536000', $cache);

        $expires = $risposta['headers']['expires'] ?? '';

        if ($expires !== '') {
            $this->assertLessThanOrEqual(time(), (int) strtotime($expires), 'Anche una risposta che non è un asset deve scadere subito');
        }
    }

    public function testLaPaginaDiAccessoNonEntraInCache(): void
    {
        if (!extension_loaded('curl')) {
            $this->markTestSkipped('Estensione curl assente');
        }

        if (getenv('IP_APACHE_STATIC_ONLY') === '1') {
            $this->markTestSkipped('Il server serve solo file statici: la pagina PHP la verifica `php tools/apache_headers_check.php` senza `--solo-statico`, con un database.');
        }

        $pagina = $this->chiedi('/sessions/login', ['Accept-Encoding: gzip']);

        $this->assertSame(200, $pagina['status']);
        $this->assertSame('gzip', $pagina['headers']['content-encoding'] ?? '', 'Anche l\'HTML va compresso');

        $cache = $pagina['headers']['cache-control'] ?? '';

        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringNotContainsString('immutable', $cache);
        $this->assertStringNotContainsString('max-age=31536000', $cache);

        // Il .htaccess porta l'Expires delle pagine a «adesso»: una scadenza
        // futura sarebbe una pagina in cache.
        $expires = $pagina['headers']['expires'] ?? '';

        if ($expires !== '') {
            $this->assertLessThanOrEqual(time(), (int) strtotime($expires));
        }
    }
}
