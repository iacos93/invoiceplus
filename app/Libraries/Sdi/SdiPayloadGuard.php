<?php

namespace App\Libraries\Sdi;

/**
 * Limiti di sicurezza sui payload che arrivano dallo SdI.
 *
 * Gli endpoint pubblici SDICoop (TrasmissioneFatture e RicezioneFatture)
 * accettano buste SOAP/MTOM e file FatturaPA da una controparte esterna:
 * senza un tetto, un body enorme o uno ZIP costruito ad arte (poche decine
 * di KB che si espandono in gigabyte) esauriscono la memoria del processo
 * PHP prima ancora che il codice arrivi a leggere il contenuto.
 *
 * Qui stanno soltanto numeri e decisioni, senza I/O e senza database: cosi'
 * i limiti si verificano nei test unitari e si cambiano in un punto solo.
 * Le stesse soglie vanno tenute allineate con il webserver, che deve
 * rifiutare i body troppo grandi prima di passarli a PHP (vedi
 * docs/sdicoop.md): questa classe e' la seconda linea, non l'unica.
 */
final class SdiPayloadGuard
{
    /**
     * Tetto del body SOAP/MTOM (o della richiesta HTTP in generale).
     *
     * 20 MB: una fattura con allegati arriva di norma a poche centinaia di
     * KB, un lotto di fatture a pochi MB. Il margine copre gli allegati
     * pesanti (PDF di cortesia) senza lasciare spazio a una richiesta che
     * PHP debba tenere in memoria due volte (body + parte MTOM decodificata).
     */
    public const MAX_BODY_BYTES = 20 * 1024 * 1024;

    /** Tetto di un singolo XML FatturaPA (o .p7m) gia' decodificato: 10 MB. */
    public const MAX_XML_BYTES = 10 * 1024 * 1024;

    /** Numero massimo di voci in uno ZIP: oltre e' un archivio, non un lotto. */
    public const MAX_ZIP_ENTRIES = 50;

    /** Dimensione decompressa massima di una singola voce ZIP: 50 MB. */
    public const MAX_ZIP_ENTRY_BYTES = 50 * 1024 * 1024;

    /** Dimensione decompressa massima di tutto lo ZIP: 50 MB. */
    public const MAX_ZIP_TOTAL_BYTES = 50 * 1024 * 1024;

    /**
     * Rapporto massimo fra dimensione decompressa e compressa di una voce.
     *
     * Un XML FatturaPA comprime bene (10-20:1); 100:1 lascia margine ai
     * tracciati molto ripetitivi e ferma le "zip bomb" fatte di zeri, che
     * stanno tranquillamente sopra 1000:1.
     */
    public const MAX_ZIP_RATIO = 100;

    /**
     * Problema del body della richiesta, o null se va bene.
     *
     * $contentLength e' l'header dichiarato (puo' mancare o essere una
     * schifezza: in quel caso si guarda solo la lunghezza reale) e
     * $actualBytes la lunghezza effettivamente letta. Passare $actualBytes
     * a null permette di rifiutare la richiesta PRIMA di leggerne il corpo,
     * quando l'header basta a dire che e' troppo grande.
     */
    public static function bodyProblem(?string $contentLength, ?int $actualBytes = null): ?string
    {
        $declared = is_string($contentLength) ? trim($contentLength) : '';

        if ($declared !== '' && ctype_digit($declared) && (int) $declared > self::MAX_BODY_BYTES) {
            return self::tooLargeBody();
        }

        if ($actualBytes !== null && $actualBytes > self::MAX_BODY_BYTES) {
            return self::tooLargeBody();
        }

        return null;
    }

    /** Problema di un singolo XML, o null se va bene. */
    public static function xmlProblem(int $bytes): ?string
    {
        if ($bytes > self::MAX_XML_BYTES) {
            return 'File XML oltre il limite di ' . self::mb(self::MAX_XML_BYTES);
        }

        return null;
    }

    /**
     * Dimensione massima del file ZIP compresso: oltre tutto cio' che siamo
     * disposti a decomprimere non c'e' ragione di aprirlo.
     */
    public static function zipFileProblem(int $bytes): ?string
    {
        return $bytes > self::MAX_ZIP_TOTAL_BYTES
            ? 'Archivio ZIP oltre il limite di ' . self::mb(self::MAX_ZIP_TOTAL_BYTES)
            : null;
    }

    /**
     * Controlla uno ZIP PRIMA di estrarne il contenuto.
     *
     * Ogni voce viene letta con statIndex(): dimensione dichiarata e
     * dimensione compressa, senza decomprimere niente. Solo se questo
     * controllo passa il chiamante puo' chiamare getFromIndex(). Un
     * archivio rifiutato qui non lascia nulla su disco e non alloca il
     * contenuto delle voci.
     */
    public static function zipProblem(\ZipArchive $zip): ?string
    {
        if ($zip->numFiles > self::MAX_ZIP_ENTRIES) {
            return 'Archivio ZIP con ' . $zip->numFiles . ' voci: il massimo consentito e\' ' . self::MAX_ZIP_ENTRIES;
        }

        $total = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                return 'Archivio ZIP illeggibile (voce ' . $i . ')';
            }

