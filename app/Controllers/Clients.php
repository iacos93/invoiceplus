<?php

namespace App\Controllers;

use App\Models\ClientModel;
use App\Models\ClientNoteModel;
use App\Models\ClientCustomModel;
use App\Models\TaxRateModel;
use App\Models\PaymentMethodModel;
use App\Libraries\CsvWriter;
use App\Services\ContactExport;
use App\Services\ContactImporter;

/**
 * Clients controller
 *
 * Porting CI4 del controller Clients (CI3).
 * Stessa logica applicativa: index (redirect a status/active), status
 * (lista filtrata attivo/inattivo), form (crea/modifica con controllo
 * anti-duplicato, assegnazione a tutti gli utenti e campi custom),
 * view (dettaglio con fatture, preventivi, pagamenti e note), delete.
 */
class Clients extends BaseController
{
    protected ClientModel $clientModel;

    public function __construct()
    {
        $this->clientModel = new ClientModel();
    }

    public function index()
    {
        return redirect()->to('/clients/status/active');
    }

    public function status(string $status = 'active')
    {
        if ($status === 'active') {
            $this->clientModel->isActive();
        } elseif ($status === 'inactive') {
            $this->clientModel->isInactive();
        }

        $perPage = list_per_page();
        $page    = list_page('ip_clients');

        $clients = $this->clientModel->withTotalBalance()->getAllOrdered($perPage, $page);

        $data = [
            'status'  => $status,
            'clients' => $clients,
            'pager'   => $this->clientModel->pager,
            'group'   => 'ip_clients',
        ];

        return view('clients/index', $data);
    }

    public function form(?int $id = null)
    {
        $request = service('request');

        if ($request->getMethod() === 'POST') {
            if ($request->getPost('btn_cancel')) {
                return redirect()->to('/clients');
            }

            $post = $request->getPost();

            // Perimetro azienda anche in scrittura: la GET controlla già
            // l'appartenenza, ma il POST con lo stesso id la scavalcherebbe
            // (update() filtra solo per chiave primaria).
            if ($id && !$this->clientModel->findScoped($id)) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }

            // Controllo anti-duplicato su nome+cognome (solo in creazione,
            // stesso comportamento del controller CI3 originale).
            if (!$id && !empty($post['client_name'])
                && $this->clientModel->findDuplicate($post['client_name'], $post['client_surname'] ?? '')) {
                session()->setFlashdata('alert_error', 'Esiste gia\' un cliente con questo nome');
                return redirect()->to('/clients/form');
            }

            if ($this->clientModel->validate($post)) {
                $dbArray  = $this->clientModel->dbArrayFromPost($post);
                $clientId = $this->clientModel->saveRecord($id, $dbArray);

                if (!$id) {
                    $this->clientModel->assignToAllUsers($clientId);
                }

                if (!empty($post['custom'])) {
                    (new ClientCustomModel())->saveCustom($clientId, $post['custom']);
                }

                session()->setFlashdata('alert_success', $id
                    ? 'Cliente aggiornato correttamente'
                    : 'Cliente creato correttamente');

                return redirect()->to('/clients/view/' . $clientId);
            }

            // Validazione fallita: ripopola i valori dal post per la view
            $this->clientModel->formValues = $post;

            if (!empty($post['custom'])) {
                foreach ($post['custom'] as $key => $value) {
                    $this->clientModel->formValues['custom[' . $key . ']'] = is_array($value) ? implode(',', $value) : $value;
                }
            }

            return $this->renderForm($id, $this->clientModel->errors());
        }

        if ($id) {
            // findScoped() prima di prepForm(): il record va cercato dentro il
            // perimetro dell'azienda attiva, altrimenti con l'id nell'URL si apre
            // in modifica la scheda di un altro soggetto giuridico -- e il
            // salvataggio successivo la riscrive senza spostarla, quindi la
            // modifica resta invisibile a chi la subisce.
            if (!$this->clientModel->findScoped($id) || !$this->clientModel->prepForm($id)) {
                throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
            }

            // Converte data di nascita nel formato di visualizzazione
            $birthdate = $this->clientModel->formValue('client_birthdate');
            if ($birthdate && $birthdate !== '0000-00-00' && $birthdate !== null) {
                $this->clientModel->formValues['client_birthdate'] = date_from_mysql($birthdate);
            }

            // Valori dei campi custom gia' salvati
            foreach ((new ClientCustomModel())->getByClient($id) as $value) {
                $this->clientModel->formValues['custom[' . $value['client_custom_fieldid'] . ']'] = $value['client_custom_fieldvalue'];
            }
        }

