<?php

use App\Services\QuoteDecision;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * La decisione su un preventivo (approvare/rifiutare) era ripetuta in quattro
 * punti con gli stessi stati e gli stessi messaggi. Qui la regola si prova da
 * sola: si decide solo da inviato (2) o visto (3).
 *
 * @internal
 */
final class QuoteDecisionTest extends CIUnitTestCase
{
    public function testSiPuoDecidereSoloSuPreventiviAperti(): void
    {
        foreach ([2, 3] as $stato) {
            $this->assertNull(QuoteDecision::error(['quote_status_id' => $stato], QuoteDecision::APPROVE));
            $this->assertNull(QuoteDecision::error(['quote_status_id' => $stato], QuoteDecision::REJECT));
        }
    }

    public function testGliAltriStatiDannoUnMessaggio(): void
    {
        foreach ([1, 4, 5, 0] as $stato) {
            $this->assertNotSame('', (string) QuoteDecision::error(['quote_status_id' => $stato], QuoteDecision::APPROVE));
            $this->assertNotSame('', (string) QuoteDecision::error(['quote_status_id' => $stato], QuoteDecision::REJECT));
        }
    }

    public function testApprovazioneERifiutoNonDiconoLaStessaCosa(): void
    {
        $approva = QuoteDecision::error(['quote_status_id' => 4], QuoteDecision::APPROVE);
        $rifiuta = QuoteDecision::error(['quote_status_id' => 4], QuoteDecision::REJECT);

        $this->assertNotSame($approva, $rifiuta);
    }

    public function testUnPreventivoSenzaStatoNonEApprovabile(): void
    {
        $this->assertNotNull(QuoteDecision::error([], QuoteDecision::APPROVE));
    }
}
