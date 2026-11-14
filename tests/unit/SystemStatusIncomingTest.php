<?php

use App\Services\SystemStatus;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Le fatture consegnate dallo SdI e rimaste su disco senza diventare spese.
 *
 * Lo SdI non le rimanda piu': se l'importazione fallisce, il file in incoming/
 * e' l'unica copia che l'applicazione possiede. Il controllo deve accorgersene
 * senza gridare per cio' che non e' una fattura (metadati, notifiche) e senza
 * confondersi con i nomi che l'importazione cambia (.p7m, lotti).
 *
 * @internal
 */
final class SystemStatusIncomingTest extends CIUnitTestCase
{
    private string $writable = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->writable = sys_get_temp_dir() . '/status-incoming-' . bin2hex(random_bytes(4));
        mkdir($this->writable, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rimuovi($this->writable);

        parent::tearDown();
    }

    private function rimuovi(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $voce) {
            is_dir($voce) ? $this->rimuovi($voce) : @unlink($voce);
        }

        @rmdir($dir);
    }

    /** @param list<string> $file */
    private function incoming(array $file): void
    {
        $dir = $this->writable . '/uploads/expenses/incoming';
        mkdir($dir, 0777, true);

        foreach ($file as $nome) {
            file_put_contents($dir . '/' . $nome, '<x/>');
        }
    }

    /** @param list<string> $importati */
    private function controllo(array $importati): array
    {
        return (new SystemStatus($this->writable, $this->writable . '/backups', $this->writable . '/cron', time()))
            ->incomingCheck($importati);
    }

    public function testSenzaLaCartellaNonCEUnProblema(): void
    {
        $r = $this->controllo([]);

        $this->assertSame(SystemStatus::OK, $r['state']);
        $this->assertSame('incoming', $r['key']);
    }

    public function testUnaFatturaImportataNonSegnalaNiente(): void
    {
        $this->incoming(['IT01234567890_AAAAA.xml']);

        $this->assertSame(SystemStatus::OK, $this->controllo(['IT01234567890_AAAAA.xml'])['state']);
    }

    public function testUnaFatturaNonImportataEUnAvvisoCheDiceQuale(): void
    {
        $this->incoming(['IT01234567890_AAAAA.xml', 'IT01234567890_BBBBB.xml']);

        $r = $this->controllo(['IT01234567890_AAAAA.xml']);

        $this->assertSame(SystemStatus::WARNING, $r['state']);
        $this->assertStringContainsString('IT01234567890_BBBBB.xml', $r['detail']);
        $this->assertStringNotContainsString('AAAAA', $r['detail']);
        $this->assertStringContainsString('Importa XML', $r['hint']);
    }

    public function testUnFileFirmatoSiRiconoscePerIlNomeInterno(): void
    {
        $this->incoming(['IT01234567890_CCCCC.xml.p7m']);

        $this->assertSame(
            SystemStatus::OK,
            $this->controllo(['IT01234567890_CCCCC.xml'])['state'],
            'La spesa porta il nome interno, senza .p7m'
        );
    }

    public function testUnLottoImportatoPezzoPerPezzoSiRiconosce(): void
    {
        $this->incoming(['IT01234567890_DDDDD.xml']);

        $this->assertSame(
            SystemStatus::OK,
            $this->controllo(['IT01234567890_DDDDD.xml (fattura 1 di 2)', 'IT01234567890_DDDDD.xml (fattura 2 di 2)'])['state']
        );
    }

    public function testMetadatiENotificheNonSonoFatture(): void
    {
        $this->incoming([
            'IT01234567890_EEEEE_MT_001.xml',
            'IT01234567890_EEEEE_DT_001.xml',
            'IT01234567890_EEEEE_NS_001.xml',
        ]);

        $this->assertSame(SystemStatus::OK, $this->controllo([])['state']);
    }

    public function testIlConteggioEIlTroncamentoDellElenco(): void
    {
        $this->incoming(['IT1_A.xml', 'IT1_B.xml', 'IT1_C.xml', 'IT1_D.xml', 'IT1_E.xml']);

        $r = $this->controllo([]);

        $this->assertSame(SystemStatus::WARNING, $r['state']);
        $this->assertStringStartsWith('5 fatture ricevute e non importate', $r['detail']);
        $this->assertStringContainsString('…', $r['detail']);
    }

    public function testUnNomeImportatoVuotoNonCopreNiente(): void
    {
        $this->incoming(['IT01234567890_FFFFF.xml']);

        $this->assertSame(SystemStatus::WARNING, $this->controllo([''])['state'], 'Una spesa senza nome file non e\' la fattura arrivata');
    }
}
