<?php

namespace Tests\Unit;

use App\Libraries\LogRotation;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Rotazione di log e debugbar.
 *
 * @internal
 */
final class LogRotationTest extends CIUnitTestCase
{
    private string $logs;

    private string $debugbar;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir() . '/ip-logrotation-' . uniqid('', true);

        $this->logs     = $base . '/logs';
        $this->debugbar = $base . '/debugbar';

        mkdir($this->logs, 0777, true);
        mkdir($this->debugbar, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->logs, $this->debugbar] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }

            rmdir($dir);
        }

        rmdir(dirname($this->logs));

        parent::tearDown();
    }

    private function logFile(string $date, int $daysAgo): string
    {
        $file = $this->logs . '/log-' . $date . '.log';
        file_put_contents($file, str_repeat('riga di log' . PHP_EOL, 50));
        touch($file, time() - $daysAgo * 86400);

        return $file;
    }

    public function testComprimeSoloILogVecchi(): void
    {
        $vecchio = $this->logFile(date('Y-m-d', time() - 30 * 86400), 30);
        $recente = $this->logFile(date('Y-m-d', time() - 2 * 86400), 2);
        $oggi    = $this->logFile(date('Y-m-d'), 0);

        $result = LogRotation::run($this->logs, $this->debugbar);

        $this->assertSame(1, $result['compressed']);
        $this->assertFileDoesNotExist($vecchio);
        $this->assertFileExists($vecchio . '.gz');

        // Meno di una settimana: niente compressione, si legge ancora com'è.
        $this->assertFileExists($recente);
        $this->assertFileExists($oggi);
    }

    public function testEliminaICompressiMoltoVecchi(): void
    {
        $file = $this->logs . '/log-2020-01-01.log.gz';
        file_put_contents($file, 'vecchio');
        touch($file, time() - (LogRotation::DELETE_AFTER_DAYS + 10) * 86400);

        $result = LogRotation::run($this->logs, $this->debugbar);

        $this->assertSame(1, $result['deleted']);
        $this->assertFileDoesNotExist($file);
    }

    public function testEliminaLaDebugbarVecchia(): void
    {
        $vecchio = $this->debugbar . '/debugbar_2020.json';
        $nuovo   = $this->debugbar . '/debugbar_oggi.json';
        file_put_contents($vecchio, '{}');
        file_put_contents($nuovo, '{}');
        touch($vecchio, time() - (LogRotation::DEBUGBAR_DAYS + 5) * 86400);

        LogRotation::run($this->logs, $this->debugbar);

        $this->assertFileDoesNotExist($vecchio);
        $this->assertFileExists($nuovo);
    }

    public function testCartelleMancantiNonSonoUnErrore(): void
    {
        $result = LogRotation::run($this->logs . '/inesistente', $this->debugbar . '/inesistente');

        $this->assertSame(['compressed' => 0, 'deleted' => 0, 'failed' => 0], $result);
    }
}
