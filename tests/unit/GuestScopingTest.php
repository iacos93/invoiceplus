<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il portale guest decide quali documenti un cliente può vedere leggendo gli
 * id dei clienti assegnati (clientIds()). La stessa lettura stava copiata in
 * Guest, GuestInvoices e GuestQuotes: tre copie di un controllo di accesso
 * significano che una correzione può arrivare a una sola delle tre porte.
 *
 * Ora vive in un trait. Questo test non prova il comportamento (servirebbe un
 * database con utenti clienti assegnati, ed è coperto dai test di
 * integrazione): prova che l'implementazione resti una sola, così una nuova
 * copia non passa inosservata.
 *
 * @internal
 */
final class GuestScopingTest extends CIUnitTestCase
{
    public function testLoScopingGuestHaUnaSolaImplementazione(): void
    {
        $trovate = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(APPPATH, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            if (!str_contains($path, 'Controllers') && !str_contains($path, 'Traits')) {
                continue;
            }

            if (preg_match('/function clientIds\s*\(/', (string) file_get_contents($path))) {
                $trovate[] = str_replace(APPPATH, 'app/', $path);
            }
        }

        $this->assertCount(
            1,
            $trovate,
            'clientIds() deve stare in un posto solo, altrimenti le porte del portale possono divergere: '
                . implode(', ', $trovate)
        );
    }
}
