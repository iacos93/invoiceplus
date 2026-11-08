<?php

namespace App\Controllers;

use App\Models\CompanyModel;
use App\Services\CalendarService;
use App\Services\FinanceService;

/**
 * Calendar — vista mensile delle scadenze e feed di sottoscrizione.
 *
 * index() sta dietro login come il resto dell'app. feed() no: è la stessa
 * eccezione di Cron e SdI — un URL con token al posto della sessione, perché
 * a chiamarlo non è un browser autenticato ma Google Calendar o un client
 * CalDAV. Il token sta in ip_settings (calendar_feed_token), generato e
 * rigenerabile da Impostazioni con lo stesso meccanismo della cron_key.
 */
class Calendar extends BaseController
{
    public function index(?int $year = null, ?int $month = null)
    {
        $today = new \DateTimeImmutable('today');
        $year  = $year ?: (int) $today->format('Y');
        $month = $month ?: (int) $today->format('n');

        if ($month < 1 || $month > 12) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $first     = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $daysInMonth = (int) $first->format('t');
        // Giorno d'inizio settimana configurabile (Impostazioni > primo giorno
        // della settimana): 0 = domenica … 6 = sabato. format('w') restituisce
        // esattamente 0..6, quindi i blank iniziali si calcolano per differenza.
        $weekStart = (int) get_setting('first_day_of_week', '1');
        $leadingBlanks = (($first->format('w') - $weekStart) % 7 + 7) % 7;

        $byDay = (new CalendarService())->byDay();

        $weeks = [];
        $week  = array_fill(0, $leadingBlanks, null);

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $week[]  = ['day' => $d, 'date' => $dateKey, 'events' => $byDay[$dateKey] ?? [], 'is_today' => $dateKey === $today->format('Y-m-d')];

            if (count($week) === 7) {
                $weeks[] = $week;
                $week    = [];
            }
        }

        if ($week) {
            while (count($week) < 7) {
                $week[] = null;
            }
            $weeks[] = $week;
        }

        $prev = $first->modify('-1 month');
        $next = $first->modify('+1 month');

        $feedToken = decrypt_secret((string) get_setting('calendar_feed_token', ''));

        return view('calendar/index', [
            'year'        => $year,
            'month'       => $month,
            'weeks'       => $weeks,
            'prev_year'   => (int) $prev->format('Y'),
            'prev_month'  => (int) $prev->format('n'),
            'next_year'   => (int) $next->format('Y'),
            'next_month'  => (int) $next->format('n'),
            'month_label' => (FinanceService::monthNames()[$month] ?? '') . ' ' . $year,
            'week_start'  => $weekStart,
            // Etichette dei giorni ruotate a partire dal giorno d'inizio scelto,
            // così l'intestazione della griglia segue la stessa impostazione.
            'weekday_labels' => array_values(array_merge(
                array_slice(['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'], $weekStart),
                array_slice(['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'], 0, $weekStart)
            )),
            'feed_url'    => $feedToken ? site_url('calendar/feed/' . $feedToken) : null,
            // Link "aggiungi con un click" per i client di calendario più
            // diffusi. Nessuno di questi fa nulla di diverso dal copiare
            // l'URL sopra a mano nel client — sono solo scorciatoie che
            // precompilano la finestra di sottoscrizione:
            // - Apple/iOS/altri CalDAV: riconoscono lo schema webcal(s),
            //   che è lo stesso URL http(s) con lo schema sostituito.
            // - Google Calendar: pagina "aggiungi da URL" con il feed
            //   già nel campo cid.
            // - Outlook/Office 365: stessa idea, endpoint "addfromweb".
            'calendar_add_links' => $feedToken ? (function () use ($feedToken): array {
                $httpUrl   = site_url('calendar/feed/' . $feedToken);
                $webcalUrl = preg_replace('/^https?:/', 'webcal:', $httpUrl);

                return [
                    'apple'   => $webcalUrl,
                    'google'  => 'https://calendar.google.com/calendar/render?cid=' . rawurlencode($webcalUrl),
                    'outlook' => 'https://outlook.live.com/calendar/0/addfromweb?url=' . rawurlencode($httpUrl)
                        . '&name=' . rawurlencode('InvoicePlane'),
                ];
            })() : null,
            // Riepilogo del mese aperto, per chi vuole solo la lista.
            'upcoming'    => array_values(array_filter(
                (new CalendarService())->events(),
                static fn(array $e): bool => substr($e['date'], 0, 7) === sprintf('%04d-%02d', $year, $month)
            )),
        ]);
    }

    /**
     * Feed iCalendar pubblico. Protetto dal token nell'URL, non dalla
     * sessione: un client di calendario non manda cookie di login.
     */
    public function feed(string $token)
    {
        // hash_equals(): confronto a tempo costante, come negli endpoint SdI e
        // MCP. Il token vuoto resta rifiutato a monte, cosi' un feed non ancora
        // configurato non diventa pubblico.
        //
        // Il token di default resta globale (azienda predefinita), ma si accettano
        // anche i token per-azienda "calendar_feed_token_<company_id>": cosi' in
        // multi-tenant ogni azienda puo' avere il suo feed, invece di vedere solo
        // quello dell'azienda predefinita (CalendarService::events() scoperebbe
        // comunque sull'azienda attiva, che senza sessione e' la predefinita).
        $expected = decrypt_secret((string) get_setting('calendar_feed_token', ''));

        if ($token !== '' && $expected !== '' && hash_equals($expected, $token)) {
            // feed globale -> azienda predefinita (nessuno scope esplicito)
        } else {
            $matched = false;

            foreach ((new CompanyModel())->getAllActive() as $company) {
                $companyToken = decrypt_secret((string) get_setting('calendar_feed_token_' . $company['company_id'], ''));

                if ($companyToken !== '' && hash_equals($companyToken, $token)) {
                    session()->set('active_company_id', (int) $company['company_id']);
                    $matched = true;

                    break;
                }
            }

            if (!$matched) {
                http_response_code(403);

                return 'Token non valido';
            }
        }

        // Il nome del calendario viene dall'azienda del token: l'impostazione
        // globale company_name non esiste piu' e ogni feed si chiamava
        // "InvoicePlane — Scadenze", indistinguibili nel calendario del telefono.
        $company = (new CompanyModel())->find((int) active_company_id());

        $ics = (new CalendarService())->toIcs(
            (new CalendarService())->events(),
            (trim((string) ($company['company_name'] ?? '')) ?: 'InvoicePlane') . ' — Scadenze'
        );

        return $this->response
            ->setContentType('text/calendar; charset=UTF-8')
            ->setHeader('Content-Disposition', 'inline; filename="scadenze.ics"')
            ->setBody($ics);
    }
}
