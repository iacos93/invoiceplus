<?php

namespace App\Controllers;

use App\Models\ClientModel;
use App\Models\InvoiceAmountModel;
use App\Models\InvoiceModel;
use App\Models\InvoiceRecurringModel;
use App\Models\InvoiceTaxRateModel;
use App\Models\ItemModel;
use App\Models\TaxRateModel;
use App\Models\TaskModel;
use App\Services\InvoiceMath;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * InvoicesAjax controller
 *
 * Porting CI4 del controller Ajax (CI3): salvataggio fatture (create
 * e aggiornamento righe), creazione fatture ricorrenti, gestione
 * righe e imposte, note di credito, cambio cliente e copie.
 */
class InvoicesAjax extends BaseController
{
    /**
     * Campi della fattura elettronica scritti dai form di creazione e di
     * modifica. Non stanno in validationRulesLegacy() (lì ci sono solo quelli
     * con un vincolo di lunghezza), quindi dbArrayFromPost() li scartava: in
     * creazione tipo documento, esigibilità, bollo, ritenuta e cassa scelti
     * nel form sparivano senza avviso. Un elenco solo, usato da entrambi i
     * rami, perché non possano più divergere. C'è anche il conto di saldo:
     * non è un campo del tracciato, ma ne decide l'IBAN, e dal form di
     * creazione andava perso per lo stesso motivo.
     */
    private const EINVOICE_FIELDS = [
        'bank_account_id',
        'invoice_cig', 'invoice_cup', 'invoice_admin_reference',
        'invoice_order_number', 'invoice_order_date', 'invoice_order_line',
        'invoice_contract_number', 'invoice_contract_date',
        'invoice_convention_number', 'invoice_convention_date',
        'invoice_receipt_number', 'invoice_receipt_date',
        'invoice_doc_type', 'invoice_vat_exigibility',
        'invoice_stamp_mode', 'invoice_stamp_amount',
        'invoice_withholding_type', 'invoice_withholding_rate',
        'invoice_withholding_reason', 'invoice_fund_type', 'invoice_fund_rate',
        'invoice_voucher_code',
    ];

    protected InvoiceModel $invoiceModel;

    /** @var array<class-string, object> Model passati dall'esterno (test) */
    private array $modelOverrides;

    /**
     * @param array<class-string, object> $models Model da usare al posto di
     *        quelli creati qui: serve ai test per isolare le azioni senza
     *        database. Il router chiama il controller senza argomenti.
     */
    public function __construct(array $models = [])
    {
        $this->modelOverrides = $models;
        $this->invoiceModel   = $models[InvoiceModel::class] ?? new InvoiceModel();
    }

    /**
     * Model condiviso dal controller: una sola istanza per classe, così una
     * richiesta non crea lo stesso model due volte (prima ne nasceva uno a
     * ogni chiamata) e nei test si può passare un finto.
     */
    private function model(string $class): object
    {
        return $this->modelOverrides[$class] ??= new $class();
    }

