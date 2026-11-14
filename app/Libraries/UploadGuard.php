<?php

namespace App\Libraries;

use CodeIgniter\HTTP\Files\UploadedFile;

/**
 * Controlli comuni sui file caricati.
 *
 * Prima di questa classe ogni punto di ingresso verificava solo l'estensione
 * dichiarata dal browser (getClientExtension()), che è un'etichetta nel
 * multipart e non una proprietà del file: un PDF rinominato .csv passava, e
 * un file da centinaia di megabyte veniva letto interamente in memoria prima
 * di accorgersene. Qui i controlli stanno una volta sola, con limiti e
 * allowlist espliciti, e i controller si limitano a chiedere un motivo
 * leggibile.
 *
 * Regola sul MIME: se finfo riconosce un tipo chiaramente incompatibile con
 * l'estensione, il file si rifiuta. Se finfo non riconosce nulla si accetta
 * solo testo/CSV (dove il tipo è spesso assente), non PDF o XML. Il MIME
 * dichiarato dal client non viene mai preso in considerazione.
 */
class UploadGuard
{
    /* --------------------------------------------------------- tipi */

    /** CSV e file di testo (import contatti, estratto conto, import dati). */
    public const KIND_CSV = 'csv';

    /** XML: fatture elettroniche, lotti ZIP e buste .p7m. */
    public const KIND_XML = 'xml';

    /** PDF: modelli F24, quietanze, allegati. */
    public const KIND_PDF = 'pdf';

    /** Allegati generici ai documenti (immagini, PDF, testo, XML). */
    public const KIND_ATTACHMENT = 'attachment';

    /* --------------------------------------------------------- limiti */

    /**
     * Limiti di dimensione per tipo, in byte.
     *
     * 5 MB per i CSV: un'anagrafica completa resta abbondantemente sotto, e
     * un tetto basso evita di leggere in memoria file che non sono CSV.
     * 10 MB per XML/PDF/allegati: i lotti FatturaPA e gli F24 firmati
     * stanno sotto questa soglia, mentre un file enorme è quasi sempre un
     * errore o un invio sbagliato.
     *
     * @var array<string, int>
     */
    private const LIMITS = [
        self::KIND_CSV        => 5 * 1024 * 1024,
        self::KIND_XML        => 10 * 1024 * 1024,
        self::KIND_PDF        => 10 * 1024 * 1024,
        self::KIND_ATTACHMENT => 10 * 1024 * 1024,
    ];

    /* ----------------------------------------------------- estensioni */

    /**
     * Estensioni ammesse per tipo.
     *
     * @var array<string, list<string>>
     */
    private const EXTENSIONS = [
        self::KIND_CSV => ['csv', 'txt'],
        // .zip per i lotti di fatture, .p7m per le buste firmate.
        self::KIND_XML        => ['xml', 'zip', 'p7m'],
        self::KIND_PDF        => ['pdf'],
        self::KIND_ATTACHMENT => ['gif', 'jpg', 'jpeg', 'png', 'pdf', 'txt', 'xml'],
    ];

    /* --------------------------------------------------------- MIME */

