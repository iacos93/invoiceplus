<?php

namespace App\Controllers;

/**
 * Filtro anno globale (selettore in sidebar).
 *
 * Un solo endpoint: salva l'anno scelto in sessione e in un cookie di lungo
 * periodo, poi riporta l'utente sulla pagina da cui è partito (referrer), così
 * il selettore funziona da qualunque schermata senza che ogni pagina debba
 * conoscere la propria URL di ritorno.
 */
class YearFilter extends BaseController
{
    public function set()
    {
        $year = (int) $this->request->getPost('year');

        // 0 = "Tutti gli anni", altrimenti solo anni con dati reali (evita che
        // un valore manomesso nel form finisca a filtrare su un anno a caso).
        if ($year !== 0 && !in_array($year, available_years(), true)) {
            $year = 0;
        }

        session()->set('selected_year', $year);

        $back = $this->request->getPost('back') ?: site_url('dashboard');

        // Il cookie tiene la scelta anche dopo il logout o la chiusura del
        // browser: la sessione da sola non basta (scade in due ore).
        return remember_selected_year(redirect()->to($back), $year);
    }
}
