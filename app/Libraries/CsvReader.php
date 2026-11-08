<?php

namespace App\Libraries;

/**
 * CsvReader — lettura e normalizzazione dei CSV in ingresso.
 *
 * Esisteva già una lettura robusta, ma solo dentro ContactImporter (import
 * clienti/fornitori dalle anagrafiche): l'import "storico" (ImportModel,
 * porting di Mdl_import CI3) leggeva invece con
 *
 *     fgetcsv($handle, 1000, ',')
 *
 * cioè virgola fissa, riga tagliata a 1000 byte e nessuna gestione del BOM.
 * Tutto il resto dell'applicazione — esportazioni, template, testi della UI —
 * dichiara invece punto e virgola e UTF-8 con BOM, perché è quello che Excel
 * in locale italiano scrive e si aspetta. Il risultato era che un file uscito
 * da questa stessa applicazione non rientrava: la prima intestazione arrivava
 * come "\xEF\xBB\xBFclient_name" e l'import moriva su "Intestazioni non
 * valide". Questa classe è l'unico punto in cui si decide come si legge un
 * CSV, così le due strade non possono più divergere.
 *
 * È volutamente priva di dipendenze (niente database, niente settings): le
 * conversioni di data e importo sono funzioni pure e si testano senza HTTP.
 */
class CsvReader
{
    /** Delimitatore usato in scrittura e come ripiego quando il rilevamento non decide. */
    public const DELIMITER = ';';

    /** Delimitatori candidati, in ordine di preferenza a parità di conteggio. */
    private const CANDIDATES = [';', ',', "\t", '|'];

    /**
     * Righe di un file CSV, intestazioni comprese (prima riga).
     * Le righe interamente vuote sono scartate: sono il newline di troppo che
     * Excel lascia in fondo al file, non un record.
     *
     * @return list<list<string>>
     */
    public static function rows(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        return self::rowsFromString((string) file_get_contents($path));
    }

    /**
     * @return list<list<string>>
     */
    public static function rowsFromString(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $raw = self::stripBom($raw);

        // Excel su Windows salva ancora in Windows-1252 se non gli si dice
        // altro: senza questa conversione "Città" arriva come "Citt<E0>" e la
        // colonna non viene riconosciuta dal matching delle intestazioni.
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1, Windows-1252');
        }

        $delimiter = self::detectDelimiter(self::firstLine($raw));

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $raw);
        rewind($handle);

        $rows = [];

        // Lunghezza 0 = nessun limite: il vecchio 1000 troncava a metà le
        // descrizioni di riga fattura, e il troncamento avveniva in silenzio.
        while (($data = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if (self::isBlank($data)) {
                continue;
            }

            $rows[] = array_map(static fn($value) => trim((string) $value), $data);
        }

        fclose($handle);

        return $rows;
    }

    public static function stripBom(string $raw): string
    {
        return str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;
    }

    /**
     * Delimitatore più frequente nella riga di intestazione. Si guarda solo la
     * prima riga perché è l'unica in cui il separatore non può essere anche
     * contenuto (le intestazioni non sono mai quotate con virgole dentro).
     */
    public static function detectDelimiter(string $firstLine): string
    {
        $best  = self::DELIMITER;
        $count = 0;

        foreach (self::CANDIDATES as $candidate) {
            $found = substr_count($firstLine, $candidate);

            if ($found > $count) {
                $best  = $candidate;
                $count = $found;
            }
        }

        return $count > 0 ? $best : self::DELIMITER;
    }

    /**
     * Chiave di confronto per un'intestazione: minuscolo, senza spazi né
     * punteggiatura. Serve perché lo stesso campo arriva scritto in troppi
     * modi ("client_name", "Client Name", "Nome / Ragione Sociale").
     */
    public static function normalizeHeader(string $header): string
    {
        $clean = mb_strtolower(trim(self::stripBom($header)));

        return str_replace([' ', '_', '-', '.', '/', '\\', "'", '"'], '', $clean);
    }

    /**
     * Data in Y-m-d da un valore di foglio di calcolo.
     *
     * I CSV reali arrivano in tre dialetti — italiano (31/12/2025), ISO
     * (2025-12-31) e americano (12/31/2025 dai gestionali anglosassoni) — e
     * finora venivano scritti nel database così com'erano: MySQL li rifiutava
     * o li riduceva a 0000-00-00, e la fattura importata risultava senza data
     * di emissione. Ritorna null se il valore non è una data riconoscibile,
     * così il chiamante può decidere se saltare la riga o lasciare il campo
     * vuoto.
     */
    public static function toDate(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || strtolower($value) === 'null') {
            return null;
        }

        // Le date/ora ("2025-12-31 00:00:00") si riducono alla sola parte data.
        $value = trim(explode(' ', $value)[0]);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
            return self::assemble((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $value, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];

            // Ambiguo per costruzione: 03/04/2025 è il 3 aprile in Italia e il
            // 4 marzo negli Stati Uniti. Si sceglie il formato italiano, che è
            // quello dell'applicazione, e si ripiega su mese/giorno solo quando
            // il primo numero non può essere un giorno.
            if ($a > 12 || $b <= 12) {
                return self::assemble((int) $m[3], $b, $a);
            }

            return self::assemble((int) $m[3], $a, $b);
        }

        return null;
    }

    /**
     * Importo con il punto decimale, pronto per una colonna DECIMAL.
     *
     * Excel italiano scrive "1.234,56": inserito così com'è, MySQL lo tronca a
     * 1.23 e il totale della fattura importata risulta mille volte più piccolo.
     * Ritorna null quando il valore non è un numero, per distinguere "campo
     * vuoto" da "zero".
     */
    public static function toDecimal(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || strtolower($value) === 'null') {
            return null;
        }

        // Via simboli di valuta e spazi (anche quello unificatore che Excel
        // infila come separatore delle migliaia).
        $value = str_replace(["\xC2\xA0", ' ', '€', '$', '£'], '', $value);

        $lastComma = strrpos($value, ',');
        $lastDot   = strrpos($value, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // Convivono entrambi: l'ultimo dei due è il separatore decimale,
            // l'altro è quello delle migliaia.
            if ($lastComma > $lastDot) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($lastComma !== false) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (string) (float) $value : null;
    }

    /** Riga vuota: nessuna cella, oppure solo celle vuote/null. */
    private static function isBlank(array $data): bool
    {
        foreach ($data as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function firstLine(string $raw): string
    {
        $end = strcspn($raw, "\r\n");

        return substr($raw, 0, $end);
    }

    /** Y-m-d solo se la data esiste davvero (scarta 31/02). */
    private static function assemble(int $year, int $month, int $day): ?string
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
