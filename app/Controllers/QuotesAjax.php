<?php

namespace App\Controllers;

use App\Models\QuoteModel;
use App\Models\QuoteItemModel;
use App\Models\QuoteAmountModel;
use App\Models\QuoteTaxRateModel;
use App\Models\QuoteCustomModel;
use App\Models\ClientModel;
use App\Models\InvoiceGroupModel;

/**
 * QuotesAjax controller
 *
 * Porting CI4 del controller Ajax del modulo quotes (CI3).
 * Stessa logica applicativa: save (righe + preventivo + custom),
 * create, get_item, save_quote_tax_rate, delete_item, quote_to_invoice,
 * change_client, copy_quote. Le risposte JSON sono per richieste AJAX;
 * con form tradizionali si risponde con redirect e flashdata.
 */
class QuotesAjax extends BaseController
{
    protected QuoteModel $quoteModel;

    public function __construct()
    {
        $this->quoteModel = new QuoteModel();
    }

    /**
     * Salva righe, dati del preventivo e campi custom
     * (equivalente di save() CI3).
     */
    public function save()
    {
        $post    = $this->request->getPost();
        $quoteId = (int) ($post['quote_id'] ?? 0);

        if (!$quoteId) {
            return $this->jsonResponse(['success' => 0, 'validation_errors' => 'Preventivo non valido']);
        }

        // findScoped(): find() non applica il perimetro, e con il quote_id preso
        // dal POST si riscrivevano numero, stato, sconti e campi custom del
        // preventivo di un'altra azienda.
        $quote = $this->quoteModel->findScoped($quoteId);
        if (!$quote) {
            return $this->jsonResponse(['success' => 0, 'validation_errors' => 'Preventivo non trovato']);
        }

        // --- Righe rimosse nella schermata di modifica ---
        // Esplicite (elenco di id) e non dedotte dall'assenza dal JSON: se il
        // JavaScript non girasse, dedurle dall'assenza svuoterebbe il
        // preventivo. Vanno prima degli inserimenti: cosi' l'item_order
        // riassegnato in saveItems() non deve fare i conti con i buchi.
        if (!empty($post['deleted_items'])) {
            $this->deleteRemovedItems($quoteId, (string) $post['deleted_items']);
        }

        // --- Salva le righe ---
        if (array_key_exists('items', $post)) {
            // Salvataggio unico dalla modifica (o richiesta AJAX): tutte le
            // righe in JSON insieme alla testata. "[]" e' significativo — vuol
            // dire "non e' rimasta nessuna riga" — quindi si guarda la presenza
            // della chiave, non che il valore sia non vuoto.
            $items = json_decode((string) $post['items'], true);
            if (is_array($items)) {
                $this->saveItems($quoteId, $items);
            }
        } elseif (array_key_exists('item_name', $post) || array_key_exists('item_description', $post)) {
            // Form riga singola: salva solo questa riga
            $this->saveItems($quoteId, [$post]);
        }

        // --- Salva i dati del preventivo ---
        if (array_key_exists('quote_date_created', $post) || array_key_exists('quote_number', $post)) {
            $quoteNumber = (string) ($post['quote_number'] ?? '');
            $statusId    = (int) ($post['quote_status_id'] ?? $quote['quote_status_id']);

            // Genera il numero se necessario
            if ($quoteNumber === '' && $statusId !== 1) {
                $quoteNumber = $this->quoteModel->generateQuoteNumber($this->quoteModel->getInvoiceGroupId($quoteId));
            }

            $discountAmount  = $post['quote_discount_amount'] ?? $quote['quote_discount_amount'];
            $discountPercent = $post['quote_discount_percent'] ?? $quote['quote_discount_percent'];

            $dbArray = [
                'quote_number'          => $quoteNumber,
                'quote_status_id'       => $statusId,
                'quote_password'        => $post['quote_password'] ?? $quote['quote_password'],
                'notes'                 => $post['notes'] ?? $quote['notes'],
                'quote_discount_amount' => standardize_amount($discountAmount === '' ? 0 : $discountAmount),
                'quote_discount_percent' => standardize_amount($discountPercent === '' ? 0 : $discountPercent),
            ];

            if (!empty($post['quote_date_created'])) {
                $dbArray['quote_date_created'] = date_to_mysql($post['quote_date_created']);
            }
            if (!empty($post['quote_date_expires'])) {
                $dbArray['quote_date_expires'] = date_to_mysql($post['quote_date_expires']);
            }

            // Numero riscritto a mano: smette di essere "provvisorio", così
            // l'uscita dalla bozza non lo rimpiazza.
            if ($quoteNumber !== (string) $quote['quote_number']) {
                $dbArray['quote_number_is_draft'] = false;
            }

            $this->quoteModel->update($quoteId, $dbArray);

            // Uscita dalla bozza: il provvisorio lascia il posto al definitivo.
            if ($statusId !== 1) {
                $this->quoteModel->finalizeNumber($quoteId);
            }
        }

        // Ricalcola per sconti e modifiche
        (new QuoteAmountModel())->calculate($quoteId);

        // --- Salva i campi custom ---
        if (!empty($post['custom'])) {
            $customValues = $this->parseCustomPost($post['custom']);
            if ($customValues) {
                (new QuoteCustomModel())->saveCustom($quoteId, $customValues);
            }
        }

        // Senza quoteId jsonResponse() rimandava a /quotes, cioe' all'ELENCO
        // dei preventivi: si aggiungeva una riga e ci si ritrovava fuori dal
        // documento. Con stay=1 (il "+" delle righe) si resta in modifica.
        if (!empty($post['stay'])) {
            return $this->jsonResponse(['success' => 1], $quoteId, '/quotes/edit/' . $quoteId . '#doc-items');
        }

        return $this->jsonResponse(['success' => 1], $quoteId);
    }

