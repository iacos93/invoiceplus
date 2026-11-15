<?php

namespace App\Controllers;

use App\Services\DashboardOverview;
use App\Services\SetupGuide;

/**
 * Dashboard controller
 *
 * Porting CI4 del controller Dashboard (CI3): la Panoramica segue l'anno scelto
 * nel filtro globale della topbar (selected_year(), 0 = tutti gli anni).
 *
 * Il calcolo dei numeri (totali per stato, KPI, serie mensili, IVA, delta,
 * liste recenti) sta in DashboardOverview: qui restano l'anno da leggere e la
 * view da rendere. Così la Panoramica si può interrogare senza aprire la
 * pagina e senza far passare tutto da una risposta HTTP.
 */
class Dashboard extends BaseController
{
    public function index()
    {
        $data = (new DashboardOverview())->overview(selected_year());

        // Promemoria dei primi passi: solo finché ce n'è qualcuno da fare e
        // nessuno l'ha chiuso. Costa una lettura di impostazioni e due o tre
        // query leggere; sparisce da solo a lista completa.
        $data['first_steps'] = null;
        if (get_setting('first_steps_dismissed', '') !== '1') {
            $progress = SetupGuide::progress(SetupGuide::steps(SetupGuide::facts()));
            if ($progress['done'] < $progress['total']) {
                $data['first_steps'] = $progress;
            }
        }

        return view('dashboard/index', ['title' => trans('dashboard')] + $data);
    }
}