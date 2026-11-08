<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;
use App\Models\SettingModel;

/**
 * BaseController: carica impostazioni e lingua per tutti i controller.
 */
abstract class BaseController extends Controller
{
    /**
     * Helpers available to all extending controllers and views.
     */
    protected $helpers = ['date', 'setting']; // Add the helper file containing date_format_datepicker()

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);

        // Riscalda la cache delle impostazioni (equivalente di load_settings CI3)
        (new SettingModel())->loadSettings();

        // Lingua dell'utente (user_language in sessione)
        $locale = session('user_language') && session('user_language') !== 'system'
            ? session('user_language')
            : get_setting('default_language', 'it');

        // Compatibilita' con valori legacy salvati prima della correzione
        // (venivano memorizzati i nomi estesi invece dei codici locale ISO)
        $legacyMap = ['italiano' => 'it', 'italian' => 'it', 'english' => 'en'];
        if (isset($legacyMap[$locale])) {
            $locale = $legacyMap[$locale];
        }

        // Applica la lingua solo se supportata, altrimenti fallback al default
        $supported = config('App')->supportedLocales ?? ['en', 'it'];
        if (!in_array($locale, $supported, true)) {
            $locale = config('App')->defaultLocale ?? 'it';
        }

        if ($locale) {
            $request->setLocale($locale);
            \Config\Services::language()->setLocale($locale);
        }
    }
}