        return $this->renderForm($id, []);
    }

    private function renderForm(?int $id, array $errors)
    {
        $customModel = new ClientCustomModel();

        $data = [
            'client_id'       => $id,
            'errors'          => $errors,
            'model'           => $this->clientModel,
            'countries'       => country_list(),
            'languages'       => $this->languages(),
            'custom_fields'   => $customModel->getFieldsForClient(),
            'custom_values'   => $customModel->getCustomValues(),
            'tax_rates'       => (new TaxRateModel())->getAllOrdered(),
            'payment_methods' => (new PaymentMethodModel())->getAllOrdered(),
        ];

        return view('clients/form', $data);
    }

    public function view(int $clientId)
    {
        $client = $this->clientModel->getByIdWithTotals($clientId);

        if (!$client) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $db = \Config\Database::connect();

        $invoices = scope_builder_to_company($db->table('ip_invoices'), 'ip_invoices')
            ->select('ip_invoices.invoice_id, ip_invoices.invoice_number, ip_invoices.invoice_date_created, ip_invoices.invoice_date_due, ip_invoices.invoice_status_id, ip_invoice_amounts.invoice_total, ip_invoice_amounts.invoice_balance')
            ->join('ip_invoice_amounts', 'ip_invoice_amounts.invoice_id = ip_invoices.invoice_id', 'left')
            ->where('ip_invoices.client_id', $clientId)
            ->orderBy('ip_invoices.invoice_date_created', 'DESC')
            ->orderBy('ip_invoices.invoice_id', 'DESC')
            ->limit(20)
            ->get()
            ->getResultArray();

        // Flag scaduta sulle righe (saldo > 0 con scadenza passata): la
        // tabella cliente evidenzia le fatture da sollecitare, come le
        // tabelle "recenti" del dashboard.
        $today = date('Y-m-d');
        foreach ($invoices as &$inv) {
            $inv['is_overdue'] = (float) ($inv['invoice_balance'] ?? 0) > 0
                && !empty($inv['invoice_date_due']) && $inv['invoice_date_due'] !== '0000-00-00' && $inv['invoice_date_due'] !== null
                && $inv['invoice_date_due'] < $today
                && (int) $inv['invoice_status_id'] !== 4;
        }
        unset($inv);

        // Conteggio reale delle fatture (badge tab, non limitato a 20) e
        // saldo scaduto (somma saldi fatture non pagate con scadenza
        // passata) in un'unica query invece di due: stesso JOIN e stessa
        // base di righe, la sola differenza è quali si sommano — una
        // SUM/COUNT condizionale evita di scandire due volte la stessa
        // tabella.
        $invoiceStats = scope_builder_to_company($db->table('ip_invoices'), 'ip_invoices')
            ->select(
                'COUNT(*) AS invoices_total,'
                . " COALESCE(SUM(CASE WHEN ip_invoices.invoice_status_id != 4"
                . " AND ip_invoices.invoice_date_due < '" . $today . "'"
                . " AND ip_invoices.invoice_date_due IS NOT NULL"
                . ' THEN ip_invoice_amounts.invoice_balance ELSE 0 END), 0) AS overdue_total,'
                . " SUM(CASE WHEN ip_invoices.invoice_status_id != 4"
                . " AND ip_invoices.invoice_date_due < '" . $today . "'"
                . " AND ip_invoices.invoice_date_due IS NOT NULL"
                . ' THEN 1 ELSE 0 END) AS overdue_count',
                false
            )
            ->join('ip_invoice_amounts', 'ip_invoice_amounts.invoice_id = ip_invoices.invoice_id', 'left')
            ->where('ip_invoices.client_id', $clientId)
            ->get()
            ->getRowArray();

        $quotes = scope_builder_to_company($db->table('ip_quotes'), 'ip_quotes')
            ->select('ip_quotes.quote_id, ip_quotes.quote_number, ip_quotes.quote_date_created, ip_quotes.quote_status_id, ip_quote_amounts.quote_total')
            ->join('ip_quote_amounts', 'ip_quote_amounts.quote_id = ip_quotes.quote_id', 'left')
            ->where('ip_quotes.client_id', $clientId)
            ->orderBy('ip_quotes.quote_date_created', 'DESC')
            ->orderBy('ip_quotes.quote_id', 'DESC')
            ->limit(20)
            ->get()
            ->getResultArray();

        // Unico builder del metodo senza perimetro: il conteggio poco piu'
        // sotto ce l'ha, quindi il badge diceva N e l'elenco ne mostrava M.
        // Qui il join non e' aliasato, l'alias e' il nome stesso della tabella.
        $payments = scope_builder_to_company($db->table('ip_payments'), 'ip_invoices')
            ->select('ip_payments.payment_id, ip_payments.payment_date, ip_payments.payment_amount, ip_invoices.invoice_id, ip_invoices.invoice_number, ip_payment_methods.payment_method_name')
            ->join('ip_invoices', 'ip_invoices.invoice_id = ip_payments.invoice_id')
            ->join('ip_payment_methods', 'ip_payment_methods.payment_method_id = ip_payments.payment_method_id', 'left')
            ->where('ip_invoices.client_id', $clientId)
            ->orderBy('ip_payments.payment_date', 'DESC')
            ->orderBy('ip_payments.payment_id', 'DESC')
            ->limit(20)
            ->get()
            ->getResultArray();

        // Serie mensile fatturato/incassato (ultimi 12 mesi) per il grafico
        // a barre della scheda cliente: stesso schema del grafico ricavi della
        // dashboard, ma circoscritto a questo cliente (via
        // InvoiceAmountModel::getMonthlySeriesForClient).
        $clientChartSeries = (new \App\Models\InvoiceAmountModel())->getMonthlySeriesForClient($clientId, 12);
        $clientChartMax    = 0.0;
        foreach ($clientChartSeries as $row) {
            $clientChartMax = max($clientChartMax, abs((float) $row['invoiced']), abs((float) $row['paid']));
        }
        $clientChartMax = $clientChartMax > 0 ? $clientChartMax : 1.0;

        // Conteggi reali per i badge delle tab (non limitati a 20).
        // Quello delle fatture viene da $invoiceStats sopra: stessa query
        // dello scaduto, un giro in meno sul database.
        $quotesCount = scope_builder_to_company($db->table('ip_quotes'), 'ip_quotes')
            ->where('client_id', $clientId)->countAllResults();
        $paymentsCount = scope_builder_to_company($db->table('ip_payments'), 'inv')
            ->join('ip_invoices inv', 'inv.invoice_id = ip_payments.invoice_id')
            ->where('inv.client_id', $clientId)
            ->countAllResults();

        $data = [
            'client'           => $client,
            'client_notes'     => (new ClientNoteModel())->getAllForClient($clientId),
            'invoices'         => $invoices,
            'quotes'           => $quotes,
            'payments'         => $payments,
            'invoices_count'   => (int) ($invoiceStats['invoices_total'] ?? 0),
            'quotes_count'     => $quotesCount,
            'payments_count'   => $paymentsCount,
            'client_overdue_total' => (float) ($invoiceStats['overdue_total'] ?? 0),
            'client_overdue_count' => (int) ($invoiceStats['overdue_count'] ?? 0),
            'client_chart_series'  => $clientChartSeries,
            'client_chart_max'     => $clientChartMax,
            'custom_fields'    => (new ClientCustomModel())->getValuesByClient($clientId),
            'invoice_statuses' => $this->invoiceStatuses(),
            'quote_statuses'   => $this->quoteStatuses(),
            'countries'        => country_list(),
        ];

        return view('clients/view', $data);
    }

    public function delete(int $clientId)
    {
        if (service('request')->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Il perimetro azienda si verifica prima di cancellare: con l'id nel
        // POST si distruggeva il record di un altro soggetto giuridico, e una
        // cancellazione non si annulla.
        if (!$this->clientModel->findScoped($clientId)) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $this->clientModel->delete($clientId);
        session()->setFlashdata('alert_success', 'Cliente eliminato correttamente');

        return redirect()->to('/clients');
    }

    /**
     * GET clients/export — scarica tutta l'anagrafica clienti come CSV.
     *
     * Colonne e righe arrivano da ContactExport, le stesse che usa il tipo
     * "Clienti" della pagina Esportazioni: erano definite due volte, e la
     * copia di qui aveva le intestazioni in italiano fisso, quindi in
     * interfaccia inglese lo stesso export usciva con nomi di colonna diversi.
     */
    public function export()
    {
        [$headers, $rows] = (new ContactExport())->clients();

        return $this->response
            ->setContentType('text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="clienti_' . date('Ymd') . '.csv"')
            ->setBody(CsvWriter::toString($headers, $rows));
    }

    /**
     * GET clients/export_template — scarica il file CSV di esempio vuoto.
     */
    public function exportTemplate()
    {
        $csv      = (new ContactImporter())->generateClientTemplate();
        $filename = 'template_clienti.csv';

        return $this->response
            ->setContentType('text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setBody($csv);
    }

    /**
     * POST clients/import — importa clienti da un file CSV caricato.
     *
     * Il form multipart deve includere:
     *   - file "import_file" — il CSV (delimitatore ; o , rilevato auto)
     *   - checkbox "update_existing" — se presente aggiorna i duplicati
     */
    public function import()
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $file = $request->getFile('import_file');

        if (!$file || !$file->isValid() || $file->hasMoved()) {
            session()->setFlashdata('alert_error', 'File non valido o non caricato.');
            return redirect()->to('/clients');
        }

        $ext = strtolower((string) $file->getClientExtension());
        if (!in_array($ext, ['csv', 'txt'], true)) {
            session()->setFlashdata('alert_error', 'Formato file non supportato. Carica un file .csv');
            return redirect()->to('/clients');
        }

        // Cartella dell'azienda attiva, come l'import da "Importa dati": una
        // cartella comune lasciava i file caricati qui dove li poteva leggere
        // anche un'altra azienda, e il nome da time() faceva sovrascrivere due
        // import partiti nello stesso secondo.
        $tempPath = WRITEPATH . 'uploads/import/' . active_company_id() . '/';

        if (!is_dir($tempPath) && !mkdir($tempPath, 0755, true) && !is_dir($tempPath)) {
            session()->setFlashdata('alert_error', 'Impossibile creare la cartella di importazione');
            return redirect()->to('/clients');
        }

        $tmpName = 'clients_' . bin2hex(random_bytes(6)) . '.' . $ext;

        if (!$file->move($tempPath, $tmpName)) {
            session()->setFlashdata('alert_error', 'Impossibile salvare il file caricato');
            return redirect()->to('/clients');
        }

        $filePath = $tempPath . $tmpName;

        $updateExisting = (bool) $request->getPost('update_existing');
        $result         = (new ContactImporter())->importClients($filePath, $updateExisting);

        @unlink($filePath);

        $msg = import_result_message($result);

        if (!empty($result['errors'])) {
            $msg .= ' Errori: ' . implode('; ', array_slice($result['errors'], 0, 5));
            session()->setFlashdata('alert_warning', $msg);
        } else {
            session()->setFlashdata('alert_success', $msg);
        }

        return redirect()->to('/clients');
    }

    private function invoiceStatuses(): array
    {
        return [
            '1' => ['label' => trans('draft'), 'class' => 'draft'],
            '2' => ['label' => trans('sent'), 'class' => 'sent'],
            '3' => ['label' => trans('viewed'), 'class' => 'viewed'],
            '4' => ['label' => trans('paid'), 'class' => 'paid'],
        ];
    }

    private function quoteStatuses(): array
    {
        return [
            '1' => ['label' => trans('draft'), 'class' => 'draft'],
            '2' => ['label' => trans('sent'), 'class' => 'sent'],
            '3' => ['label' => trans('viewed'), 'class' => 'viewed'],
            '4' => ['label' => trans('approved'), 'class' => 'approved'],
            '5' => ['label' => trans('rejected'), 'class' => 'rejected'],
            '6' => ['label' => trans('canceled'), 'class' => 'canceled'],
        ];
    }

    /**
     * Lingue disponibili per il select del form
     * (equivalente di get_available_languages() in CI3, versione ridotta).
     */
    private function languages(): array
    {
        return [
            'italian'           => 'Italiano',
            'english'           => 'English',
            'albanian'          => 'Albanian',
            'arabic'            => 'Arabic',
            'azerbaijani'       => 'Azerbaijani',
            'catalan'           => 'Catalan',
            'croatian'          => 'Croatian',
            'czech'             => 'Czech',
            'danish'            => 'Danish',
            'dutch'             => 'Dutch',
            'estonian'          => 'Estonian',
            'finnish'           => 'Finnish',
            'french'            => 'Français',
            'german'            => 'Deutsch',
            'greek'             => 'Greek',
            'indonesian'        => 'Indonesian',
            'japanese'          => 'Japanese',
            'korean'            => 'Korean',
            'latvian'           => 'Latvian',
            'lithuanian'        => 'Lithuanian',
            'norwegian'         => 'Norwegian',
            'persian'           => 'Persian',
            'polish'            => 'Polish',
            'portuguese'        => 'Portuguese',
            'portuguese-brazil' => 'Portuguese Brazil',
            'romanian'          => 'Romanian',
            'slovenian'         => 'Slovenian',
            'spanish'           => 'Spanish',
            'spanish-argentina' => 'Spanish Argentina',
            'swedish'           => 'Swedish',
            'thai'              => 'Thai',
            'turkish'           => 'Turkish',
            'vietnamese'        => 'Vietnamese',
        ];
    }
}