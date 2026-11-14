<?php

use App\Libraries\RibaFlow;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il flusso RiBa è un file a record di 120 caratteri: un campo spostato di una
 * colonna produce un file che la banca accetta e interpreta male (incassi sul
 * conto sbagliato, importi diversi). Qui si fissano posizione e lunghezza dei
 * campi che contano, non il testo di ogni record.
 *
 * Le posizioni sono quelle del tracciato CBI usato da RibaFlow (1-based nel
 * commento, 0-based nei substr()).
 *
 * @internal
 */
final class RibaFlowTest extends CIUnitTestCase
{
    private const CREDITOR_IBAN = 'IT60X0542811101000000123456';  // ABI 05428, CAB 11101
    private const DEBTOR_IBAN   = 'IT28W8000000292100645211208';  // ABI 80000, CAB 00292

    private function azienda(): array
    {
        return ['company_name' => 'Novatech Srl', 'company_tax_code' => '01234567890', 'company_vat_id' => '01234567890'];
    }

    private function conto(): array
    {
        return ['bank_account_iban' => self::CREDITOR_IBAN, 'bank_account_sia_code' => 'ab123'];
    }

    private function fattura(array $extra = []): array
    {
        return $extra + [
            'invoice_number'   => '2026/15',
            'invoice_balance'  => 123.45,
            'invoice_total'    => 999.00,
            'invoice_date_due' => '2026-01-15',
            'client_name'      => 'Caffè Società',
            'client_surname'   => '',
            'client_iban'      => self::DEBTOR_IBAN,
            'client_tax_code'  => 'rssmra80a01h501u',
            'client_address_1' => 'Via Roma 1',
            'client_zip'       => '00100',
            'client_city'      => 'Roma',
            'client_state'     => 'RM',
        ];
    }

    /** @return list<string> */
    private function righe(array $risultato): array
    {
        return explode("\r\n", rtrim($risultato['content'], "\r\n"));
    }

    public function testOgniRecordELungoCentoVentiCaratteri(): void
    {
        $risultato = (new RibaFlow())->build($this->azienda(), $this->conto(), [$this->fattura(), $this->fattura(['invoice_number' => '2026/16'])]);

        foreach ($this->righe($risultato) as $i => $riga) {
            $this->assertSame(120, strlen($riga), 'Il record ' . ($i + 1) . ' deve essere lungo 120 caratteri');
        }
    }

    public function testSequenzaDeiRecordPerOgniRicevuta(): void
    {
        $risultato = (new RibaFlow())->build($this->azienda(), $this->conto(), [$this->fattura(), $this->fattura()]);
        $righe     = $this->righe($risultato);

        // IB + 7 record x 2 ricevute + EF
        $this->assertCount(16, $righe);
        $this->assertSame('IB', substr($righe[0], 0, 2));
        $this->assertSame('EF', substr($righe[15], 0, 2));

        $attesi = ['14', '20', '30', '40', '50', '51', '70'];
        foreach ([1, 8] as $inizio) {
            foreach ($attesi as $k => $tipo) {
                $this->assertSame($tipo, substr($righe[$inizio + $k], 1, 2), "Record {$tipo} fuori posto");
            }
        }
    }

    public function testRecord14PortaImportoScadenzaEBanche(): void
    {
        $righe = $this->righe((new RibaFlow())->build($this->azienda(), $this->conto(), [$this->fattura()]));
        $r14   = $righe[1];

        $this->assertSame('150126', substr($r14, 10, 6), 'Scadenza GGMMAA');
        $this->assertSame('30000', substr($r14, 16, 5), 'Causale incasso RiBa');
        $this->assertSame('0000000012345', substr($r14, 21, 13), 'Importo in centesimi su 13 cifre: vale il saldo, non il totale');
        $this->assertSame('05428', substr($r14, 35, 5), 'ABI del creditore');
        $this->assertSame('11101', substr($r14, 40, 5), 'CAB del creditore');
        $this->assertSame('80000', substr($r14, 57, 5), 'ABI della banca del debitore');
        $this->assertSame('00292', substr($r14, 62, 5), 'CAB della banca del debitore');
        $this->assertSame('AB123', substr($r14, 70, 5), 'Il SIA si maiuscola e sta sul conto');
    }

