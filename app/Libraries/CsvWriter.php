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

        fputcsv($handle, $headers, $delimiter, '"', '\\');

        foreach ($rows as $row) {
            fputcsv($handle, $row, $delimiter, '"', '\\');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }
}
