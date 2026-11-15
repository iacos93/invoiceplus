<?php

namespace App\Controllers;

use App\Services\SystemStatus as SystemStatusService;

/**
 * Stato del sistema.
 *
 * Le cose che possono rompersi senza dare errore in faccia a nessuno: backup
 * non prodotto, automazioni ferme, cartelle non scrivibili, migrazioni
 * dimenticate, database irraggiungibile. Il caso che l'ha motivata è il
 * backup: l'endpoint falliva da giorni e l'unica traccia era una riga in un
 * log che nessuno apre.
 *
 * I controlli su file e tempo stanno in App\Services\SystemStatus, così si
 * possono provare senza database; qui restano quelli che devono parlare con
 * il database.
 */
class SystemStatus extends BaseController
{
    public function index()
    {
        $service = SystemStatusService::fromApp();
        $checks  = $service->checks();

        $checks[] = $service->databaseCheck();
        $checks[] = $service->migrationCheck();
        $checks[] = $this->incomingCheck($service);
        $checks[] = $service->sdiCertificateCheck((string) get_setting('sdi_channel', 'manual'), (string) get_setting('sdi_coop_cert_path', ''));

        return view('system/status', [
            'title'  => trans('system_status', '', 'Stato del sistema'),
            'checks' => $checks,
            // Serve alla riga "Backup del database" per mostrare il pulsante
            // di download solo quando c'è davvero un file da scaricare.
            'backup' => $service->latestBackup(),
        ]);
    }

    /**
     * Scarica l'ultimo dump del database.
     *
     * La copia off-site non si può automatizzare da qui (non c'è un servizio
     * di destinazione configurato), quindi la pagina mette a disposizione il
     * file: chi amministra lo salva fuori dal server. Il nome non arriva
     * dall'URL, così non esiste un percorso da manipolare per leggere altri
     * file; il controllo su realpath è la seconda rete, per i collegamenti
     * simbolici.
     */
    public function downloadBackup()
    {
        $backup = SystemStatusService::fromApp()->latestBackup();

        if ($backup === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound('Nessun backup da scaricare');
        }

        $base = realpath(WRITEPATH . 'backups');
        $file = realpath($backup['path']);

        if ($base === false
            || $file === false
            || ! str_starts_with($file, $base . DIRECTORY_SEPARATOR)
            || ! is_file($file)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // {backup} e non {file}: {file} è un segnaposto riservato del logger
        // CI4 e viene sostituito con il file chiamante, non con il nome del
        // dump (il log mostrava APPPATH/Controllers/SystemStatus.php).
        log_message('info', 'Backup scaricato: {backup} ({size} byte) da utente {user}', [
            'backup' => $backup['name'],
            'size' => $backup['size'],
            'user' => (int) session('user_id'),
        ]);

        return $this->response->download($file, null);
    }

    /**
     * Nomi dei file gia' diventati spesa, per confrontarli con quelli arrivati
     * dallo SdI. Un elenco di nomi di file, senza importi ne' fornitori: la
     * cartella incoming/ e' unica per tutte le aziende, e il confronto con le
     * spese di una sola non direbbe quali fatture mancano davvero.
     *
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    private function incomingCheck(SystemStatusService $service): array
    {
        try {
            $nomi = array_column(
                \Config\Database::connect()->table('ip_expenses')->select('expense_file')->where('expense_file <>', '')->get()->getResultArray(),
                'expense_file'
            );
        } catch (\Throwable $e) {
            // Senza database il controllo "Database" lo dice gia'.
            $nomi = [];
        }

        return $service->incomingCheck(array_map('strval', $nomi));
    }
}
