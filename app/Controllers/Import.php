<?php

namespace App\Controllers;

use App\Models\ImportModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Import controller
 *
 * Storico delle importazioni (import/index), form di caricamento CSV
 * (import/form), modelli CSV da scaricare (import/template/...) e
 * annullamento di un'importazione (delete).
 *
 * I file caricati vivono in writable/uploads/import/{company_id}/. La
 * sottocartella per azienda non e' un dettaglio organizzativo: i nomi dei file
 * sono fissi (clients.csv, invoices.csv, ...), quindi con una cartella sola il
 * clients.csv caricato dall'azienda A restava li' e compariva all'azienda B
 * marcato "Disponibile", pronto da importare nella sua anagrafica.
 */
class Import extends BaseController
{
    protected ImportModel $importModel;

    public function __construct()
    {
        $this->importModel = new ImportModel();
    }

    public function index()
    {
        return view('import/index', [
            'imports' => $this->importModel->getAllOrdered(),
        ]);
    }

    public function form()
    {
        if ($this->request->getMethod() === 'POST') {
            return $this->runImport();
        }

        $files = [];

        foreach (ImportModel::allowedFiles() as $file) {
            $path = $this->importDir() . $file;

            $files[] = [
                'name'    => $file,
                'field'   => ImportModel::fieldFor($file),
                'exists'  => is_file($path),
                'size'    => is_file($path) ? filesize($path) : 0,
                'mtime'   => is_file($path) ? filemtime($path) : 0,
                'headers' => ImportModel::headersFor($file),
            ];
        }

        return view('import/import_index', ['files' => $files]);
    }

