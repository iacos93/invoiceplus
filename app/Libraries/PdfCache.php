<?php

namespace App\Libraries;

/**
 * I PDF già stampati, riletti dal disco invece di essere ristampati.
 *
 * Stampare un documento con dompdf costa qualche centinaio di millisecondi, e
 * un documento con il totale delle pagine ne costa il doppio: `pdf_page_total()`
 * fa un passaggio usa-e-getta solo per contare i fogli. Lo stesso PDF però
 * viene chiesto più volte — chi apre la fattura per controllarla e poi la
 * scarica, l'allegato della mail, l'export ZIP, un secondo invio al cliente —
 * e ogni volta si ripartiva da capo.
 *
 * La chiave è l'HTML stesso: se il documento, il cliente, le righe, le
 * impostazioni o il logo cambiano, cambia l'HTML e quindi il file. Non serve
 * sapere *quale* campo è cambiato né fidarsi di una data di modifica che
 * qualche percorso di scrittura dimentica di aggiornare — era il motivo per
 * cui una cache dei PDF era stata rimandata. Nella chiave entra anche la
 * versione di dompdf: un motore nuovo stampa da zero.
 *
 * La cache non deve mai essere il motivo per cui un PDF non esce: ogni errore
 * di disco (cartella non scrivibile, file troncato, spazio finito) ricade sulla
 * stampa normale. E non cresce senza limite: oltre MAX_FILES si tolgono i file
 * più vecchi, come fa la rotazione dei backup.
 */
class PdfCache
{
    /**
     * File tenuti: un PDF di documento pesa 30-80 KB, quindi la cartella resta
     * sotto i 20 MB anche quando ci si dimentica di guardarla.
     */
    public const MAX_FILES = 200;

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? WRITEPATH . 'cache/pdf', '/\\');
    }

    public function file(string $html): string
    {
        return $this->dir . '/' . $this->key($html) . '.pdf';
    }

    /**
     * chiave = HTML + versione del motore di stampa.
     */
    public function key(string $html): string
    {
        // In sviluppo CodeIgniter infila `<!-- DEBUG-VIEW n percorso -->` prima
        // di ogni vista, e il contatore cambia a ogni composizione: senza
        // toglierli la stessa fattura avrebbe una chiave diversa a ogni stampa
        // e la cache non troverebbe mai nulla.
        $html = preg_replace('/<!-- DEBUG-VIEW[^>]*-->\s*/', '', $html) ?? $html;

        return sha1(self::engine() . "\0" . $html);
    }

    public function get(string $html): ?string
    {
        $file = $this->file($html);

        if (!is_file($file)) {
            return null;
        }

        $bytes = @file_get_contents($file);

        // Un file vuoto, troncato (disco pieno a metà scrittura) o senza la
        // chiusura del PDF non è un documento: meglio ristamparlo che servire
        // un file che il lettore non apre.
        if (!is_string($bytes) || !str_starts_with($bytes, '%PDF') || !str_contains(substr($bytes, -2048), '%%EOF')) {
            return null;
        }

        return $bytes;
    }

    /**
     * Il PDF per questo HTML: quello in cache se c'è, altrimenti lo stampa il
     * callback e lo si conserva.
     *
     * @param callable(string): string $render
     */
    public function remember(string $html, callable $render): string
    {
        $bytes = $this->get($html);

        if ($bytes !== null) {
            return $bytes;
        }

        $bytes = $render($html);
        $this->put($html, $bytes);

        return $bytes;
    }

    public function put(string $html, string $bytes): void
    {
        if (!str_starts_with($bytes, '%PDF')) {
            return;
        }

        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return;
        }

        $file = $this->file($html);

        // Scrittura atomica: due richieste contemporanee sullo stesso documento
        // (una mail e un download) non devono leggere un file a metà.
        $tmp = $file . '.' . getmypid() . '.tmp';

        if (@file_put_contents($tmp, $bytes) === false) {
            return;
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return;
        }

        $this->prune();
    }

    /**
     * Tiene i $keep file scritti più di recente.
     *
     * @return int file eliminati
     */
    public function prune(?int $keep = null): int
    {
        $keep  = $keep ?? self::MAX_FILES;
        $files = glob($this->dir . '/*.pdf') ?: [];

        if (count($files) <= $keep) {
            return 0;
        }

        usort($files, static fn (string $a, string $b): int => ((int) @filemtime($a)) <=> ((int) @filemtime($b)));

        $deleted = 0;

        foreach (array_slice($files, 0, count($files) - $keep) as $file) {
            $deleted += @unlink($file) ? 1 : 0;
        }

        return $deleted;
    }

    /**
     * La versione di dompdf fa parte della chiave: aggiornare il motore di
     * stampa deve produrre PDF nuovi, non riusare i vecchi.
     */
    private static function engine(): string
    {
        if (!class_exists(\Composer\InstalledVersions::class)) {
            return 'dompdf';
        }

        return 'dompdf/' . (\Composer\InstalledVersions::getPrettyVersion('dompdf/dompdf') ?? '');
    }
}
