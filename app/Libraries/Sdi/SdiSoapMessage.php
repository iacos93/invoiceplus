<?php

namespace App\Libraries\Sdi;

/**
 * Lettura e scrittura dei messaggi SOAP scambiati con lo SdI sui due
 * webservice che esponiamo noi (TrasmissioneFatture per le notifiche,
 * RicezioneFatture per le fatture di acquisto).
 *
 * Perche' a mano e non con \SoapServer: i WSDL SdI sono document/literal e
 * lo SdI consegna il campo File in MTOM (multipart/related con xop:Include),
 * che \SoapServer non sa ricomporre; in modalita' non-WSDL, inoltre, la
 * risposta di RiceviFattura non avrebbe la forma <rispostaRiceviFatture>
 * attesa dallo schema, e lo SdI la tratterebbe come errore di consegna.
 * Leggere la busta con DOM per nome locale tollera sia MTOM sia base64
 * inline, sia i prefissi che ogni stack SOAP sceglie a modo suo.
 *
 * La classe e' pura (stringhe in ingresso, array/stringhe in uscita):
 * nessun accesso a database o impostazioni, cosi' i messaggi di esempio
 * si verificano nei test unitari senza un accreditamento reale.
 */
class SdiSoapMessage
{
    public const NS_SOAP        = 'http://schemas.xmlsoap.org/soap/envelope/';
    public const NS_TRASMISSIONE = 'http://www.fatturapa.gov.it/sdi/ws/trasmissione/v1.0/types';
    public const NS_RICEZIONE   = 'http://www.fatturapa.gov.it/sdi/ws/ricezione/v1.0/types';

    /**
     * Elemento del Body (in minuscolo) => codice della notifica, lo stesso
     * prefisso che lo SdI usa nei nomi file (RC_, NS_, ...) e che il canale
     * PEC gia' salva in ip_sdi_transmissions.esito.
     */
    public const NOTIFICHE = [
        'ricevutaconsegna'                => 'RC',
        'notificascarto'                  => 'NS',
        'notificamancataconsegna'         => 'MC',
        'notificaesito'                   => 'NE',
        'notificadecorrenzatermini'       => 'DT',
        'attestazionetrasmissionefattura' => 'AT',
    ];

    /**
     * Estrae operazione e parametri da una richiesta SOAP.
     *
     * @return array{element: string, params: array<string, string>}|null
     *         element: nome locale del primo figlio del Body;
     *         params: figli diretti per nome locale, con File e Metadati
     *         gia' decodificati (contenuto binario reale, non base64).
     */
    public static function parse(string $body, string $contentType = ''): ?array
    {
        $parts    = [];
        $envelope = $body;

        if (stripos($contentType, 'multipart/related') !== false) {
            $parts = self::mimeParts($body, $contentType);
            if ($parts === []) {
                return null;
            }
            $start    = self::startCid($contentType);
            $envelope = $start !== null && isset($parts[$start]) ? $parts[$start] : reset($parts);
        }

        $doc = new \DOMDocument();
        // LIBXML_NONET: la busta arriva da fuori, niente risoluzione di
        // entita' o DTD esterne.
        if (trim($envelope) === '' || !@$doc->loadXML($envelope, LIBXML_NONET)) {
            return null;
        }

        $xpath = new \DOMXPath($doc);
        $bodies = $xpath->query('/*[local-name()="Envelope"]/*[local-name()="Body"]/*');
        if ($bodies === false || $bodies->length === 0) {
            return null;
        }

        $request = $bodies->item(0);
        $params  = [];

        foreach ($request->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $name = $child->localName;
            $include = null;
            foreach ($child->childNodes as $node) {
                if ($node instanceof \DOMElement && $node->localName === 'Include') {
                    $include = $node->getAttribute('href');
                }
            }

            if ($include !== null) {
                // MTOM: il contenuto e' la parte MIME indicata dall'href,
                // gia' binaria.
                $cid = self::normalizeCid($include);
                $params[$name] = $parts[$cid] ?? '';
            } elseif (in_array($name, ['File', 'Metadati'], true)) {
                $decoded = base64_decode(preg_replace('/\s+/', '', $child->textContent), true);
                $params[$name] = $decoded === false ? '' : $decoded;
            } else {
                $params[$name] = trim($child->textContent);
            }
        }

        return ['element' => $request->localName, 'params' => $params];
    }