    public function save()
    {
        $request = $this->request;

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $post = $request->getPost();

        // Una fattura Carta del Docente senza codice buono è inutilizzabile:
        // il Ministero riconosce la transazione solo dal codice (CodiceArticolo
        // CARTADELDOCENTE) e la XSD lo richiede non vuoto. Meglio fermarsi qui
        // con un messaggio che generare un XML che lo SdI scarterebbe.
        if (!empty($post['invoice_carta_docente']) && trim((string) ($post['invoice_voucher_code'] ?? '')) === '') {
            session()->setFlashdata('alert_error', trans('voucher_required', '', 'Indica il codice buono Carta del Docente: senza, la fattura elettronica non può essere generata.'));

            if (!empty($post['invoice_id'])) {
                return redirect()->to('/invoices/view/' . (int) $post['invoice_id']);
            }

            return redirect()->to('/invoices/form_create')->withInput();
        }

        // Aggiornamento di una fattura esistente (dati generali e/o una
        // riga nuova o modificata)
        if (!empty($post['invoice_id'])) {
            $invoiceId = (int) $post['invoice_id'];

            // Niente veniva letto prima di scrivere: updateInvoiceFromPost()
            // riscrive stato, numero, date, sconti e i campi della fattura
            // elettronica, e all'uscita dalla bozza consuma il progressivo
            // definitivo. Con l'invoice_id preso dal POST tutto questo poteva
            // finire su un documento di un'altra azienda.
            if (!$this->invoiceModel->findScoped($invoiceId)) {
                throw PageNotFoundException::forPageNotFound();
            }

            $this->updateInvoiceFromPost($invoiceId, $post);

            $itemModel = $this->model(ItemModel::class);

            if (array_key_exists('items', $post)) {
                // Salvataggio unico dalla schermata di modifica: tutte le righe
                // viaggiano in JSON insieme alla testata, con gli id di quelle
                // rimosse. Prima ogni riga aveva il proprio form e il proprio
                // POST, quindi ogni aggiunta ricaricava la pagina e buttava via
                // le modifiche non ancora salvate delle altre sezioni.
                $this->syncItems(
                    $invoiceId,
                    $post['items'],
                    (string) ($post['deleted_items'] ?? '')
                );
            } elseif (!empty($post['item_id'])) {
                // La riga deve gia' appartenere a questa fattura:
                // dbArrayFromItem() riscrive invoice_id con quello del POST,
                // quindi un item_id estraneo non verrebbe rifiutato, verrebbe
                // spostato qui dentro portando via il totale al documento di
                // provenienza.
                $existingItem = $itemModel->find((int) $post['item_id']);

                if (!$existingItem || (int) $existingItem['invoice_id'] !== $invoiceId) {
                    throw PageNotFoundException::forPageNotFound();
                }

                $itemModel->saveItem((int) $post['item_id'], $itemModel->dbArrayFromItem($post));
                session()->setFlashdata('alert_success', trans('item_updated', '', 'Riga aggiornata correttamente'));
            } elseif (trim((string) ($post['item_name'] ?? '')) !== ''
                || trim((string) ($post['item_description'] ?? '')) !== '') {
                // Bastano nome *o* descrizione, come per i preventivi
                // (QuoteItemModel::saveItems): una riga di sola descrizione è
                // legittima e di uso corrente. Prima la condizione guardava solo
                // item_name, quindi compilando descrizione, quantità e prezzo ma
                // lasciando vuoto "Articolo" si cadeva nel ramo finale: la riga
                // non veniva scritta e l'utente leggeva "Fattura aggiornata
                // correttamente", cioè un successo per un dato buttato via.
                $itemModel->saveItem(null, $itemModel->dbArrayFromItem($post));
                session()->setFlashdata('alert_success', trans('item_added', '', 'Riga aggiunta correttamente'));
            } elseif (array_key_exists('item_name', $post) || array_key_exists('item_description', $post)) {
                // Il POST veniva dalla riga di aggiunta (quei campi ci sono
                // sempre, anche vuoti) ma non c'era nulla da salvare: dirlo,
                // invece di rispondere con il messaggio di successo del
                // salvataggio testata, che qui sarebbe una bugia.
                session()->setFlashdata('alert_error', trans('item_empty', '', 'Riga non aggiunta: indica almeno un articolo o una descrizione.'));
            } else {
                session()->setFlashdata('alert_success', trans('invoice_updated', '', 'Fattura aggiornata correttamente'));
            }

            // Attività fatturata: marcata come tale (stato 4), come Ajax CI3.
            if (!empty($post['item_task_id'] ?? null)) {
                $this->setTaskStatus((int) $post['item_task_id'], 4);
            }

            // Il "+" delle righe manda stay=1: aggiungere una riga non e' finire
            // di lavorare al documento, quindi si torna in modifica con il
            // cursore ancora sulle righe. Il pulsante Salva, che invece chiude
            // il lavoro, continua ad andare sulla vista.
            if (!empty($post['stay'])) {
                return redirect()->to('/invoices/edit/' . $invoiceId . '#doc-items');
            }

            return redirect()->to('/invoices/view/' . $invoiceId);
        }

        // Creazione di una nuova fattura (form_create): dati fattura + righe.
        // Le regole vanno caricate esplicitamente: il model non ha
        // $validationRules propri, e validate() senza regole risponde sempre
        // "valido" — anche alle lunghezze dei campi FE (CIG, ordine...) che
        // ora arrivano fino all'insert e lo farebbero fallire.
        //
        // Non basta però: cleanValidationRules() del Model scarta le regole
        // dei campi che non compaiono nel POST, quindi un client_id mai
        // inviato non arrivava mai a "required", l'insert falliva in silenzio
        // e si atterrava su /invoices/view/0. Portando le chiavi obbligatorie
        // a null, le regole vengono eseguite e il form si riapre con il
        // messaggio invece di perdere i dati digitati.
        $post += ['client_id' => null, 'invoice_date_created' => null, 'invoice_group_id' => null];

        $this->invoiceModel->setValidationRules($this->invoiceModel->validationRulesLegacy());

        if (!$this->invoiceModel->validate($post)) {
            session()->setFlashdata('alert_error', implode(' ', $this->invoiceModel->errors()));

            return redirect()->to('/invoices/form_create')->withInput();
        }

        $dbArray   = array_merge(
            $this->invoiceModel->dbArrayFromPost($post),
            $this->einvoiceFieldsFromPost($post)
        );
        $invoiceId = $this->invoiceModel->create($dbArray);

        // Righe: JSON da campo nascosto (JS) o righe postate come array
        $items = [];

        if (!empty($post['items'])) {
            $decoded = json_decode((string) $post['items'], true);
            if (is_array($decoded)) {
                $items = $decoded;
            }
        } elseif (!empty($post['item_name']) || !empty($post['item_description'])) {
            // Anche qui basta la sola descrizione, come nel ramo di
            // aggiornamento e come per i preventivi.
            $items = [ $post ];
        }

        $itemModel = $this->model(ItemModel::class);

        foreach ($items as $item) {
            // Il JS scarta già le righe vuote, ma il filtro va tenuto anche qui:
            // il POST è pubblico, e dbArrayFromItem() non ha una sua guardia —
            // una riga vuota diventerebbe una riga vera a zero nel documento.
            if (trim((string) ($item['item_name'] ?? '')) === ''
                && trim((string) ($item['item_description'] ?? '')) === '') {
                continue;
            }

            // La riga non ha ancora invoice_id al momento del submit del
            // form di creazione: l'id esiste solo ora, dopo l'insert sopra.
            $item['invoice_id'] = $invoiceId;
            $itemModel->saveItem(null, $itemModel->dbArrayFromItem($item));
        }

        // I task fatturati vengono marcati come fatturati (stato 4),
        // come l'Ajax CI3 (update_status(4, item_task_id) a ogni salvataggio)
        foreach ($items as $item) {
            if (!empty($item['item_task_id'] ?? null)) {
                $this->setTaskStatus((int) $item['item_task_id'], 4);
            }
        }

        session()->setFlashdata('alert_success', 'Fattura creata correttamente');

        return redirect()->to('/invoices/view/' . $invoiceId);
    }

