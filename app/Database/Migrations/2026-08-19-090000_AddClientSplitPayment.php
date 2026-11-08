<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Scissione dei pagamenti (split payment, art. 17-ter DPR 633/72) come
 * preferenza del cliente, non solo del documento.
 *
 * Il campo esiste già sulla fattura (invoice_vat_exigibility, valore 'S'),
 * ma nessuno lo precompila: va scelto a mano a ogni fattura, e con la
 * pubblica amministrazione è un obbligo di legge, non un'opzione — è facile
 * scordarselo. Tre valori, non un booleano: la legge estende lo split
 * payment anche a società non PA (partecipate pubbliche, quotate FTSE MIB),
 * quindi "automatico in base al tipo cliente" non basta a coprire ogni caso
 * e serve poterlo forzare anche su un cliente 'company'.
 *
 * 'auto' come default: chi non tocca questo campo non vede cambiare nulla
 * finché non lo imposta esplicitamente.
 */
class AddClientSplitPayment extends Migration
{
    public function up()
    {
        if ($this->db->fieldExists('client_split_payment', 'ip_clients')) {
            return;
        }

        $this->forge->addColumn('ip_clients', [
            'client_split_payment' => [
                'type'       => 'VARCHAR',
                'constraint' => 4,
                'null'       => false,
                'default'    => 'auto',
                'after'      => 'client_type',
            ],
        ]);
    }

    public function down()
    {
        if ($this->db->fieldExists('client_split_payment', 'ip_clients')) {
            $this->forge->dropColumn('ip_clients', 'client_split_payment');
        }
    }
}
