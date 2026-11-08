<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Tipologia del cliente: azienda, persona fisica o pubblica amministrazione.
 *
 * Non e' un'etichetta descrittiva: nella fattura elettronica cambia il
 * tracciato. Una PA vuole formato FPA12 e un Codice Univoco Ufficio di sei
 * caratteri; un privato vuole FPR12 e un codice destinatario di sette. Una
 * persona fisica va in <Nome>/<Cognome>, un'azienda in <Denominazione>: sono
 * rami alternativi dello stesso nodo, e usare quello sbagliato fa scartare la
 * fattura.
 *
 * Vengono aggiunti anche il codice SdI e la PEC come colonne vere. Finora
 * erano campi custom raggiunti tramite variabili d'ambiente
 * (IT_CLIENTE_SDI_CODICE_ID e simili): un meccanismo che, se non configurato,
 * lasciava silenziosamente il codice destinatario a 0000000.
 */
class AddClientType extends Migration
{
    private const FIELDS = [
        'client_type'     => ['type' => 'VARCHAR', 'constraint' => 12, 'null' => false, 'default' => 'company'],
        'client_sdi_code' => ['type' => 'VARCHAR', 'constraint' => 7,  'null' => true],
        'client_sdi_pec'  => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
    ];

    public function up()
    {
        $missing = [];

        foreach (self::FIELDS as $name => $definition) {
            if (!$this->db->fieldExists($name, 'ip_clients')) {
                $missing[$name] = $definition;
            }
        }

        if ($missing !== []) {
            $this->forge->addColumn('ip_clients', $missing);
        }

        // Chi ha un cognome valorizzato e' gia' registrato come persona fisica:
        // il campo non avrebbe senso su una ragione sociale.
        $this->db->table('ip_clients')
                 ->where("TRIM(COALESCE(client_surname, '')) <> ''", null, false)
                 ->update(['client_type' => 'person']);
    }

    public function down()
    {
        foreach (array_keys(self::FIELDS) as $name) {
            if ($this->db->fieldExists($name, 'ip_clients')) {
                $this->forge->dropColumn('ip_clients', $name);
            }
        }
    }
}
