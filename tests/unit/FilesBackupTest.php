<?php

use App\Libraries\DatabaseBackup;
use App\Libraries\FilesBackup;
use App\Services\SystemStatus;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * La copia dei file caricati.
 *
 * Il dump del database non contiene gli XML delle fatture ricevute ne' gli
 * allegati. Qui si fissa che l'archivio contenga davvero tutto con i percorsi
 * giusti, che non si scambi per un dump, che non lasci mezzi archivi in giro e
 * che la rotazione non si mangi i dump del database.
 *
 * @internal
 */
final class FilesBackupTest extends CIUnitTestCase
{
    private string $radice = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->radice = sys_get_temp_dir() . '/files-backup-' . bin2hex(random_bytes(4));
        mkdir($this->radice . '/uploads/expenses/incoming', 0777, true);
        mkdir($this->radice . '/uploads/customer_files', 0777, true);
        mkdir($this->radice . '/backups', 0777, true);

        file_put_contents($this->radice . '/uploads/index.html', '<html></html>');
        file_put_contents($this->radice . '/uploads/expenses/12.xml', '<Fattura>12</Fattura>');
        file_put_contents($this->radice . '/uploads/expenses/incoming/IT01_AAAAA.xml', '<Fattura>A</Fattura>');
        file_put_contents($this->radice . '/uploads/customer_files/contratto.pdf', "%PDF-1.4\nprova");
    }

    protected function tearDown(): void
    {
        $this->rimuovi($this->radice);

        parent::tearDown();
    }

    private function rimuovi(string $dir): void
    {
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $voce) {
            if (in_array(basename($voce), ['.', '..'], true)) {
                continue;
            }

            is_dir($voce) && ! is_link($voce) ? $this->rimuovi($voce) : @unlink($voce);
        }

        @rmdir($dir);
    }

    private function backup(): FilesBackup
    {
        return new FilesBackup($this->radice . '/uploads', $this->radice . '/backups');
    }

    public function testLArchivioContieneTuttiIFileConIPercorsiRelativi(): void
    {
        $r = $this->backup()->run();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($r['file']) === true);

        $nomi = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nomi[] = $zip->getNameIndex($i);
        }
        sort($nomi);

        $this->assertSame(
            ['customer_files/contratto.pdf', 'expenses/12.xml', 'expenses/incoming/IT01_AAAAA.xml', 'index.html'],
            $nomi,
            'Percorsi relativi a uploads/: un ripristino li rimette dove stavano'
        );
        $this->assertSame('<Fattura>12</Fattura>', $zip->getFromName('expenses/12.xml'));
        $this->assertSame(4, $r['files']);
        $zip->close();
    }

    public function testNonSiConfondeConUnDump(): void
    {
        $r = $this->backup()->run();

        $this->assertStringContainsString('/files_', $r['file']);
        $this->assertSame([], glob($this->radice . '/backups/backup_*') ?: [], 'Stato del sistema e il download cercano backup_*: non devono trovare lo ZIP');
    }

    public function testIPermessiSonoRistretti(): void
    {
        $r = $this->backup()->run();

        $this->assertSame('0600', substr(sprintf('%o', fileperms($r['file'])), -4), 'Contiene dati fiscali e personali');
    }

    public function testNonLasciaArchiviProvvisori(): void
    {
        $this->backup()->run();

        $this->assertSame([], glob($this->radice . '/backups/*.tmp') ?: []);
    }

    public function testNonSegueICollegamentiSimbolici(): void
    {
        $fuori = $this->radice . '/segreto.txt';
        file_put_contents($fuori, 'fuori da uploads');
        symlink($fuori, $this->radice . '/uploads/collegamento.txt');

        $r   = $this->backup()->run();
        $zip = new ZipArchive();
        $zip->open($r['file']);

        $this->assertFalse($zip->locateName('collegamento.txt'), 'Un collegamento potrebbe portare fuori dalla cartella');
        $zip->close();
    }

    public function testLaRotazioneTieneGliUltimiQuattordiciESoloDelSuoPrefisso(): void
    {
        $dir = $this->radice . '/backups';

        for ($i = 1; $i <= 15; $i++) {
            file_put_contents($dir . sprintf('/files_202501%02d_000000.zip', $i), 'x');
        }
        file_put_contents($dir . '/backup_20250101_000000.dump', 'dump-importante');

        $r = $this->backup()->run();

        $this->assertSame(2, $r['removed'], '15 vecchi + 1 nuovo = 16: ne restano 14');
        $this->assertCount(FilesBackup::KEEP, glob($dir . '/files_*.zip') ?: []);
        $this->assertFileExists($dir . '/backup_20250101_000000.dump', 'La rotazione degli archivi non tocca i dump');
    }

    public function testSenzaCartellaSiFermaConIlMotivo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non trovata');

        (new FilesBackup($this->radice . '/non-esiste', $this->radice . '/backups'))->run();
    }

    public function testUnaCartellaVuotaNonProduceUnArchivioVuoto(): void
    {
        mkdir($this->radice . '/vuota');

        try {
            (new FilesBackup($this->radice . '/vuota', $this->radice . '/backups'))->run();
            $this->fail('Doveva fermarsi');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Nessun file', $e->getMessage());
        }

        $this->assertSame([], glob($this->radice . '/backups/files_*') ?: [], 'Nessun archivio finto');
    }

    public function testLaPaginaDiStatoSegnalaLaCopiaMancanteERecente(): void
    {
        $servizio = new SystemStatus($this->radice, $this->radice . '/backups', $this->radice . '/cron', time());

        $this->assertSame(SystemStatus::WARNING, $servizio->filesBackupCheck()['state'], 'Mai fatta');

        $this->backup()->run();
        $this->assertSame(SystemStatus::OK, $servizio->filesBackupCheck()['state']);

        $vecchio = new SystemStatus($this->radice, $this->radice . '/backups', $this->radice . '/cron', time() + 5 * 86400);
        $r       = $vecchio->filesBackupCheck();

        $this->assertSame(SystemStatus::WARNING, $r['state'], 'Vecchia di cinque giorni mentre il dump potrebbe essere di oggi');
        $this->assertStringContainsString('ha smesso di riuscire', $r['hint']);
    }

    public function testIDumpDelDatabaseNonVengonoContatiComeCopiaDeiFile(): void
    {
        file_put_contents($this->radice . '/backups/backup_20260101_000000.dump', 'dump');

        $servizio = new SystemStatus($this->radice, $this->radice . '/backups', $this->radice . '/cron', time());

        $this->assertSame(SystemStatus::WARNING, $servizio->filesBackupCheck()['state'], 'Un dump non e\' una copia dei file');
        $this->assertSame(SystemStatus::OK, $servizio->backupCheck()['state'], 'E il controllo del database lo vede');
        $this->assertSame(DatabaseBackup::KEEP, FilesBackup::KEEP);
    }
}
