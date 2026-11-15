<?php

namespace App\Controllers;

use App\Models\SettingModel;
use App\Services\SetupGuide;

/**
 * Primi passi dopo il primo avvio.
 *
 * Una lista, non una procedura a moduli: ogni voce porta alla pagina che già
 * esiste per quella cosa, e lo stato si ricava dai dati veri (un passo è
 * fatto quando il dato c'è, non quando qualcuno ha cliccato "fatto").
 */
class FirstSteps extends BaseController
{
    public function index()
    {
        $steps = SetupGuide::steps(SetupGuide::facts());

        return view('first_steps/index', [
            'title'    => trans('first_steps', '', 'Primi passi'),
            'steps'    => $steps,
            'progress' => SetupGuide::progress($steps),
        ]);
    }

    /**
     * «Non mostrare più» il promemoria in Bacheca. La pagina resta
     * raggiungibile dall'indirizzo e dal promemoria stesso finché serve.
     */
    public function dismiss()
    {
        if (! $this->request->is('post')) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        (new SettingModel())->setSetting('first_steps_dismissed', '1');

        return redirect()->to('/dashboard');
    }
}