    /**
     * Elimina le righe che l'utente ha tolto nella schermata di modifica.
     *
     * @param string $deletedRaw id separati da virgola
     */
    private function deleteRemovedItems(int $quoteId, string $deletedRaw): void
    {
        $itemModel = new QuoteItemModel();

        foreach (explode(',', $deletedRaw) as $rawId) {
            $itemId = (int) trim($rawId);

            if ($itemId <= 0) {
                continue;
            }

            $item = $itemModel->find($itemId);

            // Stesso perimetro di deleteItem(): il model cancella per item_id,
            // quindi senza questo controllo un id estraneo toglierebbe una riga
            // dal preventivo di un'altra azienda.
            if (!$item || (int) $item['quote_id'] !== $quoteId) {
                continue;
            }

            $itemModel->deleteItem($itemId);
        }
    }

    /**
     * Salva le righe di preventivo (equivalente del loop items CI3).
     */
    private function saveItems(int $quoteId, array $items): void
    {
        $itemModel = new QuoteItemModel();
        $itemOrder = 1;

        foreach ($items as $item) {
            $itemName        = (string) ($item['item_name'] ?? '');
            $itemDescription = (string) ($item['item_description'] ?? '');

            // Salta le righe vuote (custom IT: si tiene anche la sola descrizione)
            if ($itemName === '' && $itemDescription === '') {
                continue;
            }

            $quantity = !empty($item['item_quantity']) ? standardize_amount($item['item_quantity']) : 0;
            $price    = !empty($item['item_price']) ? standardize_amount($item['item_price']) : 0;

            $dbArray = [
                'quote_id'             => $quoteId,
                // item_tax_rate_id è NOT NULL (DEFAULT 0) sullo schema: a differenza di
                // item_product_id/item_product_unit_id, null qui rompe l'insert quando
                // l'utente seleziona "Nessuna" aliquota.
                'item_tax_rate_id'     => !empty($item['item_tax_rate_id']) ? $item['item_tax_rate_id'] : 0,
                'item_product_id'      => !empty($item['item_product_id']) ? $item['item_product_id'] : null,
                // Codice della riga (MEPA o articolo concordato): libero, non
                // sostituisce il codice del prodotto ma lo affianca.
                'item_code'            => trim((string) ($item['item_code'] ?? '')),
                'item_name'            => $itemName,
                'item_description'     => $itemDescription,
                'item_quantity'        => $quantity,
                'item_price'           => $price,
                'item_product_unit_id' => !empty($item['item_product_unit_id']) ? $item['item_product_unit_id'] : null,
                'item_discount_amount' => !empty($item['item_discount_amount'])
                    ? standardize_amount($item['item_discount_amount'])
                    : null,
                // Stesso trattamento dello sconto a importo: se presente lo
                // normalizza, altrimenti resta null e il calcolo lo legge 0.
                'item_discount_percent' => !empty($item['item_discount_percent'])
                    ? standardize_amount($item['item_discount_percent'])
                    : null,
            ];

            $dbArray['item_product_unit'] = $itemModel->getProductUnitName(
                (int) ($dbArray['item_product_unit_id'] ?? 0),
                $quantity
            );

            if (!empty($item['item_order'])) {
                $dbArray['item_order'] = (int) $item['item_order'];
            } else {
                $dbArray['item_order'] = $itemOrder;
            }

            $itemId = !empty($item['item_id']) ? (int) $item['item_id'] : null;

            // Come per le righe fattura: $dbArray riscrive quote_id, quindi una
            // riga di un altro preventivo non verrebbe scartata ma trasferita in
            // questo. Si salta la riga invece di interrompere, cosi' il
            // salvataggio delle altre righe legittime del batch non salta per
            // una sola sospetta.
            if ($itemId) {
                $existingItem = $itemModel->find($itemId);

                if (!$existingItem || (int) $existingItem['quote_id'] !== $quoteId) {
                    continue;
                }
            }

            $itemModel->saveItem($itemId, $dbArray);

            $itemOrder++;
        }
    }

