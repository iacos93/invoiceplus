<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Porta tutto lo schema su utf8mb4 / utf8mb4_general_ci.
 *
 * Ventidue tabelle ereditate da InvoicePlane erano ancora utf8mb3 mentre
 * quelle nate qui (spese, DDT, F24, SdI, fornitori...) sono utf8mb4. Due
 * conseguenze concrete:
 *
 *   - utf8mb3 non regge i caratteri a 4 byte: una emoji o un ideogramma in
 *     una descrizione di riga o in una nota cliente veniva rifiutato o
 *     troncato al salvataggio;
 *   - le collation diverse si incontrano nelle query che confrontano o
 *     concatenano colonne di tabelle diverse (la ricerca globale mette
 *     insieme numero DDT utf8mb4 e nome cliente utf8mb3). MariaDB oggi se la
 *     cava convertendo al volo, ma è un appoggio su un comportamento che non
 *     è garantito e che nel frattempo impedisce l'uso degli indici.
 *
 * Il target è utf8mb4_general_ci perché è quello che la connessione dichiara
 * in Config\Database: allinearsi lì evita che ogni confronto fra colonna e
 * letterale debba passare da una conversione.
 *
 * Le colonne indicizzate più lunghe sono da 50 caratteri (setting_key,
 * invoice_number), quindi 200 byte in utf8mb4: nessun rischio di superare il
 * limite di prefisso, tanto più che le tabelle sono già in row_format DYNAMIC.
 */
class NormalizeCharsetToUtf8mb4 extends Migration
{
    private const CHARSET   = 'utf8mb4';
    private const COLLATION = 'utf8mb4_general_ci';

    public function up()
    {
        if ($this->db->DBDriver !== 'MySQLi') {
            return; // Solo MySQL/MariaDB usa charset per tabella; PostgreSQL è nativo UTF-8.
        }

        // Il default dello schema: senza questo una tabella creata in futuro
        // senza charset esplicito ripartirebbe dalla collation del server e
        // reintrodurrebbe la disomogeneità che stiamo togliendo.
        try {
            $this->db->query('ALTER DATABASE `' . $this->db->getDatabase() . '` '
                . 'CHARACTER SET = ' . self::CHARSET . ' COLLATE = ' . self::COLLATION);
        } catch (\Throwable $e) {
            // L'utente applicativo può non avere ALTER sullo schema: le
            // tabelle esistenti si convertono lo stesso, che è il punto.
            log_message('warning', 'ALTER DATABASE non riuscito: ' . $e->getMessage());
        }

        foreach ($this->tablesToConvert() as $table) {
            $this->db->query("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET "
                . self::CHARSET . ' COLLATE ' . self::COLLATION);
        }
    }

    public function down()
    {
        // Tornare a utf8mb3 significherebbe troncare i caratteri a 4 byte che
        // nel frattempo sono stati salvati: non è un ripristino, è una perdita.
    }

    /** @return list<string> tabelle la cui collation non è ancora quella di destinazione */
    private function tablesToConvert(): array
    {
        $rows = $this->db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_TYPE = 'BASE TABLE'
               AND TABLE_COLLATION <> ?",
            [self::COLLATION]
        )->getResultArray();

        return array_column($rows, 'TABLE_NAME');
    }
}
