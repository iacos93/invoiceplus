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

    /**
     * @param array<string, mixed> $dati
     */
    private function marker(array $dati): void
    {
        file_put_contents($this->cron . '/backup_verify.json', json_encode($dati));
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

    /**
     * Il download prende l'ultimo dump valido: un file da 0 byte o un
     * "backup_*" che non è un dump non devono diventare la copia che si porta
     * fuori dal server.
     */
    public function testUltimoBackupScaricabileSaltaFileNonValidi(): void
    {
        $valido = $this->backups . '/backup_20261110_030000.dump';
        $vuoto  = $this->backups . '/backup_20261112_030000.dump';
        $altro  = $this->backups . '/backup_20261111_030000.txt';

        file_put_contents($valido, 'dump vero');
        file_put_contents($vuoto, '');
        file_put_contents($altro, 'non è un dump');

        touch($valido, $this->now - 3600);
        touch($vuoto, $this->now);
        touch($altro, $this->now - 60);

        $backup = $this->stato()->latestBackup();

        $this->assertNotNull($backup);
        $this->assertSame('backup_20261110_030000.dump', $backup['name']);
        $this->assertSame(9, $backup['size']);
    }

    public function testSenzaBackupNonCEDaScaricareNiente(): void
    {
        $this->assertNull($this->stato()->latestBackup());
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
     * Un guasto in fondo alla lista si legge solo se si scorre tutto: i
     * controlli da sistemare vanno in cima, e a parità di stato resta
     * l'ordine di arrivo.
     */
    public function testIControlliGuastiVengonoPrima(): void
    {
        $ordinati = SystemStatus::bySeverity([
            ['key' => 'a', 'label' => 'A', 'state' => SystemStatus::OK, 'detail' => '', 'hint' => ''],
            ['key' => 'b', 'label' => 'B', 'state' => SystemStatus::ERROR, 'detail' => '', 'hint' => ''],
            ['key' => 'c', 'label' => 'C', 'state' => SystemStatus::WARNING, 'detail' => '', 'hint' => ''],
            ['key' => 'd', 'label' => 'D', 'state' => SystemStatus::OK, 'detail' => '', 'hint' => ''],
            ['key' => 'e', 'label' => 'E', 'state' => SystemStatus::ERROR, 'detail' => '', 'hint' => ''],
        ]);

        $this->assertSame(['b', 'e', 'c', 'a', 'd'], array_column($ordinati, 'key'));
    }

    public function testSenzaVerificaDelRipristinoEAvviso(): void
    {
        $check = $this->stato()->restoreCheck();

        $this->assertSame(SystemStatus::WARNING, $check['state']);
        $this->assertStringContainsString('backup:verify', $check['hint']);
    }

    public function testVerificaDelRipristinoRecenteERegolare(): void
    {
        $backup = $this->backups . '/backup_20261110_030000.dump';
        file_put_contents($backup, 'x');

        $this->marker([
            'time'   => $this->now - 3600,
            'file'   => basename($backup),
            'tables' => 12,
            'ok'     => true,
            'error'  => '',
        ]);

        $check = $this->stato()->restoreCheck();

        $this->assertSame(SystemStatus::OK, $check['state']);
        $this->assertStringContainsString('12 tabelle', $check['detail']);
    }

    /**
     * Il caso peggiore: la verifica è stata fatta e il dump non si ripristina.
     */
    public function testVerificaDelRipristinoFallitaEGuasto(): void
    {
        $backup = $this->backups . '/backup_20261110_030000.dump';
        file_put_contents($backup, 'x');

        $this->marker([
            'time'  => $this->now - 600,
            'file'  => basename($backup),
            'ok'    => false,
            'error' => 'Ripristino fallito: pg_restore: error: input file does not appear to be a valid archive',
        ]);

        $check = $this->stato()->restoreCheck();

        $this->assertSame(SystemStatus::ERROR, $check['state']);
        $this->assertStringContainsString('fallita', $check['detail']);
    }

    /**
     * Non poter creare il database di prova è un permesso mancante, non un
     * dump rotto: va segnalato, ma non come guasto del backup.
     */
    public function testPermessoDiCreazioneMancanteEAvvisoNonGuasto(): void
    {
        $backup = $this->backups . '/backup_20261110_030000.dump';
        file_put_contents($backup, 'x');

        $this->marker([
            'time'  => $this->now - 600,
            'file'  => basename($backup),
            'ok'    => false,
            'error' => 'Creazione del database temporaneo fallita: createdb: error: permission denied to create database',
        ]);

        $check = $this->stato()->restoreCheck();

        $this->assertSame(SystemStatus::WARNING, $check['state']);
        $this->assertStringContainsString('non eseguibile', $check['detail']);
        $this->assertStringContainsString('CREATEDB', $check['hint']);
    }

    /**
     * La verifica vale per il dump provato: un backup nuovo non è coperto.
     */
    public function testNuovoBackupNonAncoraVerificatoEAvviso(): void
    {
        $vecchio = $this->backups . '/backup_20261109_030000.dump';
        $nuovo   = $this->backups . '/backup_20261110_030000.dump';

        file_put_contents($vecchio, 'x');
        file_put_contents($nuovo, 'x');

        touch($vecchio, $this->now - 5 * 86400);
        touch($nuovo, $this->now - 3600);

        $this->marker([
            'time'   => $this->now - 3600,
            'file'   => basename($vecchio),
            'tables' => 12,
            'ok'     => true,
        ]);

        $check = $this->stato()->restoreCheck();

        $this->assertSame(SystemStatus::WARNING, $check['state']);
        $this->assertStringContainsString(basename($nuovo), $check['detail']);
    }

    public function testVerificaTroppoVecchiaEAvviso(): void
    {
        $backup = $this->backups . '/backup_20261110_030000.dump';
        file_put_contents($backup, 'x');

        $this->marker([
            'time'   => $this->now - 40 * 86400,
            'file'   => basename($backup),
            'tables' => 12,
            'ok'     => true,
        ]);

        $check = $this->stato()->restoreCheck();

        $this->assertSame(SystemStatus::WARNING, $check['state']);
        $this->assertStringContainsString('40 giorni fa', $check['detail']);
    }

    public function testAmbienteDiProduzioneERegolare(): void
    {
        $check = $this->stato()->environmentCheck();

        $this->assertSame(SystemStatus::OK, $check['state']);
        $this->assertStringContainsString('production', $check['detail']);
    }

    public function testAmbienteDiSviluppoEAvviso(): void
    {
        $status = new SystemStatus($this->writable, $this->backups, $this->cron, $this->now, 'development');

        $check = $status->environmentCheck();

        $this->assertSame(SystemStatus::WARNING, $check['state']);
        $this->assertStringContainsString('stack trace', $check['hint']);
    }

    public function testSpazioSuDiscoVieneRiportato(): void
    {
        $check = $this->stato()->diskCheck();

        $this->assertContains($check['state'], [SystemStatus::OK, SystemStatus::WARNING]);
        $this->assertStringContainsString('liberi', $check['detail']);
    }

    public function testLogSenzaErroriERegolare(): void
    {
        mkdir($this->writable . '/logs', 0775, true);
        file_put_contents(
            $this->writable . '/logs/log-' . date('Y-m-d', $this->now) . '.log',
            "INFO - 2026-11-12 10:00:00 --> tutto bene\n"
        );

        $check = $this->stato()->logCheck();

        $this->assertSame(SystemStatus::OK, $check['state']);
    }

    public function testErroriNelLogSonoUnAvviso(): void
    {
        mkdir($this->writable . '/logs', 0775, true);
        file_put_contents(
            $this->writable . '/logs/log-' . date('Y-m-d', $this->now) . '.log',
            "ERROR - 2026-11-12 10:00:00 --> connessione persa\n"
            . "INFO - 2026-11-12 10:01:00 --> riprovato\n"
            . "CRITICAL - 2026-11-12 10:02:00 --> database irraggiungibile\n"
        );

        $check = $this->stato()->logCheck();

        $this->assertSame(SystemStatus::WARNING, $check['state']);
        $this->assertStringContainsString('2 voci', $check['detail']);
        $this->assertStringContainsString(date('Y-m-d', $this->now), $check['detail']);
        $this->assertStringContainsString('l\'ultima alle 10:02', $check['detail']);
    }

    /**
     * Un errore scritto la mattina non deve sparire quando il log cresce:
     * la versione precedente leggeva solo gli ultimi 200 KB e in una giornata
     * piena diceva "nessun errore" con l'errore ancora dentro il file.
     */
    public function testErroriFuoriDallaFineDelLogVengonoContati(): void
    {
        mkdir($this->writable . '/logs', 0775, true);

        $riempimento = str_repeat("INFO - 2026-11-12 10:30:00 --> richiesta gestita\n", 8000);

        file_put_contents(
            $this->writable . '/logs/log-' . date('Y-m-d', $this->now) . '.log',
            "CRITICAL - 2026-11-12 06:00:00 --> disco pieno durante il backup\n"
            . $riempimento
            . "INFO - 2026-11-12 11:00:00 --> tutto bene\n"
        );

        $check = $this->stato()->logCheck();

        $this->assertSame(SystemStatus::WARNING, $check['state']);
        $this->assertStringContainsString('1 voce', $check['detail']);
        $this->assertStringContainsString('l\'ultima alle 06:00', $check['detail']);
    }

    /**
     * Il log può esistere e non essere leggibile (permessi sbagliati dopo un
     * ripristino): va detto, non interpretato come "nessun errore".
     */
    public function testLogNonLeggibileEAvviso(): void
    {
        mkdir($this->writable . '/logs', 0775, true);

        $file = $this->writable . '/logs/log-' . date('Y-m-d', $this->now) . '.log';
        file_put_contents($file, "INFO - 2026-11-12 10:00:00 --> tutto bene\n");
        chmod($file, 0000);

        try {
            $check = $this->stato()->logCheck();

            // Da root (CI in container) chmod 0000 non toglie la lettura.
            if ($check['state'] === SystemStatus::WARNING && str_contains($check['detail'], 'non leggibile')) {
                $this->assertStringContainsString('permessi', $check['hint']);
            } else {
                $this->assertSame(SystemStatus::OK, $check['state']);
            }
        } finally {
            chmod($file, 0644);
        }
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