    /**
     * Converte il POST custom (array di {name, value} da serializeArray,
     * oppure custom[fieldId] gia' composto) in [fieldId => value].
     */
    private function parseCustomPost(array $custom): array
    {
        $values = [];

        foreach ($custom as $key => $value) {
            if (is_array($value) && isset($value['name'])) {
                // Formato serializeArray: [ ['name' => 'custom[5]', 'value' => 'x'], ... ]
                $name = $value['name'];
                if (preg_match("/^(.*)\[\]$/i", $name, $matches)) {
                    $values[$matches[1]][] = $value['value'];
                } else {
                    $values[$name] = $value['value'];
                }
            } else {
                $values[$key] = $value;
            }
        }

        $dbArray = [];
        foreach ($values as $key => $value) {
            preg_match("/^custom\[(.*?)\](?:\[\]|)$/", $key, $matches);
            if ($matches) {
                $dbArray[$matches[1]] = $value;
            }
        }

        return $dbArray;
    }

    /**
     * Crea un preventivo: riga ip_quote_amounts + tassa di default
     * (equivalente di create() CI3).
     */
    public function create()
    {
        $post = $this->request->getPost();

        if ($this->quoteModel->validate($post)) {
            $quoteId = $this->quoteModel->create($this->quoteModel->dbArrayFromPost($post));

            // Righe dal form di creazione: "items" in JSON (tabella righe della
            // schermata nuova) oppure i campi item_* di un singolo form.
            $items = [];

            if (!empty($post['items'])) {
                $decoded = json_decode((string) $post['items'], true);

                if (is_array($decoded)) {
                    $items = $decoded;
                }
            } elseif (!empty($post['item_name'] ?? null) || !empty($post['item_description'] ?? null)) {
                $items = [$post];
            }

            if ($items) {
                $this->saveItems($quoteId, $items);
            }

            (new QuoteAmountModel())->calculate($quoteId);

            session()->setFlashdata('alert_success', 'Preventivo creato correttamente');

            return redirect()->to('/quotes/view/' . $quoteId);
        }

        session()->setFlashdata('alert_error', implode(' ', $this->quoteModel->errors()));

        // withInput() come per le fatture: tornando al form le righe già
        // digitate vengono ripristinate invece di andare perse.
        return redirect()->to('/quotes/form_create')->withInput();
    }

