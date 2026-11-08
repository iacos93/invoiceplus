<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

/**
 * Popola ip_units con le unita' di misura generiche piu' usate in
 * fatture/preventivi italiani (servizi a tempo/giornata, beni a pezzo,
 * peso, lunghezza, superficie, volume). Non e' un elenco esaustivo delle
 * unita' SI: solo quelle che compaiono davvero in righe documento comuni.
 *
 * Idempotente: salta le unita' il cui unit_name esiste gia', cosi' si puo'
 * rilanciare senza creare duplicati (es. dopo l'aggiunta di nuove voci qui
 * sotto in futuro).
 *
 * Esecuzione: php spark db:seed UnitsSeeder
 */
class UnitsSeeder extends Seeder
{
    public function run()
    {
        $units = [
            // Pezzi
            ['unit_name' => 'pz',       'unit_name_plrl' => 'pz',        'unit_type' => 'pezzi'],
            ['unit_name' => 'confezione', 'unit_name_plrl' => 'confezioni', 'unit_type' => 'pezzi'],
            ['unit_name' => 'set',      'unit_name_plrl' => 'set',       'unit_type' => 'pezzi'],
            ['unit_name' => 'paio',     'unit_name_plrl' => 'paia',      'unit_type' => 'pezzi'],

            // Peso
            ['unit_name' => 'kg', 'unit_name_plrl' => 'kg', 'unit_type' => 'peso'],
            ['unit_name' => 'g',  'unit_name_plrl' => 'g',  'unit_type' => 'peso'],
            ['unit_name' => 't',  'unit_name_plrl' => 't',  'unit_type' => 'peso'],

            // Lunghezza
            ['unit_name' => 'm',  'unit_name_plrl' => 'm',  'unit_type' => 'lunghezza'],
            ['unit_name' => 'cm', 'unit_name_plrl' => 'cm', 'unit_type' => 'lunghezza'],
            ['unit_name' => 'km', 'unit_name_plrl' => 'km', 'unit_type' => 'lunghezza'],

            // Superficie
            ['unit_name' => 'mq', 'unit_name_plrl' => 'mq', 'unit_type' => 'superficie'],

            // Volume
            ['unit_name' => 'mc', 'unit_name_plrl' => 'mc', 'unit_type' => 'volume'],
            ['unit_name' => 'l',  'unit_name_plrl' => 'l',  'unit_type' => 'volume'],

            // Tempo (le piu' usate per servizi/consulenza)
            ['unit_name' => 'ora',     'unit_name_plrl' => 'ore',       'unit_type' => 'tempo'],
            ['unit_name' => 'giorno',  'unit_name_plrl' => 'giorni',    'unit_type' => 'tempo'],
            ['unit_name' => 'mese',    'unit_name_plrl' => 'mesi',      'unit_type' => 'tempo'],
            ['unit_name' => 'anno',    'unit_name_plrl' => 'anni',      'unit_type' => 'tempo'],
            ['unit_name' => 'giornata', 'unit_name_plrl' => 'giornate', 'unit_type' => 'tempo'],

            // Altro (servizi non misurabili in unita' fisiche)
            ['unit_name' => 'servizio',  'unit_name_plrl' => 'servizi',  'unit_type' => 'altro'],
            ['unit_name' => 'forfait',   'unit_name_plrl' => 'forfait',  'unit_type' => 'altro'],
            ['unit_name' => 'pratica',   'unit_name_plrl' => 'pratiche', 'unit_type' => 'altro'],
        ];

        $db    = \Config\Database::connect();
        $table = $db->table('ip_units');
        $inserted = 0;
        $skipped  = 0;

        foreach ($units as $unit) {
            $exists = $db->table('ip_units')
                ->where('unit_name', $unit['unit_name'])
                ->countAllResults();

            if ($exists > 0) {
                $skipped++;
                continue;
            }

            $table->insert($unit);
            $inserted++;
        }

        echo "Unita' di misura: {$inserted} inserite, {$skipped} gia' presenti (saltate)." . PHP_EOL;
    }
}
