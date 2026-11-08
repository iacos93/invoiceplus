<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Addizionali IRPEF proprie dell'azienda.
 *
 * I parametri fiscali per anno portano le addizionali come medie nazionali,
 * ma regionale e comunale variano davvero per residenza: fra una regione e
 * l'altra ballano piu' di un punto, e su un imponibile medio e' una cifra che
 * si nota nell'accantonamento.
 *
 * NULL significa "usa la media dell'anno": chi non compila i campi mantiene
 * esattamente la stima di prima, e chi conosce le proprie aliquote le mette.
 * Serve un valore per azienda e non per installazione perche' soggetti diversi
 * possono avere sede in comuni diversi.
 */
class AddCompanySurcharges extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ip_companies', [
            'company_add_regionale' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'null'       => true,
                'after'      => 'company_forfettario_startup',
            ],
            'company_add_comunale' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
                'null'       => true,
                'after'      => 'company_add_regionale',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('ip_companies', [
            'company_add_regionale',
            'company_add_comunale',
        ]);
    }
}
