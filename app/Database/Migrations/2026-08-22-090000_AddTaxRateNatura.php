<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Natura IVA sulle aliquote.
 *
 * Le aliquote finora erano solo nome + percentuale: per un'operazione non
 * imponibile, esente o in inversione contabile la percentuale corretta e' 0,
 * ma non basta a dire "perche'" l'IVA non c'e' — il Codice Natura ufficiale
 * dell'Agenzia delle Entrate (usato in fattura elettronica) e' l'unico modo
 * di distinguere, ad es., un'esportazione (N3.1) da un regime del margine
 * (N5) o da un subappalto edile in inversione contabile (N6.3): tutte
 * aliquote a 0%, ma con un fondamento normativo diverso da indicare in
 * fattura.
 *
 * Colonna facoltativa (le aliquote ordinarie, es. 22%, non ne hanno bisogno)
 * e testuale: i codici sono alfanumerici con un punto (N2.1, N6.3, ...), un
 * ENUM andrebbe riscritto a ogni aggiornamento normativo dell'AdE.
 */
class AddTaxRateNatura extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ip_tax_rates', [
            'tax_rate_natura' => [
                'type'       => 'VARCHAR',
                'constraint' => 6,
                'null'       => true,
                'after'      => 'tax_rate_percent',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('ip_tax_rates', 'tax_rate_natura');
    }
}
