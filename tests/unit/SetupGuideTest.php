<?php

use App\Services\SetupGuide;
use App\Services\SystemStatus;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * La valutazione dei primi passi e il blocco del primo avvio sono pure: si
 * provano con i fatti in mano, senza database.
 *
 * @internal
 */
final class SetupGuideTest extends CIUnitTestCase
{
    private function facts(array $over = []): array
    {
        return $over + [
            'company_id' => 1, 'company_name' => 'Novatech di Fabio Iacovino', 'company_vat' => '02369560038',
            'company_addr' => 'Via Vergano 6', 'company_city' => 'Borgomanero', 'logo' => true, 'footer' => true,
            'tax_rates' => 3, 'sdi_chosen' => true, 'cron_key' => true, 'backup' => true, 'mfa' => true, 'production' => true,
        ];
    }

    private function stateOf(array $steps, string $key): bool
    {
        foreach ($steps as $s) {
            if ($s['key'] === $key) {
                return $s['done'];
            }
        }
        $this->fail('Passo mancante: ' . $key);
    }

    public function testEverythingDone(): void
    {
        $p = SetupGuide::progress(SetupGuide::steps($this->facts()));

        $this->assertSame($p['total'], $p['done']);
    }

    public function testFallbackCompanyNameIsNotDone(): void
    {
        // La migrazione lascia «Azienda» come nome provvisorio: non basta.
        $steps = SetupGuide::steps($this->facts(['company_name' => 'Azienda']));

        $this->assertFalse($this->stateOf($steps, 'company'));
    }

    public function testCompanyNeedsVatAndAddress(): void
    {
        $this->assertFalse($this->stateOf(SetupGuide::steps($this->facts(['company_vat' => ''])), 'company'));
        $this->assertFalse($this->stateOf(SetupGuide::steps($this->facts(['company_addr' => ' '])), 'company'));
        $this->assertFalse($this->stateOf(SetupGuide::steps($this->facts(['company_city' => ''])), 'company'));
    }

    public function testFreshInstallHasNothingDone(): void
    {
        $steps = SetupGuide::steps([
            'company_name' => 'Azienda', 'tax_rates' => 0,
        ]);

        $this->assertSame(0, SetupGuide::progress($steps)['done']);
    }

    public function testStepsLinkToCompanyFormOfTheActiveCompany(): void
    {
        $steps = SetupGuide::steps($this->facts(['company_id' => 7]));

        $this->assertSame('companies/form/7', $steps[0]['url']);
    }

    public function testOnlyErrorsBlockTheSetup(): void
    {
        $ok   = ['state' => SystemStatus::OK];
        $warn = ['state' => SystemStatus::WARNING];
        $err  = ['state' => SystemStatus::ERROR];

        $this->assertFalse(SetupGuide::blocking([$ok, $warn]));
        $this->assertTrue(SetupGuide::blocking([$ok, $err]));
        $this->assertFalse(SetupGuide::blocking([]));
    }
}
