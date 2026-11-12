<?php

namespace App\Libraries;

use App\Models\EmailTrackModel;

/**
 * Tracciamento delle aperture delle email inviate.
 *
 * Il messaggio porta un'immagine di 1×1 che punta a una rotta pubblica; quando
 * il programma di posta la carica, la rotta registra l'apertura. Non c'è modo
 * di sapere se il messaggio è stato *consegnato* senza un server che lo dica
 * (un ESP con i suoi webhook): quello che si vede qui è solo "aperto", e va
 * letto come indicazione, non come prova.
 *
 * Il limite più importante è che il pixel non viene caricato sempre: molti
 * client bloccano le immagini per impostazione predefinita, e Apple Mail
 * scarica in anticipo i messaggi (prefetch) anche senza che l'utente li apra.
 * Per questo l'assenza di apertura non significa "non letto" e la presenza
 * può essere un falso positivo. È la stessa approssimazione che offrono i
 * gestionali con questa funzione.
 *
 * La riga nasce prima dell'invio (serve il token da mettere nel messaggio),
 * riceve `track_sent_at` solo quando il server SMTP accetta il messaggio e
 * viene eliminata se l'invio fallisce: nella pagina del documento non devono
 * comparire email mai partite.
 *
 * L'indirizzo IP viene salvato solo in forma anonima (rete, non singolo host) e
 * lo user agent non viene memorizzato: sono dati personali che nessuna schermata
 * usa, e il conteggio delle aperture non ha bisogno di identificare chi legge.
 */
class EmailTracking
{
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_QUOTE   = 'quote';

    private EmailTrackModel $model;

    public function __construct(?EmailTrackModel $model = null)
    {
        $this->model = $model ?? new EmailTrackModel();
    }

    /**
     * Il tracciamento è attivo salvo scelta contraria: chi non lo vuole lo
     * spegne da Email e mittenti, e in quel caso gli invii restano identici a
     * prima (niente pixel, niente righe).
     */
    public function enabled(): bool
    {
        return (int) get_setting('email_tracking_enabled', 1) === 1;
    }

    /**
     * Prepara la riga di un invio. Restituisce null se il tracciamento è
     * spento o se la scrittura non riesce: in entrambi i casi l'email parte
     * comunque, senza pixel. Un problema di tracciamento non deve impedire di
     * mandare una fattura.
     *
     * @return array<string, mixed>|null
     */
    public function start(string $type, int $documentId, string $recipient, string $subject, ?int $companyId = null): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $token = bin2hex(random_bytes(24)); // 48 caratteri esadecimali

