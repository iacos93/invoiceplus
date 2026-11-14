<?php

namespace App\Libraries;

use App\Models\ClientModel;
use App\Models\InvoiceModel;

/**
 * Avviso al titolare quando lo SdI riporta un problema su una fattura.
 *
 * Le notifiche arrivano da sole all'endpoint e aggiornano la fattura, ma
 * nessuno guarda l'app a ogni ora: uno scarto e' una fattura non emessa, e
 * va corretta e rinviata in tempi brevi. Prima l'unica traccia era la riga
 * nella lista fatture.
 *
 * Gli esiti sono tre: NS (scarto), MC (mancata consegna al destinatario) e AT
 * (trasmessa ma non recapitabile alla PA). Gli altri sono buone notizie o
 * informazioni che non richiedono di fare niente.
 *
 * Mai una conseguenza sulla risposta allo SdI: chi chiama deve intercettare
 * ogni errore, perche' una mail non partita non puo' trasformarsi in una
 * notifica non ricevuta.
 */
final class SdiAlert
{
    public const ESITI = ['NS', 'MC', 'AT'];

    /** Avvisa solo la prima volta che l'esito compare: lo SdI puo' ripetere la notifica. */
    public static function shouldNotify(?string $previous, string $esito): bool
    {
        return in_array($esito, self::ESITI, true) && (string) $previous !== $esito;
    }

    /**
     * @param array<string, mixed> $transmission riga di ip_sdi_transmissions
     */
    public function notify(array $transmission, string $esito, string $message): bool
    {
        $invoice = (new InvoiceModel())->find((int) ($transmission['invoice_id'] ?? 0));

        if (! $invoice) {
            return false;
        }

        // L'endpoint non ha sessione: la casella di invio e' quella
        // dell'azienda della fattura, come nei cron.
        if (! empty($invoice['company_id'])) {
            session()->set('active_company_id', (int) $invoice['company_id']);
        }

        $client   = ! empty($invoice['client_id']) ? (new ClientModel())->find((int) $invoice['client_id']) : null;
        $notifier = new SystemNotifier();

        $mail = self::compose(
            $esito,
            (string) ($invoice['invoice_number'] ?: '#' . $invoice['invoice_id']),
            $client ? format_client($client) : '',
            (string) ($transmission['filename'] ?? ''),
            $message,
            $notifier->companyName(),
            site_url('invoices/view/' . (int) $invoice['invoice_id'])
        );

        return $notifier->send($mail['subject'], $mail['body']);
    }

    /**
     * @return array{subject: string, body: string}
     */
    public static function compose(string $esito, string $numero, string $cliente, string $file, string $message, string $azienda, string $url): array
    {
        $fattura = 'La fattura ' . $numero . ($cliente !== '' ? ' (' . $cliente . ')' : '');

        $righe = match ($esito) {
            'NS' => [
                'subject' => 'Fattura ' . $numero . ' scartata dallo SdI — ' . $azienda,
                'testa'   => $fattura . ' è stata SCARTATA dal Sistema di Interscambio: non risulta emessa.',
                'coda'    => "Cosa fare: la fattura è stata sbloccata. Correggi il problema indicato e reinviala.\n"
                    . 'Di norma una fattura scartata si può ritrasmettere con lo stesso numero e la stessa data entro 5 giorni '
                    . 'dalla notifica di scarto: verifica il termine con il tuo commercialista.',
            ],
            'MC' => [
                'subject' => 'Fattura ' . $numero . ': mancata consegna allo SdI — ' . $azienda,
                'testa'   => $fattura . ' non è stata consegnata al destinatario (mancata consegna).',
                'coda'    => "Cosa fare: la fattura risulta comunque emessa e il cliente la trova nella sua area riservata dell'Agenzia delle Entrate.\n"
                    . 'Avvisalo e mandagli una copia (PDF o XML) con un altro mezzo. Controlla che il codice destinatario o la PEC in anagrafica siano giusti.',
            ],
            default => [
                'subject' => 'Fattura ' . $numero . ': impossibile recapitare alla PA — ' . $azienda,
                'testa'   => $fattura . ' è stata trasmessa, ma lo SdI non ha potuto recapitarla alla pubblica amministrazione.',
                'coda'    => "Cosa fare: verifica il codice univoco ufficio in anagrafica e contatta l'ente destinatario.",
            ],
        };

        $body = $righe['testa'] . "\n\n"
            . ($message !== '' ? "Dettaglio:\n" . $message . "\n\n" : '')
            . $righe['coda'] . "\n\n"
            . ($file !== '' ? 'File: ' . $file . "\n" : '')
            . 'Apri la fattura: ' . $url . "\n\n"
            . $azienda;

        return ['subject' => $righe['subject'], 'body' => $body];
    }
}
