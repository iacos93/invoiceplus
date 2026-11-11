<?php

use App\Libraries\PgDumpLocator;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * La scelta del pg_dump.
 *
 * Il caso che conta è quello che ha lasciato l'installazione senza backup: il
 * PATH contiene il client 16, il server è la 17.11 e pg_dump si rifiuta di
 * leggerlo. Il locatore deve scegliere un binario di versione almeno pari.
 *
 * @internal
 */
final class PgDumpLocatorTest extends CIUnitTestCase
{
    #[DataProvider('versioniFornite')]
    public function testLeggeLaVersioneMaggiore(string $output, ?int $atteso): void
    {
        $this->assertSame($atteso, PgDumpLocator::parseVersion($output));
    }

    /**
     * @return array<string, array{string, int|null}>
     */
    public static function versioniFornite(): array
    {
        return [
            'homebrew 17'  => ['pg_dump (PostgreSQL) 17.11 (Homebrew)', 17],
            'debian 16'    => ['pg_dump (PostgreSQL) 16.15 (Ubuntu 16.15-1.pgdg24.04+1)', 16],
            'senza patch'  => ['pg_dump (PostgreSQL) 15', 15],
            'vuoto'        => ['', null],
            'comando perso' => ['command not found', null],
        ];
    }

    public function testPreferisceIlPrimoCompatibileConIlServer(): void
    {
        $scelta = PgDumpLocator::pick([
            '/usr/bin/pg_dump'            => 16,
            '/opt/pg17/bin/pg_dump'       => 17,
            '/opt/pg18/bin/pg_dump'       => 18,
        ], 17);

        $this->assertSame('/opt/pg17/bin/pg_dump', $scelta, 'Deve bastare la prima versione almeno pari al server');
    }

    /**
     * Nessuno all'altezza: meglio usare il più recente e far uscire l'errore
     * di versione, che restituire "pg_dump non disponibile" e mascherare il
     * motivo vero.
     */
    public function testSenzaVersioneAdeguataRestituisceLaPiuAlta(): void
    {
        $scelta = PgDumpLocator::pick([
            '/usr/bin/pg_dump'    => 14,
            '/opt/pg16/bin/pg_dump' => 16,
            '/opt/pg15/bin/pg_dump' => 15,
        ], 17);

        $this->assertSame('/opt/pg16/bin/pg_dump', $scelta);
    }

    public function testSenzaCandidatiRestituisceNull(): void
    {
        $this->assertNull(PgDumpLocator::pick([], 17));
    }

    /**
     * Percorsi inesistenti e duplicati non devono produrre una scelta
     * fantasma: il locatore restituisce null, che il controller traduce in
     * "pg_dump non disponibile".
     */
    public function testPercorsiInesistentiNonVengonoScelti(): void
    {
        $locatore = new PgDumpLocator(null, ['/non/esiste/pg_dump', '/non/esiste/pg_dump', '']);

        $this->assertNull($locatore->locate(17));
    }
}
