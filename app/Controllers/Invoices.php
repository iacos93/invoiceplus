<?php

namespace App\Controllers;

use App\Models\ClientModel;
use App\Models\InvoiceAmountModel;
use App\Models\InvoiceCustomModel;
use App\Models\InvoiceGroupModel;
use App\Models\InvoiceModel;
use App\Models\InvoiceTaxRateModel;
use App\Models\ItemModel;
use App\Models\PaymentMethodModel;
use App\Models\ProjectModel;
use App\Models\TaskModel;
use App\Models\TaxRateModel;
use App\Models\UnitModel;
use App\Services\DocumentPdfBuilder;
use CodeIgniter\Exceptions\PageNotFoundException;
use App\Services\FatturaPaXmlService;

/**
 * Invoices controller
 *
 * Porting CI4 del controller Invoices (CI3): elenco fatture con
 * filtri per stato, dettaglio, cancellazione, generazione PDF/XML
 * (Fattura Elettronica, ZUGFeRD) e ricalcolo importi.
 */
class Invoices extends BaseController
{
    protected InvoiceModel $invoiceModel;

    public function __construct()
    {
        $this->invoiceModel = new InvoiceModel();
    }

    public function index()
    {
        return redirect()->to('/invoices/status/all');
    }

    /**
     * Form di creazione rapida fattura (pagina standalone, stile CI3).
     * Restituisce la view con clienti e gruppi fatture; il POST del
     * form va a invoices/ajax/create.
     */
    public function formCreate()
    {
        $selectedClient  = (int) (service('request')->getGet('client_id') ?? 0);
        $doc_prefill_items = [];

        // "Fattura questa attività": arriva da ?task_id=, non da un pulsante
        // generico. Il cliente si ricava dal progetto dell'attività — non
        // c'è altro modo di sapere a chi va la fattura — e la riga si
        // precompila con nome e prezzo dell'attività. item_task_id resta
        // nella riga: InvoicesAjax::save() lo legge già e marca l'attività
        // come fatturata al salvataggio (stato 4), lo stesso codice che
        // gestisce già l'aggiunta di righe a una fattura esistente.
        $taskId = (int) (service('request')->getGet('task_id') ?? 0);

        if ($taskId) {
            // findScoped e non find: l'id arriva dall'URL, e senza perimetro
            // azienda bastava mettere ?task_id=<id di un'altra azienda> per
            // vedersi precompilata una fattura con cliente, nome e prezzo
            // dell'attivita' altrui.
            $task = (new TaskModel())->findScoped($taskId);

            if (!$task) {
                throw PageNotFoundException::forPageNotFound();
            }

            if ((int) $task['task_status'] !== 3) {
                session()->setFlashdata('alert_error', trans('task_must_be_complete_to_invoice', '', 'Solo le attività completate possono essere fatturate.'));

                return redirect()->to('/tasks/form/' . $taskId);
            }

            $project    = $task['project_id'] ? (new ProjectModel())->find((int) $task['project_id']) : null;
            $taskClient = $project['client_id'] ?? null;

            if (!$taskClient) {
                session()->setFlashdata('alert_error', trans('task_needs_client_to_invoice', '', 'Assegna un progetto con cliente all\'attività prima di fatturarla.'));

                return redirect()->to('/tasks/form/' . $taskId);
            }

            $selectedClient = (int) $taskClient;
            $doc_prefill_items = [[
                'item_name'        => $task['task_name'],
                'item_description' => $task['task_description'] ?? '',
                'item_quantity'    => '1',
                'item_price'       => format_amount($task['task_price']),
                'item_task_id'     => (string) $task['task_id'],
            ]];
        }

        $igModel = new InvoiceGroupModel();
        $allGroups = $igModel->getAllOrdered();
        $invoiceGroupId    = (int) company_setting('default_invoice_group', 0);
        $draftInvoiceGroupId = (int) company_setting('draft_invoice_group', 0);
        $invoiceGroups = array_filter($allGroups, fn ($g) => in_array((int) $g['invoice_group_id'], [$invoiceGroupId, $draftInvoiceGroupId], true));

        $data = [
            'clients'           => (new ClientModel())->getAllOrdered(),
            'invoice_groups'    => $invoiceGroups ?: $allGroups,
            // Aliquote per la "prima riga" opzionale del form di creazione.
            'tax_rates'         => (new TaxRateModel())->getAllOrdered(),
            // Unità di misura selezionabili sulla riga (dopo la quantità).
            'unit_groups'       => (new UnitModel())->getGroupedByType(),
            // Metodo di pagamento: il partial condiviso lo mostra solo per le
            // fatture ($doc_show_einvoice), i preventivi non lo hanno.
            'payment_methods'   => (new PaymentMethodModel())->getAllOrdered(),
            // Conti di saldo: il partial li affianca al metodo di pagamento
            // quando il metodo richiede coordinate bancarie (stessa logica
            // della scheda di modifica).
            'bank_accounts'     => (new \App\Models\BankAccountModel())->getAllOrdered(),
            // Preselezione cliente da ?client_id=, usata dal bottone
            // "Nuova fattura"/"Nuovo preventivo" nella scheda cliente (e ora
            // anche da "Fattura questa attività").
            'selected_client'   => $selectedClient,
            'doc_prefill_items' => $doc_prefill_items,
            // Voci di ricavo per il Bilancino (riga per riga). Vuoto finché
            // non esiste un piano dei conti: la tabella righe non mostra il
            // selettore e il documento si salva come sempre.
            'costing_accounts'  => (new \App\Models\CostingAccountModel())
                ->options(\App\Models\CostingAccountModel::TYPE_REVENUE),
        ];

        return view('invoices/form_create', $data);
    }

