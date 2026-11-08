<?php

namespace App\Libraries;

/**
 * Archivio degli XML delle fatture di acquisto.
 *
 * L'import delle spese leggeva il tracciato e poi lo buttava via: in tabella
 * restava solo il nome del file (expense_file), quindi della fattura ricevuta
 * non si poteva piu' rivedere l'originale. Qui l'XML viene conservato accanto
 * alla spesa, indicizzato per id, cosi' l'anteprima puo' ricostruirlo.
 *
 * Non serve una colonna nuova: il percorso e' deterministico (id della spesa),
 * e expense_file continua a fare da nome visibile del documento.
 */
class FatturaXmlStore
{
    public function dir(): string
    {
        return WRITEPATH . 'uploads/expenses/';
    }

    public function pathFor(int $expenseId): string
    {
        return $this->dir() . $expenseId . '.xml';
    }

    public function has(int $expenseId): bool
    {
        return is_file($this->pathFor($expenseId));
    }

    public function save(int $expenseId, string $xml): bool
    {
        if ($expenseId <= 0 || trim($xml) === '') {
            return false;
        }

        if (!is_dir($this->dir()) && !@mkdir($this->dir(), 0775, true) && !is_dir($this->dir())) {
            return false;
        }

        return @file_put_contents($this->pathFor($expenseId), $xml) !== false;
    }

    public function get(int $expenseId): ?string
    {
        if (!$this->has($expenseId)) {
            return null;
        }

        $xml = @file_get_contents($this->pathFor($expenseId));

        return $xml === false ? null : $xml;
    }

    /** Rimuove l'XML archiviato (usato quando la spesa viene eliminata). */
    public function delete(int $expenseId): void
    {
        if ($this->has($expenseId)) {
            @unlink($this->pathFor($expenseId));
        }
    }
}
