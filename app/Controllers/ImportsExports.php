<?php

namespace App\Controllers;

/**
 * ImportsExports — hub per Importazioni / Esportazioni.
 *
 * La sidebar raggruppa "Importa CSV" (Import) ed "Esporta" (Exports)
 * sotto un'unica voce "Importazioni ed Esportazioni". Senza una rotta
 * per la voce genitore, cliccare sull'etichetta del gruppo dava 404.
 * Questo controller è solo un bivio: due card che rimandano alle due
 * sezioni reali, così il link del gruppo non resta vuoto.
 */
class ImportsExports extends BaseController
{
    public function index()
    {
        // Il pulsante "Cronologia" porta a una pagina che, vuota, rimanda al
        // form: senza importazioni sarebbe un secondo "Nuova importazione".
        $hasHistory = model(\App\Models\ImportModel::class)->scopeToCompany()->countAllResults() > 0;

        return view('imports_exports/index', ['hasHistory' => $hasHistory]);
    }
}