    public function status(string $status = 'all')
    {
        // Periodo dalla query string: la rotta /invoices/status/{stato} resta
        // invariata, anno e mese sono filtri aggiuntivi (?year=&month=).
        // Se l'anno non è specificato in query string (prima visita alla
        // lista, non un click sui link di stato/mese che lo propagano già),
        // si parte dal filtro anno globale scelto in topbar.
        $request  = service('request');
        $yearGet  = $request->getGet('year');
        $year     = $yearGet !== null ? (int) $yearGet : selected_year();
        // Selezione multipla: month[]=1&month[]=2. Con un mese solo resta il
        // vecchio "month=1", così i link già in giro continuano a valere.
        $months   = request_months();
        $month    = count($months) === 1 ? $months[0] : 0;

        // Preferiti: '' tutte, '1' solo preferite, '0' solo non preferite.
        $favourite = (string) ($request->getGet('fav') ?? '');
        $favourite = in_array($favourite, ['0', '1'], true) ? $favourite : '';

        // Tipo documento: '' tutti, 'invoice' solo fatture, 'credit' solo
        // note di credito (invoice_sign = -1). È un filtro indipendente dallo
        // stato: una nota di credito può essere bozza, inviata o pagata.
        $sign = (string) ($request->getGet('sign') ?? '');
        $sign = in_array($sign, ['invoice', 'credit'], true) ? $sign : '';

        // "Numero di elementi nelle liste" (Impostazioni): prima questa lista
        // ignorava del tutto l'impostazione e mostrava sempre tutte le
        // fatture del filtro corrente (es. 21 invece dei 15 configurati).
        $perPage = list_per_page();
        $page    = list_page('ip_invoices');

        $invoices = $this->invoiceModel->getAllWithStatus($status, $year, $months, $favourite, $perPage, $page, $sign);

        // Totali dell'intero periodo filtrato (non solo della pagina
        // mostrata): query aggregata separata, altrimenti sommando in PHP
        // solo le righe di $invoices i totali cambierebbero pagina per pagina.
        $periodTotals = $this->invoiceModel->getPeriodTotals($status, $year, $months, $favourite, $sign);

        // Valore di ogni mese sotto il nome nella barra periodo: stesso
        // perimetro di stato e preferiti della lista.
        $monthTotals = $this->invoiceModel->getMonthlyTotals($status, $year, $favourite, $sign);

        $data = [
            'invoices'         => $invoices,
            'pager'            => $this->invoiceModel->pager,
            'status'           => $status,
            'invoice_statuses' => $this->invoiceModel->statuses(),
            'years'            => $this->invoiceModel->getAvailableYears(),
            'year'             => $year,
            'month'            => $month,
            'months'           => $months,
            'favourite'        => $favourite,
            'sign'             => $sign,
            'period_totals'    => $periodTotals,
            'month_totals'     => $monthTotals,
            'sdi_map'          => (new \App\Models\SdiTransmissionModel())
                ->latestMap(array_column($invoices, 'invoice_id')),
        ];

        return view('invoices/index', $data);
    }

    /**
     * Segna/desegna la fattura come preferita e torna da dove si è arrivati.
     */
    public function toggleFavourite(int $invoiceId)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $invoice = $this->invoiceModel->findScoped($invoiceId);

