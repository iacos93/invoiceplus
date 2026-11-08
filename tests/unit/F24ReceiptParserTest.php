<?php

use App\Libraries\F24ReceiptParser;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Lettura e abbinamento delle quietanze F24.
 *
 * L'errore costoso qui è l'abbinamento sbagliato: una delega segnata pagata
 * che non lo è non dà alcun segnale finché non arriva l'avviso bonario.
 * Per questo i casi ambigui devono restituire null, e sono testati.
 *
 * @internal
 */
final class F24ReceiptParserTest extends CIUnitTestCase
{
    public function testQuietanzaAgenziaEntrate(): void
    {
        $text = "RICEVUTA DI PAGAMENTO\nProtocollo telematico  24051612345678901-000001\n"
              . "Data versamento   16/05/2024\nCodice tributo 1040 ... 120,00\n"
              . "SALDO FINALE     EURO     1.234,56\n";

        $r = (new F24ReceiptParser())->parse($text);

        $this->assertSame(1234.56, $r['amount']);
        $this->assertSame('2024-05-16', $r['date']);
        $this->assertSame('24051612345678901-000001', $r['protocol']);
    }

    /**
     * Quietanza di versamento dell'Agenzia così come pdftotext -layout la
     * restituisce (è anche quella scaricata da Fatture in Cloud): caselle
     * spaziate, virgola doppia, protocollo spezzato in due.
     */
    public function testQuietanzaAgenziaACaselle(): void
    {
        $text = "PROTOCOLLO TELEMATICO                     25020834590753143                               000001                Saldo delega\n"
              . "                                                                                                         2.099 ,,2 4\n"
              . "                                          giorno     mese          anno\n"
              . "DATA DEL VERSAMENTO                      1 7 0 2              2 0 2 5                ABI      03268   CAB     22300\n"
              . " INPS                      5200  CF       20697296241104574          1 2024 12 2024     1.155,82      0,00\n";

        $r = (new F24ReceiptParser())->parse($text);

        $this->assertSame(2099.24, $r['amount']);
        $this->assertSame('2025-02-17', $r['date']);
        $this->assertSame('25020834590753143-000001', $r['protocol']);
    }

    public function testQuietanzaBancaConEtichetteSuPiuRighe(): void
    {
        $text = "Pagamento F24\nData\n  addebito: 30.06.2025\nImporto\naddebitato EUR 980,00\nCRO: 0306250ABC12345";

        $r = (new F24ReceiptParser())->parse($text);

        $this->assertSame(980.0, $r['amount']);
        $this->assertSame('2025-06-30', $r['date']);
        $this->assertSame('0306250ABC12345', $r['protocol']);
    }

    public function testSenzaEtichetteNonIndovina(): void
    {
        $r = (new F24ReceiptParser())->parse('Pagina 1 di 2 — 4.500,00 1.200,00');

        $this->assertNull($r['amount']);
        $this->assertNull($r['date']);
    }

    public function testAbbinamentoUnico(): void
    {
        $open = [
            ['f24_id' => 1, 'f24_amount' => '500.00', 'f24_due_date' => '2025-06-16'],
            ['f24_id' => 2, 'f24_amount' => '980.00', 'f24_due_date' => '2025-06-30'],
        ];

        $this->assertSame(2, F24ReceiptParser::match(['amount' => 980.0, 'date' => null], $open));
        $this->assertNull(F24ReceiptParser::match(['amount' => 981.0, 'date' => null], $open));
    }

    public function testRateUgualiDecideLaScadenzaPiuVicina(): void
    {
        $open = [
            ['f24_id' => 1, 'f24_amount' => '300.00', 'f24_due_date' => '2025-07-31'],
            ['f24_id' => 2, 'f24_amount' => '300.00', 'f24_due_date' => '2025-08-20'],
        ];

        $this->assertSame(2, F24ReceiptParser::match(['amount' => 300.0, 'date' => '2025-08-19'], $open));
        // Senza data non si sceglie a caso.
        $this->assertNull(F24ReceiptParser::match(['amount' => 300.0, 'date' => null], $open));
    }
}
