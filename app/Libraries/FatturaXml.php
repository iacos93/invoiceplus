<?php

namespace App\Libraries;

use App\Libraries\Sdi\P7mSignature;
use App\Libraries\Sdi\SdiPayloadGuard;

/**
 * Lettura e verifica dei file FatturaPA.
 *
 * Due usi distinti:
 *   - parse()    estrae i dati da una fattura RICEVUTA, per registrarla come spesa
 *   - validate() verifica che una fattura EMESSA rispetti lo schema ufficiale,
 *                prima di mandarla allo SdI e vedersela scartare
 *
 * I file possono arrivare in tre forme: XML semplice, XML firmato (.xml.p7m) o
 * ZIP scaricato dal portale. Le prime due si distinguono dal contenuto, non
 * dall'estensione, che spesso è sbagliata.
 */
class FatturaXml
{
    private const XSD = APPPATH . 'Libraries/fatturapa/schema/FatturaPA_1.2.1.xsd';

    /**
     * Motivo dell'ultimo extract() andato a vuoto per un limite di sicurezza
     * (file o ZIP troppo grande, zip bomb) o per una firma digitale non
     * valida. extract() continua a restituire una lista vuota come per un
     * file illeggibile, ma chi chiama puo' distinguere i due casi e dirlo
     * all'utente invece di rispondere "formato non riconosciuto" a un file
     * che era leggibilissimo.
     */
    private ?string $lastError = null;

    /**
     * Esito dell'ultima busta CMS incontrata da extract(): null se il
     * documento non era firmato (o se non c'era una busta), altrimenti la
     * struttura restituita da P7mSignature::inspect().
     *
     * Serve a chi vuole sapere se il file era un .p7m e come è andata la
     * verifica senza rileggere il contenuto. Per uno ZIP con più documenti
     * vale l'ultima voce ispezionata: l'esito del singolo documento viaggia
     * anche nell'array restituito da extract() (chiave "signature").
     */
    private ?array $lastSignature = null;

    /**
     * Motivi dei documenti scartati perché la firma è risultata non valida.
     * Un documento manomesso viene rifiutato; gli altri dello stesso ZIP
     * restano importabili.
     *
     * @var list<string>
     */
    private array $rejectedSignatures = [];

    /** Motivo del rifiuto per firma non valida della busta appena letta. */
    private ?string $signatureRejection = null;

