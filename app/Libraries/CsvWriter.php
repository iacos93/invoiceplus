<?php

namespace App\Libraries;

/**
 * CsvWriter — unico punto in cui l'applicazione scrive un CSV.
 *
 * Le stesse righe (BOM, fputcsv, php://temp) erano copiate in cinque posti:
 * esportazioni, anagrafica clienti, anagrafica fornitori, modelli dei contatti
 * e modello d'importazione. Bastava che una copia cambiasse virgolette o BOM
 * perché il file uscito dall'app non rientrasse più dall'import — cosa che era
 * già successa con le intestazioni.
 *
 * Sta accanto a CsvReader di proposito: lettura e scrittura devono restare
 * d'accordo su delimitatore e codifica, e si vedono nello stesso posto.
 */
class CsvWriter
{
    /**
     * Caratteri che, in prima posizione, fanno leggere una cella come formula
     * da Excel, LibreOffice e Google Sheets. Il TAB e il CR non sono formule
     * di per sé: sono i prefissi usati per neutralizzarle e vanno comunque
     * trattati, altrimenti la neutralizzazione si annullerebbe da sola.
     */
    private const FORMULA_LEADERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Numero semplice: segno meno facoltativo, cifre e separatori decimali o
     * delle migliaia. Serve a distinguere un importo da una formula.
     */
    private const PLAIN_NUMBER = '/^-?\d+(?:[.,]\d+)*$/';

    /**
     * Neutralizza una cella che un foglio di calcolo potrebbe eseguire.
     *
     * Il prefisso scelto è un TAB, non l'apostrofo: il TAB è spazio bianco per
     * i fogli di calcolo, quindi il testo resta testo, e CsvReader::rows()
     * fa trim() di ogni cella — così il giro esporta → reimporta restituisce
     * lo stesso valore. Con l'apostrofo il carattere sarebbe rimasto dentro
     * il dato importato ("'=cmd") perché la lettura non lo rimuove.
     *
     * Il meno è pericoloso solo se non è un numero semplice. Un importo come
     * "-1234,56" è un numero per Excel, non una formula, e in un'app di
     * fatturazione deve restare numerico (note di credito, saldi, pagamenti):
     * prefissarlo lo farebbe diventare testo e romperebbe le somme. Le catene
     * che iniziano con il meno ma contengono altro ("-1+1", "-2+3+cmd|...")
     * restano invece neutralizzate.
     *
     * Non è una sanificazione del contenuto: la cella resta leggibile, cambia
     * solo il primo carattere che il foglio di calcolo vede.
     */
    public static function neutralizeFormula(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        if ($value[0] === '-' && preg_match(self::PLAIN_NUMBER, $value) === 1) {
            return $value;
        }

        return in_array($value[0], self::FORMULA_LEADERS, true) ? "\t" . $value : $value;
    }

    /**
     * CSV completo come stringa, BOM compreso.
     *
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     */
    public static function toString(array $headers, array $rows, string $delimiter = CsvReader::DELIMITER): string
    {
        $handle = fopen('php://temp', 'r+');

        // Senza BOM Excel apre il file come ASCII e accenti e simboli di
        // valuta arrivano illeggibili.
        fwrite($handle, "\xEF\xBB\xBF");

        // Anche le intestazioni passano dalla neutralizzazione: costano nulla
        // e un'intestazione scelta da un utente non deve poter diventare una
        // formula. La normalizzazione in lettura ignora il TAB grazie al trim().
        $cells = static fn (array $row): array => array_map(
            static fn ($value): string => self::neutralizeFormula((string) $value),
            $row
        );

        fputcsv($handle, $cells($headers), $delimiter, '"', '\\');

        foreach ($rows as $row) {
            fputcsv($handle, $cells($row), $delimiter, '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
