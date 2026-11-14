<?php

namespace Tests\Unit;

use App\Services\SystemStatus;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il controllo "Dati senza azienda" nella pagina Stato del sistema.
 *
 * Il servizio riceve i conteggi dall'esterno in questi test: così non serve un
 * database e si prova solo la traduzione dei numeri in stato, dettaglio e
 * suggerimento. Il conteggio vero è coperto da CompanyOrphanReportTest.
 *
 * @internal
 */
final class CompanyOrphansStatusTest extends CIUnitTestCase
{
    public function testSenzaOrfaniIlControlloEPositivo(): void
    {
        $controllo = $this->controllo([
            'total'  => 0,
            'tables' => [
                'ip_invoices' => ['nulls' => 0, 'total' => 10],
                'ip_clients'  => ['nulls' => 0, 'total' => 4],
            ],
        ]);

        $this->assertSame('company_orphans', $controllo['key']);
        $this->assertSame(SystemStatus::OK, $controllo['state']);
        $this->assertSame('', $controllo['hint']);
    }

    public function testConOrfaniAvvisaENominaIlComando(): void
    {
        $controllo = $this->controllo([
            'total'  => 3,
            'tables' => [
                'ip_invoices' => ['nulls' => 2, 'total' => 10],
                'ip_clients'  => ['nulls' => 1, 'total' => 4],
                'ip_expenses' => ['nulls' => 0, 'total' => 7],
            ],
        ]);

        $this->assertSame(SystemStatus::WARNING, $controllo['state']);

        // Il dettaglio conta le tabelle che hanno davvero orfani, non tutte
        // quelle esaminate.
        $this->assertStringContainsString('3 righe in 2 tabelle', $controllo['detail']);

        $this->assertStringContainsString('companies:orphans --fix', $controllo['hint']);
        $this->assertStringContainsString('visibili a tutte le aziende', $controllo['hint']);
    }

    public function testIlControlloCompareNellElencoDellaPagina(): void
    {
        $chiavi = array_column($this->servizio()->checks(), 'key');

        $this->assertContains('company_orphans', $chiavi);
    }

    /**
     * @param array{total: int, tables: array<string, array{nulls: int, total: int}>} $conteggi
     *
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    private function controllo(array $conteggi): array
    {
        return $this->servizio()->orphanCheck($conteggi);
    }

    private function servizio(): SystemStatus
    {
        $writable = sys_get_temp_dir() . '/status-orphans-' . bin2hex(random_bytes(4));

        return new SystemStatus($writable, $writable . '/backups', $writable . '/cron', time());
    }
}
