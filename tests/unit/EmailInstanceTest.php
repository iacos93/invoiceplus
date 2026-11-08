<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Ogni invio deve usare un'istanza Email propria. Services::email() senza
 * argomenti restituisce l'istanza condivisa, che conserva destinatari e
 * allegati fra un invio e l'altro: nei solleciti del cron un cliente
 * riceveva i PDF delle fatture di altri clienti (trovato il 2026-11-03).
 */
final class EmailInstanceTest extends CIUnitTestCase
{
    public function testNoSharedEmailInstance(): void
    {
        $offenders = [];
        $files     = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match('/Services::email\(\s*\)|service\(\s*[\'"]email[\'"]\s*\)/', $line)) {
                    $offenders[] = str_replace(APPPATH, 'app/', $file->getPathname()) . ':' . ($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Usare \Config\Services::email(null, false)');
    }

    public function testFreshInstancesDoNotShareAttachments(): void
    {
        $first = \Config\Services::email(null, false);
        $first->attach('contenuto', 'attachment', 'a.pdf', 'application/pdf');

        $second = \Config\Services::email(null, false);

        $this->assertNotSame($first, $second);
        $this->assertSame([], (fn () => $this->attachments)->call($second));
    }
}
