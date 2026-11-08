<?php

namespace App\Controllers;

use App\Models\CompanyModel;
use App\Models\SettingModel;

/**
 * McpSettings controller — configurazione dell'integrazione MCP.
 *
 * Pagina dedicata, volutamente fuori da Impostazioni: la chiave del server
 * /mcp è un segreto di integrazione (come la cron key), e tenerla nel form
 * gigante delle impostazioni la faceva viaggiare nel POST insieme a tutto il
 * resto. Qui vive da sola, con il proprio POST minimale e la rigenerazione
 * via ajax, e i permessi restano quelli dell'area amministrativa.
 */
class McpSettings extends BaseController
{
    public function index()
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/mcp/settings');
            }

            $companyId = (int) $request->getPost('mcp_company_id');

            // L'azienda deve esistere (o essere 0 = predefinita): un id
            // inventato fisserebbe il perimetro dell'integrazione su un
            // soggetto giuridico inesistente.
            if ($companyId !== 0 && !(new CompanyModel())->find($companyId)) {
                session()->setFlashdata('alert_error', 'Azienda non valida per l\'integrazione MCP');

                return redirect()->to('/mcp/settings');
            }

            (new SettingModel())->setSetting('mcp_company_id', $companyId);

            session()->setFlashdata('alert_success', 'Integrazione MCP aggiornata correttamente');

            return redirect()->to('/mcp/settings');
        }

        return view('mcp/settings', [
            'companies' => (new CompanyModel())->getAllActive(),
        ]);
    }

    /**
     * Rigenera la chiave del server MCP. Come per cron_key, rigenerarla
     * invalida subito i client già configurati: va aggiornata anche la
     * variabile d'ambiente del client.
     */
    public function getKey()
    {
        $key = generate_key(32);

        // Salvata cifrata (Cryptor, chiave da encryption.key): il DB non
        // deve mai contenere il bearer token in chiaro. Il valore in chiaro
        // viene mostrato una sola volta qui, come per la cron key.
        (new SettingModel())->setSetting('mcp_key', encrypt_secret($key));

        return $this->response->setJSON([
            'success' => true,
            'key'     => $key,
            'token'   => csrf_hash(),
        ]);
    }
}