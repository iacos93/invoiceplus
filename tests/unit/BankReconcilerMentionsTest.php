<?php

use App\Libraries\BankReconciler;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il numero documento nella causale vale 60 punti, cioè decide da solo chi
 * sta in cima ai candidati: un confronto per sottostringa lo assegnava anche
 * a cifre di un CRO o di un IBAN. Qui si fissa cosa conta come citazione.
 *
 * @internal
 */
final class BankReconcilerMentionsTest extends CIUnitTestCase
{
    private function mentions(string $description, string $number): bool
    {
        // Senza setAccessible(): da PHP 8.1 invoke() raggiunge anche i metodi
        // privati, e la chiamata emetteva una deprecazione a ogni esecuzione
        // (la funzione è deprecata da PHP 8.5). Stessa scelta, con lo stesso
        // motivo scritto accanto, in ContactCsvRoundTripTest.
        $method = new ReflectionMethod(BankReconciler::class, 'mentions');

        return $method->invoke(new BankReconciler(), $description, $number);
    }

    public function testCifreDentroUnAltroNumeroNonSonoUnaCitazione(): void
    {
        $this->assertFalse($this->mentions('BONIFICO CRO 0034471209', '447'));
        $this->assertFalse($this->mentions('IT60X0542811101000000447123', '2026/A/447'));
    }

    public function testNumeroCompletoConSeparatoriDiversi(): void
    {
        foreach (['SALDO FATT 2026/A/447', 'saldo 2026-a-447', 'FT 2026 A 000447', 'RIF.2026A447'] as $causale) {
            $this->assertTrue($this->mentions($causale, '2026/A/447'), $causale);
        }
    }

    public function testSoloProgressivo(): void
    {
        $this->assertTrue($this->mentions('PAGAMENTO FATTURA 447', '2026/A/447'));
        $this->assertTrue($this->mentions('FATT 447/2026', '2026/A/447'));
        // L'anno da solo non identifica nessun documento.
        $this->assertFalse($this->mentions('CANONE 2026', '2026/A/447'));
        // Progressivo troppo corto: coinciderebbe con giorni e mesi.
        $this->assertFalse($this->mentions('BONIFICO DEL 12/03', '2026/12'));
    }

    public function testNumeroSemplice(): void
    {
        $this->assertTrue($this->mentions('FATTURA N. 0447', '447'));
        $this->assertFalse($this->mentions('FATTURA N. 44', '44'));
    }
}