    /**
     * Riga di preventivo in JSON (equivalente di get_item() CI3).
     */
    public function getItem()
    {
        $itemId = (int) $this->request->getPost('item_id');

        // Come per le righe fattura: il perimetro sta sul preventivo padre,
        // altrimenti l'endpoint restituisce in JSON la riga di chiunque.
        $item = (new QuoteItemModel())->find($itemId);

        if (!$item || !$this->quoteModel->findScoped((int) $item['quote_id'])) {
            return $this->response->setJSON([]);
        }

        return $this->response->setJSON($item);
    }

    /**
     * Salva una tassa preventivo e ricalcola gli importi
     * (equivalente di save_quote_tax_rate() CI3).
     */
    public function saveQuoteTaxRate()
    {
        $post    = $this->request->getPost();
        $quoteId = (int) ($post['quote_id'] ?? 0);

        // Il preventivo va cercato dentro il perimetro prima di aggiungergli
        // un'imposta: l'aggiunta ne cambia il totale.
        if (!$quoteId || empty($post['tax_rate_id']) || !$this->quoteModel->findScoped($quoteId)) {
            $response = ['success' => 0, 'validation_errors' => ['quote_id' => 'Preventivo o aliquota non validi']];
        } else {
            (new QuoteTaxRateModel())->saveTaxRate([
                'quote_id'              => $quoteId,
                'tax_rate_id'           => (int) $post['tax_rate_id'],
                'include_item_tax'      => (int) ($post['include_item_tax'] ?? 0),
                'quote_tax_rate_amount' => 0,
            ]);

            $response = ['success' => 1];
        }

        return $this->jsonResponse($response, $quoteId);
    }

    /**
     * Elimina una riga e ricalcola gli importi (equivalente di delete_item() CI3).
     */
    public function deleteItem(?int $quoteId = null)
    {
        $itemId = (int) $this->request->getPost('item_id');
        if ($quoteId === null) {
            $quoteId = (int) $this->request->getPost('quote_id');
        }

        $success = 0;

        if ($quoteId && $itemId && $this->quoteModel->findScoped($quoteId)) {
            // find() sul preventivo non bastava per due motivi: non applicava il
            // perimetro azienda, e non diceva nulla sulla riga -- deleteItem()
            // cancella per item_id, quindi il quote_id del POST poteva riferirsi
            // a un preventivo mentre la riga cancellata stava in un altro.
            $itemModel = new QuoteItemModel();
            $item      = $itemModel->find($itemId);

            $success = ($item && (int) $item['quote_id'] === $quoteId && $itemModel->deleteItem($itemId)) ? 1 : 0;
        }

        return $this->jsonResponse(['success' => $success], $quoteId ?: null);
    }

