<?php

use App\Services\CalendarService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Generazione del feed iCalendar.
 *
 * È l'unica parte del calendario testabile senza database: eventi già
 * assemblati in ingresso, testo RFC 5545 in uscita. Un errore qui non lancia
 * un'eccezione — Google Calendar scarta in silenzio le righe non valide, e
 * l'evento sparisce senza spiegazione, quindi il formato va verificato a
 * priori e non scoperto da un calendario vuoto.
 *
 * @internal
 */
final class CalendarServiceTest extends CIUnitTestCase
{
    private CalendarService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new CalendarService();
    }

    /** @return array<string, mixed> */
    private function event(array $override = []): array
    {
        return array_replace([
            'type'    => 'invoice',
            'id'      => 42,
            'date'    => '2026-09-15',
            'title'   => 'Fattura 2026/12 — Novatech Srl',
            'amount'  => 1200.5,
            'overdue' => false,
            'url'     => 'https://esempio.it/invoices/view/42',
        ], $override);
    }

    private function veventLines(string $ics, string $uid): array
    {
        // Le righe piegate vanno riunite prima di poterle leggere per intero:
        // RFC 5545 usa un singolo spazio come marcatore di continuazione.
        $unfolded = str_replace("\r\n ", '', $ics);
        $lines    = explode("\r\n", trim($unfolded));

        $start = array_search('UID:' . $uid, $lines, true);
        $this->assertNotFalse($start, 'UID non trovato: ' . $uid);

        $begin = $start;
        while ($lines[$begin] !== 'BEGIN:VEVENT') {
            $begin--;
        }
        $end = $start;
        while ($lines[$end] !== 'END:VEVENT') {
            $end++;
        }

        return array_slice($lines, $begin, $end - $begin + 1);
    }

    public function testWrapsInAValidVcalendar(): void
    {
        $ics = $this->svc->toIcs([$this->event()], 'Scadenze');

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString('VERSION:2.0', $ics);
    }

    public function testUsesCrlfLineEndings(): void
    {
        $ics = $this->svc->toIcs([$this->event()], 'Scadenze');

        $this->assertStringNotContainsString("\r\n\n", $ics, 'Niente CRLF+LF misti');
        // Nessun \n solitario (non preceduto da \r): un editor che normalizza
        // gli a capo prima del commit lo romperebbe silenziosamente.
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $ics));
    }

    public function testAllDayEventUsesValueDate(): void
    {
        $ics   = $this->svc->toIcs([$this->event(['date' => '2026-09-05'])], 'Scadenze');
        $vevent = $this->veventLines($ics, 'invoice-42@invoiceplane.local');

        $this->assertContains('DTSTART;VALUE=DATE:20260905', $vevent);
    }

    public function testUidIsStableAcrossRegeneration(): void
    {
        $a = $this->svc->toIcs([$this->event()], 'Scadenze');
        $b = $this->svc->toIcs([$this->event()], 'Scadenze');

        preg_match('/UID:([^\r\n]+)/', $a, $m1);
        preg_match('/UID:([^\r\n]+)/', $b, $m2);

        $this->assertSame($m1[1], $m2[1], 'Stesso evento, stesso UID a ogni rigenerazione del feed');
    }

    public function testDifferentTypesNeverShareAUid(): void
    {
        $ics = $this->svc->toIcs([
            $this->event(['type' => 'invoice', 'id' => 5]),
            $this->event(['type' => 'task', 'id' => 5]),
        ], 'Scadenze');

        $this->assertStringContainsString('UID:invoice-5@invoiceplane.local', $ics);
        $this->assertStringContainsString('UID:task-5@invoiceplane.local', $ics);
    }

    public function testEscapesReservedCharactersInSummary(): void
    {
        $ics = $this->svc->toIcs([$this->event([
            'title' => 'Fattura; nota, con "virgole", e \\backslash\\',
        ])], 'Scadenze');

        $this->assertStringContainsString('Fattura\; nota\\, con', $ics);
        $this->assertStringContainsString('\\\\backslash\\\\', $ics);
        // Il punto e virgola non sfuggito romperebbe il parsing della riga
        // per ogni client iCalendar conforme a RFC 5545.
        $this->assertDoesNotMatchRegularExpression('/SUMMARY:[^\r\n]*[^\\\\];/', $ics);
    }

    public function testFoldsLinesLongerThan75Octets(): void
    {
        $ics = $this->svc->toIcs([$this->event([
            'title' => str_repeat('Attività molto lunga da fatturare al cliente ', 3),
        ])], 'Scadenze');

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(76, strlen($line), 'Riga oltre 75 ottetti non piegata: ' . $line);
        }
    }

    public function testOverdueEventIsMarkedInSummary(): void
    {
        $ics = $this->svc->toIcs([$this->event(['overdue' => true])], 'Scadenze');

        $this->assertMatchesRegularExpression('/SUMMARY:.*⚠/u', $ics);
    }

    public function testAmountAppearsInSummaryWhenPresent(): void
    {
        $ics = $this->svc->toIcs([$this->event(['amount' => 1234.5])], 'Scadenze');

        // Non format_currency(): tocca il database (formato dalle
        // impostazioni), e toIcs() deve restare verificabile senza. La
        // virgola è sfuggita perché è un carattere riservato iCalendar.
        $this->assertStringContainsString('€1.234\\,50', $ics);
    }

    public function testNullAmountIsOmittedWithoutBreakingTheLine(): void
    {
        $ics    = $this->svc->toIcs([$this->event(['amount' => null])], 'Scadenze');
        $vevent = $this->veventLines($ics, 'invoice-42@invoiceplane.local');
        $summaryLine = array_values(array_filter($vevent, static fn($l) => str_starts_with($l, 'SUMMARY:')))[0];

        $this->assertStringNotContainsString('€', $summaryLine);
    }

    public function testEmptyEventListProducesAnEmptyValidCalendar(): void
    {
        $ics = $this->svc->toIcs([], 'Scadenze');

        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringNotContainsString('BEGIN:VEVENT', $ics);
    }

    public function testCalendarNameIsEscapedAndPresent(): void
    {
        $ics = $this->svc->toIcs([], 'Studio Rossi; Scadenze, 2026');

        $this->assertStringContainsString('X-WR-CALNAME:Studio Rossi\; Scadenze\\, 2026', $ics);
    }
}
