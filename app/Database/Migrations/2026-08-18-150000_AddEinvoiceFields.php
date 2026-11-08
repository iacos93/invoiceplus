<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Campi della fattura elettronica finora assenti dallo schema.
 *
 * Senza CIG e CUP una fattura verso la pubblica amministrazione viene
 * scartata dallo SdI, e senza i riferimenti all'ordine (DatiOrdineAcquisto)
 * o al contratto non e' liquidabile. Gli altri campi coprono i blocchi del
 * tracciato che l'applicazione non sapeva compilare: tipo documento, bollo,
 * esigibilita' IVA, scissione dei pagamenti, ritenuta d'acconto e cassa
 * previdenziale.
 *
 * Tutti nullable: sono dati facoltativi per la fattura ordinaria fra privati.
 */
class AddEinvoiceFields extends Migration
{
    private const FIELDS = [
        // Riferimenti di commessa pubblica (obbligatori verso la PA)
        'invoice_cig'                => ['type' => 'VARCHAR', 'constraint' => 15,  'null' => true],
        'invoice_cup'                => ['type' => 'VARCHAR', 'constraint' => 15,  'null' => true],

        // DatiOrdineAcquisto / DatiContratto / DatiConvenzione / DatiRicezione
        'invoice_order_number'       => ['type' => 'VARCHAR', 'constraint' => 20,  'null' => true],
        'invoice_order_date'         => ['type' => 'DATE',                          'null' => true],
        'invoice_order_line'         => ['type' => 'VARCHAR', 'constraint' => 20,  'null' => true],
        'invoice_contract_number'    => ['type' => 'VARCHAR', 'constraint' => 20,  'null' => true],
        'invoice_contract_date'      => ['type' => 'DATE',                          'null' => true],
        'invoice_convention_number'  => ['type' => 'VARCHAR', 'constraint' => 20,  'null' => true],
        'invoice_convention_date'    => ['type' => 'DATE',                          'null' => true],
        'invoice_receipt_number'     => ['type' => 'VARCHAR', 'constraint' => 20,  'null' => true],
        'invoice_receipt_date'       => ['type' => 'DATE',                          'null' => true],

        // RiferimentoAmministrazione: codice con cui l'ente ritrova la fattura
        'invoice_admin_reference'    => ['type' => 'VARCHAR', 'constraint' => 20,  'null' => true],

        // TipoDocumento (TD01 fattura, TD04 nota di credito, TD24 differita...)
        'invoice_doc_type'           => ['type' => 'VARCHAR', 'constraint' => 4,   'null' => true],

        // EsigibilitaIVA: I immediata, D differita, S scissione dei pagamenti
        'invoice_vat_exigibility'    => ['type' => 'CHAR',    'constraint' => 1,   'null' => true],

        // Bollo: 'auto' lascia la regola dei 77,47 €, 'si'/'no' la forzano
        'invoice_stamp_mode'         => ['type' => 'VARCHAR', 'constraint' => 4,   'null' => true],
        'invoice_stamp_amount'       => ['type' => 'DECIMAL', 'constraint' => '10,2', 'null' => true],

        // Ritenuta d'acconto (DatiRitenuta)
        'invoice_withholding_type'   => ['type' => 'VARCHAR', 'constraint' => 4,   'null' => true],
        'invoice_withholding_rate'   => ['type' => 'DECIMAL', 'constraint' => '5,2',  'null' => true],
        'invoice_withholding_reason' => ['type' => 'VARCHAR', 'constraint' => 4,   'null' => true],

        // Cassa previdenziale (DatiCassaPrevidenziale)
        'invoice_fund_type'          => ['type' => 'VARCHAR', 'constraint' => 4,   'null' => true],
        'invoice_fund_rate'          => ['type' => 'DECIMAL', 'constraint' => '5,2',  'null' => true],
    ];

    public function up()
    {
        $missing = [];

        foreach (self::FIELDS as $name => $definition) {
            if (!$this->db->fieldExists($name, 'ip_invoices')) {
                $missing[$name] = $definition;
            }
        }

        if ($missing !== []) {
            $this->forge->addColumn('ip_invoices', $missing);
        }
    }

    public function down()
    {
        foreach (array_keys(self::FIELDS) as $name) {
            if ($this->db->fieldExists($name, 'ip_invoices')) {
                $this->forge->dropColumn('ip_invoices', $name);
            }
        }
    }
}
