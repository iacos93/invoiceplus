<?php

namespace App\Database\Migrations;

use App\Models\SettingModel;
use CodeIgniter\Database\Migration;

/**
 * La settimana comincia di lunedì.
 *
 * Il seme di InvoicePlane — e quindi ogni installazione nata da quello —
 * portava first_day_of_week = 0, cioè la domenica: la griglia del calendario si
 * apriva con la domenica in prima colonna, che è l'ordine statunitense e non
 * quello di un calendario italiano (lunedì primo, domenica ultimo). Nessuno
 * aveva scelto quel valore, era il punto di partenza del seme.
 *
 * Si tocca solo se il valore è ancora 0: un altro giorno scelto in Impostazioni
 * resta com'è. Va detto che 0 è anche ciò che salva Impostazioni se si sceglie
 * davvero la domenica — i due casi non si distinguono, quindi si preferisce
 * l'ordine italiano, e chi vuole la domenica la rimette da Impostazioni in un
 * clic. La stessa impostazione governa i selettori di data: lì la settimana
 * comincia lunedì come qui, invece di contraddire il calendario.
 */
class SetItalianWeekStart extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('ip_settings')) {
            return;
        }

        $row = $this->db->table('ip_settings')
            ->where('setting_key', 'first_day_of_week')
            ->get()
            ->getRowArray();

        if ($row === null || (string) $row['setting_value'] !== '0') {
            return;
        }

        $this->db->table('ip_settings')
            ->where('setting_key', 'first_day_of_week')
            ->update(['setting_value' => '1']);

        // La lettura delle impostazioni passa da una cache: senza questa riga
        // la griglia resterebbe a domenica fino alla scadenza della cache.
        SettingModel::resetCache();
    }

    public function down(): void
    {
        // Non si ripristina: rimettere la domenica sarebbe una scelta, e si fa
        // da Impostazioni.
    }
}