    /**
     * Verifica il tracciato contro lo schema ufficiale.
     *
     * @return array{valid: bool, errors: list<string>}
     */
    public function validate(string $xml): array
    {
        if (!is_file(self::XSD)) {
            return ['valid' => false, 'errors' => ['Schema XSD non disponibile: ' . self::XSD]];
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $doc = new \DOMDocument();

        if (!$doc->loadXML($xml, LIBXML_NONET)) {
            $errors = $this->collectErrors();
            libxml_use_internal_errors($previous);

            return ['valid' => false, 'errors' => $errors ?: ['XML non leggibile']];
        }

        // Lo schema importa xmldsig-core-schema.xsd, che ora sta in locale
        // accanto alla XSD. Il loader qui sotto chiude la porta alla rete per
        // tutta la durata della validazione: se un giorno qualcuno
        // rimettesse un URL remoto nello schemaLocation, la validazione
        // fallirebbe subito con un errore leggibile invece di restare appesa
        // a w3.org (che risponde lento o in 429 e bloccava l'invio allo SdI).
        $previousLoader = libxml_get_external_entity_loader();
        libxml_set_external_entity_loader(static function (?string $public, string $system) {
            return preg_match('#^(https?|ftp)://#i', $system) ? null : $system;
        });

        try {
            $valid = $doc->schemaValidate(self::XSD);
        } finally {
            libxml_set_external_entity_loader($previousLoader);
        }

        $errors = $valid ? [] : $this->collectErrors();

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return ['valid' => $valid, 'errors' => $errors];
    }

    /**
     * @return list<string>
     */
    private function collectErrors(): array
    {
        $errors = [];

        foreach (libxml_get_errors() as $error) {
            // Il messaggio grezzo cita il namespace completo a ogni riga: qui
            // interessa il campo che non va, non l'URI dello schema.
            $message = trim($error->message);
            $message = preg_replace('/\{[^}]*\}/', '', $message);

            $errors[] = ($error->line ? 'Riga ' . $error->line . ': ' : '') . self::italianMessage($message);
        }

        return array_values(array_unique($errors));
    }

    /**
     * Traduce le formule ricorrenti di libxml.
     *
     * Le piu' frequenti su un tracciato FatturaPA sono tre — elemento non
     * atteso, elemento obbligatorio mancante, valore fuori formato — e in
     * inglese dicono il contrario di cio' che serve sapere: "Element 'Nazione':
     * This element is not expected" non fa capire che manca l'indirizzo. La
     * forma non riconosciuta resta com'è: meglio l'originale che una
     * traduzione inventata.
     */
    private static function italianMessage(string $message): string
    {
        // libxml antepone la sfaccettatura che ha fallito: [facet 'pattern].
        $message = (string) preg_replace("/^\[facet '[^']*'\]\s*/", '', $message);

        // Doppi apici perché i modelli sono pieni di apici singoli: cosi' il
        // backslash serve solo a preg_replace, non a PHP.
        $rules = [
            // "Element 'Nazione': This element is not expected. Expected is ( Indirizzo )."
            "/^Element '([^']+)': This element is not expected\. Expected is \( (.+?) \)\.$/",
            "/^Element '([^']+)': This element is not expected\.$/",
            "/^Missing child element\(s\)\. Expected is \( (.+?) \)\.$/",
            "/^Element '([^']+)': Missing child element\(s\)\. Expected is \( (.+?) \)\.$/",
            "/^Element '([^']+)': The attribute '([^']+)' is required but missing\.$/",
            "/^The value '([^']*)' is not accepted by the pattern '([^']+)'[*+?]?\.$/",
            "/^The value '([^']*)' is not an element of the set \{.*\}\.$/",
            "/^The value '([^']*)' is not a valid value of the (?:atomic type|union type) '([^']+)'?\.$/",
            '/^The document has no root element\.$/',
            "/^Start tag expected, '<' not found$/",
            "/^Premature end of data in tag (\S+) line (\d+)$/",
        ];

        $italian = [
            "Elemento '$1' non atteso: qui è previsto $2.",
            "Elemento '$1' non atteso.",
            'Manca un elemento obbligatorio: $1.',
            "All'elemento '$1' manca $2.",
            "All'elemento '$1' manca l'attributo '$2'.",
            "Il valore '$1' non rispetta il formato di $2.",
            "Il valore '$1' non è tra quelli ammessi.",
            "Il valore '$1' non è valido per $2.",
            'Il documento non ha un elemento radice.',
            'Atteso un tag di apertura: il file non comincia come un XML.',
            "Il tag '$1' (riga $2) non è mai stato chiuso.",
        ];

        foreach ($rules as $i => $pattern) {
            if (preg_match($pattern, $message)) {
                return (string) preg_replace($pattern, $italian[$i], $message);
            }
        }

        return $message;
    }

    /**
     * Estrae il contenuto XML da un file che può essere .xml, .xml.p7m (firmato)
     * o uno ZIP. Ritorna una lista di documenti XML.
     *
     * Ogni documento porta anche "signature": l'esito di P7mSignature::inspect()
     * se il file era una busta CMS, null per un XML in chiaro. Non è un
     * requisito per l'import: un .p7m non verificabile viene comunque letto.
     *
     * @return list<array{name: string, xml: string, signature: ?array}>
     */
    public function extract(string $path, string $originalName): array
    {
        $this->lastError          = null;
        $this->lastSignature      = null;
        $this->rejectedSignatures = [];

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        // La firma ZIP si guarda sui primi byte: un archivio non va letto
        // tutto in memoria per decidere come trattarlo.
        $head = (string) fread($handle, 4);
        $stat = fstat($handle);
        fclose($handle);

        $size = (int) ($stat['size'] ?? 0);

        // ZIP: firma "PK\x03\x04" all'inizio del file. I limiti dello ZIP
        // sono su numero di voci e contenuto decompresso (SdiPayloadGuard),
        // non sulla dimensione del file compresso.
        if ($head === "PK\x03\x04") {
            return $this->extractFromZip($path, $size);
        }

        // XML singolo: il tetto si controlla sul filesize, prima di
        // caricare il contenuto in memoria.
        if (($problem = SdiPayloadGuard::xmlProblem($size)) !== null) {
            return $this->reject($problem);
        }

        $raw = (string) @file_get_contents($path);

        if ($raw === '') {
            return [];
        }

        $xml = $this->unwrapP7m($raw);

        if ($xml === null) {
            // Una firma non valida è un rifiuto esplicito, non un file
            // illeggibile: il motivo va in lastError(), come per i limiti.
            if ($this->signatureRejection !== null) {
                return $this->reject($this->signatureRejection);
            }

            return [];
        }

        return [['name' => $originalName, 'xml' => $xml, 'signature' => $this->lastSignature]];
    }

    /**
     * @return list<array{name: string, xml: string, signature: ?array}>
     */
    private function extractFromZip(string $path, int $size): array
    {
        // Un archivio compresso piu' grande di tutto cio' che siamo disposti
        // a decomprimere non ha motivo di essere aperto.
        if (($problem = SdiPayloadGuard::zipFileProblem($size)) !== null) {
            return $this->reject($problem);
        }

        $zip = new \ZipArchive();

        if ($zip->open($path) !== true) {
            return [];
        }

        // Numero di voci, dimensione per voce, totale e rapporto di
        // compressione si leggono da statIndex(), senza decomprimere nulla.
        if (($problem = SdiPayloadGuard::zipProblem($zip)) !== null) {
            $zip->close();

            return $this->reject($problem);
        }

        $documents = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            // Cartelle e file di sistema dei pacchetti scaricati dal portale.
            if (str_ends_with($name, '/') || str_starts_with(basename($name), '.')) {
                continue;
            }

            // Il tetto del singolo XML vale anche dentro lo ZIP. Una voce
            // fuori misura si salta (le altre fatture dell'archivio restano
            // importabili) invece di far fallire tutto il pacchetto.
            $entry = $zip->statIndex($i);
            $bytes = (int) ($entry['size'] ?? 0);

            if (($problem = SdiPayloadGuard::xmlProblem($bytes)) !== null) {
                log_message('warning', 'FatturaXml: voce ' . basename($name) . ' dello ZIP ignorata: ' . $problem);

                continue;
            }

            $content = (string) $zip->getFromIndex($i);
            $xml     = $this->unwrapP7m($content);

            if ($xml !== null) {
                $documents[] = ['name' => basename($name), 'xml' => $xml, 'signature' => $this->lastSignature];

                continue;
            }

            // Firma non valida dentro un archivio: si salta la voce, non
            // tutto il pacchetto. Le altre fatture del lotto restano
            // importabili; il motivo finisce nel log e in lastError().
            if ($this->signatureRejection !== null) {
                log_message('error', 'FatturaXml: voce ' . basename($name) . ' rifiutata: ' . $this->signatureRejection);
            }
        }

        $zip->close();

        if ($this->rejectedSignatures !== []) {
            $this->lastError = implode('; ', $this->rejectedSignatures);
        }

        return $documents;
    }