    /**
     * GET import/template/{file} — modello CSV con le intestazioni attese.
     *
     * Serviva un modo di sapere che cosa vuole l'import senza leggere il
     * codice: le esportazioni scrivono intestazioni in italiano ("Nome /
     * Ragione Sociale"), l'import vuole i nomi delle colonne, quindi un file
     * esportato non e' un modello valido per rientrare.
     */
    public function template(string $file)
    {
        if (!in_array($file, ImportModel::allowedFiles(), true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response
            ->setContentType('text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="modello_' . $file . '"')
            ->setBody($this->importModel->templateFor($file));
    }

    /**
     * POST import/delete/{id} — annulla l'importazione.
     *
     * Cancella le righe che aveva inserito, non solo la voce di storico: era
     * il comportamento in CI3 (ip_import_details) e la voce di menu continua a
     * prometterlo. Cancellare il solo log lasciava in archivio fatture e
     * clienti che l'utente credeva di aver rimosso.
     */
    public function delete(int $id)
    {
        if ($this->request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        // Un'importazione di un'altra azienda non si annulla da qui: findScoped
        // torna null e la richiesta muore come un id inesistente.
        if (!$this->importModel->findScoped($id)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $removed = $this->importModel->rollback($id);

        session()->setFlashdata(
            'alert_success',
            trans('import_rolled_back', '', 'Importazione annullata') . ': ' . $removed . ' '
            . trans('rows_removed', '', 'righe rimosse dall\'archivio')
        );

        return redirect()->to('/import');
    }

    /* ------------------------------------------------------------ import */

    private function runImport()
    {
        $uploadDir = $this->importDir();

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            session()->setFlashdata('alert_error', 'Impossibile creare la cartella di importazione');

            return redirect()->to('/import/form');
        }

        $uploadedFiles = [];

        foreach (ImportModel::allowedFiles() as $file) {
            $upload = $this->request->getFile(ImportModel::fieldFor($file));

            if ($upload === null || $upload->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($upload->getError() !== UPLOAD_ERR_OK || !$upload->isValid()) {
                session()->setFlashdata('alert_error', 'Errore caricamento ' . $file . ': ' . $upload->getErrorString());

                return redirect()->to('/import/form');
            }

            if (!in_array(strtolower((string) $upload->getClientExtension()), ['csv', 'txt'], true)) {
                session()->setFlashdata('alert_error', 'Il file ' . $file . ' deve essere un CSV');

                return redirect()->to('/import/form');
            }

            if (!$upload->move($uploadDir, $file, true)) {
                session()->setFlashdata('alert_error', 'Impossibile salvare ' . $file . ' nella cartella import');

                return redirect()->to('/import/form');
            }

            $uploadedFiles[] = $file;
        }

        $selected = (array) ($this->request->getPost('files') ?: []);

        if ($uploadedFiles === [] && $selected === []) {
            session()->setFlashdata('alert_error', 'Carica almeno un file CSV o seleziona quelli già presenti');

            return redirect()->to('/import/form');
        }

        // Una riga di storico per esecuzione, non per file: è l'unità che
        // l'utente riconosce ed è quella che poi annulla.
        $importId = $this->importModel->startRun();

        $done     = [];
        $rows     = 0;
        $messages = [];
        $errors   = [];
        $warnings = [];

        // L'ordine è vincolante e non è quello di $allowedFiles: le righe e i
        // pagamenti si agganciano alla fattura per numero, quindi le testate
        // devono essere già dentro. Prima l'ordine dipendeva da come l'utente
        // aveva spuntato le caselle, e importare tutto in un colpo scartava
        // ogni riga con "fattura non trovata".
        $order = ['clients.csv', 'invoices.csv', 'invoice_items.csv', 'payments.csv'];

        foreach ($order as $file) {
            $path = $uploadDir . $file;

            if (!in_array($file, $selected, true) && !in_array($file, $uploadedFiles, true)) {
                continue;
            }

            if (!is_file($path)) {
                continue;
            }

            try {
                $result = $this->importModel->importCsv($file, $path, $importId);
                $rows  += $result['inserted'];
                $done[] = $file;

                $line = $file . ': ' . $result['inserted'] . ' righe importate';

                if ($result['skipped'] > 0) {
                    $line .= ', ' . $result['skipped'] . ' saltate';
                }

                $messages[] = $line;

                foreach (array_slice($result['warnings'], 0, 5) as $warning) {
                    $warnings[] = $file . ' — ' . $warning;
                }

                if (count($result['warnings']) > 5) {
                    $warnings[] = $file . ' — e altre ' . (count($result['warnings']) - 5) . ' segnalazioni';
                }
            } catch (\Throwable $e) {
                // \Throwable e non solo RuntimeException: un errore del
                // database (una colonna che non c'e', un vincolo violato)
                // arriva come DatabaseException o come Error, e prima faceva
                // saltare l'intera richiesta a meta' — lasciando in storico una
                // voce aperta a zero righe, perche' completeRun() non veniva
                // mai raggiunta e l'utente non vedeva nemmeno il messaggio.
                log_message('error', 'Import ' . $file . ': ' . $e->getMessage());
                $errors[] = $file . ': ' . $e->getMessage();

                // Il run si ferma qui, ma in modo ordinato: i file successivi
                // dipendono da questo — le righe cercano la fattura, le fatture
                // cercano il cliente — e su PostgreSQL una transazione fallita
                // blocca comunque quelle seguenti. Proseguire produceva quattro
                // errori a cascata che nascondevano l'unico vero. Quello che era
                // gia' entrato resta, registrato e annullabile dallo storico.
                break;
            }
        }

        $this->importModel->completeRun($importId, $done, $rows, $errors === [] ? null : implode('; ', $errors));

        if ($messages !== []) {
            session()->setFlashdata('alert_success', 'Importazione completata — ' . implode('; ', $messages));
        }

        if ($warnings !== []) {
            session()->setFlashdata('alert_warning', 'Righe non importate: ' . implode('; ', $warnings));
        }

        if ($errors !== []) {
            session()->setFlashdata('alert_error', 'Errori di importazione: ' . implode('; ', $errors));
        }

        return redirect()->to('/import');
    }

    /** Cartella dei CSV dell'azienda attiva, con la barra finale. */
    private function importDir(): string
    {
        return WRITEPATH . 'uploads/import/' . active_company_id() . '/';
    }
}
