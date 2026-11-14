<?php

use App\Libraries\BankReconciler;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il suggerimento di abbinamento: chi lo legge decide quale fattura segnare
 * come incassata. Un candidato sbagliato in cima, accettato per fretta, produce
 * un incasso su un documento che non lo ha ricevuto, e l'errore salta fuori
 * mesi dopo. Qui si fissano le regole che devono reggere: il verso del
 * movimento, le note di credito, le bozze, la tolleranza, la finestra di date
 * e l'ordine dei pari merito.
 *
 * Le fatture e le spese si iniettano in memoria: la classe le cerca nel
 * database solo la prima volta, e questi test non ne hanno bisogno.
 *
 * @internal
 */
final class BankReconcilerMatchingTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper('ip_helpers');
    }

    private function fattura(int $id, string $numero, float $totale, float $saldo, string $data, int $stato = 2): array
    {
        return [
            'invoice_id'           => $id,
            'invoice_number'       => $numero,
            'invoice_total'        => $totale,
            'invoice_balance'      => $saldo,
            'invoice_date_created' => $data,
            'invoice_status_id'    => $stato,
            'client_name'          => 'Cliente' . $id,
            'client_surname'       => '',
        ];
    }

    private function spesa(int $id, string $numero, float $totale, float $pagato, string $data): array
    {
        return [
            'expense_id'     => $id,
            'expense_number' => $numero,
            'expense_total'  => $totale,
            'expense_paid'   => $pagato,
            'expense_date'   => $data,
            'supplier_name'  => 'Fornitore' . $id,
        ];
    }

    private function riconciliatore(array $fatture, array $spese = []): BankReconciler
    {
        $r = new BankReconciler();

        foreach (['invoicesCache' => $fatture, 'expensesCache' => $spese] as $proprieta => $valore) {
            $p = new ReflectionProperty(BankReconciler::class, $proprieta);
            $p->setValue($r, $valore);
        }

        return $r;
    }

    private function movimento(float $importo, string $data, string $causale = ''): array
    {
        return [
            'transaction_amount'      => $importo,
            'transaction_date'        => $data,
            'transaction_description' => $causale,
        ];
    }

    public function testUnIncassoCercaFraLeFattureEUnaUscitaFraLeSpese(): void
    {
        $r = $this->riconciliatore(
            [$this->fattura(1, '2026/10', 100.00, 100.00, '2026-03-01')],
            [$this->spesa(7, 'F-9', 100.00, 0.0, '2026-03-01')]
        );

        $entrata = $r->candidatesFor($this->movimento(100.00, '2026-03-05'));
        $uscita  = $r->candidatesFor($this->movimento(-100.00, '2026-03-05'));

        $this->assertSame(['invoice'], array_column($entrata, 'type'));
        $this->assertSame(['expense'], array_column($uscita, 'type'));
    }

    public function testLeBozzeNonSonoMaiCandidate(): void
    {
        $r = $this->riconciliatore([$this->fattura(1, '2026/10', 100.00, 100.00, '2026-03-01', 1)]);

        $this->assertSame([], $r->candidatesFor($this->movimento(100.00, '2026-03-05')));
    }

    public function testImportoDiversoOltreLaToleranzaNonEUnSegnale(): void
    {
        $r = $this->riconciliatore([$this->fattura(1, '2026/10', 100.00, 100.00, '2026-03-01')]);

        $this->assertCount(1, $r->candidatesFor($this->movimento(100.02, '2026-03-05')), 'Due centesimi di differenza: ancora lo stesso importo');
        $this->assertSame([], $r->candidatesFor($this->movimento(100.03, '2026-03-05')), 'Tre centesimi: non e\' piu\' un candidato');
    }

    public function testUnBonificoPuoSaldareIlResiduoDopoUnAcconto(): void
    {
        $r = $this->riconciliatore([$this->fattura(1, '2026/10', 1000.00, 400.00, '2026-03-01')]);

        $perResiduo = $r->candidatesFor($this->movimento(400.00, '2026-03-05'));
        $perTotale  = $r->candidatesFor($this->movimento(1000.00, '2026-03-05'));

        $this->assertCount(1, $perResiduo);
        $this->assertCount(1, $perTotale);
        $this->assertContains('importo corrispondente', $perResiduo[0]['reasons']);
    }

    public function testIlNumeroNellaCausalePesaPiuDellImporto(): void
    {
        $r = $this->riconciliatore([
            $this->fattura(1, '2026/100', 250.00, 250.00, '2026-03-01'),
            $this->fattura(2, '2026/101', 999.00, 999.00, '2026-03-01'),
        ]);

        $candidati = $r->candidatesFor($this->movimento(250.00, '2026-03-05', 'BONIFICO SALDO FATT 101'));

        // La 101 e' citata ma ha un altro importo (60 + data); la 100 ha solo
        // l'importo (30 + data). Entrambe proposte, la citata in cima.
        $this->assertSame([2, 1], array_column($candidati, 'id'));
        $this->assertContains('numero documento nella causale', $candidati[0]['reasons']);
    }

    public function testLaSolaVicinanzaDiDataNonBasta(): void
    {
        $r = $this->riconciliatore([$this->fattura(1, '2026/10', 100.00, 100.00, '2026-03-05')]);

        $this->assertSame([], $r->candidatesFor($this->movimento(77.77, '2026-03-05', 'nessun riferimento')));
    }

    public function testOltreUnAnnoDiDistanzaIlDocumentoNonEPiuCandidato(): void
    {
        $r = $this->riconciliatore([$this->fattura(1, '2026/10', 100.00, 100.00, '2025-03-01')]);

        $this->assertCount(1, $r->candidatesFor($this->movimento(100.00, '2026-02-28')), 'Entro 365 giorni');
        $this->assertSame([], $r->candidatesFor($this->movimento(100.00, '2026-03-05')), 'Oltre 365 giorni');
    }

    public function testAParitaDiSegnaliVinceLaDataPiuVicina(): void
    {
        $r = $this->riconciliatore([
            $this->fattura(1, '2026/10', 100.00, 100.00, '2025-12-01'),
            $this->fattura(2, '2026/11', 100.00, 100.00, '2026-03-01'),
        ]);

        $candidati = $r->candidatesFor($this->movimento(100.00, '2026-03-05'));

        $this->assertSame([2, 1], array_column($candidati, 'id'));
        $this->assertGreaterThan($candidati[1]['score'], $candidati[0]['score']);
    }

    public function testUnaNotaDiCreditoEmessaSiAbbinaAUnaUscita(): void
    {
        $r = $this->riconciliatore([$this->fattura(1, 'NC/5', -300.00, -300.00, '2026-03-01')]);

        $uscita  = $r->candidatesFor($this->movimento(-300.00, '2026-03-05'));
        $entrata = $r->candidatesFor($this->movimento(300.00, '2026-03-05'));

        $this->assertCount(1, $uscita, 'Il rimborso di una nota di credito e\' un\'uscita');
        $this->assertStringStartsWith('Nota di credito', $uscita[0]['label']);
        $this->assertSame([], $entrata, 'Non va proposta per un incasso');
    }

    public function testUnaSpesaGiaPagataPerIntero(): void
    {
        // Residuo zero, ma il totale coincide: e' ancora un candidato (un
        // pagamento registrato prima di importare l'estratto conto).
        $r = $this->riconciliatore([], [$this->spesa(7, 'F-9', 100.00, 100.00, '2026-03-01')]);

        $this->assertCount(1, $r->candidatesFor($this->movimento(-100.00, '2026-03-05')));
    }

    public function testUnDocumentoAZeroNonECandidato(): void
    {
        $r = $this->riconciliatore([$this->fattura(1, '2026/10', 0.0, 0.0, '2026-03-01')]);

        $this->assertSame([], $r->candidatesFor($this->movimento(0.0, '2026-03-05', 'FATT 2026/10')));
    }

    public function testNonSiProponganoPiuDiCinqueCandidati(): void
    {
        $fatture = [];
        for ($i = 1; $i <= 8; $i++) {
            $fatture[] = $this->fattura($i, '2026/' . (200 + $i), 100.00, 100.00, '2026-03-0' . min($i, 9));
        }

        $this->assertCount(5, $this->riconciliatore($fatture)->candidatesFor($this->movimento(100.00, '2026-03-05')));
    }

    public function testUnaDataIllegibileEscludeIlCandidato(): void
    {
        $r = $this->riconciliatore([$this->fattura(1, '2026/10', 100.00, 100.00, '')]);

        $this->assertSame([], $r->candidatesFor($this->movimento(100.00, '2026-03-05')));
    }
}
