<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;
use App\Models\IpBaseModel;
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

    /**
     * Salva il form e mette in flashdata il messaggio che gli spetta.
     *
     * Ogni controller con un form ripeteva lo stesso blocco: se saveRecord()
     * torna 0 metti alert_error con il riepilogo del modello, altrimenti
     * alert_success con «creato» o «aggiornato». Le uniche differenze erano i
     * testi, che restano qui in chiamata; la regola invece sta in un posto
     * solo, così un form nuovo non può più dimenticare il caso 0 e annunciare
     * un salvataggio che non c'è stato — che è il difetto che aveva reso
     * necessaria la correzione di saveRecord(). Torna l'id scritto (0 se il
     * database ha rifiutato), perché chi deve fare altro dopo il salvataggio
     * — campi personalizzati, righe del documento — lo usa per continuare.
     *
     * @param array{errore: string, creato: string, aggiornato?: string, dettaglio?: bool} $messaggi
     *        errore: prefisso del messaggio d'errore, senza i due punti;
     *        creato/aggiornato: testi di successo (con uno solo vale per
     *        entrambi, per i form che dicono la stessa cosa nei due casi);
     *        dettaglio: false per non accodare il riepilogo del modello a un
     *        errore che dice già cosa fare (es. «controlla i dati e riprova»).
     *
     * @return int l'id salvato, 0 se il database non ha scritto
     */
    protected function saveFormRecord(IpBaseModel $model, ?int $id, array $dbArray, array $messaggi): int
    {
        $savedId = (int) $model->saveRecord($id, $dbArray);

        if ($savedId === 0) {
            $errore = (string) $messaggi['errore'];

            if ($messaggi['dettaglio'] ?? true) {
                $errore .= ': ' . $model->saveErrorSummary();
            }

            session()->setFlashdata('alert_error', $errore);

            return 0;
        }

        session()->setFlashdata(
            'alert_success',
            $messaggi[$id ? 'aggiornato' : 'creato'] ?? (string) $messaggi['creato']
        );

        return $savedId;
    }
}
