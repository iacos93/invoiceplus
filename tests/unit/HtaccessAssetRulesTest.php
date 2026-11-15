<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Le regole di compressione e cache degli asset dentro `public/.htaccess`.
 *
 * Sono l'unico posto dove vivono: nessun test le attraversa in esecuzione (la
 * suite non passa da Apache, e `php spark serve` non legge `.htaccess`), e una
 * modifica sbagliata non si vede — la pagina si apre lo stesso, solo più
 * pesante e riscaricata a ogni visita. Per questo le regole si controllano qui,
 * leggendo il file: girano in CI come le altre guardie (AssetFreshnessTest,
 * FontAwesomeSubsetTest).
 *
 * Le richieste vere, quelle con gli header prodotti da Apache, stanno in
 * `tests/collaudo/AssetHeadersTest.php` perché hanno bisogno di un server:
 *
 *     php tools/apache_headers_check.php
 *
 * @internal
 */
final class HtaccessAssetRulesTest extends CIUnitTestCase
{
    /**
     * File di servizio che stanno sotto assets senza essere asset scaricati:
     * non c'è niente da mettere in cache.
     */
    // `json` è il manifesto del font delle icone
    // (webfonts/fa-solid-subset.json): un file di servizio, che il browser non
    // scarica. Non va in cache perché non lo chiede nessuno.
    private const IGNORATI = ['gitignore', 'ds_store', 'map', 'json'];

    private function htaccess(): string
    {
        $path = ROOTPATH . 'public/.htaccess';

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * Il contenuto di un blocco `<IfModule modulo> ... </IfModule>`.
     */
    private function blocco(string $modulo): string
    {
        $this->assertSame(
            1,
            preg_match('/<IfModule ' . preg_quote($modulo, '/') . '>\n(.*?)<\/IfModule>/s', $this->htaccess(), $m),
            'Manca il blocco <IfModule ' . $modulo . '> in public/.htaccess'
        );

        return $m[1];
    }

    public function testGliAssetDiTestoSonoCompressi(): void
    {
        $deflate = $this->blocco('mod_deflate.c');

        $this->assertStringContainsString('AddOutputFilterByType DEFLATE', $deflate);

        // I tipi che l'applicazione serve davvero: senza uno di questi la
        // compressione smette di funzionare per quel file, e in pagina non si
        // nota.
        foreach (['text/css', 'text/javascript', 'application/javascript', 'application/json', 'image/svg+xml'] as $tipo) {
            $this->assertStringContainsString($tipo, $deflate, 'Tipo non compresso: ' . $tipo);
        }
    }

    public function testIFileGiaCompressiNonVengonoRicompressi(): void
    {
        $deflate = $this->blocco('mod_deflate.c');

        $this->assertStringContainsString('SetEnvIfNoCase Request_URI', $deflate);

        foreach (['woff2', 'woff', 'png', 'jpe?g', 'gif', 'zip', 'gz'] as $estensione) {
            $this->assertStringContainsString($estensione, $deflate, 'Estensione non esclusa dalla compressione: ' . $estensione);
        }
    }

    public function testGliAssetHannoUnaCacheLungaInEntrambiIModi(): void
    {
        // mod_expires e mod_headers: se manca uno dei due moduli, l'altro deve
        // bastare. Due copie della stessa riga sono volute.
        $volte = substr_count($this->htaccess(), 'public, max-age=31536000, immutable');

        $this->assertSame(2, $volte, 'La cache immutabile degli asset deve stare sia in mod_expires sia in mod_headers');
        $this->assertStringContainsString('access plus 1 year', $this->blocco('mod_expires.c'));
    }

    public function testOgniTipoDiAssetInCacheESullaLista(): void
    {
        $htaccess = $this->htaccess();

        // Le estensioni che il progetto serve davvero, lette dalla cartella:
        // aggiungere un .avif senza metterlo in FilesMatch lo lascerebbe fuori
        // dalla cache, e nessuno se ne accorgerebbe guardando la pagina.
        $presenti = [];

        $iteratore = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(ROOTPATH . 'public/assets', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iteratore as $file) {
            if ($file->isFile()) {
                $presenti[strtolower($file->getExtension())] = true;
            }
        }

        $mancanti = [];

        // Non solo i tipi noti a memoria: qualunque cosa compaia sotto assets e
        // non sia nella lista va guardata una volta, perché il silenzio qui è
        // un file servito senza cache che nessuno nota.
        foreach (array_keys($presenti) as $estensione) {
            if (in_array($estensione, self::IGNORATI, true)) {
                continue;
            }

            if (!$this->estensioneCoperta($htaccess, $estensione)) {
                $mancanti[] = $estensione;
            }
        }

        $this->assertSame(
            [],
            $mancanti,
            'Tipi serviti da public/assets ma fuori dalle regole di cache del .htaccess: '
            . implode(', ', $mancanti)
            . ' — se sono asset da cacheare vanno aggiunti a entrambi i FilesMatch; se sono file di servizio, a IGNORATI in questo test.'
        );
    }

    private function estensioneCoperta(string $htaccess, string $estensione): bool
    {
        // La lista sta in FilesMatch "\\.(css|js|...)$": si estraggono tutte e
        // si confronta ogni alternativa con l'espressione regolare, così
        // `jpe?g` vale per `jpg`.
        if (!preg_match_all('/<FilesMatch "([^"]+)"/', $htaccess, $blocchi)) {
            return false;
        }

        foreach ($blocchi[1] as $lista) {
            $inizio = strpos($lista, '(');
            $fine   = strrpos($lista, ')');

            if ($inizio === false || $fine === false || $fine <= $inizio) {
                continue;
            }

            foreach (explode('|', substr($lista, $inizio + 1, $fine - $inizio - 1)) as $alternativa) {
                if (preg_match('/^' . $alternativa . '$/i', $estensione)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function testLePagineNonHannoCacheLunga(): void
    {
        $expires = $this->blocco('mod_expires.c');

        $this->assertStringContainsString('access plus 0 seconds', $expires, 'Le pagine devono scadere subito');

        // Fuori dai blocchi degli asset non deve restare nessuna cache lunga:
        // una pagina in cache è un utente che vede i dati di un altro.
        $senzaAsset = preg_replace('/<FilesMatch "[^"]*">.*?<\/FilesMatch>/s', '', $this->htaccess()) ?? '';

        $this->assertStringNotContainsString('max-age', $senzaAsset);
        $this->assertStringNotContainsString('Access plus 1 year', $senzaAsset);
    }

    public function testLaRiscritturaDelleRotteResta(): void
    {
        // Le regole aggiunte stanno in fondo: se un giorno qualcuno le
        // incollasse sopra, o riscrivesse il file, questo test protegge il
        // front controller.
        $this->assertStringContainsString('<IfModule mod_rewrite.c>', $this->htaccess());
        $this->assertStringContainsString('RewriteRule ^([\s\S]*)$ index.php/$1', $this->htaccess());
    }
}
