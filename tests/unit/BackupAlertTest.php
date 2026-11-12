<?php

use App\Libraries\BackupAlert;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il freno alle notifiche di backup fallito.
 *
 * Senza, il cron che riprova a ogni giro manderebbe decine di email identiche
 * per lo stesso guasto. Il caso da evitare è opposto e altrettanto brutto:
 * un avviso che non arriva mai perché il segnaposto è rimasto da un guasto
 * vecchio, quindi un backup riuscito deve azzerarlo.
 *
 * @internal
 */
final class BackupAlertTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/ip-alert-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/backup_failed.last');
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function testIlPrimoGuastoAvvisa(): void
    {
        $this->assertTrue((new BackupAlert($this->dir, 1_800_000_000))->shouldNotify());
    }

    /** Due giri ravvicinati non devono produrre due email. */
    public function testUnSecondoGuastoRavvicinatoNonAvvisa(): void
    {
        $now = 1_800_000_000;

        $alert = new BackupAlert($this->dir, $now);
        $alert->markNotified();

        $this->assertFalse((new BackupAlert($this->dir, $now + 3600))->shouldNotify(), 'Un\'ora dopo la prima notifica non se ne manda un\'altra');
        $this->assertTrue((new BackupAlert($this->dir, $now + BackupAlert::THROTTLE + 1))->shouldNotify(), 'Passate dodici ore si torna ad avvisare');
    }

    /**
     * Un backup riuscito azzera il segnaposto: se il guasto torna il giorno
     * dopo, l'avviso deve partire subito, non aspettare la scadenza del freno
     * del guasto precedente.
     */
    public function testUnBackupRiuscitoAzzeraIlFreno(): void
    {
        $now = 1_800_000_000;

        $alert = new BackupAlert($this->dir, $now);
        $alert->markNotified();
        $alert->clear();

        $this->assertTrue((new BackupAlert($this->dir, $now + 60))->shouldNotify());
    }
}