            $name = self::cleanName($stat['name']);

            // Le cartelle non hanno contenuto da decomprimere.
            if (str_ends_with($stat['name'], '/')) {
                continue;
            }

            $size = $stat['size'];
            $comp = $stat['comp_size'];

            if ($size > self::MAX_ZIP_ENTRY_BYTES) {
                return 'Voce ZIP ' . $name . ' di ' . self::mb($size)
                    . ', oltre il limite di ' . self::mb(self::MAX_ZIP_ENTRY_BYTES);
            }

            $total += $size;

            if ($total > self::MAX_ZIP_TOTAL_BYTES) {
                return 'Contenuto decompresso dello ZIP oltre il limite di ' . self::mb(self::MAX_ZIP_TOTAL_BYTES);
            }

            // comp_size 0 su una voce non vuota e' ancora peggio di un
            // rapporto alto: si rifiuta con la stessa motivazione.
            $ratio = $size > 0 ? ($comp > 0 ? $size / $comp : INF) : 0.0;

            if ($ratio > self::MAX_ZIP_RATIO) {
                return 'Voce ZIP ' . $name . ' troppo compressa (rapporto '
                    . ($comp > 0 ? (int) round($ratio) : 'infinito') . ':1, massimo '
                    . self::MAX_ZIP_RATIO . ':1): possibile zip bomb';
            }
        }

        return null;
    }

    /**
     * Nome e percorso da usare per conservare un file ricevuto, senza
     * sovrascrivere quello che c'e' gia'.
     *
     * Lo SdI puo' ripetere la consegna dello stesso file (o due file diversi
     * possono arrivare con lo stesso NomeFile). Il file gia' presente non si
     * tocca:
     *  - contenuto identico: si riusa il percorso esistente (write=false),
     *    cosi' l'import a valle resta idempotente;
     *  - contenuto diverso: si sceglie un nome con un suffisso preso
     *    dall'hash del contenuto, prima dell'estensione, e si scrive li'.
     *
     * @return array{path: string, write: bool, duplicate: bool, renamed: bool}
     */
    public static function destination(string $dir, string $name, string $content): array
    {
        $path = rtrim($dir, '/') . '/' . $name;

        if (!is_file($path)) {
            return ['path' => $path, 'write' => true, 'duplicate' => false, 'renamed' => false];
        }

        $existing = @file_get_contents($path);

        if ($existing !== false && hash_equals(hash('sha256', $existing), hash('sha256', $content))) {
            return ['path' => $path, 'write' => false, 'duplicate' => true, 'renamed' => false];
        }

        $alt = rtrim($dir, '/') . '/' . self::suffixedName($name, $content);

        // Consegna ripetuta identica di un file gia' finito con suffisso:
        // si riusa anche quello, senza scrivere.
        if (is_file($alt)) {
            return ['path' => $alt, 'write' => false, 'duplicate' => true, 'renamed' => true];
        }

        return ['path' => $alt, 'write' => true, 'duplicate' => false, 'renamed' => true];
    }

    /**
     * Nome con un suffisso di 12 caratteri esadecimali (dall'hash del
     * contenuto) prima dell'estensione: IT0123_00001.xml diventa
     * IT0123_00001.a1b2c3d4e5f6.xml e IT0123_00001.xml.p7m resta un .p7m.
     */
    public static function suffixedName(string $name, string $content): string
    {
        $suffix = substr(hash('sha256', $content), 0, 12);
        $dot    = strrpos($name, '.');

        // Nessun punto, oppure punto iniziale (nome che comincia con "."):
        // il suffisso va in coda.
        if ($dot === false || $dot === 0) {
            return $name . '.' . $suffix;
        }

        return substr($name, 0, $dot) . '.' . $suffix . substr($name, $dot);
    }

    /**
     * Toglie da un testo URL, credenziali Basic e token: finisce nei log e
     * nei messaggi mostrati all'utente, che non devono contenere ne'
     * l'endpoint configurato ne' i segreti per raggiungerlo.
     */
    public static function redact(string $text): string
    {
        $text = preg_replace('#\bhttps?://[^\s\'"<>]+#i', '[endpoint]', $text) ?? $text;
        $text = preg_replace('#\bBasic\s+[A-Za-z0-9+/=]+#i', 'Basic [redacted]', $text) ?? $text;

        return $text;
    }

    private static function tooLargeBody(): string
    {
        return 'Richiesta oltre il limite di ' . self::mb(self::MAX_BODY_BYTES);
    }

    /** Byte in MB, per i messaggi: 20971520 -> "20 MB". */
    private static function mb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1, ',', ''), '0'), ',') . ' MB';
    }

    /** Nome di voce ZIP ripulito per il log (niente righe o controllo). */
    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $name);

        return $name !== '' ? mb_substr($name, 0, 80) : '(senza nome)';
    }
}
