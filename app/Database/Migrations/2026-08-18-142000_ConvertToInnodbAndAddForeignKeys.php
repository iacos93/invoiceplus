<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Porta tutto lo schema su InnoDB e vincola le tabelle figlie alle loro
 * tabelle padre.
 *
 * Trenta tabelle ereditate da InvoicePlane erano ancora MyISAM, che non
 * conosce transazioni ne' chiavi esterne: il salvataggio di una fattura tocca
 * documento, righe, importi delle righe e totali, e su MyISAM un errore a
 * meta' lasciava il documento in uno stato intermedio senza possibilita' di
 * rollback. E' anche la ragione per cui in archivio c'erano righe di importi
 * senza piu' la fattura corrispondente.
 *
 * Le chiavi esterne sono in ON DELETE CASCADE dove il figlio non ha senso
 * senza il padre (righe, importi, campi custom): la cancellazione manuale
 * degli orfani sparsa nei model resta come rete, ma non e' piu' l'unica
 * garanzia.
 */
class ConvertToInnodbAndAddForeignKeys extends Migration
{
    /** [tabella figlia, colonna, tabella padre, colonna padre] */
    private const FOREIGN_KEYS = [
        ['ip_invoice_amounts',      'invoice_id', 'ip_invoices',       'invoice_id'],
        ['ip_invoice_items',        'invoice_id', 'ip_invoices',       'invoice_id'],
        ['ip_invoice_item_amounts', 'item_id',    'ip_invoice_items',  'item_id'],
        ['ip_invoice_custom',       'invoice_id', 'ip_invoices',       'invoice_id'],
        ['ip_invoice_tax_rates',    'invoice_id', 'ip_invoices',       'invoice_id'],
        ['ip_quote_amounts',        'quote_id',   'ip_quotes',         'quote_id'],
        ['ip_quote_items',          'quote_id',   'ip_quotes',         'quote_id'],
        ['ip_quote_item_amounts',   'item_id',    'ip_quote_items',    'item_id'],
        ['ip_quote_custom',         'quote_id',   'ip_quotes',         'quote_id'],
        ['ip_quote_tax_rates',      'quote_id',   'ip_quotes',         'quote_id'],
        ['ip_payments',             'invoice_id', 'ip_invoices',       'invoice_id'],
        ['ip_payment_custom',       'payment_id', 'ip_payments',       'payment_id'],
        ['ip_client_custom',        'client_id',  'ip_clients',        'client_id'],
        ['ip_client_notes',         'client_id',  'ip_clients',        'client_id'],
        ['ip_delivery_note_items',  'delivery_note_id', 'ip_delivery_notes', 'delivery_note_id'],
    ];

    public function up()
    {
        if ($this->db->DBDriver === 'MySQLi') {
            // 1. Tutto su InnoDB: senza questo passaggio le chiavi esterne
            //    verrebbero accettate dal parser e silenziosamente ignorate.
            foreach ($this->myisamTables() as $table) {
                $this->db->query("ALTER TABLE {$table} ENGINE=InnoDB");
            }

            // 2. Gli orfani gia' presenti impedirebbero la creazione dei vincoli.
            foreach (self::FOREIGN_KEYS as [$child, $column, $parent, $parentColumn]) {
                if (!$this->db->tableExists($child) || !$this->db->tableExists($parent)) {
                    continue;
                }

                $this->db->query("
                    DELETE c FROM {$child} c
                    LEFT JOIN {$parent} p ON p.{$parentColumn} = c.{$column}
                    WHERE c.{$column} IS NOT NULL AND p.{$parentColumn} IS NULL");
            }
        } else {
            // 2. Gli orfani gia' presenti impedirebbero la creazione dei vincoli.
            foreach (self::FOREIGN_KEYS as [$child, $column, $parent, $parentColumn]) {
                if (!$this->db->tableExists($child) || !$this->db->tableExists($parent)) {
                    continue;
                }

                $this->db->query("
                    DELETE FROM {$child}
                    WHERE {$column} IS NOT NULL AND {$column} NOT IN (
                        SELECT {$parentColumn} FROM {$parent}
                    )");
            }
        }

        // 3. I vincoli veri e propri.
        foreach (self::FOREIGN_KEYS as [$child, $column, $parent, $parentColumn]) {
            if (!$this->db->tableExists($child) || !$this->db->tableExists($parent)) {
                continue;
            }

            $name = 'fk_' . str_replace('ip_', '', $child) . '_' . $column;

            if ($this->constraintExists($child, $name)) {
                continue;
            }

            // Una colonna figlia con tipo diverso dal padre farebbe fallire la
            // migrazione: si allinea prima, cosi' il vincolo e' accettabile.
            $this->db->query("ALTER TABLE {$child}
                ADD CONSTRAINT {$name} FOREIGN KEY ({$column})
                REFERENCES {$parent}({$parentColumn}) ON DELETE CASCADE");
        }
    }

    public function down()
    {
        foreach (self::FOREIGN_KEYS as [$child, $column, , ]) {
            $name = 'fk_' . str_replace('ip_', '', $child) . '_' . $column;

            if ($this->db->tableExists($child) && $this->constraintExists($child, $name)) {
                $this->db->query("ALTER TABLE {$child} DROP CONSTRAINT {$name}");
            }
        }
        // Il motore non torna a MyISAM: sarebbe una regressione, non un ripristino.
    }

    private function myisamTables(): array
    {
        $rows = $this->db->query("
            SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND ENGINE = 'MyISAM'")->getResultArray();

        return array_column($rows, 'TABLE_NAME');
    }

    private function constraintExists(string $table, string $name): bool
    {
        if ($this->db->DBDriver === 'MySQLi') {
            return (int) $this->db->query("
                SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?",
                [$table, $name])->getRowArray()['n'] > 0;
        }

        return (int) $this->db->query("
            SELECT COUNT(*) AS n FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_NAME = ? AND CONSTRAINT_NAME = ?",
            [$table, $name])->getRowArray()['n'] > 0;
    }
}