    /**
     * Esito dell'ultima busta CMS ispezionata (null se l'ultimo documento
     * non era firmato). Vedi P7mSignature::inspect() per la struttura.
     *
     * @return array<string, mixed>|null
     */
    public function lastSignature(): ?array
    {
        return $this->lastSignature;
    }

    /** Motivo dell'ultimo rifiuto per limiti di sicurezza o firma non valida (null se non c'e' stato). */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Rifiuta il payload: resta il motivo per chi chiama e una riga nel log,
     * ma il contratto di extract() non cambia (lista vuota, nessuna
     * eccezione: gli import da form la gestiscono gia' cosi').
     *
     * @return list<array{name: string, xml: string, signature: ?array}>
     */
    private function reject(string $problem): array
    {
        $this->lastError = $problem;
        log_message('error', 'FatturaXml: payload rifiutato: ' . $problem);

        return [];
    }

    /**
     * Estrae l'XML da un contenuto eventualmente firmato CAdES (.p7m).
     *
     * La firma viene verificata in modo best-effort con P7mSignature::inspect(),
     * una volta sola, dove la busta viene già aperta. La politica è:
     *   - valid === false: OpenSSL ha letto la busta e il digest/la firma non
     *     tornano. Il contenuto è stato modificato: il documento viene
     *     rifiutato (null + motivo in lastError()). Un .p7m manomesso non
     *     deve diventare una spesa o una fattura.
     *   - valid === null: la busta non è verificabile (BER non digerito,
     *     nessuna firma, openssl assente). Il documento viene accettato e
     *     l'avviso arriva dagli importer: le buste reali dello SdI sono BER a
     *     lunghezza indefinita e non si può perdere una fattura valida solo
     *     perché OpenSSL non la capisce.
     *   - valid === true: firma coerente col contenuto, si accetta.
     * La scadenza del certificato non rende invalida la busta: viene
     * segnalata come avviso (P7mSignature::warning()).
     *
     * Ritorna null se dentro non c'è una fattura.
     *
     * L'ordine dei tentativi conta. I .p7m consegnati dallo SdI sono CMS in
     * codifica BER a lunghezza indefinita, con il contenuto spezzato in
     * blocchi da 1000 byte (OCTET STRING costruita): fra un blocco e l'altro
     * ci sono gli header ASN.1 (04 82 03 e8...). Il vecchio taglio "dal
     * primo <FatturaElettronica all'ultima chiusura" prendeva anche quei byte
     * e consegnava al parser un XML con spazzatura binaria in mezzo, che poi
     * non si leggeva. Si decodifica quindi prima la busta per davvero
     * (berEnvelopeContent), poi si prova openssl, e solo come ultima spiaggia
     * il taglio per sottostringa, che resta valido per le buste a blocco unico.
     */
    private function unwrapP7m(string $content): ?string
    {
        $this->lastSignature      = null;
        $this->signatureRejection = null;

        if ($this->looksLikeInvoice($content) && !$this->looksLikeDer($content)) {
            return $this->stripBom($content);
        }

        // I .p7m sono spesso in base64: si prova a decodificarli come tali,
        // e il risultato è a sua volta una busta DER da aprire.
        $decoded = base64_decode(preg_replace('/\s+/', '', $content), true);

        if ($decoded !== false && $decoded !== '') {
            if ($this->looksLikeInvoice($decoded) && !$this->looksLikeDer($decoded)) {
                return $this->stripBom($decoded);
            }

            if ($this->looksLikeDer($decoded)) {
                $content = $decoded;
            }
        }

        if ($this->looksLikeDer($content)) {
            // Verifica una volta sola, prima di estrarre: se il contenuto è
            // stato modificato non ha senso consegnarlo al parser.
            $this->lastSignature = P7mSignature::inspect($content);

            if (($this->lastSignature['valid'] ?? null) === false) {
                $this->signatureRejection = 'firma digitale non valida: il contenuto è stato modificato';
                $this->rejectedSignatures[] = $this->signatureRejection;

                log_message('error', 'FatturaXml: .p7m rifiutato: ' . $this->signatureRejection);

                return null;
            }

            foreach ([$this->berEnvelopeContent($content), $this->opensslEnvelopeContent($content)] as $inner) {
                if ($inner !== null && $this->looksLikeInvoice($inner)) {
                    return $this->stripBom($inner);
                }
            }
        }

        // Busta binaria non decodificabile: l'XML sta comunque dentro, fra il
        // primo tag FatturaElettronica e la chiusura corrispondente. Funziona
        // solo se il contenuto è in un blocco unico, ma è meglio di niente.
        foreach (['FatturaElettronica', 'fatturaelettronica'] as $tag) {
            $start = strpos($content, '<' . $tag);
            $end   = strrpos($content, '</' . $tag);

            if ($start !== false && $end !== false) {
                $chunk = substr($content, $start, $end - $start + strlen($tag) + 3);

                if ($this->looksLikeInvoice($chunk)) {
                    return $chunk;
                }
            }
        }

        return null;
    }

