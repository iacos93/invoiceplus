<?php

use App\Services\SystemStatus;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * I controlli di "Stato del sistema".
 *
 * Ognuno di questi avvisi corrisponde a un guasto che non dà errore finché
 * qualcuno non lo cerca: backup assente o vecchio, automazioni ferme, cartelle
 * non scrivibili.
 *
 * @internal
 */
final class SystemStatusTest extends CIUnitTestCase
{
    private string $writable;

    private string $backups;

    private string $cron;

    private int $now;

    protected function setUp(): void
    {
        parent::setUp();

        $base          = sys_get_temp_dir() . '/ip-status-' . bin2hex(random_bytes(4));
        $this->writable = $base;
        $this->backups  = $base . '/backups';
        $this->cron     = $base . '/cron';

        mkdir($this->backups, 0775, true);
        mkdir($this->cron, 0775, true);

        $this->now = 1_800_000_000;
    }

    protected function tearDown(): void
    {
        foreach ([$this->backups, $this->cron, $this->writable . '/logs', $this->writable] as $dir) {
            @chmod($dir, 0775);
        }

        foreach (glob($this->writable . '/backups/*') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($this->writable . '/cron/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->backups);
        @rmdir($this->cron);
        @rmdir($this->writable . '/logs');
        @rmdir($this->writable);

        parent::tearDown();
    }

    private function stato(): SystemStatus
    {
        return new SystemStatus($this->writable, $this->backups, $this->cron, $this->now);
    }

    public function testPhpDellaVersioneRichiestaERegolare(): void
    {
        $check = $this->stato()->phpCheck();

        $this->assertSame(SystemStatus::OK, $check['state']);
        $this->assertStringContainsString(PHP_VERSION, $check['detail']);
    }

    public function testCartelleScrivibili(): void
    {
        mkdir($this->writable . '/logs', 0775);

        $check = $this->stato()->writableCheck();

        $this->assertSame(SystemStatus::OK, $check['state']);
        $this->assertSame('', $check['hint']);
    }

    /**
     * Una cartella che c'è ma non si può scrivere è il caso peggiore: log,
     * sessioni e PDF falliscono più tardi, con errori che non indicano il
     * permesso.
     */
    public function testCartellaNonScrivibileEGuasto(): void
    {
        mkdir($this->writable . '/logs', 0555);

        $check = $this->stato()->writableCheck();

        $this->assertSame(SystemStatus::ERROR, $check['state']);
        $this->assertStringContainsString('writable/logs', $check['detail']);
        $this->assertNotSame('', $check['hint']);
    }

    public function testSenzaBackupEGuasto(): void
    {
        $check = $this->stato()->backupCheck();

        $this->assertSame(SystemStatus::ERROR, $check['state']);
        $this->assertStringContainsString('Nessun backup', $check['detail']);
    }

    public function testBackupRecenteERegolare(): void
    {
        $file = $this->backups . '/backup_1800000000.dump';
        file_put_contents($file, str_repeat('x', 2048));
        touch($file, $this->now - 600);

        $check = $this->stato()->backupCheck();

        $this->assertSame(SystemStatus::OK, $check['state']);
        $this->assertStringContainsString('2 KB', $check['detail']);
        $this->assertStringContainsString('10 minuti fa', $check['detail']);
    }

    public function testBackupVecchioEUnAvviso(): void
    {
        $file = $this->backups . '/backup_1799700000.dump';
        file_put_contents($file, 'x');
        touch($file, $this->now - 3 * 86400);

        $check = $this->stato()->backupCheck();

        $this->assertSame(SystemStatus::WARNING, $check['state'], 'Un dump di tre giorni fa non è un backup');
        $this->assertStringContainsString('3 giorni fa', $check['detail']);
        $this->assertNotSame('', $check['hint']);
    }

    /**
     * Un file da 0 byte è più insidioso di nessun file: sembra un backup e
     * non lo è.
     */
    public function testBackupDaZeroByteEGuasto(): void
    {
        $file = $this->backups . '/backup_1800000000.dump';
        file_put_contents($file, '');
        touch($file, $this->now);

        $check = $this->stato()->backupCheck();

        $this->assertSame(SystemStatus::ERROR, $check['state']);
        $this->assertStringContainsString('0 byte', $check['detail']);
    }

    /**
     * Fra più dump conta il più recente, non il primo in ordine alfabetico.
     */
    public function testContaIlBackupPiuRecente(): void
    {
        $vecchio = $this->backups . '/backup_20261101_030000.dump';
        $nuovo   = $this->backups . '/backup_20261110_030000.dump';

        file_put_contents($vecchio, 'x');
        file_put_contents($nuovo, 'xx');

        touch($vecchio, $this->now - 5 * 86400);
        touch($nuovo, $this->now - 3600);

        $check = $this->stato()->backupCheck();

        $this->assertSame(SystemStatus::OK, $check['state']);
        $this->assertStringContainsString('backup_20261110_030000.dump', $check['detail']);
    }

    public function testSenzaEsecuzioniDelCronEAvviso(): void
    {
        $check = $this->stato()->cronCheck();

        $this->assertSame(SystemStatus::WARNING, $check['state']);
        $this->assertStringContainsString('cron-runner', $check['hint']);
    }

    public function testCronRecenteERegolare(): void
    {
        $file = $this->cron . '/sdi_poll.last';
        file_put_contents($file, (string) ($this->now - 900));
        touch($file, $this->now - 900);

        $check = $this->stato()->cronCheck();

        $this->assertSame(SystemStatus::OK, $check['state']);
        $this->assertStringContainsString('sdi_poll', $check['detail']);
        $this->assertStringContainsString('15 minuti fa', $check['detail']);
    }

    public function testCronFermoDaTroppoEAvviso(): void
    {
        $file = $this->cron . '/backup.last';
        file_put_contents($file, 's');
        touch($file, $this->now - 3 * 3600);

        $check = $this->stato()->cronCheck();

        $this->assertSame(SystemStatus::WARNING, $check['state']);
        $this->assertStringContainsString('3 ore fa', $check['detail']);
        $this->assertNotSame('', $check['hint']);
    }

    /**
     * Le migrazioni disponibili arrivano indicizzate per UID, la storia
     * riporta le versioni con i trattini: confrontare le chiavi faceva
     * risultare tutto da applicare su uno schema aggiornato.
     */
    public function testLeMigrazioniApplicateNonRisultanoPendenti(): void
    {
        $disponibili = [
            '20260817000000App\\Database\\Migrations\\CreateBaseTables' =>
                (object) ['version' => '2026-08-17-000000', 'class' => 'CreateBaseTables'],
            '20261111120000App\\Database\\Migrations\\EnsureIndexNames' =>
                (object) ['version' => '2026-11-11-120000', 'class' => 'EnsureIndexNames'],
        ];

        $applicate = ['2026-08-17-000000', '2026-11-11-120000'];

        $this->assertSame([], SystemStatus::pendingMigrations($disponibili, $applicate));
    }

    public function testLeMigrazioniMancantiVengonoElencate(): void
    {
        $disponibili = [
            '20260817000000App\\Database\\Migrations\\CreateBaseTables' =>
                (object) ['version' => '2026-08-17-000000'],
            '20261111120000App\\Database\\Migrations\\EnsureIndexNames' =>
                (object) ['version' => '2026-11-11-120000'],
        ];

        $this->assertSame(
            ['2026-11-11-120000'],
            SystemStatus::pendingMigrations($disponibili, ['2026-08-17-000000'])
        );
    }

    #[DataProvider('etaFornite')]
    public function testFormattaLEta(int $secondi, string $atteso): void
    {
        $this->assertSame($atteso, SystemStatus::age($secondi));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function etaFornite(): array
    {
        return [
            'adesso'      => [30, 'adesso'],
            'un minuto'   => [60, '1 minuto fa'],
            'due minuti'  => [120, '2 minuti fa'],
            "un'ora"      => [3600, '1 ora fa'],
            'due ore'     => [7200, '2 ore fa'],
            'un giorno'   => [86400, '1 giorno fa'],
            'due giorni'  => [172800, '2 giorni fa'],
        ];
    }
}
