<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Campi per la fatturazione Carta del Docente (bonus docente).
 *
 * Quando l'esercente incassa buoni Carta del Docente, la fattura "normale"
 * resta intestata al docente (persona fisica), ma per ottenere il rimborso
 * dallo Stato va emessa anche la fattura elettronica verso il Ministero
 * dell'Istruzione: un documento diverso, con sezioni dedicate del tracciato
 * FatturaPA che l'applicazione non sapeva compilare (CodiceArticolo
 * CARTADELDOCENTE, descrizione "Pagamento Buono", Natura N2,
 * RiferimentoNormativo, destinatario MIUR con codice ufficio QGGT71, niente
 * bollo). Il flag sulla fattura dice all'applicazione di generare quel
 * documento in automatico; il codice buono (8 caratteri, case-sensitive) e'
 * quello che il Ministero usa per riconoscere la transazione e liquidarla.
 *
 * Il codice esercente (RiferimentoAmministrazione 1.2.6) e' un dato
 * dell'azienda, non della fattura: lo assegna la piattaforma Carta del
 * Docente al momento dell'iscrizione, e vale per tutte le fatture di quella
 * azienda.
 */
class AddCartaDocenteFields extends Migration
{
    public function up()
    {
        if (!$this->db->fieldExists('invoice_carta_docente', 'ip_invoices')) {
            $this->forge->addColumn('ip_invoices', [
                'invoice_carta_docente' => [
                    'type' => 'BOOLEAN', 'null' => false, 'default' => false,
                ],
            ]);
        }

        if (!$this->db->fieldExists('invoice_voucher_code', 'ip_invoices')) {
            $this->forge->addColumn('ip_invoices', [
                'invoice_voucher_code' => [
                    'type' => 'VARCHAR', 'constraint' => 20, 'null' => true,
                ],
            ]);
        }

        if (!$this->db->fieldExists('company_carta_docente_code', 'ip_companies')) {
            $this->forge->addColumn('ip_companies', [
                'company_carta_docente_code' => [
                    'type' => 'VARCHAR', 'constraint' => 20, 'null' => true,
                ],
            ]);
        }
    }

    public function down()
    {
        $this->forge->dropColumn('ip_invoices', 'invoice_carta_docente');
        $this->forge->dropColumn('ip_invoices', 'invoice_voucher_code');
        $this->forge->dropColumn('ip_companies', 'company_carta_docente_code');
    }
}