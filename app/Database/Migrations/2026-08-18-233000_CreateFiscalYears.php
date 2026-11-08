<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Fiscal;

/**
 * Parametri fiscali per anno in tabella.
 *
 * Stavano in Config\Fiscal, cioe' in un file PHP: aggiornarli quando cambia la
 * legge voleva dire modificare il codice e ridistribuirlo, e il file rischiava
 * di essere sovrascritto dagli aggiornamenti dell'applicazione. In tabella si
 * modificano dall'interfaccia, restano al loro posto fra una versione e
 * l'altra, e il salvataggio puo' invalidare da solo la cache del prospetto.
 *
 * Gli scaglioni stanno in una tabella propria perche' il loro numero cambia:
 * tenerli in colonne fisse avrebbe messo un tetto a quanti possono essere,
 * proprio la rigidita' da cui si voleva uscire.
 *
 * Config\Fiscal resta come sorgente del seed e come ripiego se la tabella e'
 * vuota: un'installazione senza righe deve mostrare un prospetto, non un
 * errore.
 */
class CreateFiscalYears extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'fiscal_id'            => ['type' => 'INT', 'auto_increment' => true],
            'fiscal_year'          => ['type' => 'INT'],
            // Un anno proiettato non e' legge: la stima deve poterlo dichiarare
            // invece di presentare numeri provvisori come definitivi.
            'fiscal_is_projection' => ['type' => 'BOOLEAN', 'default' => false],

            'fiscal_add_regionale'   => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
            'fiscal_add_comunale'    => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],

            'fiscal_inps_art_minimale'  => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'fiscal_inps_art_massimale' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'fiscal_inps_art_aliquota'  => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
            'fiscal_inps_art_fisso'     => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
            'fiscal_inps_gs_aliquota'   => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],

            'fiscal_ires_aliquota' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
            'fiscal_irap_aliquota' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],

            'fiscal_forf_ordinaria' => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
            'fiscal_forf_startup'   => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
            'fiscal_forf_limite'    => ['type' => 'DECIMAL', 'constraint' => '12,2', 'default' => 0],
        ]);
        $this->forge->addKey('fiscal_id', true);
        $this->forge->addUniqueKey('fiscal_year');
        $this->forge->createTable('ip_fiscal_years', true);

        $this->forge->addField([
            'bracket_id'    => ['type' => 'INT', 'auto_increment' => true],
            'fiscal_year'   => ['type' => 'INT'],
            // NULL = ultimo scaglione, senza tetto superiore.
            'bracket_limit' => ['type' => 'DECIMAL', 'constraint' => '12,2', 'null' => true],
            'bracket_rate'  => ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 0],
            'bracket_order' => ['type' => 'INT', 'default' => 0],
        ]);
        $this->forge->addKey('bracket_id', true);
        $this->forge->addKey('fiscal_year');
        $this->forge->createTable('ip_fiscal_brackets', true);

        $this->seedFromConfig();
    }

    /**
     * Porta in tabella gli anni gia' descritti nella configurazione, cosi'
     * l'aggiornamento non cambia nessuna stima gia' vista.
     */
    private function seedFromConfig(): void
    {
        $config = new Fiscal();
        $years  = $this->db->table('ip_fiscal_years');

        foreach ($config->years as $year => $p) {
            if ($years->where('fiscal_year', $year)->countAllResults(false) > 0) {
                $years->resetQuery();
                continue;
            }
            $years->resetQuery();

            $years->insert([
                'fiscal_year'          => $year,
                // Gli anni distribuiti con l'applicazione sono dati per
                // acquisiti. Il flag resta disponibile per gli anni che si
                // aggiungono in avanti, quando si vuole dichiarare che i valori
                // sono stimati e non ancora pubblicati.
                'fiscal_is_projection' => false,

                'fiscal_add_regionale' => $p['irpef']['addizionale_regionale'],
                'fiscal_add_comunale'  => $p['irpef']['addizionale_comunale'],

                'fiscal_inps_art_minimale'  => $p['inps_artigiani']['minimale'],
                'fiscal_inps_art_massimale' => $p['inps_artigiani']['massimale'],
                'fiscal_inps_art_aliquota'  => $p['inps_artigiani']['aliquota'],
                'fiscal_inps_art_fisso'     => $p['inps_artigiani']['contributo_fisso'],
                'fiscal_inps_gs_aliquota'   => $p['inps_gestione_separata']['aliquota'],

                'fiscal_ires_aliquota' => $p['ires']['aliquota'],
                'fiscal_irap_aliquota' => $p['irap']['aliquota'],

                'fiscal_forf_ordinaria' => $p['forfettario']['aliquota_ordinaria'],
                'fiscal_forf_startup'   => $p['forfettario']['aliquota_startup'],
                'fiscal_forf_limite'    => $p['forfettario']['limite_ricavi'],
            ]);

            foreach ($p['irpef']['scaglioni'] as $i => [$limit, $rate]) {
                $this->db->table('ip_fiscal_brackets')->insert([
                    'fiscal_year'   => $year,
                    'bracket_limit' => $limit,
                    'bracket_rate'  => $rate,
                    'bracket_order' => $i,
                ]);
            }
        }
    }

    public function down()
    {
        $this->forge->dropTable('ip_fiscal_brackets', true);
        $this->forge->dropTable('ip_fiscal_years', true);
    }
}