    /**
     * MIME accettati per estensione.
     *
     * I CSV arrivano dai browser con etichette diverse: "text/csv" quando il
     * sistema lo conosce, "application/vnd.ms-excel" quando è Excel a
     * esportarli, "text/plain" quando sono solo testo. "application/octet-
     * stream" è il caso in cui finfo non riconosce il contenuto: si accetta
     * solo per CSV/testo, come da regola.
     *
     * Per XML e PDF l'elenco è volutamente stretto: application/zip vale solo
     * per i lotti .zip, application/pkcs7-mime e application/octet-stream
     * solo per le buste .p7m.
     *
     * @var array<string, list<string>>
     */
    private const MIME_BY_EXTENSION = [
        'csv' => [
            'text/csv',
            'application/csv',
            'application/vnd.ms-excel',
            'text/plain',
            'text/x-csv',
            'application/x-csv',
            'application/octet-stream',
        ],
        'txt' => [
            'text/plain',
            'text/csv',
            'application/csv',
            'application/vnd.ms-excel',
            'application/octet-stream',
        ],
        // Un XML con BOM o righe vuote in testa viene visto da finfo come
        // semplice testo: il parser XML resta comunque l'ultima parola.
        'xml' => ['text/xml', 'application/xml', 'text/plain'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        // I .p7m arrivano sia come CMS binari sia in base64 (alcuni
        // intermediari li consegnano così): in quest'ultimo caso finfo vede
        // solo testo. Il parser distingue comunque una busta vera da un .txt
        // rinominato.
        'p7m' => ['application/pkcs7-mime', 'application/x-pkcs7-mime', 'application/octet-stream', 'text/plain'],
        'pdf' => ['application/pdf'],
        'gif' => ['image/gif'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    /** Estensioni per cui un MIME non riconosciuto si può ancora accettare. */
    private const TEXT_EXTENSIONS = ['csv', 'txt'];

    /**
     * Dimensione massima per tipo, in byte.
     *
     * Un tipo sconosciuto ricade sul limite degli allegati: meglio un tetto
     * prudente che nessun tetto.
     */
    public static function maxBytes(string $kind): int
    {
        return self::LIMITS[$kind] ?? self::LIMITS[self::KIND_ATTACHMENT];
    }

    /**
     * MIME reale del file, letto dal contenuto.
     *
     * Restituisce stringa vuota se finfo non riesce a leggere il file o non
     * riconosce il contenuto: chi chiama decide se il vuoto è accettabile
     * (solo per CSV/testo).
     */
    public static function detectMime(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return '';
        }

        // Da PHP 8.5 finfo_close() è deprecata: l'oggetto viene liberato da
        // solo, e chiamarla farebbe scrivere un avviso nei log a ogni upload.
        $mime = finfo_file($finfo, $path);

        if (!is_string($mime)) {
            return '';
        }

        // finfo può restituire "text/plain; charset=us-ascii".
        $mime = strtolower(trim(explode(';', $mime, 2)[0]));

        return $mime === 'inode/x-empty' || $mime === 'application/x-empty' ? '' : $mime;
    }

    /**
     * Il MIME è compatibile con tipo ed estensione?
     *
     * Non guarda mai il MIME dichiarato dal client: quello arriva dal
     * chiamante solo se è stato letto dal contenuto.
     */
    public static function mimeAllowed(string $kind, string $mime, string $extension): bool
    {
        $extension = strtolower(ltrim(trim($extension), '.'));
        $mime      = strtolower(trim(explode(';', $mime, 2)[0]));

        if (!in_array($extension, self::EXTENSIONS[$kind] ?? [], true)) {
            return false;
        }

        // Contenuto non riconosciuto: si accetta solo dove il testo è la
        // norma, cioè CSV e .txt.
        if ($mime === '') {
            return in_array($extension, self::TEXT_EXTENSIONS, true);
        }

        if (!array_key_exists($extension, self::MIME_BY_EXTENSION)) {
            return false;
        }

        return in_array($mime, self::MIME_BY_EXTENSION[$extension], true);
    }

    /**
     * Controllo completo su un file già salvato sul disco.
     *
     * Restituisce null se va bene, altrimenti un motivo leggibile da mostrare
     * all'utente. Il file non viene mai aperto per intero: la dimensione si
     * legge da filesize() e il tipo da finfo.
     */
    public static function check(string $kind, string $path, string $originalName): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return 'File non trovato o non leggibile.';
        }

        $size = filesize($path);

        if ($size === false) {
            return 'Impossibile determinare la dimensione del file.';
        }

        $limit = self::maxBytes($kind);

        if ($size > $limit) {
            return 'Il file supera il limite di ' . self::label($limit) . ' (dimensione: '
                . self::label($size) . ').';
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed   = self::EXTENSIONS[$kind] ?? [];

        if (!in_array($extension, $allowed, true)) {
            return 'Estensione non ammessa' . ($extension !== '' ? ' (.' . $extension . ')' : '')
                . '. Ammesse: ' . implode(', ', $allowed) . '.';
        }

        // Un file vuoto non ha un tipo riconoscibile: per CSV/testo è
        // comunque accettabile e lo segnala il parser.
        $mime = $size === 0 ? '' : self::detectMime($path);

        if (!self::mimeAllowed($kind, $mime, $extension)) {
            $detected = $mime !== '' ? $mime : 'non riconosciuto';

            return 'Il contenuto del file (' . $detected . ') non corrisponde all\'estensione .'
                . $extension . '.';
        }

        return null;
    }

    /**
     * Controllo su un UploadedFile non ancora spostato.
     *
     * Centralizza anche isValid()/hasMoved() così i controller non ripetono
     * la stessa sequenza. Restituisce null se il file è accettabile.
     */
    public static function checkUploadedFile(UploadedFile $file, string $kind): ?string
    {
        if (!$file->isValid() || $file->hasMoved()) {
            return 'Il file non è stato caricato correttamente.';
        }

        return self::check($kind, $file->getTempName(), $file->getClientName());
    }

    /**
     * L'url_key di un documento è un hash esadecimale di 32 caratteri?
     *
     * Il valore arriva dall'URL e finisce nel nome del file salvato: senza
     * questo controllo bastava un url_key arbitrario per depositare allegati
     * con un nome scelto da chi chiama.
     */
    public static function isValidUrlKey(string $urlKey): bool
    {
        return preg_match('/^[0-9a-f]{32}$/i', $urlKey) === 1;
    }

    /** "10485760" → "10 MB", per i messaggi d'errore. */
    private static function label(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            $mb = $bytes / (1024 * 1024);

            return rtrim(rtrim(number_format($mb, 1, ',', ''), '0'), ',') . ' MB';
        }

        if ($bytes >= 1024) {
            return rtrim(rtrim(number_format($bytes / 1024, 1, ',', ''), '0'), ',') . ' KB';
        }

        return $bytes . ' byte';
    }
}