    /** Una busta CMS comincia con una SEQUENCE (0x30) seguita dalla lunghezza. */
    private function looksLikeDer(string $content): bool
    {
        return strlen($content) > 16 && $content[0] === "\x30"
            && str_contains(substr($content, 0, 32), "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x07\x02");
    }

    private function stripBom(string $xml): string
    {
        return str_starts_with($xml, "\xEF\xBB\xBF") ? substr($xml, 3) : $xml;
    }

    /**
     * Contenuto incapsulato di una busta CMS SignedData, letto con un
     * decodificatore BER minimo.
     *
     * Percorso: ContentInfo -> [0] -> SignedData -> encapContentInfo (terzo
     * elemento) -> [0] -> OCTET STRING. L'OCTET STRING può essere costruita
     * (tag 0x24, a blocchi): i blocchi si concatenano. Niente librerie né
     * processi esterni, così funziona anche dove openssl non c'è.
     */
    private function berEnvelopeContent(string $der): ?string
    {
        $pos  = 0;
        $root = $this->berNode($der, $pos, strlen($der), 0);

        $signed = $root['children'][1]['children'][0] ?? null;
        $encap  = $signed['children'][2] ?? null;
        $octets = $encap['children'][1]['children'][0] ?? null;

        if ($octets === null || ($octets['tag'] & 0x1f) !== 0x04) {
            return null;
        }

        return $this->berOctets($octets);
    }

    /**
     * Un nodo BER: tag, valore (se primitivo) o figli (se costruito).
     * Gestisce lunghezze definite e indefinite (terminate da 00 00).
     *
     * @return array{tag: int, value: ?string, children: list<array>}|null
     */
    private function berNode(string $d, int &$pos, int $end, int $depth): ?array
    {
        if ($depth > 32 || $pos + 2 > $end) {
            return null;
        }

        $tag = ord($d[$pos++]);

        // Tag a numero alto: i byte successivi con il bit 8 acceso proseguono il numero.
        if (($tag & 0x1f) === 0x1f) {
            while ($pos < $end && (ord($d[$pos++]) & 0x80)) {
            }
        }

        if ($pos >= $end) {
            return null;
        }

        $lenByte    = ord($d[$pos++]);
        $indefinite = $lenByte === 0x80;
        $length     = 0;

        if (!$indefinite) {
            if ($lenByte < 0x80) {
                $length = $lenByte;
            } else {
                $n = $lenByte & 0x7f;

                if ($n > 4 || $pos + $n > $end) {
                    return null;
                }

                for ($i = 0; $i < $n; $i++) {
                    $length = ($length << 8) | ord($d[$pos++]);
                }
            }

            if ($pos + $length > $end) {
                return null;
            }
        }

        $constructed = (bool) ($tag & 0x20);
        $node        = ['tag' => $tag, 'value' => null, 'children' => []];

        if (!$constructed) {
            if ($indefinite) {
                return null;
            }

            $node['value'] = substr($d, $pos, $length);
            $pos += $length;

            return $node;
        }

        $limit = $indefinite ? $end : $pos + $length;

        while ($pos < $limit) {
            if ($indefinite && $pos + 1 < $end && $d[$pos] === "\x00" && $d[$pos + 1] === "\x00") {
                $pos += 2;

                return $node;
            }

            $child = $this->berNode($d, $pos, $limit, $depth + 1);

            if ($child === null) {
                return null;
            }

            $node['children'][] = $child;
        }

        // Lunghezza indefinita senza terminatore: busta troncata.
        return $indefinite ? null : $node;
    }