    /**
     * Legge i campi utili dal file di notifica SdI (RC, NS, NE, ...):
     * il NomeFile e' quello della fattura ORIGINALE, ed e' la chiave con
     * cui si ritrova la trasmissione. Il NomeFile del parametro SOAP e'
     * invece il nome della notifica stessa (IT..._RC_001.xml) e non
     * corrisponde a nessuna riga di ip_sdi_transmissions.
     *
     * @return array{nome_file: string, identificativo: string, esito: string, errori: list<string>}
     */
    public static function notificationFields(string $xml): array
    {
        $out = ['nome_file' => '', 'identificativo' => '', 'esito' => '', 'errori' => []];

        $doc = new \DOMDocument();
        if (trim($xml) === '' || !@$doc->loadXML($xml, LIBXML_NONET)) {
            return $out;
        }

        $xpath = new \DOMXPath($doc);
        $first = static function (string $name) use ($xpath): string {
            $nodes = $xpath->query('//*[local-name()="' . $name . '"]');

            return $nodes !== false && $nodes->length > 0 ? trim($nodes->item(0)->textContent) : '';
        };

        $out['nome_file']      = $first('NomeFile');
        $out['identificativo'] = $first('IdentificativoSdI');
        $out['esito']          = $first('Esito');

        $errori = $xpath->query('//*[local-name()="ListaErrori"]/*[local-name()="Errore"]');
        foreach ($errori ?: [] as $errore) {
            $codice = $descrizione = '';
            foreach ($errore->childNodes as $node) {
                if ($node instanceof \DOMElement && $node->localName === 'Codice') {
                    $codice = trim($node->textContent);
                } elseif ($node instanceof \DOMElement && $node->localName === 'Descrizione') {
                    $descrizione = trim($node->textContent);
                }
            }
            $out['errori'][] = trim($codice . ' ' . $descrizione);
        }

        return $out;
    }

    /**
     * Risposta a RiceviFattura. ER01 e' l'unico esito previsto dallo schema
     * ("file ricevuto correttamente"): non e' un'accettazione della fattura,
     * e va restituito anche se l'import come spesa poi fallisce, perche' il
     * file e' comunque salvato e lo SdI altrimenti ritenterebbe la consegna.
     */
    public static function riceviFatturaResponse(string $esito = 'ER01'): string
    {
        return self::envelope(
            '<ns:rispostaRiceviFatture xmlns:ns="' . self::NS_RICEZIONE . '">'
            . '<Esito>' . htmlspecialchars($esito, ENT_XML1) . '</Esito>'
            . '</ns:rispostaRiceviFatture>'
        );
    }

    /**
     * Richiesta SdIRiceviFile in MTOM: la busta porta solo un xop:Include,
     * il file viaggia come parte binaria a se'. E' il formato che SOGEI
     * documenta per il campo File; \SoapClient sa solo il base64 inline.
     *
     * @return array{body: string, content_type: string}
     */
    public static function riceviFileRequest(string $nomeFile, string $file): array
    {
        $boundary = 'uuid:' . bin2hex(random_bytes(12));
        $rootId   = 'root.message@invoiceplane';
        $fileId   = 'file@invoiceplane';

        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="' . self::NS_SOAP . '"><soap:Body>'
            . '<ns:fileSdIAccoglienza xmlns:ns="' . self::NS_TRASMISSIONE . '">'
            . '<NomeFile>' . htmlspecialchars($nomeFile, ENT_XML1) . '</NomeFile>'
            . '<File><xop:Include xmlns:xop="http://www.w3.org/2004/08/xop/include" href="cid:' . rawurlencode($fileId) . '"/></File>'
            . '</ns:fileSdIAccoglienza></soap:Body></soap:Envelope>';

        $body = '--' . $boundary . "\r\n"
            . 'Content-Type: application/xop+xml; charset=UTF-8; type="text/xml"' . "\r\n"
            . 'Content-Transfer-Encoding: 8bit' . "\r\n"
            . 'Content-ID: <' . $rootId . '>' . "\r\n\r\n"
            . $envelope . "\r\n"
            . '--' . $boundary . "\r\n"
            . 'Content-Type: application/octet-stream' . "\r\n"
            . 'Content-Transfer-Encoding: binary' . "\r\n"
            . 'Content-ID: <' . $fileId . '>' . "\r\n\r\n"
            . $file . "\r\n"
            . '--' . $boundary . "--\r\n";

        return [
            'body'         => $body,
            'content_type' => 'multipart/related; type="application/xop+xml"; boundary="' . $boundary
                . '"; start="<' . $rootId . '>"; start-info="text/xml"',
        ];
    }

