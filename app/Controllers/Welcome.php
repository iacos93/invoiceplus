<?php

namespace App\Controllers;

/**
 * Welcome controller
 *
 * Porting CI4 del controller Welcome (CI3): pagina statica di benvenuto.
 */
class Welcome extends BaseController
{
    public function index()
    {
        return view('welcome/welcome');
    }
}