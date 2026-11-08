<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Rimuove due tabelle che nessuna parte dell'applicazione legge o scrive.
 *
 * ip_versions registrava gli aggiornamenti SQL applicati dal meccanismo di
 * upgrade di CI3. In CI4 quel ruolo e' della tabella "migrations", e con la
 * sezione Aggiornamenti tolta dalle impostazioni non restava nemmeno un
 * percorso per leggerla: model, rotta e vista sono stati eliminati con questa
 * migrazione.
 *
 * ip_item_lookups non ha mai avuto un model ne' un controller nel porting:
 * esisteva solo la voce di menu nei file lingua.
 */
class DropDeadTables extends Migration
{
    private const TABLES = ['ip_versions', 'ip_item_lookups'];

    public function up()
    {
        foreach (self::TABLES as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table, true);
            }
        }
    }

    /**
     * Irreversibile per scelta: ricreare tabelle vuote e non referenziate da
     * nessun codice non ripristinerebbe niente di utile.
     */
    public function down()
    {
    }
}
