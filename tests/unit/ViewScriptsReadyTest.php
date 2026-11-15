<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Gli script delle viste non si agganciano a DOMContentLoaded.
 *
 * Con la navigazione parziale (ip-pjax.js) il contenuto di una pagina entra nel
 * documento già caricato e i suoi script girano dopo: DOMContentLoaded è
 * scattato da un pezzo, il listener non viene mai chiamato e i pulsanti della
 * pagina non rispondono finché non si ricarica. Succede solo arrivandoci dal
 * menu, quindi nessun test sulla pagina lo vede. La forma giusta è `ipReady()`
 * (layout/base.php) o un controllo su document.readyState.
 *
 * I layout sono esclusi: stanno fuori da #main-content e girano una volta sola.
 *
 * @internal
 */
final class ViewScriptsReadyTest extends CIUnitTestCase
{
    public function testLeVisteNonUsanoDomContentLoadedNudo(): void
    {
        $colpevoli = [];
        $iterator  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH . 'Views', FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = str_replace(APPPATH . 'Views/', '', $file->getPathname());

            if (! $file->isFile() || $file->getExtension() !== 'php' || str_starts_with($path, 'layout/') || str_starts_with($path, 'errors/')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($source, "addEventListener('DOMContentLoaded'") || str_contains($source, 'addEventListener("DOMContentLoaded"')) {
                // Va bene se il file controlla anche lo stato del documento.
                if (! str_contains($source, 'readyState')) {
                    $colpevoli[] = $path;
                }
            }
        }

        $this->assertSame([], $colpevoli, 'Viste con un listener DOMContentLoaded che non parte dopo una navigazione parziale: usare ipReady().');
    }
}