    public function testLaCodaRiportaNumeroTotaleERighe(): void
    {
        $risultato = (new RibaFlow())->build($this->azienda(), $this->conto(), [
            $this->fattura(['invoice_balance' => 100.10]),
            $this->fattura(['invoice_balance' => 50.05]),
        ]);
        $ef = $this->righe($risultato)[15];

        $this->assertSame('0000002', substr($ef, 48, 7), 'Numero ricevute');
        $this->assertSame('000000000015015', substr($ef, 55, 15), 'Totale in centesimi: 100,10 + 50,05');
        $this->assertSame('0000016', substr($ef, 85, 7), 'Righe del file');
        $this->assertSame(2, $risultato['count']);
        $this->assertEqualsWithDelta(150.15, $risultato['total'], 0.001);
    }

    public function testIlSaldoHaLaPrecedenzaSulTotale(): void
    {
        $senzaSaldo = $this->fattura();
        unset($senzaSaldo['invoice_balance']);

        $risultato = (new RibaFlow())->build($this->azienda(), $this->conto(), [$senzaSaldo]);

        $this->assertEqualsWithDelta(999.00, $risultato['total'], 0.001, 'Senza saldo si ripiega sul totale');
    }

    public function testCaratteriNonAmmessiVengonoTrasliterati(): void
    {
        $righe = $this->righe((new RibaFlow())->build($this->azienda(), $this->conto(), [$this->fattura()]));

        $this->assertStringContainsString('CAFFE SOCIETA', $righe[3], 'Accenti ammessi solo come lettera semplice');
        $this->assertSame(1, preg_match('/^[A-Z0-9 \/\-\.,]*$/', substr($righe[3], 10, 30)), 'Nessun carattere fuori tracciato');
        $this->assertStringContainsString('RSSMRA80A01H501U', $righe[3], 'Il codice fiscale va in maiuscolo');
    }

    public function testUnIbanConPunteggiaturaDaLoStessoAbiECab(): void
    {
        $conPunti = $this->fattura(['client_iban' => 'it28 w800 0000 2921 0064 5211 208']);
        $righe    = $this->righe((new RibaFlow())->build($this->azienda(), $this->conto(), [$conPunti]));

        $this->assertSame('80000', substr($righe[1], 57, 5));
        $this->assertSame('00292', substr($righe[1], 62, 5));
    }

    public function testSiaIbanECreditoreOClienteMancantiGeneranoAvvisi(): void
    {
        $risultato = (new RibaFlow())->build(
            $this->azienda(),
            ['bank_account_iban' => '', 'bank_account_sia_code' => ''],
            [$this->fattura(['client_iban' => '', 'client_name' => 'Bianchi'])]
        );

        $testo = implode("\n", $risultato['warnings']);

        $this->assertStringContainsString('SIA', $testo);
        $this->assertStringContainsString('IBAN del conto di accredito', $testo);
        $this->assertStringContainsString('Bianchi', $testo, 'L\'avviso deve dire quale cliente non ha l\'IBAN');
    }

    public function testNessunAvvisoQuandoIDatiSonoCompleti(): void
    {
        $risultato = (new RibaFlow())->build($this->azienda(), $this->conto(), [$this->fattura()]);

        $this->assertSame([], $risultato['warnings']);
    }

    public function testUnaDistintaVuotaProduceSoloTestataECoda(): void
    {
        $risultato = (new RibaFlow())->build($this->azienda(), $this->conto(), []);
        $righe     = $this->righe($risultato);

        $this->assertCount(2, $righe);
        $this->assertSame(0, $risultato['count']);
        $this->assertSame('0000000', substr($righe[1], 48, 7));
        $this->assertStringStartsWith('RIBA', $risultato['reference']);
    }

    public function testIRecordSonoSeparatiDaCrLf(): void
    {
        $risultato = (new RibaFlow())->build($this->azienda(), $this->conto(), [$this->fattura()]);

        $this->assertStringEndsWith("\r\n", $risultato['content']);
        $this->assertSame(9, substr_count($risultato['content'], "\r\n"));
    }
}
