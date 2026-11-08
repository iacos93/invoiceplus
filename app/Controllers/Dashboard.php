<?php

namespace App\Controllers;

use App\Services\DashboardOverview;

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

        return view('dashboard/index', ['title' => trans('dashboard')] + $data);
    }
}