    private function berOctets(array $node): string
    {
        if ($node['value'] !== null) {
            return $node['value'];
        }

        $out = '';

        foreach ($node['children'] as $child) {
            $out .= $this->berOctets($child);
        }

        return $out;
    }

    /**
     * Ripiego su OpenSSL (equivalente di `openssl cms -verify -noverify
     * -inform DER`) per le buste che il decodificatore minimo non regge.
     * Lavora su file temporanei perché l'API PHP vuole percorsi.
     */
    private function opensslEnvelopeContent(string $der): ?string
    {
        if (!function_exists('openssl_cms_verify')) {
            return null;
        }

        $in  = tempnam(sys_get_temp_dir(), 'p7m');
        $out = tempnam(sys_get_temp_dir(), 'xml');

        try {
            file_put_contents($in, $der);

            $ok = @openssl_cms_verify($in, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_NOSIGS, null, [], null, $out, null, null, OPENSSL_ENCODING_DER);

            $xml = $ok ? (string) file_get_contents($out) : '';

            return $xml !== '' ? $xml : null;
        } finally {
            @unlink($in);
            @unlink($out);
            while (openssl_error_string() !== false) {
            }
        }
    }

    private function looksLikeInvoice(string $content): bool
    {
        return stripos($content, 'FatturaElettronica') !== false
            && stripos($content, 'CedentePrestatore') !== false;
    }

    /**
     * Tipi documento che stornano un acquisto: l'importo va in negativo,
     * altrimenti una nota di credito ricevuta finisce nei costi come una
     * fattura e il report Utile e imposte la somma invece di sottrarla.
     * Alcuni emittenti scrivono già gli importi in negativo nella TD04 (non
     * sarebbe corretto, ma succede): per questo il segno si impone sul valore
     * assoluto invece di moltiplicare.
     */
    public const CREDIT_NOTE_TYPES = ['TD04', 'TD08'];

    /**
     * Integrazioni e autofatture per inversione contabile (TD16-TD19, più
     * TD28 per San Marino). L'imposta che riportano è a debito e a credito
     * insieme: non è un costo, e caricarla come IVA d'acquisto gonfierebbe
     * la spesa. Per TD17/TD18/TD19/TD28 il documento è però l'unica traccia
     * elettronica dell'acquisto dall'estero (la fattura del fornitore estero
     * non passa dallo SdI), quindi l'imponibile entra come costo con imposta
     * zero. La TD16 invece integra una fattura italiana già ricevuta con
     * natura N6: l'imponibile è già nei costi con quella, e reimportarlo lo
     * raddoppierebbe -- vedi REVERSE_CHARGE_INTEGRATION.
     */
    public const REVERSE_CHARGE_TYPES = ['TD16', 'TD17', 'TD18', 'TD19', 'TD28'];

    public const REVERSE_CHARGE_INTEGRATION = 'TD16';

    /**
     * Un file "lotto" diviso in tanti documenti quante sono le fatture.
     *
     * Il tracciato ammette più FatturaElettronicaBody sotto la stessa
     * testata: è il lotto di fatture, stesso cedente e stesso cessionario,
     * usato da chi emette molte fatture verso lo stesso cliente (utenze,
     * canoni). parse() legge un body solo, e l'import registrava come spesa
     * soltanto la prima fattura del lotto: le altre sparivano senza errore.
     *
     * Ogni pezzo è l'XML originale con la testata intera e un solo body, non
     * un estratto ricostruito: è quello che finisce in FatturaXmlStore e
     * nell'anteprima della spesa, e deve restare una FatturaPA valida.
     * Namespace e prefissi del file vengono conservati, perché si lavora sul
     * DOM e si tolgono soltanto i body degli altri documenti.
     *
     * Un file con un solo body (il caso normale) torna com'è, senza passare
     * dal DOM; uno che il DOM non legge torna com'è anche lui, e sarà
     * parse() a dire che non è una fattura.
     *
     * @return list<string>
     */
    public function splitBodies(string $xml): array
    {
        $xml = $this->stripBom($xml);

        if (preg_match_all('/<(?:[A-Za-z0-9_]+:)?FatturaElettronicaBody[\s>]/', $xml) < 2) {
            return [$xml];
        }

        $previous = libxml_use_internal_errors(true);
        $doc      = new \DOMDocument();
        $loaded   = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $doc->documentElement === null) {
            return [$xml];
        }

        $count = count($this->bodyNodes($doc));

        if ($count < 2) {
            return [$xml];
        }

