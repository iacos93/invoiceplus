<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Valori fiscali di riferimento, per anno.
 *
 * NON e' piu' la sorgente usata dai calcoli: quella e' la tabella
 * ip_fiscal_years, modificabile da Impostazioni > Parametri fiscali. Questo
 * file ha due compiti residui:
 *
 * 1. fa da seed alla migrazione che crea la tabella;
 * 2. fa da ripiego se la tabella e' vuota, cosi' un'installazione appena
 *    migrata mostra un prospetto invece di un errore.
 *
 * Quindi quando cambia la legge NON si modifica questo file: si aggiorna
 * l'anno dall'interfaccia. Qui si interviene solo per cambiare i valori con
 * cui parte un'installazione nuova.
 *
 * Fonti dei valori: circolari INPS per minimale/massimale e contributo fisso,
 * TUIR per scaglioni IRPEF e addizionali, L. 190/2014 per il forfettario.
 * Le addizionali sono medie nazionali: variano per regione e comune, e per una
 * stima gestionale la media e' piu' onesta di ometterle del tutto.
 */
class Fiscal extends BaseConfig
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $years = [
        2024 => [
            'irpef' => [
                // [soglia superiore dello scaglione, aliquota]. null = nessun tetto.
                'scaglioni'   => [[28000, 23.0], [50000, 35.0], [null, 43.0]],
                'addizionale_regionale' => 1.73,
                'addizionale_comunale'  => 0.50,
            ],
            // Artigiani e commercianti: contributo fisso fino al minimale, poi
            // percentuale sull'eccedenza fino al massimale.
            'inps_artigiani' => [
                'minimale'        => 18415.0,
                'massimale'       => 92016.0,
                'aliquota'        => 24.48,
                'contributo_fisso' => 4508.0,
            ],
            // Gestione separata: percentuale piena sul reddito, senza minimale.
            'inps_gestione_separata' => ['aliquota' => 26.23],
            'ires' => ['aliquota' => 24.0],
            'irap' => ['aliquota' => 3.9],
            'forfettario' => [
                'aliquota_ordinaria' => 15.0,
                'aliquota_startup'   => 5.0,
                'limite_ricavi'      => 85000.0,
            ],
        ],
        2025 => [
            'irpef' => [
                'scaglioni'   => [[28000, 23.0], [50000, 35.0], [null, 43.0]],
                'addizionale_regionale' => 1.73,
                'addizionale_comunale'  => 0.50,
            ],
            'inps_artigiani' => [
                'minimale'        => 18955.0,
                'massimale'       => 94750.0,
                'aliquota'        => 24.48,
                'contributo_fisso' => 4640.0,
            ],
            'inps_gestione_separata' => ['aliquota' => 26.23],
            'ires' => ['aliquota' => 24.0],
            'irap' => ['aliquota' => 3.9],
            'forfettario' => [
                'aliquota_ordinaria' => 15.0,
                'aliquota_startup'   => 5.0,
                'limite_ricavi'      => 85000.0,
            ],
        ],
        2026 => [
            'irpef' => [
                'scaglioni'   => [[28000, 23.0], [50000, 33.0], [null, 43.0]],
                // Il secondo scaglione scende dal 35% al 33% (Legge di
                // Bilancio 2026). Il beneficio e' pero' neutralizzato
                // sopra i 200.000 di reddito complessivo con una
                // riduzione delle detrazioni: qui non si modella,
                // perche' la stima non calcola detrazioni affatto.
                'addizionale_regionale' => 1.73,
                'addizionale_comunale'  => 0.50,
            ],
            'inps_artigiani' => [
                'minimale'        => 19500.0,
                'massimale'       => 97500.0,
                'aliquota'        => 24.48,
                'contributo_fisso' => 4774.0,
            ],
            'inps_gestione_separata' => ['aliquota' => 26.23],
            'ires' => ['aliquota' => 24.0],
            'irap' => ['aliquota' => 3.9],
            'forfettario' => [
                'aliquota_ordinaria' => 15.0,
                'aliquota_startup'   => 5.0,
                'limite_ricavi'      => 85000.0,
            ],
        ],
    ];

    /**
     * Parametri dell'anno richiesto.
     *
     * Un anno non coperto non deve far fallire la pagina: l'archivio contiene
     * documenti piu' vecchi di qualunque tabella si decida di mantenere, e chi
     * apre il 2019 si aspetta un prospetto, non un errore. Si ripiega sull'anno
     * definito piu' vicino per difetto (la legge in vigore piu' prossima), e in
     * mancanza sul piu' antico disponibile.
     *
     * @return array{params: array<string, mixed>, year: int, exact: bool}
     */
    public function forYear(int $year): array
    {
        if (isset($this->years[$year])) {
            return ['params' => $this->years[$year], 'year' => $year, 'exact' => true];
        }

        $available = array_keys($this->years);
        sort($available);

        $chosen = $available[0];
        foreach ($available as $candidate) {
            if ($candidate <= $year) {
                $chosen = $candidate;
            }
        }

        return ['params' => $this->years[$chosen], 'year' => $chosen, 'exact' => false];
    }
}
