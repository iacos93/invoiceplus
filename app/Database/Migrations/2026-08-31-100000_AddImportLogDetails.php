<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Storico importazioni utilizzabile.
 *
 * ip_imports arrivava da CI3 con due sole colonne (import_id, import_date):
 * il risultato era una pagina "Importazioni" che elencava una serie di date
 * identiche senza dire che cosa fosse stato importato, quante righe fossero
 * entrate o se qualcosa fosse andato storto — quel dettaglio viveva solo nel
 * messaggio flash, cioè spariva al primo cambio pagina.
 *
 * Mancava inoltre company_id: lo storico delle importazioni di un'azienda era
 * visibile a tutte, che è la stessa classe di problema già corretta altrove
 * (righe con company_id NULL restano visibili ovunque per l'archivio
 * pre-multi-azienda, quindi le nuove devono nascere valorizzate).
 *
 * ip_import_details esisteva già nello schema ma non veniva mai scritta: è la
 * tabella che in CI3 permetteva di annullare un'importazione. Qui le si
 * aggiunge solo l'indice mancante, perché ora viene popolata davvero.
 */
class AddImportLogDetails extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('ip_imports', [
            'import_file' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'import_date',
            ],
            'import_rows' => [
                'type'    => 'INT',
                'null'    => true,
                'default' => 0,
                'after'   => 'import_file',
            ],
            'import_error' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'import_rows',
            ],
            'company_id' => [
                'type'    => 'INT',
                'null'    => true,
                'default' => null,
                'after'   => 'import_error',
            ],
        ]);

        $this->db->query('CREATE INDEX idx_imports_company ON ip_imports (company_id)');
        $this->db->query('CREATE INDEX idx_import_details_import ON ip_import_details (import_id)');
    }

    public function down(): void
    {
        // DROP INDEX ha due sintassi incompatibili: MySQL vuole la tabella,
        // PostgreSQL (il driver in uso qui) no. CREATE INDEX invece e' uguale
        // su entrambi, quindi il ramo serve solo in discesa.
        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query('DROP INDEX idx_import_details_import ON ip_import_details');
            $this->db->query('DROP INDEX idx_imports_company ON ip_imports');
        } else {
            $this->db->query('DROP INDEX IF EXISTS idx_import_details_import');
            $this->db->query('DROP INDEX IF EXISTS idx_imports_company');
        }

        $this->forge->dropColumn('ip_imports', ['import_file', 'import_rows', 'import_error', 'company_id']);
    }
}
