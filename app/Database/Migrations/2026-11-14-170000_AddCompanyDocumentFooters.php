<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Testo del piè di pagina dei documenti, per azienda.
 *
 * Stesso motivo dei loghi (AddCompanyLogos): il piè di pagina porta contatti
 * e diciture di legge che appartengono a un soggetto giuridico — per una
 * S.r.l. sede, Registro delle imprese e capitale sociale — e come
 * impostazione globale finiva identico sui documenti di tutte le aziende. Le
 * colonne hanno il nome delle impostazioni (pdf_invoice_footer per fatture e
 * DDT, pdf_quote_footer per i preventivi) e si leggono con la stessa regola:
 * il testo dell'azienda se c'è, altrimenti quello delle Impostazioni.
 */
class AddCompanyDocumentFooters extends Migration
{
    private const COLUMNS = ['pdf_invoice_footer', 'pdf_quote_footer'];

    public function up(): void
    {
        foreach (self::COLUMNS as $column) {
            if ($this->db->fieldExists($column, 'ip_companies')) {
                continue;
            }

            $this->forge->addColumn('ip_companies', [
                $column => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            if ($this->db->fieldExists($column, 'ip_companies')) {
                $this->forge->dropColumn('ip_companies', $column);
            }
        }
    }
}