        try {
            $id = $this->model->insert([
                'track_token'         => $token,
                'track_document_type' => $type,
                'track_document_id'   => $documentId,
                'track_company_id'    => $companyId,
                'track_recipient'     => mb_substr($recipient, 0, 255),
                'track_subject'       => mb_substr($subject, 0, 255),
                'track_open_count'    => 0,
                'track_created_at'    => date('Y-m-d H:i:s'),
            ]);

            return $id ? $this->model->find($id) : null;
        } catch (\Throwable $e) {
            log_message('error', 'Tracciamento email non creato: ' . $e->getMessage());

            return null;
        }
    }

    /** Indirizzo pubblico del pixel per un token. */
    public function pixelUrl(string $token): string
    {
        return site_url('t/open/' . $token . '.gif');
    }

    /**
     * Converte in HTML un corpo scritto come testo semplice: i caratteri
     * speciali vengono codificati e gli a capo diventano <br>. Il corpo di una
     * textarea non è HTML, e spedirlo in modalità HTML senza questa conversione
     * perderebbe le righe (o interpreterebbe come tag ciò che l'utente ha
     * scritto).
     */
    public function textToHtml(string $text): string
    {
        return nl2br(esc($text), false);
    }

    /**
     * Versione testuale di un corpo HTML, per la parte `text/plain` dello
     * stesso messaggio.
     *
     * I client che non mostrano HTML, o che lo preferiscono, leggono questa.
     * Non è lo HTML con i tag tolti alla leggera: la fine di paragrafi, righe
     * e intestazioni diventa un a capo, le entità tornano caratteri veri e le
     * righe vuote ripetute si riducono. Un corpo già in testo semplice resta
     * com'è, a capo compresi.
     */
    public function htmlToText(string $html): string
    {
        if (trim($html) === '') {
            return ' ';
        }

        if (strlen($html) === strlen(strip_tags($html))) {
            return $html;
        }

        // Style, script e testa non sono contenuto: senza toglierli, il CSS
        // finirebbe nella parte testuale come una riga di selettori.
        $text = preg_replace('/<(style|script|head)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $text = preg_replace('/<br\b[^>]*>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/(p|div|h[1-6]|table)\b[^>]*>/i', "\n\n", $text) ?? $text;
        $text = preg_replace('/<\/(tr|li)\b[^>]*>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/td\b[^>]*>/i', '  ', $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = trim($text);

        return $text === '' ? ' ' : $text;
    }

    /**
     * Aggiunge il pixel al corpo HTML. Se c'è un </body> il pixel va lì,
     * altrimenti in fondo: alcuni client tagliano il contenuto dopo il primo
     * metro di testo e un pixel a metà messaggio non verrebbe mai richiesto.
     *
     * @param array<string, mixed> $track
     */
    public function appendPixel(string $body, array $track): string
    {
        $token = (string) ($track['track_token'] ?? '');

        if ($token === '') {
            return $body;
        }

        $pixel = '<img src="' . esc($this->pixelUrl($token)) . '"'
            . ' width="1" height="1" alt=""'
            . ' style="display:block;width:1px;height:1px;border:0;margin:0;padding:0" />';

        if (stripos($body, '</body>') !== false) {
            return (string) preg_replace('/<\/body>/i', $pixel . '</body>', $body, 1);
        }

        return $body . $pixel;
    }

    /** L'invio è riuscito: da ora la riga è visibile nella pagina del documento. */
    public function markSent(int $trackId): void
    {
        // L'email è già partita: un errore di scrittura non deve trasformare
        // un invio riuscito in una pagina di errore per chi l'ha mandata.
        try {
            $this->model->update($trackId, ['track_sent_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            log_message('error', 'Tracciamento email non aggiornato: ' . $e->getMessage());
        }
    }

    /** L'invio non è riuscito: la riga non deve restare. */
    public function discard(int $trackId): void
    {
        try {
            $this->model->delete($trackId);
        } catch (\Throwable $e) {
            log_message('error', 'Tracciamento email non eliminato: ' . $e->getMessage());
        }
    }

    /**
     * Riduce l'indirizzo IP alla sua rete: per IPv4 azzera l'ultimo ottetto,
     * per IPv6 tiene i primi 64 bit. Basta a distinguere aperture ripetute
     * senza conservare l'indirizzo della singola persona, che non serve a
     * nessuna schermata e sarebbe un dato personale in più.
     */
    public function anonymizeIp(string $ip): string
    {
        $ip = trim($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $parts    = explode('.', $ip);
            $parts[3] = '0';

            return implode('.', $parts);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $binary = inet_pton($ip);

            if ($binary === false) {
                return '';
            }

            $masked = inet_ntop(substr($binary, 0, 8) . str_repeat("\0", 8));

            return $masked === false ? '' : $masked;
        }

        return '';
    }

    /**
     * Registra un caricamento del pixel. Il token sconosciuto o non ancora
     * inviato non produce niente: la rotta risponde comunque con l'immagine,
     * così un token sbagliato non diventa un modo per capire quali esistono.
     */
    public function recordOpen(string $token, string $ip = ''): bool
    {
        $track = $this->model->findByToken($token);

        if (!$track || empty($track['track_sent_at'])) {
            return false;
        }

        $update = [
            'track_open_count' => (int) ($track['track_open_count'] ?? 0) + 1,
            'track_last_ip'    => $this->anonymizeIp($ip),
        ];

        // La prima apertura resta quella che conta per la data mostrata:
        // le successive aggiornano solo il contatore.
        if (empty($track['track_opened_at'])) {
            $update['track_opened_at'] = date('Y-m-d H:i:s');
        }

        $this->model->update((int) $track['track_id'], $update);

        return true;
    }
}
