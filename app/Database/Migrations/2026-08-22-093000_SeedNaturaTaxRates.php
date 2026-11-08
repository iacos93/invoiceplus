<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Aliquote precompilate per le operazioni senza IVA ordinaria.
 *
 * Con la sola colonna tax_rate_natura (migration precedente) la sezione
 * Imposte restava comunque vuota su questo fronte: chi apre la lista trova
 * ancora solo IVA 4/10/22%, nessuna a Natura valorizzata, e deve scoprire da
 * solo quale dei 21 codici scegliere e come chiamare l'aliquota. Questa
 * migration inserisce un set curato delle situazioni che un'azienda incontra
 * più spesso — non tutti i 21 codici (affollerebbe la lista di voci mai
 * usate), ma una per ciascuna famiglia principale, reverse charge compreso,
 * cosi' la lista mostra da subito "tutti i tipi di imposte" richiesti, pronti
 * da usare o da clonare/rinominare per il caso specifico.
 *
 * Idempotente: salta le aliquote il cui nome esiste gia' (riesecuzione sicura,
 * e non tocca nulla se l'utente le ha gia' create o rinominate a mano).
 */
class SeedNaturaTaxRates extends Migration
{
    /**
     * @var list<array{name: string, percent: float, natura: string}>
     */
    private const PRESETS = [
        ['name' => 'Esclusa – art. 15 DPR 633/72',            'percent' => 0, 'natura' => 'N1'],
        ['name' => 'Non soggetta – regime forfettario',        'percent' => 0, 'natura' => 'N2.2'],
        ['name' => 'Non imponibile – esportazione',            'percent' => 0, 'natura' => 'N3.1'],
        ['name' => 'Non imponibile – cessione intracomunitaria', 'percent' => 0, 'natura' => 'N3.2'],
        ['name' => 'Esente – art. 10 DPR 633/72',              'percent' => 0, 'natura' => 'N4'],
        ['name' => 'Regime del margine',                       'percent' => 0, 'natura' => 'N5'],
        ['name' => 'Inversione contabile – subappalto edile',  'percent' => 0, 'natura' => 'N6.3'],
        ['name' => 'Inversione contabile – rottami e materiali di recupero', 'percent' => 0, 'natura' => 'N6.1'],
        ['name' => 'IVA assolta in altro stato UE',            'percent' => 0, 'natura' => 'N7'],
    ];

    public function up()
    {
        $existing = array_column($this->db->table('ip_tax_rates')->select('tax_rate_name')->get()->getResultArray(), 'tax_rate_name');

        foreach (self::PRESETS as $preset) {
            if (in_array($preset['name'], $existing, true)) {
                continue;
            }

            $this->db->table('ip_tax_rates')->insert([
                'tax_rate_name'    => $preset['name'],
                'tax_rate_percent' => $preset['percent'],
                'tax_rate_natura'  => $preset['natura'],
            ]);
        }
    }

    public function down()
    {
        $names = array_column(self::PRESETS, 'name');

        $this->db->table('ip_tax_rates')->whereIn('tax_rate_name', $names)->delete();
    }
}
