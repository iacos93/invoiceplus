<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Le chiavi d'accesso dei documenti diventano UNIQUE, dove si può.
 *
 * `invoice_url_key`, `quote_url_key` e `uploads.url_key` sono le uniche
 * colonne dell'applicazione interrogate da fuori: le usa il cliente che apre il
 * link della fattura o scarica un allegato, senza sessione. Avevano un indice
 * semplice (la fattura già UNIQUE dalla migrazione degli indici), ma non erano
 * univoche: due documenti con la stessa chiave erano possibili per costruzione,
 * e la chiave è l'unica cosa che tiene in piedi quell'accesso.
 *
 * Dove i duplicati ci sono già — un archivio importato da CI3 — l'indice unico
 * farebbe fallire l'aggiornamento, e un'installazione che non si aggiorna è
 * peggio di un indice mancante: in quel caso si lascia il semplice e si scrive
 * nel log che cosa c'è da sistemare.
 */
class UniqueUrlKeys extends Migration
{
    /** @var list<array{table: string, column: string, index: string}> */
    private const TARGETS = [
        ['table' => 'ip_quotes', 'column' => 'quote_url_key', 'index' => 'ip_quotes_idx_url_key'],
        ['table' => 'ip_uploads', 'column' => 'url_key', 'index' => 'ip_uploads_idx_url_key'],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $target) {
            $this->recreate($target['table'], $target['column'], $target['index'], true);
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as $target) {
            $this->recreate($target['table'], $target['column'], $target['index'], false);
        }
    }

    /**
     * Ricrea l'indice, unico o semplice secondo $unique.
     *
     * Prima lo toglie: su MariaDB e PostgreSQL l'indice si sostituisce, non si
     * modifica, e tenerne due uguali (uno unico e uno no) confonderebbe il
     * pianificatore e chi guarda lo schema.
     */
    private function recreate(string $table, string $column, string $index, bool $unique): void
    {
        $duplicati = 0;

        if ($unique) {
            // Un'unica query che conta i valori ripetuti: se non ce ne sono,
            // il vincolo si può imporre. Il conteggio è sull'archivio intero,
            // perché una chiave doppia viola il vincolo anche fra aziende
            // diverse.
            $row = $this->db->query(
                'SELECT COUNT(*) AS n FROM (SELECT ' . $column . ' AS k FROM ' . $table
                . " WHERE " . $column . " IS NOT NULL AND " . $column . " <> '' GROUP BY " . $column . ' HAVING COUNT(*) > 1) d'
            )->getRowArray();

            $duplicati = (int) ($row['n'] ?? 0);
        }

        if ($duplicati > 0) {
            log_message('warning', 'Indice unico su {table}.{column} non creato: {n} valori ripetuti. Le chiavi d\'accesso doppie vanno corrette a mano.', [
                'table'  => $table,
                'column' => $column,
                'n'      => $duplicati,
            ]);

            $unique = false;
        }

        if (array_key_exists(strtolower($index), $this->db->getIndexData($table))) {
            $this->forge->dropKey($table, $index, true);
        }

        $this->forge->addKey($column, false, $unique, $index);
        $this->forge->processIndexes($table);
    }
}
