<?php

namespace App\Controllers;

use App\Models\SupplierModel;
use App\Models\ExpenseModel;
use App\Libraries\CsvWriter;
use App\Services\ContactExport;
use App\Services\ContactImporter;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Suppliers controller — anagrafica fornitori.
 *
 * Stessa struttura del controller Clients: index (redirect alla lista degli
 * attivi), status (lista filtrata), form (crea/modifica con controllo
 * anti-duplicato), view (dettaglio con le spese del fornitore), delete.
 */
class Suppliers extends BaseController
{
    protected SupplierModel $supplierModel;

    public function __construct()
    {
        $this->supplierModel = new SupplierModel();
    }

    public function index()
    {
        return redirect()->to('/suppliers/status/active');
    }

    public function status(string $status = 'active')
    {
        return view('suppliers/index', [
            'status'    => $status,
            'suppliers' => $this->supplierModel->getAllWithTotals($status),
        ]);
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/suppliers');
            }

            $post = $request->getPost();

            // Perimetro azienda anche in scrittura: la GET controlla già
            // l'appartenenza, ma il POST con lo stesso id la scavalcherebbe
            // (update() filtra solo per chiave primaria).
            if ($id && !$this->supplierModel->findScoped($id)) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }

            // Anti-duplicato in creazione: stessa partita IVA (o stesso nome se
            // la P.IVA manca). Lo stesso controllo lo fa l'import XML.
            if (!$id) {
                $existing = $this->supplierModel->findExisting(
                    $post['supplier_vat_id'] ?? null,
                    $post['supplier_name'] ?? null
                );

                if ($existing) {
                    session()->setFlashdata('alert_error', 'Esiste già un fornitore con questa partita IVA o denominazione');

                    return redirect()->to('/suppliers/form/' . (int) $existing['supplier_id']);
                }
            }

            if ($this->supplierModel->validate($post)) {
                $dbArray = $this->supplierModel->dbArrayFromPost($post);
                // Booleano nativo: PostgreSQL rifiuta gli interi su colonne boolean.
                $dbArray['supplier_active'] = !empty($post['supplier_active']);

                $supplierId = $this->supplierModel->saveRecord($id, $dbArray);

                session()->setFlashdata('alert_success', $id
                    ? 'Fornitore aggiornato correttamente'
                    : 'Fornitore creato correttamente');

                return redirect()->to('/suppliers/view/' . $supplierId);
            }

            $this->supplierModel->formValues = $post;

            return view('suppliers/form', [
                'id'        => $id,
                'model'     => $this->supplierModel,
                'errors'    => $this->supplierModel->errors(),
                'countries' => country_list(),
            ]);
        }

        // findScoped() prima di prepForm(): il record va cercato dentro il
        // perimetro dell'azienda attiva, altrimenti con l'id nell'URL si apre
        // in modifica la scheda di un altro soggetto giuridico -- e il
        // salvataggio successivo la riscrive senza spostarla, quindi la
        // modifica resta invisibile a chi la subisce.
        if ($id && (!$this->supplierModel->findScoped($id) || !$this->supplierModel->prepForm($id))) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('suppliers/form', [
            'id'        => $id,
            'model'     => $this->supplierModel,
            'errors'    => [],
            'countries' => country_list(),
        ]);
    }

    public function view(int $id)
    {
        $supplier = $this->supplierModel->getByIdWithTotals($id);

        if (!$supplier) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('suppliers/view', [
            'supplier' => $supplier,
            'expenses' => (new ExpenseModel())->forSupplier($id),
        ]);
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        // Il perimetro azienda si verifica prima di cancellare: con l'id nel
        // POST si distruggeva il record di un altro soggetto giuridico, e una
        // cancellazione non si annulla.
        if (!$this->supplierModel->findScoped($id)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->supplierModel->deleteWithRelations($id);
        session()->setFlashdata('alert_success', 'Fornitore eliminato correttamente');

        return redirect()->to('/suppliers');
    }

    /**
     * GET suppliers/export — scarica tutta l'anagrafica fornitori come CSV.
     *
     * Stesse colonne del tipo "Fornitori" nella pagina Esportazioni, da
     * ContactExport: la definizione scritta qui intorno duplicava l'altra.
     */
    public function export()
    {
        [$headers, $rows] = (new ContactExport())->suppliers();

        return $this->response
            ->setContentType('text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="fornitori_' . date('Ymd') . '.csv"')
            ->setBody(CsvWriter::toString($headers, $rows));
    }

    /**
     * GET suppliers/export_template — scarica il file CSV di esempio vuoto.
     */
    public function exportTemplate()
    {
        $csv      = (new ContactImporter())->generateSupplierTemplate();
        $filename = 'template_fornitori.csv';

        return $this->response
            ->setContentType('text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($csv);
    }

    /**
     * POST suppliers/import — importa fornitori da un file CSV caricato.
     */
    public function import()
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $file = $request->getFile('import_file');

        if (!$file || !$file->isValid() || $file->hasMoved()) {
            session()->setFlashdata('alert_error', 'File non valido o non caricato.');
            return redirect()->to('/suppliers');
        }

        $ext = strtolower((string) $file->getClientExtension());
        if (!in_array($ext, ['csv', 'txt'], true)) {
            session()->setFlashdata('alert_error', 'Formato file non supportato. Carica un file .csv');
            return redirect()->to('/suppliers');
        }

        // Cartella dell'azienda attiva, come l'import da "Importa dati": una
        // cartella comune lasciava i file caricati qui dove li poteva leggere
        // anche un'altra azienda, e il nome da time() faceva sovrascrivere due
        // import partiti nello stesso secondo.
        $tempPath = WRITEPATH . 'uploads/import/' . active_company_id() . '/';

        if (!is_dir($tempPath) && !mkdir($tempPath, 0755, true) && !is_dir($tempPath)) {
            session()->setFlashdata('alert_error', 'Impossibile creare la cartella di importazione');
            return redirect()->to('/suppliers');
        }

        $tmpName = 'suppliers_' . bin2hex(random_bytes(6)) . '.' . $ext;

        if (!$file->move($tempPath, $tmpName)) {
            session()->setFlashdata('alert_error', 'Impossibile salvare il file caricato');
            return redirect()->to('/suppliers');
        }

        $filePath = $tempPath . $tmpName;

        $updateExisting = (bool) $request->getPost('update_existing');
        $result         = (new ContactImporter())->importSuppliers($filePath, $updateExisting);

        @unlink($filePath);

        $msg = import_result_message($result);

        if (!empty($result['errors'])) {
            $msg .= ' Errori: ' . implode('; ', array_slice($result['errors'], 0, 5));
            session()->setFlashdata('alert_warning', $msg);
        } else {
            session()->setFlashdata('alert_success', $msg);
        }

        return redirect()->to('/suppliers');
    }
}
