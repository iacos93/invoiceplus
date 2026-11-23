<?php

namespace App\Database\Migrations;

use App\Libraries\ClientSnapshot;
use CodeIgniter\Database\Migration;

/**
 * Copia dei dati del cliente dentro fatture, preventivi e DDT.
 *
 * Fino a oggi l'intestazione dei documenti si leggeva dall'anagrafica a ogni
 * apertura: correggere il nome di un cliente cambiava anche le fatture già
 * emesse, e lo stesso numero di documento mostrava due intestazioni diverse a
 * seconda di quando lo si apriva.
 *
 * Una colonna JSON per tabella, non dodici colonne: i campi congelati hanno lo
 * stesso nome che arriva dal join (client_name, client_address_1, ...), così
 * l'applicazione sopra la riga basta e nessun template cambia.
 *
 * I documenti esistenti prendono copia dai dati attuali dell'anagrafica, una
 * volta sola: è l'unica informazione disponibile, e da qui in avanti restano
 * fermi anche loro.
 */
class AddClientSnapshotToDocuments extends Migration
{
    /** Tabella => chiave primaria. */
    private const TABLES = [
        'ip_invoices'        => 'invoice_id',
        'ip_quotes'          => 'quote_id',
        'ip_delivery_notes'  => 'delivery_note_id',
    ];

    /** Documenti per giro nel riempimento: un UPDATE massivo alla volta no. */
    private const BACKFILL_BATCH = 500;

    public function up()
    {
        foreach (self::TABLES as $table => $primaryKey) {
            if (!$this->db->fieldExists('client_snapshot', $table)) {
                $this->forge->addColumn($table, [
                    'client_snapshot' => [
                        'type'    => 'TEXT',
                        'null'    => true,
                        'default' => null,
                    ],
                ]);
            }
        }

        $this->backfill();
    }

    public function down()
    {
        foreach (self::TABLES as $table => $primaryKey) {
            if ($this->db->fieldExists('client_snapshot', $table)) {
                $this->forge->dropColumn($table, 'client_snapshot');
            }
        }
    }

    /**
     * Riempie solo le righe ancora vuote, a blocchi: la condizione
     * `client_snapshot IS NULL` fa avanzare da sola il giro, senza offset che
     * salterebbero righe quando nel frattempo la tabella cambia.
     */
    private function backfill(): void
    {
        foreach (self::TABLES as $table => $primaryKey) {
            do {
                $rows = $this->db->table($table)
                    ->select("{$table}.{$primaryKey} AS doc_id, c.*", false)
                    ->join('ip_clients AS c', "c.client_id = {$table}.client_id", 'inner')
                    ->where("{$table}.client_snapshot IS NULL", null, false)
                    ->orderBy("{$table}.{$primaryKey}", 'ASC')
                    ->limit(self::BACKFILL_BATCH)
                    ->get()
                    ->getResultArray();

                foreach ($rows as $row) {
                    $this->db->table($table)
                        ->where($primaryKey, (int) $row['doc_id'])
                        ->update(['client_snapshot' => ClientSnapshot::encode($row)]);
                }
            } while (count($rows) === self::BACKFILL_BATCH);
        }
    }
}