    /**
     * Riordina le righe di un preventivo trascinate dall'utente.
     *
     * Speculare a InvoicesAjax::reorderItems(): riscrive item_order in sequenza
     * (1-based) sull'elenco ordinato di item_id ricevuto, ignorando gli id che
     * non appartengono al preventivo (scoping multi-azienda). Non ricalcola gli
     * importi, che non cambiano spostando una riga.
     */
    public function reorderItems()
    {
        $quoteId = (int) $this->request->getPost('quote_id');

        if ($this->request->getMethod() !== 'POST' || !$quoteId) {
            // Nel namespace App\Controllers questa classe non esiste: senza la FQN
            // il ramo produceva un fatal error invece della 404 che intendeva.
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        if (!$this->quoteModel->findScoped($quoteId)) {
            return $this->jsonResponse(['success' => 0, 'msg' => 'Preventivo non trovato']);
        }

        $rawIds  = $this->request->getPost('item_ids');
        $itemIds = is_array($rawIds) ? $rawIds : (json_decode((string) $rawIds, true) ?? []);

        if (!is_array($itemIds) || $itemIds === []) {
            return $this->jsonResponse(['success' => 1]);
        }

        $itemModel = new QuoteItemModel();
        $order     = 1;

        foreach ($itemIds as $rawId) {
            $id = (int) $rawId;

            if (!$id) {
                continue;
            }

            $item = $itemModel->find($id);

            if (!$item || (int) $item['quote_id'] !== $quoteId) {
                continue;
            }

            $itemModel->update($id, ['item_order' => $order]);
            $order++;
        }

        return $this->jsonResponse(['success' => 1]);
    }

    /**
     * Converte il preventivo in fattura (equivalente di quote_to_invoice() CI3).
     * Crea la fattura inline (il modulo invoices non e' ancora portato):
     * ip_invoices con invoice_status_id 1, copia sconti/righe/tasse.
     */
    public function quoteToInvoice(?int $quoteId = null)
    {
        if ($quoteId === null) {
            $quoteId = (int) $this->request->getPost('quote_id');
        }

        // find() fuori perimetro: si convertiva in fattura il preventivo di
        // un'altra azienda, gli si scriveva pure invoice_id addosso in fondo al
        // metodo, e la fattura nasceva nella company del preventivo sorgente,
        // cioe' fuori da quella su cui si sta operando.
        $quote = $this->quoteModel->findScoped($quoteId);

        if (!$quote) {
            session()->setFlashdata('alert_error', 'Preventivo non trovato');

            return redirect()->to('/quotes');
        }

        $db = \Config\Database::connect();

        // Crea la fattura (bozza)
        // company_setting() considera 0 un valore valido, quindi il default a 1
        // non scattava mai su un'azienda con default_invoice_group = 0 (il
        // valore iniziale) e la fattura convertita nasceva sulla serie 0, che
        // non esiste e non sa generare un numero.
        //
        // Il gruppo preferito NON e' quello del preventivo: quello e' la serie
        // dei preventivi, e passarlo qui faceva nascere la fattura con un
        // numero da preventivo (P-0136) sulla numerazione sbagliata. Come in
        // CI3 vale la serie scelta nel form, se inviata, altrimenti il gruppo
        // fatture predefinito dell'azienda.
        $invoiceGroupId = (new InvoiceGroupModel())
            ->defaultGroupId((int) $this->request->getPost('invoice_group_id'), 'default_invoice_group');
        $statusId       = 1;

        // Data della fattura: oggi, non quella del preventivo. Il porting
        // copiava quote_date_created, quindi un preventivo di marzo convertito
        // a novembre produceva una fattura retrodatata a marzo, fuori ordine
        // rispetto alla numerazione della serie e con una data di emissione
        // falsa sull'XML per lo SdI. CI3 apriva una finestra con la data
        // (oggi di default); qui si usa direttamente oggi, modificabile poi
        // in bozza come ogni fattura. La scadenza segue la stessa regola della
        // creazione manuale (InvoiceModel::create): giorni di pagamento del
        // cliente, altrimenti invoices_due_after — non la data in cui il
        // preventivo smetteva di valere, che con il pagamento non c'entra.
        $clientPrefs = \App\Models\ClientModel::billingPreferencesFor($quote['client_id']);
        $invoiceDate = date('Y-m-d');
        $invoiceDue  = (new \App\Models\InvoiceModel())->getDateDue($invoiceDate, $clientPrefs['client_payment_days']);

        // I nomi delle colonne seguono ip_invoices: invoice_date_due (non
        // invoice_due_date) e invoice_terms (non notes); invoice_time_created
        // non esiste proprio. Con i nomi sbagliati l'INSERT falliva e la
        // conversione preventivo → fattura andava in errore 500.
        $dbArray = [
            'user_id'                => $quote['user_id'],
            'client_id'              => $quote['client_id'],
            'invoice_group_id'       => $invoiceGroupId,
            'invoice_status_id'      => $statusId,
            'invoice_number'         => '',
            'invoice_date_created'   => $invoiceDate,
            'invoice_date_modified'  => date('Y-m-d H:i:s'),
            'invoice_date_due'       => $invoiceDue,
            'invoice_password'       => $quote['quote_password'],
            'invoice_terms'          => $quote['notes'] ?? '',
            'invoice_url_key'        => generate_key(32),
            'invoice_discount_amount' => $quote['quote_discount_amount'],
            'invoice_discount_percent' => $quote['quote_discount_percent'],
            // L'insert e' grezzo e non passa da InvoiceModel::saveRecord(),
            // che e' il punto in cui l'azienda verrebbe assegnata da sola:
            // senza questa riga la fattura convertita nasceva senza azienda e
            // compariva nel fatturato di tutte.
            'company_id'             => $quote['company_id'] ?? active_company_id(),
            // Stessa regola della creazione manuale (InvoiceModel::create()):
            // il metodo di pagamento arriva dalle preferenze del cliente.
            // Senza questa riga la conversione era l'unico percorso che lo
            // ignorava, e due fatture allo stesso cliente nascevano diverse
            // secondo da dove si partiva.
            'payment_method'         => $clientPrefs['client_default_payment_method_id'] ?? 0,
        ];

        if ((int) get_setting('generate_invoice_number_for_draft') === 1) {
            $dbArray['invoice_number'] = (new InvoiceGroupModel())->generateInvoiceNumber($invoiceGroupId);
        }

        // Transazione: la sequenza fattura + importi + righe + tasse e' fatta
        // di insert grezzi separati. Senza transStart/transComplete, un
        // fallimento a meta' (es. un insert riga in errore) lasciava una
        // fattura orfana con solo alcune righe -- e con generate_invoice_number
        // attivo aveva gia' bruciato un numero definitivo.
        $db->transStart();

        $db->table('ip_invoices')->insert($dbArray);
        $invoiceId = (int) $db->insertID();

        // Riga importi fattura
        $db->table('ip_invoice_amounts')->insert(['invoice_id' => $invoiceId]);

        // Copia le righe
        foreach ((new QuoteItemModel())->getByQuote($quoteId) as $item) {
            $db->table('ip_invoice_items')->insert([
                'invoice_id'           => $invoiceId,
                'item_tax_rate_id'     => $item['item_tax_rate_id'],
                'item_product_id'      => $item['item_product_id'],
                // Il codice scritto sulla riga (MEPA, articolo concordato)
                // segue la riga anche nella conversione: e' il dato che finisce
                // in fattura, non un dettaglio del preventivo.
                'item_code'            => $item['item_code'] ?? null,
                'item_name'            => $item['item_name'],
                'item_description'     => $item['item_description'],
                'item_quantity'        => $item['item_quantity'],
                'item_price'           => $item['item_price'],
                'item_product_unit_id' => $item['item_product_unit_id'],
                'item_product_unit'    => $item['item_product_unit'],
                'item_discount_amount' => $item['item_discount_amount'],
                // Senza questo campo, uno sconto percentuale sulla riga del
                // preventivo si perdeva alla conversione: i totali iniziali
                // restavano giusti (copiati di peso da ip_quote_item_amounts),
                // ma qualunque modifica successiva della riga ricalcolava
                // senza lo sconto percentuale originario, alterando il
                // totale in modo silenzioso.
                'item_discount_percent' => $item['item_discount_percent'] ?? null,
                'item_order'           => $item['item_order'],
            ]);

            $itemId = (int) $db->insertID();

            $db->table('ip_invoice_item_amounts')->insert([
                'item_id'       => $itemId,
                'item_subtotal' => $item['item_subtotal'],
                'item_tax_total' => $item['item_tax_total'],
                'item_discount' => $item['item_discount'],
                'item_total'    => $item['item_total'],
            ]);
        }

        // Copia le tasse preventivo
        foreach ((new QuoteTaxRateModel())->getByQuote($quoteId) as $taxRate) {
            $db->table('ip_invoice_tax_rates')->insert([
                'invoice_id'               => $invoiceId,
                'tax_rate_id'              => $taxRate['tax_rate_id'],
                'include_item_tax'         => $taxRate['include_item_tax'],
                'invoice_tax_rate_amount'  => $taxRate['quote_tax_rate_amount'],
            ]);
        }

        // Ricalcola gli importi della fattura
        // Routine ufficiale degli importi fattura: quella locale qui sotto
        // aggiornava totale e imponibile ma non pagato/saldo, così la fattura
        // nata da un preventivo risultava con saldo zero pur non essendo pagata.
        (new \App\Models\InvoiceAmountModel())->calculate($invoiceId);

        // Aggancia la fattura al preventivo
        $this->quoteModel->update($quoteId, ['invoice_id' => $invoiceId]);

        $db->transComplete();

        if ($db->transStatus() === false) {
            session()->setFlashdata('alert_error', 'Conversione in fattura fallita, nessuna modifica salvata');

            return redirect()->to('/quotes');
        }

        // Qui la fattura nasce da un insert grezzo sul builder, che non passa
        // dagli eventi di InvoiceModel: senza questa chiamata la conversione
        // preventivo -> fattura resterebbe l'unico percorso di creazione che
        // lascia "Utile e imposte" con i dati vecchi.
        invalidate_finance_cache();

        session()->setFlashdata('alert_success', 'Fattura creata dal preventivo correttamente');

        return redirect()->to('/invoices/view/' . $invoiceId);
    }


    /**
     * Cambia il cliente del preventivo (equivalente di change_client() CI3).
     */
    public function changeClient()
    {
        $post     = $this->request->getPost();
        $quoteId  = (int) ($post['quote_id'] ?? 0);
        $clientId = (int) ($post['client_id'] ?? 0);

        // Il cliente si cercava fuori perimetro e il preventivo non si cercava
        // affatto: bastava il quote_id nel POST per reintestare il preventivo di
        // un'altra azienda, per giunta a un cliente che quell'azienda non ha.
        $client = (new ClientModel())->findScoped($clientId);

        if ($client && $quoteId && $this->quoteModel->findScoped($quoteId)) {
            $this->quoteModel->update($quoteId, ['client_id' => $clientId]);
            $response = ['success' => 1, 'quote_id' => $quoteId];
        } else {
            $response = ['success' => 0, 'validation_errors' => ['client_id' => 'Cliente non valido']];
        }

        return $this->jsonResponse($response, $quoteId ?: null);
    }

    /**
     * Copia il preventivo completo (equivalente di copy_quote() CI3).
     */
    public function copyQuote(?int $quoteId = null)
    {
        if ($quoteId === null) {
            $quoteId = (int) $this->request->getPost('quote_id');
        }

        // copyQuote() legge la sorgente con find(): il perimetro va imposto qui,
        // altrimenti si porta dentro l'azienda attiva una copia integrale --
        // cliente, righe, prezzi, note -- di un preventivo che non le appartiene.
        $newId = $this->quoteModel->findScoped($quoteId) ? $this->quoteModel->copyQuote($quoteId) : 0;

        if ($newId) {
            session()->setFlashdata('alert_success', 'Preventivo copiato correttamente');

            return redirect()->to('/quotes/view/' . $newId);
        }

        session()->setFlashdata('alert_error', 'Preventivo non trovato');

        return redirect()->to('/quotes');
    }

    /**
     * Risposta JSON; se la richiesta non e' AJAX (form tradizionale)
     * redirige alla vista del preventivo con flashdata.
     */
    private function jsonResponse(array $response, ?int $quoteId = null, ?string $redirectTo = null)
    {
        if ($this->request->isAJAX()) {
            return $this->response->setJSON($response);
        }

        if (($response['success'] ?? 0) === 1) {
            session()->setFlashdata('alert_success', 'Operazione completata correttamente');
        } else {
            $errors = $response['validation_errors'] ?? '';
            if (is_array($errors)) {
                $errors = implode(' ', $errors);
            }
            session()->setFlashdata('alert_error', $errors ?: 'Operazione non riuscita');
        }

        if ($redirectTo !== null) {
            return redirect()->to($redirectTo);
        }

        return redirect()->to($quoteId ? '/quotes/view/' . $quoteId : '/quotes');
    }
}