    /**
     * Creazione diretta di una fattura senza righe (form rapido).
     */
    public function create()
    {
        $request = $this->request;

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $post = $request->getPost();

        // Come nel ramo di creazione di save(): regole caricate a mano, e
        // chiavi obbligatorie presenti anche quando il POST non le manda.
        // Senza, cleanValidationRules() le scarta e la creazione senza
        // cliente finisce su /invoices/view/0 invece che sul form.
        $post += ['client_id' => null, 'invoice_date_created' => null, 'invoice_group_id' => null];

        $this->invoiceModel->setValidationRules($this->invoiceModel->validationRulesLegacy());

        if (!$this->invoiceModel->validate($post)) {
            session()->setFlashdata('alert_error', implode(' ', $this->invoiceModel->errors()));

            return redirect()->to('/invoices/form_create')->withInput();
        }

        $dbArray   = array_merge(
            $this->invoiceModel->dbArrayFromPost($post),
            $this->einvoiceFieldsFromPost($post)
        );
        $invoiceId = $this->invoiceModel->create($dbArray);

        session()->setFlashdata('alert_success', 'Fattura creata correttamente');

        return redirect()->to('/invoices/view/' . $invoiceId);
    }

    /**
     * Crea/aggiorna la ricorrenza di una fattura.
     */
    public function createRecurring()
    {
        $request = $this->request;

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $post = $request->getPost();

        if (empty($post['invoice_id'])) {
            throw PageNotFoundException::forPageNotFound();
        }

        $invoiceId = (int) $post['invoice_id'];

        // La ricorrenza e' l'unica mutazione di questo file che poi lavora da
        // sola: Cron::recur() genera fatture vere da questa riga. Un invoice_id
        // non verificato qui diventa fatturazione automatica su un documento
        // altrui, che continua a produrre effetti dopo la richiesta.
        if (!$this->invoiceModel->findScoped($invoiceId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Valida frequenza e periodo contro l'elenco delle frequenze
        $recurringModel = $this->model(InvoiceRecurringModel::class);
        $frequencies    = $recurringModel->recurFrequencies();

        if (!in_array(($post['recur_frequency'] ?? ''), array_keys($frequencies), true)) {
            session()->setFlashdata('alert_error', trans('recurring_invoice_frequency_invalid', '', 'Frequenza non valida'));

            return redirect()->to('/invoices/view/' . $invoiceId);
        }

        // insert() e non save(null, ...): la firma CI3 save($id, $data) non
        // esiste in CI4, dove save() riceve solo i dati. Con null come primo
        // argomento non salvava niente e restituiva true, quindi la pagina
        // diceva "salvata" e nessuna ricorrente e' mai stata creata.
        if (!$recurringModel->insert($recurringModel->dbArrayFromPost($post))) {
            session()->setFlashdata('alert_error', implode(' ', $recurringModel->errors())
                ?: trans('recurring_invoice_not_saved', '', 'Fattura ricorrente non salvata'));

            return redirect()->to('/invoices/view/' . $invoiceId);
        }

        session()->setFlashdata('alert_success', trans('recurring_invoice_created', '', 'Fattura ricorrente salvata'));

        return redirect()->to('/invoices/view/' . $invoiceId);
    }

    /**
     * Ritorna la riga richiesta in JSON (per la modifica via JS).
     */
    public function getItem()
    {
        $request = $this->request;

        $itemId = (int) $request->getPost('item_id');

        // La riga si legge per item_id, ma il perimetro sta sul documento
        // padre: senza questo controllo l'endpoint restituiva in JSON nome,
        // descrizione, prezzo e sconto di una riga di un'altra azienda.
        $item = $this->model(ItemModel::class)->find($itemId);

        if (!$item || !$this->invoiceModel->findScoped((int) $item['invoice_id'])) {
            return $this->response->setJSON(['success' => false]);
        }

        // Aggiunge i dati dell'aliquota per il popolamento del form
        $taxRate = $this->model(TaxRateModel::class)->find($item['item_tax_rate_id'] ?? 0);

        if ($taxRate) {
            $item['item_tax_rate_percent'] = $taxRate['tax_rate_percent'];
        }

        return $this->response->setJSON(['success' => true, 'item' => $item]);
    }

    /**
     * Aggiunge un'imposta a livello di fattura e ricalcola.
     */
    public function saveInvoiceTaxRate()
    {
        $request = $this->request;

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $post = $request->getPost();

        $invoiceId = (int) ($post['invoice_id'] ?? 0);

        // Aggiungere un'imposta cambia il totale della fattura: e' una mutazione
        // a tutti gli effetti, ed e' speculare al guard di deleteInvoiceTax().
        if (!$this->invoiceModel->findScoped($invoiceId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $this->model(InvoiceTaxRateModel::class)->saveTaxRate(null, [
            'invoice_id'              => $invoiceId,
            'tax_rate_id'             => $post['tax_rate_id'] ?? null,
            'include_item_tax'        => $post['include_item_tax'] ?? 0,
            'invoice_tax_rate_amount' => 0,
        ]);

        session()->setFlashdata('alert_success', trans('tax_rate_added', '', 'Imposta aggiunta'));

        return redirect()->to('/invoices/view/' . $invoiceId);
    }

    /**
     * Elimina una riga e ricalcola gli importi della fattura.
     */
    public function deleteItem()
    {
        $request = $this->request;

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        // La riga si legge PRIMA di cancellarla, non dopo: deleteItem() cancella
        // per id e ricalcola i totali, quindi cosi' com'era un item_id qualunque
        // svuotava una riga del documento di un'altra azienda, e il controllo sul
        // valore di ritorno serviva solo a scoprirlo a cose fatte.
        $itemModel = $this->model(ItemModel::class);
        $item      = $itemModel->find((int) $request->getPost('item_id'));

        if (!$item || !$this->invoiceModel->findScoped((int) $item['invoice_id'])) {
            throw PageNotFoundException::forPageNotFound();
        }

        $itemModel->deleteItem((int) $item['item_id']);

        // Riga di attività rimossa dalla fattura: il task torna completato
        // (stato 3), come l'Ajax CI3 (update_status(3, item_task_id)).
        if (!empty($item['item_task_id'] ?? null)) {
            $this->setTaskStatus((int) $item['item_task_id'], 3);
        }

        session()->setFlashdata('alert_success', trans('item_deleted', '', 'Riga eliminata'));

        return redirect()->to('/invoices/view/' . (int) $item['invoice_id']);
    }

    /**
     * Riordina le righe di una fattura trascinate dall'utente.
     *
     * Riceve l'elenco ordinato degli item_id e riscrive item_order in sequenza
     * (1-based). Ogni id viene prima verificato: deve appartenere alla fattura
     * indicata, altrimenti viene ignorato — così una riga di un'altra fattura
     * (o di un'altra azienda) non può essere infilata nell'ordinamento.
     * Non tocca gli importi: spostare una riga non ne cambia il totale.
     */
    public function reorderItems()
    {
        $request = $this->request;
        $invoiceId = (int) $request->getPost('invoice_id');

        if ($request->getMethod() !== 'POST' || !$invoiceId) {
            throw PageNotFoundException::forPageNotFound();
        }

        if (!$this->invoiceModel->findScoped($invoiceId)) {
            return $this->response->setJSON(['success' => 0, 'msg' => 'Fattura non trovata']);
        }

        $rawIds  = $request->getPost('item_ids');
        $itemIds = is_array($rawIds) ? $rawIds : (json_decode((string) $rawIds, true) ?? []);

        if (!is_array($itemIds) || $itemIds === []) {
            return $this->response->setJSON(['success' => 1]);
        }

        $itemModel = $this->model(ItemModel::class);
        $order     = 1;

        foreach ($itemIds as $rawId) {
            $id = (int) $rawId;

            if (!$id) {
                continue;
            }

            $item = $itemModel->find($id);

            if (!$item || (int) $item['invoice_id'] !== $invoiceId) {
                continue;
            }

            $itemModel->update($id, ['item_order' => $order]);
            $order++;
        }

        return $this->response->setJSON(['success' => 1]);
    }

    /**
     * Crea una nota di credito dalla fattura indicata.
     */
    public function createCredit()
    {
        $request = $this->request;

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $parentInvoiceId = (int) $request->getPost('invoice_id');
        $parentInvoice   = $this->invoiceModel->getByIdScoped($parentInvoiceId);

        if (!$parentInvoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        $dbArray = [
            'user_id'               => $parentInvoice['user_id'],
            'client_id'             => $parentInvoice['client_id'],
            'invoice_date_created'  => date('Y-m-d'),
            'invoice_date_due'      => $this->invoiceModel->getDateDue(date('Y-m-d')),
            'invoice_group_id'      => $this->invoiceModel->getInvoiceGroupId($parentInvoiceId),
            'invoice_status_id'     => 1,
            'invoice_number'        => null,
            'creditinvoice_parent_id' => $parentInvoiceId,
            'invoice_terms'         => $parentInvoice['invoice_terms'],
            'payment_method'        => $parentInvoice['payment_method'],
            // La nota di credito deve riportare lo stesso sconto documento
            // della fattura di origine: altrimenti InvoiceAmountModel::calculate
            // lo legge come 0 e lo storno non compensa l'originale scontata.
            // Lo sconto a importo va però rovesciato come le righe: il calcolo
            // fa totale − sconto, e su un totale negativo uno sconto positivo
            // allontana da zero (−100 − 10 = −110) invece di avvicinare
            // (−90). La percentuale invece segue da sola il segno del totale.
            'invoice_discount_amount'  => $parentInvoice['invoice_discount_amount'] !== null && $parentInvoice['invoice_discount_amount'] !== ''
                ? -1 * (float) $parentInvoice['invoice_discount_amount']
                : null,
            'invoice_discount_percent' => $parentInvoice['invoice_discount_percent'],
            // TD04: e' il tipo documento a dire allo SdI che si tratta di uno
            // storno. Prima la nota usciva TD01 con quantita' negative, che la
            // XSD rifiuta (Quantita non ammette il segno).
            'invoice_doc_type'         => 'TD04',
            // Lo storno segue il regime della fattura che rettifica: una nota
            // verso la PA in split payment resta in split payment, e i
            // riferimenti CIG/CUP servono all'ente per abbinarla alla
            // fattura originale.
            'invoice_vat_exigibility'  => $parentInvoice['invoice_vat_exigibility'] ?? null,
            'invoice_cig'              => $parentInvoice['invoice_cig'] ?? null,
            'invoice_cup'              => $parentInvoice['invoice_cup'] ?? null,
            'invoice_admin_reference'  => $parentInvoice['invoice_admin_reference'] ?? null,
            'bank_account_id'          => $parentInvoice['bank_account_id'] ?? null,
            // Ritenuta e cassa si stornano insieme alla prestazione: una nota
            // di credito su una parcella senza la sua ritenuta lascerebbe al
            // cliente un credito d'imposta che non ha.
            'invoice_withholding_type'   => $parentInvoice['invoice_withholding_type'] ?? null,
            'invoice_withholding_rate'   => $parentInvoice['invoice_withholding_rate'] ?? null,
            'invoice_withholding_reason' => $parentInvoice['invoice_withholding_reason'] ?? null,
            'invoice_fund_type'          => $parentInvoice['invoice_fund_type'] ?? null,
            'invoice_fund_rate'          => $parentInvoice['invoice_fund_rate'] ?? null,
        ];

        $creditInvoiceId = $this->invoiceModel->create($dbArray);

        // Copia righe (quantita' negative) e imposte. Internamente la nota
        // resta negativa: e' cosi' che dashboard, report e stime fiscali la
        // sottraggono senza doverla riconoscere. Il tracciato invece esce con
        // importi positivi (Invoices::generateXml rovescia le righe dei TD04).
        $this->invoiceModel->copyCreditInvoice($parentInvoiceId, $creditInvoiceId);

        // invoice_sign = -1 come in CI3 (Ajax::create_credit): e' il flag che
        // le viste usano per marcare la nota di credito nell'elenco. Sta in
        // ip_invoice_amounts e il model degli importi non lo scrive mai,
        // quindi si aggiorna qui con il builder.
        db_connect()->table('ip_invoice_amounts')
            ->where('invoice_id', $creditInvoiceId)
            ->update(['invoice_sign' => '-1']);

        session()->setFlashdata('alert_success', trans('credit_invoice_created', '', 'Nota di credito creata'));

        return redirect()->to('/invoices/view/' . $creditInvoiceId);
    }

    /**
     * Cambia il cliente di una fattura e ricalcola gli importi.
     */
    public function changeClient()
    {
        $request = $this->request;

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $invoiceId  = (int) $request->getPost('invoice_id');
        $clientId   = (int) $request->getPost('client_id');

        // Nessuno dei due id veniva letto prima della update: si poteva cambiare
        // l'intestatario di una fattura di un'altra azienda e, in ogni caso,
        // intestarla a un cliente che l'azienda del documento non possiede --
        // il che rompe anche la fattura elettronica, che i dati del cessionario
        // li prende proprio da li'.
        if (!$this->invoiceModel->findScoped($invoiceId)
            || !$this->model(ClientModel::class)->findScoped($clientId)) {
            return $this->response->setJSON(['success' => false]);
        }

        $this->invoiceModel->update($invoiceId, ['client_id' => $clientId]);

        $this->model(InvoiceAmountModel::class)->calculate($invoiceId);

        return $this->response->setJSON(['success' => true]);
    }

    /**
     * Copia una fattura (nuova bozza con righe e imposte della sorgente).
     */
    public function copyInvoice()
    {
        $request = $this->request;

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        $sourceInvoiceId = (int) $request->getPost('invoice_id');
        $sourceInvoice   = $this->invoiceModel->getByIdScoped($sourceInvoiceId);

        if (!$sourceInvoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        $dbArray = [
            'user_id'              => $sourceInvoice['user_id'],
            'client_id'            => $sourceInvoice['client_id'],
            'invoice_date_created' => date('Y-m-d'),
            'invoice_date_due'     => $this->invoiceModel->getDateDue(date('Y-m-d')),
            'invoice_group_id'     => $this->invoiceModel->getInvoiceGroupId($sourceInvoiceId),
            'invoice_status_id'    => 1,
            'invoice_number'       => null,
            'invoice_terms'        => $sourceInvoice['invoice_terms'],
            'payment_method'       => $sourceInvoice['payment_method'],
            'invoice_discount_amount'  => $sourceInvoice['invoice_discount_amount'],
            'invoice_discount_percent' => $sourceInvoice['invoice_discount_percent'],
        ];

        $targetInvoiceId = $this->invoiceModel->create($dbArray);

        $this->invoiceModel->copyInvoice($sourceInvoiceId, $targetInvoiceId);

        session()->setFlashdata('alert_success', trans('invoice_copied', '', 'Fattura copiata'));

        return redirect()->to('/invoices/view/' . $targetInvoiceId);
    }

    /**
     * Aggiorna i dati generali della fattura (date, stato, numero,
     * sconti, termini, metodo di pagamento) da un POST parziale.
     */
    /**
     * Allinea le righe della fattura a quelle arrivate dalla schermata di
     * modifica: aggiorna quelle con item_id, inserisce quelle senza, elimina
     * quelle che l'utente ha tolto.
     *
     * Le eliminazioni sono *esplicite* (elenco di id in $deletedRaw) e non
     * dedotte dall'assenza dal JSON. La differenza conta: se il JavaScript non
     * girasse, o il campo arrivasse vuoto per un qualsiasi motivo, dedurre le
     * eliminazioni dall'assenza svuoterebbe la fattura. Con l'elenco esplicito
     * il caso peggiore e' "non ho cancellato nulla", che si corregge; l'altro
     * no.
     *
     * @param mixed  $rawItems  JSON delle righe (dal campo nascosto "items")
     * @param string $deletedRaw id separati da virgola delle righe rimosse
     */
    private function syncItems(int $invoiceId, $rawItems, string $deletedRaw): void
    {
        $itemModel = $this->model(ItemModel::class);

        $items = is_array($rawItems) ? $rawItems : json_decode((string) $rawItems, true);

        if (!is_array($items)) {
            // JSON illeggibile: meglio non toccare nulla che riscrivere le
            // righe a partire da un input che non si e' capito.
            session()->setFlashdata('alert_error', trans('items_not_saved', '', 'Righe non salvate: dati non leggibili.'));

            return;
        }

        // --- Righe rimosse -------------------------------------------------
        foreach (explode(',', $deletedRaw) as $rawId) {
            $deletedId = (int) trim($rawId);

            if ($deletedId <= 0) {
                continue;
            }

            $item = $itemModel->find($deletedId);

            // Stesso perimetro del ramo a riga singola: deleteItem() cancella
            // per id, quindi senza questo controllo un id estraneo svuoterebbe
            // una riga del documento di un'altra azienda.
            if (!$item || (int) $item['invoice_id'] !== $invoiceId) {
                continue;
            }

            $itemModel->deleteItem($deletedId);

            // Riga di attività rimossa: il task torna completato (stato 3),
            // come fa deleteItem() del controller.
            if (!empty($item['item_task_id'])) {
                $this->setTaskStatus((int) $item['item_task_id'], 3);
            }
        }

        // --- Righe aggiornate e nuove --------------------------------------
        $order = 1;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            // Bastano nome *o* descrizione: una riga di sola descrizione è
            // legittima. Le righe del tutto vuote non si salvano — la tabella
            // parte sempre con una riga pronta, che spesso resta intatta.
            if (trim((string) ($item['item_name'] ?? '')) === ''
                && trim((string) ($item['item_description'] ?? '')) === '') {
                continue;
            }

            $itemId = !empty($item['item_id']) ? (int) $item['item_id'] : null;

            if ($itemId !== null) {
                $existing = $itemModel->find($itemId);

                // dbArrayFromItem() riscrive invoice_id con quello passato qui:
                // una riga di un'altra fattura non verrebbe rifiutata, verrebbe
                // spostata in questa portando via il totale al documento di
                // provenienza. Si salta la riga sospetta invece di interrompere,
                // cosi' il salvataggio delle altre righe legittime non salta.
                if (!$existing || (int) $existing['invoice_id'] !== $invoiceId) {
                    continue;
                }
            }

            $item['invoice_id'] = $invoiceId;
            $item['item_order'] = $item['item_order'] ?? $order;

            // Senza ricalcolo della fattura: lo fa una volta sola il
            // calculate() dopo il ciclo.
            $itemModel->saveItem($itemId, $itemModel->dbArrayFromItem($item), false);

            // Attività fatturata: marcata come tale (stato 4).
            if (!empty($item['item_task_id'])) {
                $this->setTaskStatus((int) $item['item_task_id'], 4);
            }

            $order++;
        }

        // Unico ricalcolo della fattura per tutto il batch (le righe sopra
        // sono salvate senza), e anche quando tutte le righe sono state
        // saltate o rimosse. La cache del report va svuotata qui, perché
        // saveItem() senza ricalcolo non lo fa.
        $this->model(InvoiceAmountModel::class)->calculate($invoiceId);
        invalidate_finance_cache();

        session()->setFlashdata('alert_success', trans('invoice_updated', '', 'Fattura aggiornata correttamente'));
    }

    /**
     * Campi della fattura elettronica presenti nel POST, pronti da scrivere.
     *
     * Solo le chiavi effettivamente inviate: un POST parziale (riga singola,
     * cambio stato) non deve azzerare quello che non tocca.
     */
    private function setTaskStatus(int $taskId, int $statusId): void
    {
        // L'id dell'attivita' arriva dal POST come tutti gli altri, e come gli
        // altri va ricondotto all'azienda attiva: senza, un id estraneo
        // segnava "fatturata" (o riapriva) l'attivita' di un'altra azienda.
        $taskModel = $this->model(TaskModel::class);

        if ($taskId > 0 && $taskModel->findScoped($taskId)) {
            $taskModel->updateStatus($taskId, $statusId);
        }
    }

    private function einvoiceFieldsFromPost(array $post): array
    {
        $fields = [];

        foreach (self::EINVOICE_FIELDS as $field) {
            if (array_key_exists($field, $post)) {
                $fields[$field] = $post[$field] === '' ? null : $post[$field];
            }
        }

        // Flag Carta del Docente: arriva dal form con la coppia
        // hidden 0 + checkbox 1, quindi il valore e' sempre esplicito.
        if (array_key_exists('invoice_carta_docente', $post)) {
            $fields['invoice_carta_docente'] = !empty($post['invoice_carta_docente']);
        }

        return $fields;
    }

    private function updateInvoiceFromPost(int $invoiceId, array $post): void
    {
        $update = [];

        // La conversione '' => null qui sotto e' cio' che rende salvabili i
        // campi facoltativi della fattura elettronica: date e decimali lasciati
        // vuoti non devono diventare 0000-00-00 o 0,00, che significherebbero
        // "indicato e pari a zero" invece di "non indicato".
        foreach (['invoice_status_id', 'invoice_number', 'payment_method', 'invoice_terms',
                  'invoice_password', 'invoice_discount_amount', 'invoice_discount_percent'] as $field) {
            if (array_key_exists($field, $post)) {
                $update[$field] = $post[$field] === '' ? null : $post[$field];
            }
        }

        // Lo sconto di documento ora si scrive dalla scheda, nel formato
        // locale ("10,50"): PostgreSQL rifiuta la virgola su una colonna
        // numeric, e senza normalizzazione la validazione scartava il campo
        // in silenzio.
        foreach (['invoice_discount_amount', 'invoice_discount_percent'] as $field) {
            if (isset($update[$field]) && $update[$field] !== null) {
                $update[$field] = standardize_amount($update[$field]);
            }
        }

        // Sconto di documento oltre l'imponibile: porterebbe il totale sotto
        // zero (vedi InvoiceMath::discountExceedsBase()) e il documento non
        // sarebbe né incassabile né valido per l'XML. Si scarta il solo
        // sconto, non il resto della scheda: chi salva sta scrivendo anche
        // altro e perdere tutto il form per un importo sbagliato sarebbe
        // peggio del campo da correggere.
        if (array_key_exists('invoice_discount_amount', $update)
            || array_key_exists('invoice_discount_percent', $update)) {
            $current = $this->invoiceModel->find($invoiceId);
            // array_key_exists e non ??: un campo svuotato arriva qui come
            // null, e con ?? si tornava al valore salvato. Passando da importo
            // fisso a percentuale, il controllo vedeva vecchio importo + nuova
            // percentuale e scartava uno sconto valido.
            $amount  = (float) (array_key_exists('invoice_discount_amount', $update)
                ? $update['invoice_discount_amount']
                : ($current['invoice_discount_amount'] ?? 0));
            $percent = (float) (array_key_exists('invoice_discount_percent', $update)
                ? $update['invoice_discount_percent']
                : ($current['invoice_discount_percent'] ?? 0));
            $lines   = InvoiceMath::linesFromItems($this->model(ItemModel::class)->getByInvoice($invoiceId));

            if (InvoiceMath::discountExceedsBase($lines, $amount, $percent)) {
                unset($update['invoice_discount_amount'], $update['invoice_discount_percent']);
                session()->setFlashdata(
                    'alert_error',
                    trans(
                        'discount_exceeds_taxable',
                        '',
                        'Sconto non applicato: supera l\'imponibile delle righe e porterebbe il totale sotto zero.'
                    )
                );
            }
        }

        $update += $this->einvoiceFieldsFromPost($post);

        if (array_key_exists('invoice_date_created', $post) && $post['invoice_date_created'] !== '') {
            $update['invoice_date_created'] = date_to_mysql($post['invoice_date_created']);
            $update['invoice_date_due']     = $this->invoiceModel->getDateDue($update['invoice_date_created']);
        }

        if (array_key_exists('invoice_date_due', $post) && $post['invoice_date_due'] !== '') {
            $update['invoice_date_due'] = date_to_mysql($post['invoice_date_due']);
        }

        // Numero riscritto a mano: smette di essere "provvisorio", altrimenti
        // l'uscita dalla bozza lo rimpiazzerebbe buttando via la scelta fatta.
        if (array_key_exists('invoice_number', $update)) {
            $current = $this->invoiceModel->find($invoiceId);

            if ($current && (string) $update['invoice_number'] !== (string) $current['invoice_number']) {
                $update['invoice_number_is_draft'] = false;
            }
        }

        if (!empty($update)) {
            // I campi della fattura elettronica arrivano dal POST senza
            // passare da dbArrayFromPost: se uno supera la lunghezza della
            // colonna, la UPDATE crasha. Si valida e si scartano solo i campi
            // non validi, così il resto dell'aggiornamento va a buon fine.
            // setValidationRules() e non l'assegnazione diretta: in CI4
            // $validationRules è protected e scriverla da fuori è un Error
            // fatale, che bloccava ogni salvataggio della fattura.
            $this->invoiceModel->setValidationRules($this->invoiceModel->validationRulesLegacy());

            if (!$this->invoiceModel->validate($update)) {
                foreach (array_keys($this->invoiceModel->errors()) as $badField) {
                    unset($update[$badField]);
                }
            }

            if (!empty($update)) {
                $this->invoiceModel->update($invoiceId, $update);
            }
        }

        // Uscita dalla bozza: il numero provvisorio (serie bozze) lascia il
        // posto a quello definitivo. Si fa qui e non solo alla generazione del
        // PDF perché lo stato si cambia anche a mano da questa schermata.
        if (isset($update['invoice_status_id']) && (int) $update['invoice_status_id'] !== 1) {
            $this->invoiceModel->finalizeNumber($invoiceId);
        }

        // Ricalcolo anche quando lo sconto viene azzerato o svuotato: con la
        // vecchia condizione (!empty) cancellare uno sconto lasciava i totali
        // derivati (invoice_total/invoice_balance) non ricalcolati.
        // Esigibilita' e ritenuta non cambiano il totale ma cambiano il saldo
        // (split payment e ritenuta si sottraggono dal dovuto): vanno
        // ricalcolate anche loro, o una fattura appena passata in split
        // payment resterebbe aperta per l'IVA fino al prossimo pagamento.
        if (array_key_exists('invoice_discount_amount', $update)
            || array_key_exists('invoice_discount_percent', $update)
            || array_key_exists('invoice_vat_exigibility', $update)
            || array_key_exists('invoice_withholding_type', $update)
            || array_key_exists('invoice_withholding_rate', $update)
            // Il contributo cassa entra nell'imponibile e nel totale
            // (InvoiceMath::documentSummary): cambiarlo cambia tutto.
            || array_key_exists('invoice_fund_type', $update)
            || array_key_exists('invoice_fund_rate', $update)) {
            $this->model(InvoiceAmountModel::class)->calculate($invoiceId);
        }
    }

}