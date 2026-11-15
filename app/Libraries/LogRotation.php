<?php

namespace App\Libraries;

/**
 * Rotazione di writable/logs e writable/debugbar.
 *
 * I backup si ruotano da soli (14 file), questi due no: il log arriva a
 * 200-460 KB al giorno e la cartella della debugbar a qualche MB, e su
 * un'installazione lasciata andare per anni sono l'unica cosa che cresce
 * senza limite. Nessuno se ne accorge finché il disco non si riempie, e a quel
 * punto l'applicazione smette di scrivere — cioè di funzionare.
 *
 * I log vecchi si comprimono invece di cancellarli subito: un errore accaduto
 * un mese fa si diagnostica ancora, e un .log di testo si riduce a un decimo.
 * La classe non tocca il file del giorno corrente.
 *
 * Riceve percorsi e istante per essere provabile senza aspettare giorni.
 */
class LogRotation
{
    /** Giorni dopo i quali un log viene compresso. */
    public const COMPRESS_AFTER_DAYS = 7;

    /** Giorni dopo i quali un log compresso viene eliminato. */
    public const DELETE_AFTER_DAYS = 180;

    /** Giorni dopo i quali un file della debugbar viene eliminato. */
    public const DEBUGBAR_DAYS = 14;

    /**
     * @return array{compressed: int, deleted: int, failed: int}
     */
    public static function run(
        ?string $logsDir = null,
        ?string $debugbarDir = null,
        ?int $now = null
    ): array {
        $logsDir     ??= WRITEPATH . 'logs';
        $debugbarDir ??= WRITEPATH . 'debugbar';
        $now         ??= time();

        $result = ['compressed' => 0, 'deleted' => 0, 'failed' => 0];

        foreach (glob($logsDir . '/log-*.log') ?: [] as $file) {
            // Il file del giorno si sta ancora scrivendo: comprimerlo adesso
            // vorrebbe dire perdere quello che arriva dopo.
            if (date('Y-m-d', (int) filemtime($file)) === date('Y-m-d', $now)) {
                continue;
            }

            if ((int) filemtime($file) > $now - self::COMPRESS_AFTER_DAYS * 86400) {
                continue;
            }

            $result[self::gzip($file) ? 'compressed' : 'failed']++;
        }

        foreach (glob($logsDir . '/log-*.log.gz') ?: [] as $file) {
            if ((int) filemtime($file) < $now - self::DELETE_AFTER_DAYS * 86400) {
                $result[self::remove($file) ? 'deleted' : 'failed']++;
            }
        }

        if (is_dir($debugbarDir)) {
            foreach (glob($debugbarDir . '/*') ?: [] as $file) {
                if (is_file($file) && (int) filemtime($file) < $now - self::DEBUGBAR_DAYS * 86400) {
                    $result[self::remove($file) ? 'deleted' : 'failed']++;
                }
            }
        }

        return $result;
    }

    /**
     * Toglie un file il cui nome arriva da glob(), quindi già dentro la
     * cartella: un permesso negato conta come fallimento, non come eccezione.
     */
    private static function remove(string $file): bool
    {
        return @unlink($file);
    }

    /**
     * Comprime un file in `.gz` e toglie l'originale.
     *
     * Solo a compressione riuscita e non vuota: un .gz troncato al posto del
     * log è peggio del log non compresso.
     */
    private static function gzip(string $file): bool
    {
        if (!function_exists('gzencode')) {
            return false;
        }

        $contenuto = @file_get_contents($file);

        if ($contenuto === false) {
            return false;
        }

        $compresso = @gzencode($contenuto, 9);

        if ($compresso === false || @file_put_contents($file . '.gz', $compresso) === false) {
            return false;
        }

        return self::remove($file);
    }
}
