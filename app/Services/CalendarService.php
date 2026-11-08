<?php

namespace App\Services;

use App\Models\F24Model;
use App\Models\InvoiceModel;
use App\Models\TaskModel;

/**
 * CalendarService — aggrega le scadenze dell'app in una forma unica.
 *
 * Tre fonti che oggi vivono in pagine separate (Scadenzario, F24, Attività)
 * e non si vedono mai insieme: qui diventano una lista di eventi omogenei,
 * usata sia dalla pagina Calendario sia dal feed .ics. Un solo posto dove
 * "cos'è una scadenza" è definito, cosi' la vista e l'export non possono
 * divergere.
 */
class CalendarService
{
    /**
     * Tutte le scadenze aperte, in un'unica lista.
     *
     * @return list<array{
     *     type: string, id: int, date: string, title: string,
     *     amount: float|null, overdue: bool, url: string
     * }>
     */
    public function events(): array
    {
        $events = [];

        foreach ((new InvoiceModel())->getDueSchedule() as $row) {
            $events[] = [
                'type'    => 'invoice',
                'id'      => (int) $row['invoice_id'],
                'date'    => substr((string) $row['invoice_date_due'], 0, 10),
                'title'   => trans('invoice', '', 'Fattura') . ' '
                    . ($row['invoice_number'] ?: ('#' . $row['invoice_id']))
                    . ' — ' . trim($row['client_name'] . ' ' . $row['client_surname']),
                'amount'  => (float) $row['invoice_balance'],
                'overdue' => (int) $row['days_overdue'] > 0,
                'url'     => site_url('invoices/view/' . $row['invoice_id']),
            ];
        }

        foreach ((new F24Model())->getAll(0, 'open') as $row) {
            $events[] = [
                'type'    => 'f24',
                'id'      => (int) $row['f24_id'],
                'date'    => substr((string) $row['f24_due_date'], 0, 10),
                'title'   => 'F24' . ($row['f24_period'] ? ' — ' . $row['f24_period'] : ''),
                'amount'  => $row['f24_amount'] !== null ? (float) $row['f24_amount'] : null,
                'overdue' => $row['f24_due_date'] < date('Y-m-d'),
                'url'     => site_url('f24/form/' . $row['f24_id']),
            ];
        }

        // Solo attività aperte con scadenza: is_overdue è già calcolato dalla
        // stessa regola corretta di TaskModel (fatturata non è mai in ritardo).
        foreach ((new TaskModel())->getAllWithProject('all') as $row) {
            if ((int) $row['task_status'] === 4 || empty($row['task_finish_date'])) {
                continue;
            }

            $events[] = [
                'type'    => 'task',
                'id'      => (int) $row['task_id'],
                'date'    => substr((string) $row['task_finish_date'], 0, 10),
                'title'   => trans('tasks', '', 'Attività') . ' — ' . $row['task_name'],
                'amount'  => $row['task_price'] !== null ? (float) $row['task_price'] : null,
                'overdue' => !empty($row['is_overdue']),
                'url'     => site_url('tasks/form/' . $row['task_id']),
            ];
        }

        usort($events, static fn(array $a, array $b): int => $a['date'] <=> $b['date']);

        return $events;
    }

    /**
     * Eventi raggruppati per giorno (Y-m-d), per la griglia mensile.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function byDay(): array
    {
        $byDay = [];

        foreach ($this->events() as $event) {
            $byDay[$event['date']][] = $event;
        }

        return $byDay;
    }

    /**
     * Eventi come feed iCalendar (RFC 5545), tutti a giornata intera.
     *
     * Sola lettura per costruzione: genera solo VEVENT, non ne legge mai.
     * Un client CalDAV o Google Calendar che sottoscrive questo URL non può
     * scrivere nulla nell'app — il rischio di un sync bidirezionale rotto
     * (eventi duplicati, cancellazioni propagate per errore) non esiste
     * perché qui non c'è nessun "indietro".
     *
     * @param list<array{type: string, id: int, date: string, title: string, amount: float|null, overdue: bool, url: string}> $events
     */
    public function toIcs(array $events, string $calendarName): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//InvoicePlane//Calendario Scadenze//IT',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . $this->escape($calendarName),
            // Suggerimento di aggiornamento per i client che lo rispettano
            // (Google e Apple Calendario tra questi): senza, la sottoscrizione
            // resta comunque valida ma il client sceglie da solo la cadenza.
            'X-PUBLISHED-TTL:PT6H',
            'REFRESH-INTERVAL;VALUE=DURATION:PT6H',
        ];

        foreach ($events as $event) {
            $date = str_replace('-', '', $event['date']);

            // UID stabile: lo stesso evento deve avere lo stesso UID a ogni
            // rigenerazione del feed, altrimenti ogni sync lo ricrea come
            // nuovo invece di aggiornarlo.
            $uid = $event['type'] . '-' . $event['id'] . '@invoiceplane.local';

            $summary = $event['title'];
            if ($event['amount'] !== null) {
                // Non format_currency(): legge il formato numerico dalle
                // impostazioni (query al database), e la generazione del feed
                // deve poter girare — ed essere testata — senza. Stesso motivo
                // per cui FinanceService non lo usa (vedi il suo commento).
                $summary .= ' (' . $this->money($event['amount']) . ')';
            }
            if ($event['overdue']) {
                $summary = '⚠ ' . $summary;
            }

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $uid;
            $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z');
            // VALUE=DATE: evento a giornata intera, non un orario preciso —
            // le scadenze di questa app non ne hanno uno.
            $lines[] = 'DTSTART;VALUE=DATE:' . $date;
            $lines[] = 'SUMMARY:' . $this->escape($summary);
            $lines[] = 'URL:' . $event['url'];
            $lines[] = 'STATUS:CONFIRMED';
            $lines[] = 'TRANSP:TRANSPARENT';
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        // CRLF e piegatura righe a 75 ottetti: RFC 5545 le richiede entrambe,
        // e Google Calendar scarta silenziosamente le righe che le violano
        // invece di segnalare un errore — un evento sparirebbe senza spiegazione.
        return implode("\r\n", array_map([$this, 'fold'], $lines)) . "\r\n";
    }

    /** Importo in un evento: fisso in euro, non dal formato delle impostazioni. */
    private function money(float $value): string
    {
        return '€' . number_format($value, 2, ',', '.');
    }

    /** Escape dei caratteri riservati iCalendar (RFC 5545 §3.3.11). */
    private function escape(string $value): string
    {
        return str_replace(
            ["\\", "\n", ",", ";"],
            ["\\\\", "\\n", "\\,", "\\;"],
            $value
        );
    }

    /** Piega una riga oltre i 75 ottetti con continuazione a spazio iniziale. */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $chunks = [];
        $rest   = $line;

        while (strlen($rest) > 75) {
            // Il taglio e' a ottetti (lo impone la RFC), ma non deve cadere in
            // mezzo a un carattere UTF-8: un titolo con un accento nel punto
            // sbagliato produceva una sequenza non valida a cavallo delle due
            // righe, e Google Calendar scarta in silenzio le righe malformate,
            // quindi l'evento spariva dal feed senza alcun errore. Si arretra
            // finche' il byte successivo e' di continuazione (10xxxxxx).
            $take = 75;

            while ($take > 0 && (ord($rest[$take]) & 0xC0) === 0x80) {
                $take--;
            }

            $chunks[] = substr($rest, 0, $take);
            $rest     = ' ' . substr($rest, $take);
        }
        $chunks[] = $rest;

        return implode("\r\n", $chunks);
    }
}