        if (!$invoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        // empty() e non !empty(): il valore va invertito, non riscritto
        // uguale -- con !empty() l'update salvava lo stesso stato gia'
        // presente e il pulsante "preferita" non commutava mai.
        $this->invoiceModel->update($invoiceId, [
            'invoice_is_favourite' => empty($invoice['invoice_is_favourite']),
        ]);

        $back = service('request')->getPost('back');

        return redirect()->to(ip_back_url('invoices', $back));
    }

    /**
     * Azioni di gruppo sulla lista fatture.
     *
     * Regola che tiene insieme tutto il metodo: in blocco si può fare solo
     * ciò che si può già fare su una riga sola, con gli stessi vincoli e
     * passando per lo stesso codice. Niente scorciatoie che esistono solo
     * qui — un'azione di massa che salta un controllo lo salta N volte.
     *
     * Di conseguenza:
     *  - preferiti: nessuna conseguenza fiscale, sempre consentito. Imposta
     *    un valore (1 o 0), non inverte: su una selezione mista "inverti"
     *    darebbe un risultato che l'utente non può prevedere guardando lo
     *    schermo.
     *  - segna come inviata: solo da BOZZA, e passa da finalizeNumber(),
     *    che è la stessa strada del salvataggio dal form. Uscire dalla bozza
     *    assegna il numero definitivo, cioè brucia un progressivo: è un atto
     *    fiscale, per questo la vista lo fa confermare dicendolo.
     *  - elimina: stessa condizione del pulsante di riga (solo bozze, salvo
     *    enable_invoice_deletion), verificata qui documento per documento.
     *
     * Ogni fattura è risolta con findScoped(): il perimetro azienda non è un
     * filtro di comodo, e un id arrivato dal POST non è una prova di
     * appartenenza.
     */
    public function bulk()
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $action = (string) $request->getPost('bulk_action');
        $ids    = (array) ($request->getPost('ids') ?? []);
        $ids    = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $back   = (string) ($request->getPost('back') ?? '');

        $allowed = ['favourite_on', 'favourite_off', 'mark_sent', 'delete'];

        if (!in_array($action, $allowed, true) || $ids === []) {
            session()->setFlashdata('alert_error', trans('bulk_nothing_selected', '', 'Nessuna fattura selezionata.'));

        return redirect()->to(ip_back_url('invoices', $back));
        }

        $done    = 0;
        $skipped = 0;

        foreach ($ids as $invoiceId) {
            $invoice = $this->invoiceModel->findScoped($invoiceId);

            if (!$invoice) {
                $skipped++;
                continue;
            }

            switch ($action) {
                case 'favourite_on':
                case 'favourite_off':
                    $this->invoiceModel->update($invoiceId, [
                        'invoice_is_favourite' => $action === 'favourite_on',
                    ]);
                    $done++;
                    break;

                case 'mark_sent':
                    // Solo bozze: una fattura già inviata o pagata non torna
                    // indietro da qui, e "inviata" su un documento già emesso
                    // non significherebbe niente.
                    if ((int) $invoice['invoice_status_id'] !== 1) {
                        $skipped++;
                        break;
                    }

                    $this->invoiceModel->update($invoiceId, ['invoice_status_id' => 2]);
                    // Stessa chiamata del salvataggio dal form: assegna il
                    // numero definitivo se il documento ne aveva uno
                    // provvisorio (o nessuno).
                    $this->invoiceModel->finalizeNumber($invoiceId);
                    $done++;
                    break;

                case 'delete':
                    $enableDeletion = (int) get_setting('enable_invoice_deletion', 0);

                    if ((int) $invoice['invoice_status_id'] !== 1 && $enableDeletion !== 1) {
                        $skipped++;
                        break;
                    }

                    (new TaskModel())->updateOnInvoiceDelete($invoiceId);
                    $this->invoiceModel->delete($invoiceId);
                    $done++;
                    break;
            }
        }

        // Il riepilogo dice sempre quante ne sono state saltate: in un'azione
        // di massa il silenzio su ciò che non è stato fatto è il modo più
        // rapido per convincersi che sia stato fatto tutto.
        $labels = [
            'favourite_on'  => trans('bulk_done_favourite_on', '', 'aggiunte ai preferiti'),
            'favourite_off' => trans('bulk_done_favourite_off', '', 'tolte dai preferiti'),
            'mark_sent'     => trans('bulk_done_mark_sent', '', 'segnate come inviate'),
            'delete'        => trans('bulk_done_delete', '', 'eliminate'),
        ];

