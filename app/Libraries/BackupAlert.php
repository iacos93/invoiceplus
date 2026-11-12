<?php

namespace App\Libraries;

/**
 * Promemoria dei backup falliti, per non mandare una email a ogni giro.
 *
 * Il cron-runner riprova un job fallito a ogni passata: senza un freno, un
 * disco pieno o un pg_dump sbagliato produrrebbe una notifica ogni quarto
 * d'ora, e dopo un giorno la casella sarebbe piena di avvisi uguali. Qui si
 * ricorda l'ultima notifica inviata e si lascia passare mezza giornata.
 *
 * Il file è un segnaposto in writable/cron: non contiene dati, serve solo la
 * data. Un backup riuscito lo cancella, così la prossima rottura avvisa
 * subito.
 */
final class BackupAlert
{
    /** Intervallo minimo fra due notifiche dello stesso guasto (12 ore). */
    public const THROTTLE = 43200;

    private int $now;

    /**
     * @param string   $dir cartella degli esiti (writable/cron)
     * @param int|null $now istante di riferimento, per i test
     */
    public function __construct(private string $dir, ?int $now = null)
    {
        $this->now = $now ?? time();
    }

    /** C'è già stato un avviso da meno di THROTTLE secondi? */
    public function shouldNotify(): bool
    {
        $marker = $this->marker();

        if (! is_file($marker)) {
            return true;
        }

        return ($this->now - (int) filemtime($marker)) >= self::THROTTLE;
    }

    /** Registra che l'avviso è stato inviato. */
    public function markNotified(): void
    {
        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }

        @touch($this->marker(), $this->now);
    }

    /** Il backup è riuscito: il prossimo guasto può avvisare subito. */
    public function clear(): void
    {
        @unlink($this->marker());
    }

    private function marker(): string
    {
        return $this->dir . '/backup_failed.last';
    }
}
