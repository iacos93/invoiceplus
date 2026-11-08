<?php

namespace App\Controllers;

use App\Models\BankAccountModel;
use App\Models\ExpenseCategoryModel;
use App\Models\ExpenseModel;

/**
 * Expenses controller — ciclo passivo: fatture di acquisto e spese.
 *
 * L'importazione legge i file XML delle fatture ricevute (singoli, firmati
 * .p7m o lo ZIP scaricato dal portale Fatture e Corrispettivi). Senza
 * accreditamento SDICoop come canale di ricezione, l'Agenzia delle Entrate
 * non consegna le fatture di acquisto in automatico: il file va procurato dal
 * portale Fatture e Corrispettivi o da un intermediario, e questo è il punto
 * in cui entra nell'applicazione. Con l'accreditamento (vedi
 * SdiRicezioneEndpoint) lo stesso import passa invece da
 * PurchaseInvoiceImporter::importDocument() senza intervento manuale.
 */
class Expenses extends BaseController
{
    protected ExpenseModel $expenseModel;

    public function __construct()
    {
        $this->expenseModel = new ExpenseModel();
    }

    public function index()
    {
        $request = service('request');

        $yearGet = $request->getGet('year');
        $year    = $yearGet !== null ? (int) $yearGet : selected_year();
        $months  = request_months();
        $month   = count($months) === 1 ? $months[0] : 0;
        $paid    = (string) ($request->getGet('paid') ?? '');
        $paid    = in_array($paid, ['open', 'paid'], true) ? $paid : '';

        // Tipo documento: '' tutte, 'invoice' spese/fatture di acquisto,
        // 'credit' note di credito ricevute (totale negativo).
        $sign = (string) ($request->getGet('sign') ?? '');
        $sign = in_array($sign, ['invoice', 'credit'], true) ? $sign : '';

        // "Numero di elementi nelle liste" (Impostazioni): come fatture e
        // clienti, la lista spese mostra una pagina per volta invece di tutte
        // le spese del periodo.
        $perPage = list_per_page();
        $page    = list_page('ip_expenses');

        $expenses = $this->expenseModel->getPage($year, $months, $paid, $sign, $perPage, $page);

        // Totali del periodo filtrato, non della pagina mostrata: con la
        // somma delle sole righe in pagina il piede sarebbe cambiato a ogni
        // cambio di pagina.
        $periodTotals = $this->expenseModel->getPeriodTotals($year, $months, $paid, $sign);

        return view('expenses/index', [
            'expenses'      => $expenses,
            'pager'         => $this->expenseModel->pager,
            'group'         => 'ip_expenses',
            'year'          => $year,
            'month'         => $month,
            'months'        => $months,
            'paid'          => $paid,
            'sign'          => $sign,
            'period_totals' => $periodTotals,
            'month_totals'  => $this->expenseModel->getMonthlyTotals($year, $paid, $sign),
        ]);
    }

    /**
     * Azioni di gruppo dalla lista (segna come pagate / da pagare, elimina).
     *
     * Stesso schema delle fatture: la selezione arriva da un form che contiene
     * solo la barra delle azioni, e le caselle di riga gli appartengono con
     * l'attributo HTML5 "form". Avvolgere la tabella avrebbe annidato i moduli
     * gia' presenti nelle righe (elimina): moduli annidati non sono HTML
     * valido e il browser scarta quelli interni.
     */
    public function bulk()
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $action = (string) $request->getPost('bulk_action');
        $ids    = array_values(array_unique(array_filter(array_map('intval', (array) ($request->getPost('ids') ?? [])))));
        $back   = (string) ($request->getPost('back') ?? '');

        if (!in_array($action, ['mark_paid', 'mark_unpaid', 'delete'], true) || $ids === []) {
            session()->setFlashdata('alert_error', trans('bulk_nothing_selected_expenses', '', 'Nessuna spesa selezionata.'));

            return redirect()->to(ip_back_url('expenses', $back));
        }

        $done    = 0;
        $skipped = 0;