        if ($done > 0) {
            $msg = $done . ' ' . ($done === 1
                    ? trans('invoice', '', 'fattura')
                    : trans('invoices', '', 'fatture')) . ' ' . $labels[$action];

            if ($skipped > 0) {
                $msg .= ' — ' . $skipped . ' ' . trans('bulk_skipped', '', 'saltate perché non ammesse per questa azione');
            }

            session()->setFlashdata('alert_success', $msg);
        } else {
            session()->setFlashdata('alert_error',
                trans('bulk_none_eligible', '', 'Nessuna delle fatture selezionate era ammessa per questa azione.'));
        }

        return redirect()->to(ip_back_url('invoices', $back));
    }

    /**
     * Import delle fatture emesse da XML FatturaPA (vedi SalesInvoiceImporter).
     */
    public function import()
    {
        return view('invoices/import');
    }

    public function runImport()
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $files = $request->getFileMultiple('xml_files') ?: [];

        if (!$files) {
            session()->setFlashdata('alert_error', 'Nessun file selezionato.');

            return redirect()->to('/invoices/import');
        }

        $importer = new \App\Libraries\SalesInvoiceImporter();
        $totals   = ['imported' => 0, 'skipped' => 0, 'created_clients' => 0, 'failed' => [], 'rejected' => [], 'warnings' => []];

        foreach ($files as $file) {
            if (!$file->isValid()) {
                continue;
            }

            $outcome = $importer->importFromPath($file->getTempName(), $file->getClientName());

            foreach (['imported', 'skipped', 'created_clients'] as $key) {
                $totals[$key] += $outcome[$key];
            }
            foreach (['failed', 'rejected', 'warnings'] as $key) {
                $totals[$key] = array_merge($totals[$key], $outcome[$key]);
            }
        }

        $message = $totals['imported'] . ' fatture importate';

        if ($totals['skipped']) {
            $message .= ', ' . $totals['skipped'] . ' già presenti (saltate)';
        }

        if ($totals['created_clients']) {
            $message .= ', ' . $totals['created_clients'] . ' nuovi clienti in anagrafica';
        }

        session()->setFlashdata($totals['imported'] ? 'alert_success' : 'alert_error', $message);

        // Come nell'import delle spese: rifiutati (ricevute, di un'altra
        // azienda, autofatture), illeggibili e totali che non tornano sono
        // tre problemi diversi, e vanno detti separatamente.
        $problems = [];
        foreach (['rejected' => 'rifiutati', 'failed' => 'non leggibili', 'warnings' => 'da controllare'] as $key => $label) {
            if ($totals[$key]) {
                $problems[] = count($totals[$key]) . ' ' . $label . ': ' . implode('; ', array_slice($totals[$key], 0, 5))
                    . (count($totals[$key]) > 5 ? '; …' : '');
            }
        }

        if ($problems) {
            session()->setFlashdata('alert_error', $message . '. ' . implode('. ', $problems));
        }

        return redirect()->to('/invoices');
    }

    public function archive()
    {
        $request = service('request');

        // Download di un file dall'archivio (parametro ?download=)
        $download = $request->getGet('download');
        if ($download) {
            $filePath = WRITEPATH . 'uploads/archive/' . basename($download);

            if (!is_file($filePath)) {
                throw PageNotFoundException::forPageNotFound();
            }

            $this->response->setContentType('application/pdf');
            $this->response->setHeader('Content-Disposition', 'attachment; filename="' . basename($download) . '"');

            return $this->response->setBody((string) file_get_contents($filePath));
        }

        // Filtro per numero fattura (?invoice_number=)
        $invoiceNumber = $request->getGet('invoice_number');

        $archiveDir = WRITEPATH . 'uploads/archive/';

        if (!is_dir($archiveDir)) {
            $archiveDir = null;
        }

        $invoiceArray = [];

        if ($archiveDir !== null) {
            if (!empty($invoiceNumber)) {
                $invoiceArray = glob($archiveDir . '*' . '_' . basename((string) $invoiceNumber) . '.pdf');
            } else {
                foreach (glob($archiveDir . '*.pdf') as $file) {
                    $invoiceArray[] = $file;
                }
            }

            rsort($invoiceArray);
        }

        $data = [
            'invoices_archive' => $invoiceArray,
            'invoice_number'   => $invoiceNumber,
        ];

        return view('invoices/archive', $data);
    }

    /**
     * Vista principale della fattura: anteprima del foglio, come lo si
     * scaricherà o invierà. La modifica sta su /invoices/edit — si prepara un
     * documento molte volte prima di spedirlo, quindi la domanda frequente è
     * "com'è venuto?", non "dove modifico il campo".
     */
    public function view(int $invoiceId)
    {
        $invoice = $this->invoiceModel->getByIdScoped($invoiceId);

        if (!$invoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('invoices/preview', [
            'invoice'         => $invoice,
            'invoice_id'      => $invoiceId,
            'payments'        => $this->invoiceModel->getPayments($invoiceId),
            'statuses'        => $this->invoiceModel->statuses(),
            'sdi_transmission' => (new \App\Models\SdiTransmissionModel())->latestForInvoice($invoiceId),
        ]);
    }

    /**
     * Anteprima HTML del documento, servita dentro l'iframe della vista.
     * Stesso template del PDF: quello che si vede è quello che si invia.
     */
    public function previewSheet(int $invoiceId)
    {
        // Il logo va referenziato via http: qui il documento lo rende il
        // browser, non dompdf, e un percorso file:// darebbe immagine rotta.
        $GLOBALS['pdf_preview'] = true;

        $html = $this->buildInvoiceHtml($invoiceId);

        // Anteprima nell'iframe, non PDF: la classe tiene il foglio un A4 vero
        // (210x297 mm, margini del PDF) e layout/preview_sheet.php lo divide in
        // fogli e lo scala alla larghezza del riquadro. Prima la classe faceva
        // l'opposto — adattava il documento alla finestra — e a 1.600 px
        // l'anteprima non somigliava più alla carta. La stessa view usata per
        // il PDF non riceve la classe.
        $html = str_replace('<html ', '<html class="ip-sheet-embedded" ', $html);

        return $this->response->setHeader('Content-Type', 'text/html; charset=UTF-8')
                              ->setBody($html);
    }

    public function edit(int $invoiceId)
    {
        $invoice = $this->invoiceModel->getByIdScoped($invoiceId);

        if (!$invoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        $data = [
            'invoice'            => $invoice,
            'items'              => (new ItemModel())->getByInvoice($invoiceId),
            'invoice_id'         => $invoiceId,
            'tax_rates'          => (new TaxRateModel())->getAllOrdered(),
            'unit_groups'        => (new UnitModel())->getGroupedByType(),
            'invoice_tax_rates'  => (new InvoiceTaxRateModel())->getByInvoice($invoiceId),
            'payment_methods'    => (new PaymentMethodModel())->getAllOrdered(),
            'payments'           => $this->invoiceModel->getPayments($invoiceId),
            'clients'            => (new ClientModel())->getAllOrdered(),
            'invoice_statuses'   => $this->invoiceModel->statuses(),
            'recur_frequencies'  => (new \App\Models\InvoiceRecurringModel())->recurFrequencies(),
            'invoice_is_credit'  => !empty($invoice['creditinvoice_parent_id']),
            'invoice_custom'     => (new InvoiceCustomModel())->getValues($invoiceId),
            'bank_accounts'      => (new \App\Models\BankAccountModel())->getAllOrdered(),
            // Stesse voci del form di creazione: la riga si assegna anche
            // dopo, senza dover tornare al Bilancino.
            'costing_accounts'   => (new \App\Models\CostingAccountModel())
                ->options(\App\Models\CostingAccountModel::TYPE_REVENUE),
        ];

        return view('invoices/edit', $data);
    }

    public function delete(int $invoiceId)
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $invoice = $this->invoiceModel->getByIdScoped($invoiceId);

        if (!$invoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Cancellazione consentita solo per bozze (o setting abilitato)
        $enableDeletion = get_setting('enable_invoice_deletion', 0);

        if ((int) $invoice['invoice_status_id'] === 1 || (int) $enableDeletion === 1) {
            // Riporta i task a "completata"
            (new TaskModel())->updateOnInvoiceDelete($invoiceId);

            // Elimina la fattura e gli orfani
            $this->invoiceModel->delete($invoiceId);

            session()->setFlashdata('alert_success', 'Fattura eliminata correttamente');
        } else {
            session()->setFlashdata('alert_error', trans('invoice_deletion_forbidden'));
        }

        return redirect()->to('/invoices');
    }

    /**
     * Invia la fattura allo SdI tramite il canale configurato
     * (PEC, webservice API o download manuale): Impostazioni > SdI.
     */
    public function sendSdi(int $invoiceId)
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $invoice = $this->invoiceModel->getByIdScoped($invoiceId);

        if (!$invoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Una pratica già in attesa di esito non va rinviata: un doppio
        // invio della stessa fattura farebbe scartare la seconda copia.
        $latest = (new \App\Models\SdiTransmissionModel())->latestForInvoice($invoiceId);

        // Anche una fattura importata già trasmessa da un altro gestionale
        // (esito IMP): un secondo invio sarebbe un duplicato per lo SdI.
        if ($latest && $latest['esito'] === 'IMP') {
            session()->setFlashdata('alert_error', 'Fattura importata: è già stata trasmessa allo SdI con un altro gestionale e non va rinviata.');

            return redirect()->to('/invoices/view/' . $invoiceId);
        }

        if ($latest && ($latest['esito'] === 'pending' || $latest['esito'] === null)) {
            session()->setFlashdata('alert_error', 'Trasmissione già in corso per questa fattura ('
                . $latest['filename'] . '): attendi l\'esito prima di reinviare.');

            return redirect()->to('/invoices/view/' . $invoiceId);
        }

        // stream=false: l'XML è una stringa e non consuma il progressivo
        // di trasmissione, che avanza solo a trasmissione riuscita.
        $xml = (string) $this->generateXml($invoiceId, false);

        // Garanzia: mai trasmettere un file che la XSD rifiuterebbe.
        $result = (new \App\Libraries\FatturaXml())->validate($xml);

        if (empty($result['valid'])) {
            session()->setFlashdata('alert_error', 'XML non valido, invio bloccato: '
                . implode('; ', array_slice($result['errors'], 0, 3)));

            return redirect()->to('/invoices/view/' . $invoiceId);
        }

        $send = \App\Libraries\Sdi\SdiSender::send($invoice, $xml);

        session()->setFlashdata($send['ok'] ? 'alert_success' : 'alert_error', $send['message']);

        return redirect()->to('/invoices/view/' . $invoiceId);
    }

    /**
     * Aggiornamento manuale dell'esito di una trasmissione: fallback
     * quando manca il polling IMAP (estensione php-imap) o la risposta
     * del webservice. Ricevute ufficiali: RC, NS, NE, EC, MC, DT.
     */
    public function setSdiEsito(int $invoiceId)
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $invoice = $this->invoiceModel->getByIdScoped($invoiceId);

        if (!$invoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        $transmissionId = (int) $request->getPost('transmission_id');
        $esito          = (string) $request->getPost('esito');
        $note           = trim((string) $request->getPost('sdi_note'));

        $model        = new \App\Models\SdiTransmissionModel();
        $transmission = $transmissionId > 0 ? $model->find($transmissionId) : null;

        if (!$transmission || (int) $transmission['invoice_id'] !== $invoiceId) {
            throw PageNotFoundException::forPageNotFound();
        }

        if (!array_key_exists($esito, \App\Libraries\Sdi\SdiSender::ESITI)) {
            session()->setFlashdata('alert_error', 'Esito non riconosciuto');

            return redirect()->to('/invoices/view/' . $invoiceId);
        }

        $model->setEsito($transmissionId, $esito, $note);

        session()->setFlashdata('alert_success', 'Esito aggiornato: '
            . \App\Libraries\Sdi\SdiSender::esitoLabel($esito));

        return redirect()->to('/invoices/view/' . $invoiceId);
    }

    /**
     * Genera il PDF della fattura con dompdf.
     * Se mark_invoices_sent_pdf e' attivo genera il numero e marca
     * la fattura come inviata.
     */
    public function generatePdf(int $invoiceId, bool $stream = true, ?string $template = null)
    {
        // Il perimetro si verifica qui, prima di toccare la fattura: con
        // mark_invoices_sent_pdf attivo le due righe sotto assegnano il numero
        // e marcano il documento come inviato. Su un id di un'altra azienda
        // quelle scritture avverrebbero comunque, prima ancora che il
        // rendering fallisca -- si sporcherebbe un documento che non si ha
        // titolo nemmeno di vedere.
        if (!$this->invoiceModel->getByIdScoped($invoiceId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        if (get_setting('mark_invoices_sent_pdf', 0) == 1) {
            $this->invoiceModel->generateInvoiceNumberIfApplicable($invoiceId);
            $this->invoiceModel->markSent($invoiceId);
        }

        return $this->renderInvoicePdf($invoiceId, $stream, $template);
    }

    /**
     * Anteprima PDF (custom IT): come generatePdf ma senza marcare
     * la fattura come inviata.
     */
    public function previewPdf(int $invoiceId, bool $stream = true, ?string $template = null)
    {
        return $this->renderInvoicePdf($invoiceId, $stream, $template);
    }

    /**
     * Prepara i dati e renderizza il template PDF della fattura.
     */
    private function renderInvoicePdf(int $invoiceId, bool $stream = true, ?string $template = null)
    {
        $invoice = $this->invoiceModel->getByIdScoped($invoiceId);

        if (!$invoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        $html     = $this->buildInvoiceHtml($invoiceId, $template);
        $filename = (new DocumentPdfBuilder())->invoiceFilename($invoice);

        return pdf_create($html, $filename, $stream);
    }

    /**
     * Compone l'HTML del documento dal template PDF configurato.
     * Usato sia dal PDF sia dall'anteprima a schermo, cosi' le due non possono
     * mostrare cose diverse.
     */
    private function buildInvoiceHtml(int $invoiceId, ?string $template = null): string
    {
        $invoice = $this->invoiceModel->getByIdScoped($invoiceId);

        if (!$invoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Composizione condivisa con portale cliente, link pubblico, cron e
        // Mailer: una sola copia, cosi' il PDF allegato alle email non puo'
        // divergere da quello scaricato (vedi DocumentPdfBuilder).
        return (new DocumentPdfBuilder())->invoice($invoice, $template);
    }
    /**
     * Genera e scarica l'XML Fattura Elettronica (custom IT).
     */
    /**
     * Verifica che l'XML della fattura rispetti lo schema ufficiale
     * FatturaPA prima dell'invio.
     *
     * Lo SdI scarta i file non conformi con un codice di errore che arriva
     * giorni dopo: controllarlo qui costa un secondo e dice subito quale
     * campo manca.
     */
    /**
     * Anteprima leggibile della fattura emessa: lo stesso XML che verrebbe
     * trasmesso, reso in HTML dal foglio di stile scelto. stream=false perche'
     * qui l'XML serve solo da guardare e non deve consumare il progressivo di
     * trasmissione, che spetta all'invio vero (stessa scelta di validateXml).
     */
    public function previewXml(int $invoiceId)
    {
        $invoice = $this->invoiceModel->getByIdScoped($invoiceId);

        if (!$invoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        $preview = new \App\Libraries\FatturaPreview();
        $style   = (string) (service('request')->getGet('style') ?: \App\Libraries\FatturaPreview::DEFAULT_STYLE);
        $download = service('request')->getGet('download');

        try {
            $xml    = (string) $this->generateXml($invoiceId, false);
            $result = $preview->render($xml, $style);
        } catch (\Throwable $e) {
            $xml    = '';
            $result = [
                'ok'    => false,
                'style' => $style,
                'error' => 'Non e\' stato possibile generare l\'XML di questa fattura: ' . $e->getMessage(),
            ];
        }

        if ($download) {
            $filename = 'fattura_' . $invoice['invoice_number'] . '.xml';
            return $this->response
                ->setHeader('Content-Type', 'application/xml')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->setBody($xml);
        }

        return view('invoices/preview_xml', [
            'invoice' => $invoice,
            'result'  => $result,
            'styles'  => $preview->styles(),
            'style'   => $result['style'],
            'summary' => $preview->summary($xml ?? ''),
            'rawXml'  => $xml ?? '',
        ]);
    }

    public function validateXml(int $invoiceId)
    {
        $invoice = $this->invoiceModel->getByIdScoped($invoiceId);

        if (!$invoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        // stream=false: qui l'XML serve come stringa e non deve consumare il
        // progressivo di trasmissione, che spetta all'invio vero.
        $xml    = (string) $this->generateXml($invoiceId, false);
        $result = (new \App\Libraries\FatturaXml())->validate($xml);

        return view('invoices/validate_xml', [
            'invoice' => $invoice,
            'result'  => $result,
            // pretty_print_xml() reindenta solo la copia mostrata in pagina:
            // $xml (quello validato sopra) resta la stringa compatta originale.
            'xml'     => pretty_print_xml($xml),
        ]);
    }

    /**
     * XML FatturaPA della fattura.
     *
     * $stream = false restituisce solo la stringa XML (senza toccare la
     * response) e NON consuma il progressivo di trasmissione: serve
     * all'esportazione massiva, che produce copie d'archivio, non nuovi invii
     * allo SdI. Rigenerare l'archivio di un anno non deve far avanzare il
     * progressivo di centinaia di posizioni.
     */

    /**
     * Blocchi DatiGenerali che dipendono dalla commessa: ordine di acquisto,
     * contratto, convenzione, ricezione.
     *
     * CIG e CUP vivono dentro questi blocchi, non da soli: il tracciato non
     * prevede un punto dove metterli isolati, quindi vanno replicati in ognuno
     * dei riferimenti presenti. Se non c'e' nessun riferimento ma il CIG o il
     * CUP sono stati indicati, si emette comunque un DatiOrdineAcquisto con
     * IdDocumento "ND" — altrimenti una fattura PA senza ordine perderebbe
     * proprio i due codici che la rendono liquidabile.
     */

    /**
     * Corpo della fattura Carta del Docente: riga "Pagamento Buono".
     *
     * Il documento che liquida il buono non riproduce le righe della fattura
     * normale: le linee guida dell'iniziativa vogliono una sola riga per
     * buono, con descrizione fissa "Pagamento Buono", importo pari al valore
     * del buono (il totale del documento, IVA inclusa: il buono copre il
     * prezzo pieno) e fuori campo IVA (aliquota 0, Natura N2). Il codice del
     * buono va nel blocco CodiceArticolo con tipo CARTADELDOCENTE: e' cosi'
     * che lo Stato riconosce la transazione e la liquida.
     *
     * Il RiferimentoAmministrazione (1.2.6) porta il codice esercente: lo
     * assegna la piattaforma all'iscrizione, e senza di esso la fattura non
     * viene riconosciuta. Il codice sta sulla scheda azienda; si ripiega sul
     * riferimento amministrazione della fattura per le installazioni che non
     * l'hanno ancora configurato.
     */

    /**
     * Scarica l'XML della fattura elettronica.
     *
     * La composizione vive in FatturaPaXmlService: qui restano solo la risposta
     * e i suoi header. La verifica del tracciato si fa senza HTTP.
     */
    public function generateXml(int $invoiceId, bool $stream = true)
    {
        ['xml' => $xml, 'filename' => $filename] = (new FatturaPaXmlService())->build($invoiceId);

        if (!$stream) {
            return $xml;
        }

        $this->response->setContentType('application/xml');
        $this->response->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $this->response->setBody($xml);
    }

    /**
     * XML minimale di fallback (mittente/destinatario/righe).
     */

    /**
     * Genera e scarica l'XML ZUGFeRD della fattura.
     */
    public function generateZugferdXml(int $invoiceId)
    {
        $invoice = $this->invoiceModel->getByIdScoped($invoiceId);

        if (!$invoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        $items = array_map(static fn($item) => (object) $item, (new ItemModel())->getByInvoice($invoiceId));

        $zugferd = new \App\Libraries\ZugferdXml([
            'invoice' => (object) $invoice,
            'items'   => $items,
        ]);

        $filename = 'Fattura_' . ($invoice['invoice_number'] ?: $invoiceId) . '_ZUGFeRD.xml';

        $this->response->setContentType('application/xml');
        $this->response->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $this->response->setBody($zugferd->xml());
    }

    /**
     * Elimina un'imposta a livello di fattura e ricalcola gli importi.
     */
    public function deleteInvoiceTax(int $invoiceId, int $invoiceTaxRateId)
    {
        // Due id dall'URL e nessuna lettura prima della cancellazione:
        // deleteWithRecalculate() cancella per id e basta, quindi l'invoice_id
        // dell'indirizzo non proteggeva niente da solo. Servono entrambe le
        // verifiche -- che la fattura sia dell'azienda attiva e che l'imposta
        // appartenga davvero a quella fattura -- perche' l'una senza l'altra
        // lascia comunque togliere un'imposta a un documento diverso.
        $taxRateModel = new InvoiceTaxRateModel();
        $taxRate      = $taxRateModel->find($invoiceTaxRateId);

        if (!$taxRate
            || (int) $taxRate['invoice_id'] !== $invoiceId
            || !$this->invoiceModel->findScoped($invoiceId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $taxRateModel->deleteWithRecalculate($invoiceTaxRateId, $invoiceId);

        session()->setFlashdata('alert_success', 'Imposta eliminata e importi ricalcolati');

        return redirect()->to('/invoices/view/' . $invoiceId);
    }

    /**
     * Ricalcola gli importi di tutte le fatture.
     */
    public function recalculateAllInvoices()
    {
        // Il controller non ha una proprieta' $db (BaseController non la
        // dichiara): la connessione va presa esplicitamente.
        $invoiceIds = scope_builder_to_company(\Config\Database::connect()->table('ip_invoices'), 'ip_invoices')
            ->select('invoice_id')->get()->getResultArray();

        $amountModel = new InvoiceAmountModel();

        foreach ($invoiceIds as $invoiceId) {
            $amountModel->calculate((int) $invoiceId['invoice_id']);
        }

        session()->setFlashdata('alert_success', 'Importi ricalcolati per ' . count($invoiceIds) . ' fatture');

        // Direttamente l'elenco: /invoices fa un secondo redirect a
        // /invoices/status/all e il flashdata, che vale per la richiesta
        // successiva, veniva consumato dal salto intermedio senza mai essere
        // mostrato. L'utente ricalcolava e non vedeva alcun esito.
        return redirect()->to('/invoices/status/all');
    }
}