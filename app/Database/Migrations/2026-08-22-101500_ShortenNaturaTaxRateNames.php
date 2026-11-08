<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Rinomina le aliquote precompilate da SeedNaturaTaxRates con etichette piu'
 * brevi, e ripara quelle il cui nome e' stato troncato in silenzio da
 * ip_tax_rates.tax_rate_name quando la colonna era ancora varchar(25)
 * (vedi WidenTaxRateNameColumn, che va applicata prima di questa).
 *
 * Il testo esteso col riferimento normativo resta comunque leggibile per
 * intero altrove in tax_rates/index.php, nella nota accanto al badge Natura
 * (TaxRateModel::NATURA_CODES): il nome puo' quindi tornare a fare un solo
 * lavoro, un'etichetta breve e riconoscibile, senza ripetere l'articolo di
 * legge una seconda volta e senza rischiare di essere tagliato a meta'.
 *
 * L'abbinamento e' per codice Natura, non per nome: dopo il troncamento a 25
 * caratteri il nome salvato non coincide piu' col preset originale, quindi
 * un confronto esatto sul nome non troverebbe piu' nulla da correggere.
 * Ogni riga viene toccata solo se il suo nome attuale e' ancora
 * riconoscibile come uno dei preset (originale o troncato a 25 caratteri) e
 * non e' gia' stata rinominata a mano dall'utente in qualcos'altro.
 */
class ShortenNaturaTaxRateNames extends Migration
{
    /** @var array<string, array{old: string, new: string}> natura => preset */
    private const PRESETS = [
        'N1'   => ['old' => 'Esclusa – art. 15 DPR 633/72',                          'new' => 'Esclusa da IVA'],
        'N2.2' => ['old' => 'Non soggetta – regime forfettario',                      'new' => 'Non soggetta – forfettario'],
        'N3.1' => ['old' => 'Non imponibile – esportazione',                          'new' => 'Non imponibile – esportazione'],
        'N3.2' => ['old' => 'Non imponibile – cessione intracomunitaria',             'new' => 'Non imponibile – intra UE'],
        'N4'   => ['old' => 'Esente – art. 10 DPR 633/72',                            'new' => 'Esente da IVA'],
        'N6.3' => ['old' => 'Inversione contabile – subappalto edile',                'new' => 'Reverse charge – edile'],
        'N6.1' => ['old' => 'Inversione contabile – rottami e materiali di recupero', 'new' => 'Reverse charge – rottami'],
        'N7'   => ['old' => 'IVA assolta in altro stato UE',                          'new' => 'IVA assolta – altro stato UE'],
    ];


    public function up()
    {
        $rows = $this->db->table('ip_tax_rates')
            ->select('tax_rate_id, tax_rate_name, tax_rate_natura')
            ->get()->getResultArray();

        foreach ($rows as $row) {
            $preset = self::PRESETS[$row['tax_rate_natura'] ?? ''] ?? null;

            if ($preset === null || $row['tax_rate_name'] === $preset['new']) {
                continue;
            }

            $recognized = [$preset['old'], mb_substr($preset['old'], 0, 25)];

            if (!in_array($row['tax_rate_name'], $recognized, true)) {
                continue;
            }

            $this->db->table('ip_tax_rates')
                ->where('tax_rate_id', $row['tax_rate_id'])
                ->update(['tax_rate_name' => $preset['new']]);
        }
    }

    public function down()
    {
        $rows = $this->db->table('ip_tax_rates')
            ->select('tax_rate_id, tax_rate_name, tax_rate_natura')
            ->get()->getResultArray();

        foreach ($rows as $row) {
            $preset = self::PRESETS[$row['tax_rate_natura'] ?? ''] ?? null;

            if ($preset === null || $row['tax_rate_name'] !== $preset['new']) {
                continue;
            }

            $this->db->table('ip_tax_rates')
                ->where('tax_rate_id', $row['tax_rate_id'])
                ->update(['tax_rate_name' => $preset['old']]);
        }
    }
}