        foreach ($ids as $expenseId) {
            // Perimetro azienda prima di toccare la riga: l'id arriva da un
            // POST, e senza findScoped() si scriverebbe (o cancellerebbe) la
            // spesa di un altro soggetto giuridico.
            $expense = $this->expenseModel->findScoped($expenseId);

            if (!$expense) {
                $skipped++;
                continue;
            }

            switch ($action) {
                case 'mark_paid':
                    // Nel verso del documento: su una nota di credito ricevuta
                    // "pagata" significa rimborsata, cioe' pagato = totale
                    // negativo. E' lo stesso confronto di filtro e badge, cosi'
                    // la riga non resta "aperta" per un segno.
                    $this->expenseModel->update($expenseId, [
                        'expense_paid' => number_format((float) $expense['expense_total'], 2, '.', ''),
                    ]);
                    $done++;
                    break;

                case 'mark_unpaid':
                    $this->expenseModel->update($expenseId, ['expense_paid' => '0.00']);
                    $done++;
                    break;

                case 'delete':
                    $this->expenseModel->delete($expenseId);
                    // Stesso motivo della cancellazione singola: l'XML
                    // archiviato resterebbe orfano in writable/uploads e un id
                    // riassegnato mostrerebbe il documento della spesa prima.
                    (new \App\Libraries\FatturaXmlStore())->delete($expenseId);
                    $done++;
                    break;
            }
        }

        // Il riepilogo dice sempre quante righe sono state saltate: in
        // un'azione di massa il silenzio su cio' che non e' stato fatto e' il
        // modo piu' rapido per convincersi che sia stato fatto tutto.
        $labels = [
            'mark_paid'   => trans('bulk_done_mark_paid', '', 'segnate come pagate'),
            'mark_unpaid' => trans('bulk_done_mark_unpaid', '', 'segnate come da pagare'),
            'delete'      => trans('bulk_done_delete_expenses', '', 'eliminate'),
        ];

        if ($done > 0) {
            $msg = $done . ' ' . trans('expenses', '', 'spese') . ' ' . $labels[$action];

            if ($skipped > 0) {
                $msg .= ' — ' . $skipped . ' ' . trans('bulk_skipped', '', 'saltate perché non ammesse per questa azione');
            }

            session()->setFlashdata('alert_success', $msg);
        } else {
            session()->setFlashdata('alert_error',
                trans('bulk_none_eligible_expenses', '', 'Nessuna delle spese selezionate era ammessa per questa azione.'));
        }

        return redirect()->to(ip_back_url('expenses', $back));
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            $post = $request->getPost();