    /**
     * Esito di SdIRiceviFile letto dalla risposta HTTP (MTOM o semplice).
     * Un SOAP Fault e un Errore EIxx sono entrambi un rifiuto; ok=true solo
     * con un IdentificativoSdI assegnato.
     *
     * @return array{ok: bool, identificativo: string, data_ora: string, errore: string}
     */
    public static function riceviFileResult(string $body, string $contentType): array
    {
        $out    = ['ok' => false, 'identificativo' => '', 'data_ora' => '', 'errore' => ''];
        $parsed = self::parse($body, $contentType);

        if ($parsed === null) {
            $out['errore'] = 'risposta SdI non leggibile';

            return $out;
        }

        $p = $parsed['params'];

        if ($parsed['element'] === 'Fault') {
            $out['errore'] = 'SOAP Fault: ' . ($p['faultstring'] ?? $p['faultcode'] ?? 'senza descrizione');

            return $out;
        }

        $out['identificativo'] = $p['IdentificativoSdI'] ?? '';
        $out['data_ora']       = $p['DataOraRicezione'] ?? '';
        $out['errore']         = $p['Errore'] ?? '';
        $out['ok']             = $out['errore'] === '' && $out['identificativo'] !== '';

        if (!$out['ok'] && $out['errore'] === '') {
            $out['errore'] = 'risposta senza IdentificativoSdI';
        }

        return $out;
    }

    /** SOAP Fault, per richieste illeggibili: lo SdI la registra come errore e ritenta. */
    public static function fault(string $message, string $code = 'soap:Client'): string
    {
        return self::envelope(
            '<soap:Fault><faultcode>' . htmlspecialchars($code, ENT_XML1) . '</faultcode>'
            . '<faultstring>' . htmlspecialchars($message, ENT_XML1) . '</faultstring></soap:Fault>'
        );
    }

    private static function envelope(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="' . self::NS_SOAP . '"><soap:Body>'
            . $body
            . '</soap:Body></soap:Envelope>';
    }

    /**
     * Parti di un messaggio multipart/related, indicizzate per Content-ID
     * normalizzato. Le parti binarie (Content-Transfer-Encoding: binary) si
     * conservano byte per byte; base64 e quoted-printable si decodificano.
     *
     * @return array<string, string>
     */
    private static function mimeParts(string $body, string $contentType): array
    {
        if (!preg_match('/boundary\s*=\s*"?([^";]+)"?/i', $contentType, $m)) {
            return [];
        }

        $boundary = '--' . trim($m[1]);
        $chunks   = explode($boundary, $body);
        $parts    = [];
        $index    = 0;

        foreach ($chunks as $chunk) {
            if (str_starts_with($chunk, '--') || trim($chunk) === '') {
                continue;
            }

            // Ogni parte: CRLF iniziale, intestazioni, riga vuota, contenuto,
            // CRLF finale prima del boundary successivo.
            $chunk = preg_replace('/^\r?\n/', '', $chunk);
            $split = preg_split('/\r?\n\r?\n/', $chunk, 2);
            if (count($split) !== 2) {
                continue;
            }

            [$rawHeaders, $content] = $split;
            $content = preg_replace('/\r?\n$/', '', $content);

            $headers = [];
            foreach (preg_split('/\r?\n/', $rawHeaders) as $line) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $headers[strtolower(trim($k))] = trim($v);
                }
            }

            $encoding = strtolower($headers['content-transfer-encoding'] ?? '');
            if ($encoding === 'base64') {
                $content = (string) base64_decode(preg_replace('/\s+/', '', $content));
            } elseif ($encoding === 'quoted-printable') {
                $content = quoted_printable_decode($content);
            }

            $cid = isset($headers['content-id']) ? self::normalizeCid($headers['content-id']) : 'part' . $index;
            $parts[$cid] = $content;
            $index++;
        }

        return $parts;
    }

    private static function startCid(string $contentType): ?string
    {
        return preg_match('/start\s*=\s*"?([^";]+)"?/i', $contentType, $m) ? self::normalizeCid($m[1]) : null;
    }

    /** "<abc@x>", "cid:abc%40x" e "abc@x" sono lo stesso Content-ID. */
    private static function normalizeCid(string $cid): string
    {
        $cid = trim($cid, " <>\t");
        if (stripos($cid, 'cid:') === 0) {
            $cid = substr($cid, 4);
        }

        return strtolower(rawurldecode($cid));
    }
}
