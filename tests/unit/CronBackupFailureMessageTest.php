<?php

use App\Controllers\Cron;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il corpo HTTP di un backup fallito non deve contenere il messaggio
 * dell'eccezione (SQLSTATE, percorsi, comandi): dice cosa è successo e dove
 * guardare. Il dettaglio resta nel log e nell'email all'amministratore.
 *
 * La costruzione del testo è isolata in un metodo statico puro, così si può
 * provare senza database né pg_dump.
 *
 * @internal
 */
final class CronBackupFailureMessageTest extends CIUnitTestCase
{
    public function testMessaggioDelDatabaseSenzaDettagliInterni(): void
    {
        $messaggio = Cron::backupFailureMessage();

        $this->assertSame('Backup fallito. Dettagli nel log e in Stato del sistema.', $messaggio);
        $this->assertStringNotContainsString('SQLSTATE', $messaggio);
        $this->assertStringNotContainsString('/', $messaggio);
        $this->assertStringNotContainsString('pg_dump', $messaggio);
    }

    public function testMessaggioDeiFileCaricatiIndicaLaParteFallita(): void
    {
        $this->assertSame(
            'Backup fallito (file caricati). Dettagli nel log e in Stato del sistema.',
            Cron::backupFailureMessage('file caricati')
        );
    }
}
