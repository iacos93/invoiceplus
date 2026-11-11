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
        $checks = SystemStatusService::fromApp()->checks();

        $checks[] = $this->databaseCheck();
        $checks[] = $this->migrationCheck();

        return view('system/status', [
            'title'  => trans('system_status', '', 'Stato del sistema'),
            'checks' => $checks,
        ]);
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    private function databaseCheck(): array
    {
        $config = config('Database');
        $group  = $config->defaultGroup;
        $nome   = (string) (($config->{$group}['database'] ?? ''));

        try {
            $db = \Config\Database::connect();

            return [
                'key'    => 'database',
                'label'  => 'Database',
                'state'  => SystemStatusService::OK,
                'detail' => trim(((string) ($config->{$group}['DBDriver'] ?? '')) . ' ' . $db->getVersion() . ' — ' . $nome),
                'hint'   => '',
            ];
        } catch (\Throwable $e) {
            return [
                'key'    => 'database',
                'label'  => 'Database',
                'state'  => SystemStatusService::ERROR,
                'detail' => 'Connessione non riuscita a ' . $nome . ': ' . mb_substr($e->getMessage(), 0, 160),
                'hint'   => 'Controlla i parametri del gruppo "' . $group . '" nel .env e che il server sia raggiungibile.',
            ];
        }
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, hint: string}
     */
    private function migrationCheck(): array
    {
        try {
            $runner    = \Config\Services::migrations();
            $applicate = array_map(static fn ($row) => (string) $row->version, $runner->getHistory());

            $pendenti = SystemStatusService::pendingMigrations($runner->findMigrations(), $applicate);
        } catch (\Throwable $e) {
            return [
                'key'    => 'migrations',
                'label'  => 'Migrazioni',
                'state'  => SystemStatusService::ERROR,
                'detail' => 'Impossibile leggere lo stato: ' . mb_substr($e->getMessage(), 0, 160),
                'hint'   => 'Con il database irraggiungibile non si può sapere se lo schema è aggiornato: risolvi prima il controllo "Database".',
            ];
        }

        if ($pendenti === []) {
            return [
                'key'    => 'migrations',
                'label'  => 'Migrazioni',
                'state'  => SystemStatusService::OK,
                'detail' => 'Schema aggiornato (' . count($applicate) . ' migrazioni applicate)',
                'hint'   => '',
            ];
        }

        $elenco = implode(', ', array_slice($pendenti, 0, 3)) . (count($pendenti) > 3 ? '…' : '');

        return [
            'key'    => 'migrations',
            'label'  => 'Migrazioni',
            'state'  => SystemStatusService::ERROR,
            'detail' => count($pendenti) . ' migrazioni da applicare: ' . $elenco,
            'hint'   => 'Dopo un aggiornamento esegui `php spark migrate`. Con lo schema indietro rispetto al codice, le pagine possono fallire su colonne che il codice si aspetta.',
        ];
    }
}