        $pieces = [];

        for ($keep = 0; $keep < $count; $keep++) {
            $copy = new \DOMDocument();
            $copy->preserveWhiteSpace = true;
            $copy->loadXML($xml, LIBXML_NONET);

            foreach ($this->bodyNodes($copy) as $index => $body) {
                if ($index !== $keep) {
                    $body->parentNode->removeChild($body);
                }
            }

            $pieces[] = $copy->saveXML();
        }

        return $pieces;
    }

    /**
     * Figli diretti della radice che sono un FatturaElettronicaBody, con
     * qualunque prefisso (o nessuno).
     *
     * @return list<\DOMElement>
     */
    private function bodyNodes(\DOMDocument $doc): array
    {
        $bodies = [];

        foreach ($doc->documentElement->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'FatturaElettronicaBody') {
                $bodies[] = $child;
            }
        }

        return $bodies;
    }

    /**
     * Documento completo, per ricostruire una fattura EMESSA nel gestionale.
     *
     * parse() legge solo i totali che servono a una spesa; per importare il
     * ciclo attivo servono anche cliente, righe, sconti, aliquote, riferimenti
     * di commessa e pagamento — tutto ciò che serve perché la fattura
     * ricreata dica la stessa cosa dell'originale. Legge un solo body: un
     * lotto va prima diviso con splitBodies().
     *
     * Restano fuori di proposito: Allegati (il PDF di cortesia, che il
     * gestionale rigenera), DatiDDT, DatiTrasporto, RiferimentoAmministrazione
     * di riga — non hanno un posto nel modello della fattura.
     *
     * Gli importi tornano come nel tracciato (positivi anche per una TD04):
     * il segno interno lo decide chi importa.
     *
     * @return array<string, mixed>|null
     */
    public function parseIssued(string $xml): ?array
    {
        $xml = $this->stripBom($xml);

        // Gli allegati base64 possono pesare megabyte e qui non servono:
        // toglierli prima del parsing rende la lettura di uno ZIP con decine
        // di fatture questione di millisecondi.
        $xml = preg_replace('#<((?:[A-Za-z0-9_]+:)?)Allegati>.*?</\1Allegati>#s', '', $xml) ?? $xml;

        $flat = simplexml_load_string(preg_replace('/(<\/?)[a-zA-Z0-9]+:/', '$1', $xml), \SimpleXMLElement::class, LIBXML_NONET);

        if ($flat === false || !isset($flat->FatturaElettronicaHeader, $flat->FatturaElettronicaBody)) {
            return null;
        }

        $header  = $flat->FatturaElettronicaHeader;
        $body    = $flat->FatturaElettronicaBody[0];
        $general = $body->DatiGenerali->DatiGeneraliDocumento ?? null;

        if ($general === null || !isset($header->CedentePrestatore, $header->CessionarioCommittente)) {
            return null;
        }

        $t   = static fn($node): string => trim((string) ($node ?? ''));
        $num = static fn($node): float => (float) str_replace(',', '.', trim((string) ($node ?? '0')));

        $party = static function ($node) use ($t): array {
            $anag = $node->DatiAnagrafici->Anagrafica ?? null;
            $sede = $node->Sede ?? null;

            return [
                'vat'      => $t($node->DatiAnagrafici->IdFiscaleIVA->IdCodice ?? null),
                'country'  => $t($node->DatiAnagrafici->IdFiscaleIVA->IdPaese ?? null) ?: $t($sede->Nazione ?? null),
                'tax_code' => $t($node->DatiAnagrafici->CodiceFiscale ?? null),
                'name'     => $t($anag->Denominazione ?? null),
                'first'    => $t($anag->Nome ?? null),
                'last'     => $t($anag->Cognome ?? null),
                'address'  => trim($t($sede->Indirizzo ?? null) . ' ' . $t($sede->NumeroCivico ?? null)),
                'zip'      => $t($sede->CAP ?? null),
                'city'     => $t($sede->Comune ?? null),
                'state'    => $t($sede->Provincia ?? null),
                'nation'   => $t($sede->Nazione ?? null),
            ];
        };

        $lines = [];
        foreach ($body->DatiBeniServizi->DettaglioLinee ?? [] as $line) {
            $discountPercent = 0.0;
            $discountAmount  = 0.0;

            // Sconti di riga in cascata: la percentuale sul prezzo, l'importo
            // per unità — lo stesso modello di InvoiceMath::lineAmounts().
            // Più nodi dello stesso tipo si sommano; MG (maggiorazione) è
            // uno sconto negativo.
            foreach ($line->ScontoMaggiorazione ?? [] as $sm) {
                $sign = strtoupper($t($sm->Tipo)) === 'MG' ? -1 : 1;

                if (isset($sm->Percentuale)) {
                    $discountPercent += $sign * $num($sm->Percentuale);
                } elseif (isset($sm->Importo)) {
                    $discountAmount += $sign * abs($num($sm->Importo));
                }
            }

            $codes = [];
            foreach ($line->CodiceArticolo ?? [] as $code) {
                $codes[strtoupper($t($code->CodiceTipo))] = $t($code->CodiceValore);
            }

            $lines[] = [
                'description'      => $t($line->Descrizione),
                'quantity'         => isset($line->Quantita) ? $num($line->Quantita) : 1.0,
                'unit'             => $t($line->UnitaMisura ?? null),
                'price'            => $num($line->PrezzoUnitario),
                'total'            => $num($line->PrezzoTotale),
                'rate'             => $num($line->AliquotaIVA),
                'natura'           => $t($line->Natura ?? null) ?: null,
                'discount_percent' => round($discountPercent, 2),
                'discount_amount'  => round($discountAmount, 2),
                'codes'            => $codes,
                'type'             => $t($line->TipoCessionePrestazione ?? null) ?: null,
            ];
        }

        $taxable = 0.0;
        $tax     = 0.0;
        $exigibility = null;
        foreach ($body->DatiBeniServizi->DatiRiepilogo ?? [] as $riepilogo) {
            $taxable += $num($riepilogo->ImponibileImporto);
            $tax     += $num($riepilogo->Imposta);
            $exigibility ??= $t($riepilogo->EsigibilitaIVA ?? null) ?: null;
        }

        $reference = static function ($nodes) use ($t): ?array {
            $node = $nodes[0] ?? null;
            if ($node === null) {
                return null;
            }

            return [
                'id'   => $t($node->IdDocumento),
                'date' => $t($node->Data ?? null) ?: null,
                'line' => $t($node->NumItem ?? null) ?: ($t($node->RiferimentoNumeroLinea ?? null) ?: null),
                'cig'  => $t($node->CodiceCIG ?? null) ?: null,
                'cup'  => $t($node->CodiceCUP ?? null) ?: null,
            ];
        };

        $payment = $body->DatiPagamento->DettaglioPagamento[0] ?? null;

        $causale = [];
        foreach ($general->Causale ?? [] as $c) {
            $causale[] = $t($c);
        }

        return [
            'format'        => strtoupper($t($flat['versione'] ?? null) ?: $t($header->DatiTrasmissione->FormatoTrasmissione ?? null)),
            'sdi_code'      => $t($header->DatiTrasmissione->CodiceDestinatario ?? null),
            'sdi_pec'       => $t($header->DatiTrasmissione->PECDestinatario ?? null),
            'supplier'      => $party($header->CedentePrestatore),
            'customer'      => $party($header->CessionarioCommittente),
            'document_type' => strtoupper($t($general->TipoDocumento)) ?: 'TD01',
            'number'        => $t($general->Numero),
            'date'          => $t($general->Data),
            'declared_total' => isset($general->ImportoTotaleDocumento) ? $num($general->ImportoTotaleDocumento) : null,
            'causale'       => implode("\n", array_filter($causale)),
            'withholding'   => isset($general->DatiRitenuta) ? [
                'type'   => $t($general->DatiRitenuta[0]->TipoRitenuta),
                'rate'   => $num($general->DatiRitenuta[0]->AliquotaRitenuta),
                'reason' => $t($general->DatiRitenuta[0]->CausalePagamento ?? null),
            ] : null,
            'fund'          => isset($general->DatiCassaPrevidenziale) ? [
                'type' => $t($general->DatiCassaPrevidenziale[0]->TipoCassa),
                'rate' => $num($general->DatiCassaPrevidenziale[0]->AlCassa),
            ] : null,
            'stamp'         => isset($general->DatiBollo) ? $num($general->DatiBollo->ImportoBollo ?? 2) : null,
            'order'         => $reference($body->DatiGenerali->DatiOrdineAcquisto ?? null),
            'contract'      => $reference($body->DatiGenerali->DatiContratto ?? null),
            'convention'    => $reference($body->DatiGenerali->DatiConvenzione ?? null),
            'receipt'       => $reference($body->DatiGenerali->DatiRicezione ?? null),
            'linked'        => $reference($body->DatiGenerali->DatiFattureCollegate ?? null),
            'admin_reference' => $t($header->CedentePrestatore->RiferimentoAmministrazione ?? null) ?: null,
            'lines'         => $lines,
            'taxable'       => round($taxable, 2),
            'tax'           => round($tax, 2),
            'total'         => round($taxable + $tax, 2),
            'vat_exigibility' => $exigibility,
            'payment'       => [
                'mode'     => $t($payment->ModalitaPagamento ?? null) ?: null,
                'due_date' => $t($payment->DataScadenzaPagamento ?? null) ?: null,
                'amount'   => $payment !== null && isset($payment->ImportoPagamento) ? $num($payment->ImportoPagamento) : null,
                'iban'     => $t($payment->IBAN ?? null) ?: null,
            ],
        ];
    }

    /**
     * Dati essenziali di una fattura ricevuta.
     *
     * Il totale non si legge da ImportoTotaleDocumento (facoltativo e spesso
     * assente): si sommano imponibile e imposta dei riepiloghi IVA, che sono
     * obbligatori. Le fatture semplificate (FSM10, TD07/TD08) non hanno
     * riepiloghi: lì ogni riga porta l'importo IVA inclusa e l'imposta.
     *
     * Gli importi tornano già col segno e il trattamento IVA del tipo
     * documento (vedi CREDIT_NOTE_TYPES e REVERSE_CHARGE_TYPES).
     *
     * @return array{
     *     document_type: string,
     *     supplier_name: string, supplier_vat: string, supplier_tax_code: string,
     *     customer_vat: string, customer_tax_code: string,
     *     number: string, date: string, amount: float, tax: float, total: float
     * }|null
     */
    public function parse(string $xml): ?array
    {
        $xml = $this->stripBom($xml);

        // LIBXML_NONET: stesso hardening di FatturaPreview per XML di
        // provenienza esterna (qui una fattura di acquisto ricevuta da
        // terzi) -- niente richieste di rete innescate dal parser.
        $previous = libxml_use_internal_errors(true);
        $doc      = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false) {
            return null;
        }

        // I file usano prefissi di namespace diversi (p:, ns2:, nessuno):
        // si azzerano per poter usare percorsi uniformi.
        $flat = simplexml_load_string(preg_replace('/(<\/?)[a-zA-Z0-9]+:/', '$1', $xml), \SimpleXMLElement::class, LIBXML_NONET);

        if ($flat === false) {
            return null;
        }

        $header = $flat->FatturaElettronicaHeader ?? null;
        $body   = $flat->FatturaElettronicaBody ?? null;

        if ($header === null || $body === null || !isset($header->CedentePrestatore)) {
            return null;
        }

        $supplier = $header->CedentePrestatore->DatiAnagrafici ?? null;
        $customer = $header->CessionarioCommittente->DatiAnagrafici ?? null;
        $general  = $body->DatiGenerali->DatiGeneraliDocumento ?? null;

        if ($general === null) {
            return null;
        }

        $name = trim((string) ($supplier->Anagrafica->Denominazione ?? ''));

        if ($name === '') {
            $name = trim(
                (string) ($supplier->Anagrafica->Nome ?? '') . ' ' .
                (string) ($supplier->Anagrafica->Cognome ?? '')
            );
        }

        $vat = trim(
            (string) ($supplier->IdFiscaleIVA->IdPaese ?? '') .
            (string) ($supplier->IdFiscaleIVA->IdCodice ?? '')
        );

        // Nel tracciato semplificato il committente può avere solo
        // IdentificativiFiscali al posto di DatiAnagrafici.
        $customerIds = $customer ?? ($header->CessionarioCommittente->IdentificativiFiscali ?? null);
        $customerVat = trim(
            (string) ($customerIds->IdFiscaleIVA->IdPaese ?? '') .
            (string) ($customerIds->IdFiscaleIVA->IdCodice ?? '')
        );

        $type   = strtoupper(trim((string) ($general->TipoDocumento ?? '')));
        $amount = 0.0;
        $tax    = 0.0;

        if (isset($body->DatiBeniServizi->DatiRiepilogo)) {
            foreach ($body->DatiBeniServizi->DatiRiepilogo as $riepilogo) {
                $amount += (float) ($riepilogo->ImponibileImporto ?? 0);
                $tax    += (float) ($riepilogo->Imposta ?? 0);
            }
        } else {
            // Semplificata: Importo è IVA inclusa, DatiIVA/Imposta la quota d'imposta.
            foreach ($body->DatiBeniServizi ?? [] as $line) {
                $lineTax  = (float) ($line->DatiIVA->Imposta ?? 0);
                $amount  += (float) ($line->Importo ?? 0) - $lineTax;
                $tax     += $lineTax;
            }
        }

        if (in_array($type, self::CREDIT_NOTE_TYPES, true)) {
            $amount = -abs($amount);
            $tax    = -abs($tax);
        }

        if (in_array($type, self::REVERSE_CHARGE_TYPES, true)) {
            $tax = 0.0;
        }

        $date = trim((string) ($general->Data ?? ''));

        return [
            'document_type'     => $type !== '' ? $type : 'TD01',
            'supplier_name'     => $name !== '' ? $name : 'Fornitore sconosciuto',
            'supplier_vat'      => $vat,
            'supplier_tax_code' => trim((string) ($supplier->CodiceFiscale ?? '')),
            'customer_vat'      => $customerVat,
            'customer_tax_code' => trim((string) ($customerIds->CodiceFiscale ?? '')),
            'number'            => trim((string) ($general->Numero ?? '')),
            'date'              => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d'),
            'amount'            => round($amount, 2),
            'tax'               => round($tax, 2),
            'total'             => round($amount + $tax, 2),
        ];
    }
}
