<?php

namespace App\Controllers;

use App\Libraries\UploadGuard;
use App\Models\ClientModel;
use App\Models\InvoiceAmountModel;
use App\Models\InvoiceCustomModel;
use App\Models\InvoiceGroupModel;
use App\Models\InvoiceModel;
use App\Models\InvoiceTaxRateModel;
use App\Models\ItemModel;
use App\Models\PaymentMethodModel;
use App\Models\PaymentModel;
use App\Models\ProjectModel;
use App\Models\TaskModel;
use App\Models\TaxRateModel;
use App\Models\UnitModel;
use App\Services\DocumentPdfBuilder;
use App\Services\InvoiceMath;
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
        // Questo salto intermedio consumava il flashdata: chi rimandava qui
        // (eliminazione, import, azioni di gruppo senza "back") impostava un
        // messaggio che l'utente non vedeva mai. Lo si conserva per la
        // richiesta successiva, una volta per tutti i chiamanti.
        // Una chiave alla volta: markAsFlashdata() con un array si arrende
        // alla prima chiave assente e non conserva nemmeno le altre.
        foreach (['alert_success', 'alert_error', 'alert_info', 'alert_warning'] as $key) {
            session()->keepFlashdata($key);
        }

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
        // perimetro di stato e preferiti della lista, ma su un anno solo —
        // quello scelto o, con «Tutti gli anni», l'anno in corso (vedi
        // period_month_year()).
        $monthYear   = period_month_year($year);
        $monthTotals = $this->invoiceModel->getMonthlyTotals($status, $monthYear, $favourite, $sign);

        $data = [
            'invoices'         => $invoices,
            'pager'            => $this->invoiceModel->pager,
            'status'           => $status,
            'invoice_statuses' => $this->invoiceModel->statuses(),
            'years'            => $this->invoiceModel->getAvailableYears(),
            'year'             => $year,
            'months'           => $months,
            'favourite'        => $favourite,
            'sign'             => $sign,
            'period_totals'    => $periodTotals,
            'month_totals'     => $monthTotals,
            'month_year'       => $monthYear,
            'sdi_map'          => (new \App\Models\SdiTransmissionModel())
                ->latestMap(array_column($invoices, 'invoice_id')),
            // Ultimo invio per riga: la lista mostra se l'email è stata aperta
            // senza una query per fattura (vedi email_track_note()).
            'email_map'        => (new \App\Models\EmailTrackModel())
                ->latestMap('invoice', array_column($invoices, 'invoice_id')),
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

        // Il driver Postgre restituisce il booleano come stringa "t"/"f", e
        // empty("f") è false: l'inversione non avveniva mai e la stella
        // riscriveva sempre false. db_bool legge lo stato, poi si inverte.
        $isFavourite = db_bool($invoice['invoice_is_favourite'] ?? false);

        $this->invoiceModel->update($invoiceId, [
            'invoice_is_favourite' => !$isFavourite,
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
     *  - segna come pagate: registra un incasso del residuo, non cambia lo
     *    stato — "Pagato" è una conseguenza del saldo, e scriverlo a mano lo
     *    scollerebbe dai pagamenti. Restano fuori bozze, documenti già saldati
     *    e le RiBa non ancora scadute, che la banca presenterà alla scadenza
     *    (la regola sta in InvoiceMath::bulkPaidSkipReason() ed è testata lì).
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

        $allowed = ['favourite_on', 'favourite_off', 'mark_sent', 'mark_paid', 'delete'];

        if (!in_array($action, $allowed, true) || $ids === []) {
            session()->setFlashdata('alert_error', trans('bulk_nothing_selected', '', 'Nessuna fattura selezionata.'));

        return redirect()->to(ip_back_url('invoices', $back));
        }

        $done    = 0;
        $skipped = 0;

        // Metodi RiBa (codice SdI MP12), oggi e conteggio a parte delle RiBa
        // saltate: servono solo all'azione che registra gli incassi, e i
        // metodi si leggono una volta sola invece di una per riga.
        $ribaMethodIds = $action === 'mark_paid' ? (new PaymentMethodModel())->ribaMethodIds() : [];
        $today         = date('Y-m-d');
        $ribaSkipped   = 0;

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

                case 'mark_paid':
                    // Il residuo e i totali stanno in ip_invoice_amounts:
                    // findScoped() legge la sola ip_invoices e qui non basta.
                    $full = $this->invoiceModel->getByIdScoped($invoiceId);

                    $reason = $full === null ? 'missing' : InvoiceMath::bulkPaidSkipReason(
                        (int) $full['invoice_status_id'],
                        (float) $full['invoice_total'],
                        (float) $full['invoice_balance'],
                        in_array((int) ($full['payment_method'] ?? 0), $ribaMethodIds, true),
                        $full['invoice_date_due'] ?? null,
                        $today
                    );

                    if ($reason !== null || ! $this->registerBulkPayment($full)) {
                        $skipped++;

                        if ($reason === 'riba_pending') {
                            $ribaSkipped++;
                        }

                        break;
                    }

                    $done++;
                    break;

                case 'delete':
                    $enableDeletion = (int) get_setting('enable_invoice_deletion', 0);

                    if (((int) $invoice['invoice_status_id'] !== 1 && $enableDeletion !== 1)
                        || $this->invoiceModel->isLocked($invoice)) {
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
            'mark_paid'     => trans('bulk_done_mark_paid_invoice', '', 'segnate come pagate'),
            'delete'        => trans('bulk_done_delete', '', 'eliminate'),
        ];

        if ($done > 0) {
            $msg = $done . ' ' . ($done === 1
                    ? trans('invoice', '', 'fattura')
                    : trans('invoices', '', 'fatture')) . ' ' . $labels[$action];

            if ($skipped > 0) {
                $msg .= ' — ' . $skipped . ' ' . trans('bulk_skipped', '', 'saltate perché non ammesse per questa azione');

                // Le RiBa non scadute sono un'esclusione voluta, non un
                // errore: chi ha selezionato tutto deve sapere quante ne sono
                // rimaste fuori e perché.
                if ($ribaSkipped > 0) {
                    $msg .= ' (' . sprintf(trans('bulk_skipped_riba', '', '%d RiBa non ancora scadute'), $ribaSkipped) . ')';
                }
            }

            session()->setFlashdata('alert_success', $msg);
        } else {
            session()->setFlashdata('alert_error',
                trans('bulk_none_eligible', '', 'Nessuna delle fatture selezionate era ammessa per questa azione.'));
        }

        return redirect()->to(ip_back_url('invoices', $back));
    }

    /**
     * Registra l'incasso del residuo di una fattura (azione di gruppo).
     *
     * Passa dal modello dei pagamenti e non da un update dello stato: il
     * pagamento è la fonte di verità del saldo e da lì InvoiceAmountModel
     * porta la fattura a "Pagato", esattamente come un incasso inserito dal
     * form. Data di oggi, metodo della fattura e conto di saldo predefinito di
     * quel metodo, così la riga che si vede nei pagamenti è quella che si
     * sarebbe scritta a mano.
     */
    private function registerBulkPayment(array $invoice): bool
    {
        $suggested = InvoiceMath::suggestedPayment(
            (float) $invoice['invoice_total'],
            (float) $invoice['invoice_balance']
        );

        if ($suggested <= 0) {
            return false;
        }

        $paymentModel = new PaymentModel();
        $methodId     = (int) ($invoice['payment_method'] ?? 0);

        $post = $paymentModel->applyDocumentSign([
            'invoice_id'        => (int) $invoice['invoice_id'],
            'payment_date'      => date('Y-m-d'),
            'payment_amount'    => format_amount($suggested),
            'payment_method_id' => $methodId,
            'bank_account_id'   => default_bank_account_for_payment($methodId) ?: null,
            'payment_note'      => trans('bulk_payment_note', '', 'Incasso registrato dalle azioni di gruppo'),
        ]);

        if (! $paymentModel->validate($post)) {
            return false;
        }

        return (bool) $paymentModel->saveRecord(null, $paymentModel->dbArrayFromPost($post));
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
            if (!$file instanceof \CodeIgniter\HTTP\Files\UploadedFile) {
                continue;
            }

            // FatturaPA emessa: dimensione e MIME reale prima di passare il
            // file all'importer. Un PDF rinominato .xml o un archivio enorme
            // non devono arrivare al parser; il rifiuto non ferma gli altri.
            $reason = UploadGuard::checkUploadedFile($file, UploadGuard::KIND_XML);

            if ($reason !== null) {
                $totals['rejected'][] = $file->getClientName() . ': ' . $reason;
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

    /**
     * Archivio PDF delle fatture (cartella writable/uploads/archive).
     *
     * La cartella e' comune a tutte le aziende e i nomi non contengono
     * l'azienda: prima l'elenco e il download mostravano qualunque PDF
     * presente, compreso quello di un altro soggetto giuridico. Qui si
     * accettano solo i file il cui suffisso corrisponde al numero di una
     * fattura dell'azienda attiva.
     */
    public function archive()
    {
        $request = service('request');

        $suffissi = $this->archiveNumberSuffixes();

        // Download di un file dall'archivio (parametro ?download=)
        $download = $request->getGet('download');
        if ($download) {
            $name = basename((string) $download);

            if (!$this->archiveBelongsToCompany($name, $suffissi)) {
                throw PageNotFoundException::forPageNotFound();
            }

            $filePath = WRITEPATH . 'uploads/archive/' . $name;

            if (!is_file($filePath)) {
                throw PageNotFoundException::forPageNotFound();
            }

            $this->response->setContentType('application/pdf');
            $this->response->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"');

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
                $cercato = '_' . basename((string) $invoiceNumber) . '.pdf';

                if (isset($suffissi[$cercato])) {
                    $invoiceArray = glob($archiveDir . '*' . $cercato) ?: [];
                }
            } else {
                foreach (glob($archiveDir . '*.pdf') ?: [] as $file) {
                    if ($this->archiveBelongsToCompany(basename($file), $suffissi)) {
                        $invoiceArray[] = $file;
                    }
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
     * Suffissi `_<numero>.pdf` delle fatture dell'azienda attiva, come chiavi.
     *
     * Senza azienda attiva l'elenco e' vuoto: meglio un archivio vuoto che uno
     * con i documenti di tutti.
     *
     * @return array<string, true>
     */
    private function archiveNumberSuffixes(): array
    {
        $companyId = active_company_id();

        if ($companyId <= 0) {
            return [];
        }

        $numbers = $this->invoiceModel->builder()
            ->select('invoice_number')
            ->where('company_id', $companyId)
            ->get()
            ->getResultArray();

        $suffissi = [];

        foreach ($numbers as $row) {
            $number = (string) ($row['invoice_number'] ?? '');

            if ($number !== '') {
                $suffissi['_' . $number . '.pdf'] = true;
            }
        }

        return $suffissi;
    }

    /**
     * Il nome file dell'archivio termina con il numero di una fattura
     * dell'azienda attiva?
     *
     * @param array<string, true> $suffissi
     */
    private function archiveBelongsToCompany(string $name, array $suffissi): bool
    {
        foreach (array_keys($suffissi) as $suffisso) {
            if (str_ends_with($name, $suffisso)) {
                return true;
            }
        }

        return false;
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
            'email_tracks'    => (new \App\Models\EmailTrackModel())->latestFor('invoice', $invoiceId),
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

        // La pagina di modifica non si apre su una fattura bloccata: gli
        // endpoint che salvano la rifiutano comunque, ma lasciar compilare un
        // modulo che poi non si puo' salvare e' peggio che dirlo subito.
        if ($this->invoiceModel->isLocked($invoice)) {
            session()->setFlashdata('alert_error', trans('invoice_locked', '', 'La fattura è bloccata e non si può modificare. Se è stata trasmessa allo SdI, per correggerla emetti una nota di credito.'));

            return redirect()->to('/invoices/view/' . $invoiceId);
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

        // Una fattura bloccata non si elimina nemmeno con la cancellazione
        // abilitata: l'impostazione serve alle bozze e agli errori di
        // emissione, non a far sparire un documento che lo SdI ha gia'.
        if ($this->invoiceModel->isLocked($invoice)) {
            session()->setFlashdata('alert_error', trans('invoice_locked', '', 'La fattura è bloccata e non si può modificare. Se è stata trasmessa allo SdI, per correggerla emetti una nota di credito.'));

            return redirect()->to('/invoices/view/' . $invoiceId);
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

        // Prima della XSD: il messaggio dello schema parla di un elemento "non
        // atteso" e non dice che manca l'indirizzo di una delle due parti, che
        // è l'unica cosa da fare per sbloccare l'invio.
        $incomplete = \App\Libraries\Sdi\SdiInvoiceCheck::missingSede($invoice);

        if ($incomplete !== []) {
            $details = [];

            foreach ($incomplete as $problem) {
                $party = $problem['party'] === 'company'
                    ? trans('company', '', 'Azienda')
                    : trans('client', '', 'Cliente');

                $details[] = $party . ' «' . $problem['name'] . '»: ' . implode(', ', $problem['fields']);
            }

            session()->setFlashdata(
                'alert_error',
                trans('sdi_send_blocked_missing', '', 'Invio bloccato: indirizzo, CAP o comune mancanti.') . ' ' . implode('; ', $details)
            );

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
        // Solo lettura. Prima, con mark_invoices_sent_pdf attivo, questa GET
        // numerava e marcava la fattura come inviata: un link aperto per
        // curiosita' cambiava lo stato di un documento fiscale, e l'export ZIP
        // (che chiama questo metodo per ogni fattura) le spediva tutte in
        // blocco senza che nessuno lo avesse chiesto. La marcatura ora ha il
        // suo POST: Invoices::markSent().
        return $this->renderInvoicePdf($invoiceId, $stream, $template);
    }

    /**
     * "Segna come inviata" dopo aver scaricato il PDF (impostazione
     * mark_invoices_sent_pdf).
     *
     * Staccata dalla generazione del PDF di proposito: generare e scaricare
     * non e' inviare, e chi apre due volte il PDF non deve trovare la fattura
     * gia' "spedita". Il POST lo dice esplicitamente, con il token CSRF.
     */
    public function markSent(int $invoiceId)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        if (!$this->invoiceModel->getByIdScoped($invoiceId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->invoiceModel->generateInvoiceNumberIfApplicable($invoiceId);
        $this->invoiceModel->markSent($invoiceId);

        log_message('info', 'Fattura {invoice} segnata come inviata da utente {user}', [
            'invoice' => $invoiceId,
            'user'    => (int) session('user_id'),
        ]);

        session()->setFlashdata('alert_success', trans('invoice_marked_sent', '',
            'Fattura segnata come inviata.'));

        return redirect()->to('/invoices/view/' . $invoiceId);
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

        // La riga già letta passa al compositore: rileggerla dentro
        // buildInvoiceHtml() erano due volte la query più pesante della pagina
        // (5 join, due sottoquery) per lo stesso documento.
        $html     = $this->buildInvoiceHtml($invoiceId, $template, $invoice);
        $filename = (new DocumentPdfBuilder())->invoiceFilename($invoice);

        return pdf_create($html, $filename, $stream);
    }

    /**
     * Compone l'HTML del documento dal template PDF configurato.
     * Usato sia dal PDF sia dall'anteprima a schermo, cosi' le due non possono
     * mostrare cose diverse.
     */
    /**
     * @param array<string, mixed>|null $invoice riga già letta dal chiamante,
     *        per non rileggerla: null = la legge qui
     */
    private function buildInvoiceHtml(int $invoiceId, ?string $template = null, ?array $invoice = null): string
    {
        $invoice ??= $this->invoiceModel->getByIdScoped($invoiceId);

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
            // $xml è assegnata sia nel ramo riuscito sia nel catch: il
            // controllo su null qui non copriva nessun caso.
            'summary' => $preview->summary($xml),
            'rawXml'  => $xml,
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
        $xml = (string) $this->generateXml($invoiceId, false);

        // La validazione gira sulla stessa stringa che si legge in pagina: il
        // numero di riga dell'errore deve poter essere cercato nello <pre>.
        // Validando il file compatto il messaggio diceva "Riga 44" mentre a
        // schermo le righe erano già tre volte tante, e la riga citata non
        // corrispondeva a nulla di visibile. Il tracciato è lo stesso: la
        // formattazione cambia solo gli spazi fra gli elementi.
        $pretty = pretty_print_xml($xml);
        $result = (new \App\Libraries\FatturaXml())->validate($pretty);

        return view('invoices/validate_xml', [
            'invoice'   => $invoice,
            'result'    => $result,
            // Dati anagrafici obbligatori: quasi sempre è questo il motivo per
            // cui la XSD rifiuta, e lo dice in un modo che non aiuta.
            'preflight' => \App\Libraries\Sdi\SdiInvoiceCheck::missingSede($invoice),
            'xml'       => $pretty,
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
    /**
     * Sblocca una fattura in sola lettura, se lo SdI non la considera chiusa
     * (vedi InvoiceModel::canUnlock). Serve per correggere un'importata, una
     * scartata o una scaricata a mano e mai consegnata.
     */
    public function unlock(int $invoiceId)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $invoice = $this->invoiceModel->getByIdScoped($invoiceId);

        if (!$invoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        if (!$this->invoiceModel->isLocked($invoice)) {
            return redirect()->to('/invoices/view/' . $invoiceId);
        }

        if (!$this->invoiceModel->canUnlock($invoiceId)) {
            session()->setFlashdata('alert_error', trans('invoice_unlock_forbidden', '', 'Lo SdI ha già questa fattura: non si sblocca. Per correggerla emetti una nota di credito.'));

            return redirect()->to('/invoices/view/' . $invoiceId);
        }

        $this->invoiceModel->setLocked($invoiceId, false);
        log_message('info', 'Fattura {id} sbloccata dall\'utente {user}', ['id' => $invoiceId, 'user' => (int) session('user_id')]);

        session()->setFlashdata('alert_success', trans('invoice_unlocked', '', 'Fattura sbloccata: ora si può modificare.'));

        return redirect()->to('/invoices/view/' . $invoiceId);
    }

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

        $fattura = $taxRate ? $this->invoiceModel->findScoped($invoiceId) : null;

        if (!$taxRate
            || (int) $taxRate['invoice_id'] !== $invoiceId
            || !$fattura) {
            throw PageNotFoundException::forPageNotFound();
        }

        if ($this->invoiceModel->isLocked($fattura)) {
            session()->setFlashdata('alert_error', trans('invoice_locked', '', 'La fattura è bloccata e non si può modificare. Se è stata trasmessa allo SdI, per correggerla emetti una nota di credito.'));

            return redirect()->to('/invoices/view/' . $invoiceId);
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

        // Una transazione per tutto il giro: senza, ogni calculate() fa i suoi
        // commit e su qualche centinaio di fatture il tempo se ne va in fsync.
        $db = \Config\Database::connect();
        $db->transStart();

        foreach ($invoiceIds as $invoiceId) {
            $amountModel->calculate((int) $invoiceId['invoice_id']);
        }

        $db->transComplete();

        session()->setFlashdata('alert_success', 'Importi ricalcolati per ' . count($invoiceIds) . ' fatture');

        // Direttamente l'elenco: /invoices fa un secondo redirect a
        // /invoices/status/all e il flashdata, che vale per la richiesta
        // successiva, veniva consumato dal salto intermedio senza mai essere
        // mostrato. L'utente ricalcolava e non vedeva alcun esito.
        return redirect()->to('/invoices/status/all');
    }
}