            // Perimetro azienda anche in scrittura: la GET controlla già
            // l'appartenenza, ma il POST con lo stesso id la scavalcherebbe
            // (update() filtra solo per chiave primaria).
            if ($id && !$this->expenseModel->findScoped($id)) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }

            // Gli importi arrivano nel formato locale (1.234,56).
            foreach (['expense_amount', 'expense_tax', 'expense_total', 'expense_paid'] as $field) {
                if (isset($post[$field])) {
                    $post[$field] = standardize_amount($post[$field]) ?: 0;
                }
            }

            $post['expense_date'] = date_to_mysql($post['expense_date'] ?? '');

            // Le tendine lasciate su "Nessuno" arrivano come stringa vuota:
            // MySQL la convertiva in 0, PostgreSQL rifiuta '' in una colonna
            // integer e la spesa non si salvava affatto (fornitore nuovo
            // digitato a mano, nessuna categoria, nessun conto).
            foreach (['supplier_id', 'expense_category_id', 'bank_account_id', 'costing_account_id'] as $field) {
                if (array_key_exists($field, $post) && $post[$field] === '') {
                    $post[$field] = null;
                }
            }

            // Totale coerente con imponibile e imposta: se chi inserisce lo
            // lascia vuoto lo si ricava, invece di salvare una spesa da zero euro.
            if (empty($post['expense_total'])) {
                $post['expense_total'] = (float) $post['expense_amount'] + (float) $post['expense_tax'];
            }

            if ($this->expenseModel->validate($post)) {
                $dbArray = $this->expenseModel->dbArrayFromPost($post);

                if (!$id) {
                    $dbArray['expense_date_created'] = date('Y-m-d H:i:s');
                }

                // Fornitore digitato a mano invece che scelto dalla tendina:
                // prima la spesa restava con il solo nome in chiaro, e il
                // fornitore non compariva in anagrafica né nella sua scheda
                // (saldo, spese collegate). Lo si cerca per P.IVA o nome
                // dentro l'azienda attiva e, se non c'è, lo si crea -- come fa
                // già l'import XML.
                if (empty($dbArray['supplier_id']) && trim((string) ($dbArray['supplier_name'] ?? '')) !== '') {
                    $dbArray['supplier_id'] = $this->resolveSupplier($dbArray);
                }

                $this->expenseModel->saveRecord($id, $dbArray);
                session()->setFlashdata('alert_success', $id ? 'Spesa aggiornata correttamente' : 'Spesa registrata correttamente');

                return redirect()->to('/expenses');
            }

            $this->expenseModel->formValues = $post;

            return view('expenses/form', $this->formData($id, $this->expenseModel->errors()));
        }

        // findScoped() prima di prepForm(): il record va cercato dentro il
        // perimetro dell'azienda attiva, altrimenti con l'id nell'URL si apre
        // in modifica la scheda di un altro soggetto giuridico -- e il
        // salvataggio successivo la riscrive senza spostarla, quindi la
        // modifica resta invisibile a chi la subisce.
        if ($id && (!$this->expenseModel->findScoped($id) || !$this->expenseModel->prepForm($id))) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // "Nuova spesa" dalla scheda di un fornitore arriva con ?supplier_id=:
        // il form si apre gia' compilato, come per i documenti creati da un
        // cliente (?client_id=).
        if (!$id && ($preset = (int) $request->getGet('supplier_id'))) {
            // findScoped(): il supplier_id arriva dalla query string, quindi
            // senza perimetro il form della nuova spesa si apriva precompilato
            // con ragione sociale e partita IVA del fornitore di un'altra
            // azienda, che poi finivano salvate qui dentro.
            $supplier = (new \App\Models\SupplierModel())->findScoped($preset);

            if ($supplier) {
                $this->expenseModel->formValues = [
                    'supplier_id'       => $supplier['supplier_id'],
                    'supplier_name'     => $supplier['supplier_name'],
                    'supplier_vat'      => $supplier['supplier_vat_id'],
                    'supplier_tax_code' => $supplier['supplier_tax_code'],
                ];
            }
        }

        return view('expenses/form', $this->formData($id, []));
    }

    /** Id del fornitore indicato nella spesa, creandolo in anagrafica se manca. */
    private function resolveSupplier(array $expense): ?int
    {
        $suppliers = new \App\Models\SupplierModel();
        $name      = trim((string) $expense['supplier_name']);
        $vat       = trim((string) ($expense['supplier_vat'] ?? ''));
        $existing  = $suppliers->findExisting($vat, $name);

        if ($existing) {
            return (int) $existing['supplier_id'];
        }

        $id = $suppliers->saveRecord(null, [
            'supplier_name'     => $name,
            'supplier_vat_id'   => $vat,
            'supplier_tax_code' => trim((string) ($expense['supplier_tax_code'] ?? '')),
            'supplier_active'   => true,
        ]);

        return $id ?: null;
    }

    private function formData(?int $id, array $errors): array
    {
        return [
            'expense_id'    => $id,
            'errors'        => $errors,
            'model'         => $this->expenseModel,
            'categories'    => (new ExpenseCategoryModel())->getAllOrdered(),
            'bank_accounts' => (new BankAccountModel())->getAllOrdered(),
            'suppliers'     => (new \App\Models\SupplierModel())->forSelect(),
            // Voci di costo del Bilancino: variabili e fisse insieme, perché
            // la distinzione si vede nel report e chi inserisce la spesa non
            // deve scegliere due volte.
            'costing_accounts' => (new \App\Models\CostingAccountModel())
                ->options(\App\Models\CostingAccountModel::TYPE_VARIABLE)
                + (new \App\Models\CostingAccountModel())
                    ->options(\App\Models\CostingAccountModel::TYPE_FIXED),
        ];
    }

    public function delete(int $id)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Il perimetro azienda si verifica prima di cancellare: con l'id nel
        // POST si distruggeva il record di un altro soggetto giuridico, e una
        // cancellazione non si annulla.
        if (!$this->expenseModel->findScoped($id)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $this->expenseModel->delete($id);

        // Via anche l'XML archiviato, altrimenti resterebbe orfano in
        // writable/uploads/expenses e un id riassegnato mostrerebbe il
        // documento della spesa precedente.
        (new \App\Libraries\FatturaXmlStore())->delete($id);

        session()->setFlashdata('alert_success', 'Spesa eliminata correttamente');

        return redirect()->to('/expenses');
    }

    /**
     * Anteprima leggibile della fattura di acquisto: l'XML archiviato reso in
     * HTML dal foglio di stile scelto (Agenzia delle Entrate o AssoSoftware).
     */
    public function previewXml(int $id)
    {
        $expense = $this->expenseModel->getById($id);

        if (!$expense) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $store = new \App\Libraries\FatturaXmlStore();
        $xml   = $store->get($id);

        $preview = new \App\Libraries\FatturaPreview();
        $style   = (string) (service('request')->getGet('style') ?: \App\Libraries\FatturaPreview::DEFAULT_STYLE);
        $download = service('request')->getGet('download');

        $result = $xml === null
            ? [
                'ok'    => false,
                'style' => $style,
                'error' => 'Di questa spesa non e\' archiviato l\'XML. Sono conservati solo i file importati dopo l\'attivazione dell\'anteprima: reimporta la fattura per poterla visualizzare.',
            ]
            : $preview->render($xml, $style);

        if ($download && $xml !== null) {
            $filename = 'spesa_' . $expense['expense_number'] . '.xml';
            return $this->response
                ->setHeader('Content-Type', 'application/xml')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->setBody($xml);
        }

        return view('expenses/preview_xml', [
            'expense'  => $expense,
            'result'   => $result,
            'styles'   => $preview->styles(),
            'style'    => $result['style'],
            'has_xml'  => $xml !== null,
            'summary'  => $preview->summary((string) ($xml ?? '')),
            'rawXml'   => $xml ?? '',
        ]);
    }

    /** Scarica l'XML originale della fattura di acquisto. */
    public function downloadXml(int $id)
    {
        $expense = $this->expenseModel->getById($id);
        $xml     = (new \App\Libraries\FatturaXmlStore())->get($id);

        if (!$expense || $xml === null) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $name = $expense['expense_file'] ?: ('spesa_' . $id . '.xml');

        return $this->response
            ->setHeader('Content-Type', 'application/xml')
            ->setHeader('Content-Disposition', 'attachment; filename="' . str_replace('"', '', $name) . '"')
            ->setBody($xml);
    }

    /* ------------------------------------------------- importazione XML */

    public function import()
    {
        return view('expenses/import', [
            'categories' => (new ExpenseCategoryModel())->getAllOrdered(),
        ]);
    }

    public function runImport()
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $files = $request->getFileMultiple('xml_files') ?: [];

        if (!$files) {
            session()->setFlashdata('alert_error', 'Nessun file selezionato.');

            return redirect()->to('/expenses/import');
        }

        $categoryId = (int) $request->getPost('expense_category_id') ?: null;
        $importer   = new \App\Libraries\PurchaseInvoiceImporter();

        $imported = 0;
        $skipped  = 0;
        $failed   = [];
        $rejected = [];
        $createdSuppliers = 0;

        // La lettura (.xml/.p7m/.zip) resta qui perché lavora su un file
        // caricato (getTempName()); il resto — parsing, dedup, anagrafica
        // fornitore, salvataggio spesa+XML — è nell'importer condiviso
        // anche con l'endpoint automatico SDICoop-Ricezione.
        foreach ($files as $file) {
            if (!$file->isValid()) {
                continue;
            }

            $outcome = $importer->importFromPath($file->getTempName(), $file->getClientName(), $categoryId);

            $imported         += $outcome['imported'];
            $skipped          += $outcome['skipped'];
            $createdSuppliers += $outcome['created_suppliers'];
            $failed            = array_merge($failed, $outcome['failed']);
            $rejected          = array_merge($rejected, $outcome['rejected']);
        }

        $message = $imported . ' spese importate';

        if ($skipped) {
            $message .= ', ' . $skipped . ' già presenti (saltate)';
        }

        if ($createdSuppliers) {
            $message .= ', ' . $createdSuppliers . ' nuovi fornitori in anagrafica';
        }

        session()->setFlashdata($imported ? 'alert_success' : 'alert_error', $message);

        // I rifiutati (fatture emesse, intestate ad altri, integrazioni TD16)
        // si elencano a parte dagli illeggibili: sono due problemi diversi, e
        // chi carica uno ZIP misto deve capire subito quale dei due ha.
        $problems = [];

        if ($rejected) {
            $problems[] = count($rejected) . ' rifiutati: ' . implode('; ', array_slice($rejected, 0, 5))
                . (count($rejected) > 5 ? '; …' : '');
        }

        if ($failed) {
            $problems[] = count($failed) . ' non leggibili: ' . implode('; ', array_slice($failed, 0, 5))
                . (count($failed) > 5 ? '; …' : '');
        }

        if ($problems) {
            session()->setFlashdata('alert_error', $message . '. ' . implode('. ', $problems));
        }

        return redirect()->to('/expenses');
    }
}
