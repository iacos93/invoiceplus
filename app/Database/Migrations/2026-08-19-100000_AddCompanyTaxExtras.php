<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Voci contributive aggiuntive sulla scheda azienda.
 *
 * La stima delle imposte conosce contributi previdenziali, addizionali e
 * imposte sul reddito, ma non voci come INAIL, quote associative, fondi
 * integrativi o altri versamenti che l'azienda sa di dover accantonare.
 *
 * Si salvano come JSON sulla riga dell'azienda: sono pochi dati, solo
 * descrittivi per questa azienda, e una tabella dedicata costerebbe più della
 * colonna. Ogni voce ha nome, aliquota percentuale, base di calcolo
 * (imponibile / utile / ricavi) e il flag di deducibilità, che riduce la base
 * su cui si calcolano imposta sostitutiva e IRPEF.
 */
class AddCompanyTaxExtras extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ip_companies', [
            'company_tax_extras' => [
                'type'  => 'TEXT',
                'null'  => true,
                'after' => 'company_add_comunale',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('ip_companies', 'company_tax_extras');
    }
}