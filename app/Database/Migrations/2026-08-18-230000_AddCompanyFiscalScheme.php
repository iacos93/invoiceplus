<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Schema contributivo e forfettario agevolato sulla scheda azienda.
 *
 * La stima delle imposte applicava a tutti una percentuale piatta di contributi
 * sul reddito. Va bene per la gestione separata, non per artigiani e
 * commercianti, dove il contributo fisso e' dovuto comunque fino al minimale e
 * oltre il massimale non si versa piu': la percentuale piatta sbagliava da
 * entrambi i lati. Servono due informazioni che solo l'azienda conosce.
 *
 * 'separata' come default riproduce il comportamento precedente, cosi'
 * l'aggiornamento non cambia le stime a chi non tocca la scheda.
 */
class AddCompanyFiscalScheme extends Migration
{
    public function up()
    {
        $this->forge->addColumn('ip_companies', [
            'company_inps_scheme' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'default'    => 'separata',
                'after'      => 'company_contributions',
            ],
            'company_forfettario_startup' => [
                'type'       => 'BOOLEAN',
                'null'       => false,
                'default'    => false,
                'after'      => 'company_inps_scheme',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('ip_companies', [
            'company_inps_scheme',
            'company_forfettario_startup',
        ]);
    }
}
