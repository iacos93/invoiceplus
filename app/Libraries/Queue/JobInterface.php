<?php

namespace App\Libraries\Queue;

/**
 * Contratto per i job accodabili su Redis.
 *
 * Ogni job è una classe serializzabile con un singolo metodo handle().
 * Lo stato necessario all'esecuzione è passato via costruttore e
 * serializzato insieme al job nella coda.
 */
interface JobInterface
{
    public function handle(): void;
}
