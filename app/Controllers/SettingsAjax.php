<?php

namespace App\Controllers;

use App\Models\SettingModel;

/**
 * SettingsAjax controller
 *
 * Porting CI4 del controller Ajax (CI3, modulo settings).
 * Endpoint AJAX per la generazione della chiave cron.
 */
class SettingsAjax extends BaseController
{
    public function getCronKey()
    {
        $key = generate_key(16);

        (new SettingModel())->setSetting('cron_key', encrypt_secret($key));

        return $this->response->setJSON([
            'success' => true,
            'key'     => $key,
            'token'   => csrf_hash(),
        ]);
    }

    /**
     * Rigenera il token del feed calendario. Va fatto se l'URL è stato
     * condiviso per errore: il vecchio smette subito di funzionare, come per
     * cron_key.
     */
    public function getCalendarFeedToken()
    {
        $key = generate_key(32);

        (new SettingModel())->setSetting('calendar_feed_token', encrypt_secret($key));

        return $this->response->setJSON([
            'success'  => true,
            'key'      => $key,
            'feed_url' => site_url('calendar/feed/' . $key),
            'token'    => csrf_hash(),
        ]);
    }
}