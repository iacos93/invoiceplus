<?php

namespace App\Models;

class QuoteModel extends IpBaseModel
{
    protected $table            = 'ip_quotes';
    protected $primaryKey       = 'quote_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    // Stessa ragione di InvoiceModel: la password del preventivo si cifra a
    // riposo e non si hasha, perche' la vista di modifica deve poterla
    // mostrare allo staff che la comunica al cliente. Il prefisso "enc:"
    // rende la cifratura idempotente.
    protected $beforeInsert = ['encryptDocumentPassword'];
    protected $beforeUpdate = ['encryptDocumentPassword'];

    protected $allowedFields = [
        'user_id',
        'client_id',
        'invoice_group_id',
        'quote_status_id',
        'quote_date_created',
        'quote_time_created',
        'quote_date_expires',
        'quote_number',
        'quote_number_is_draft',
        'quote_discount_amount',
        'quote_discount_percent',
        'quote_url_key',
        'quote_password',
        'notes',
        'invoice_id',
        'quote_is_favourite',
        'company_id',
    ];

    public function validationRulesLegacy(): array
    {
        return [
            'client_id'          => 'required',
            'quote_date_created' => 'required',
            'quote_time_created' => 'permit_empty',
            'invoice_group_id'   => 'required',
            'quote_number'        => 'permit_empty|max_length[100]',
            'quote_password'     => 'permit_empty',
            'user_id'            => 'permit_empty',
        ];
    }

    /**
     * Join completo con clienti, importi, utenti e fattura collegata.
     */
    private function withFullJoins(): static
    {
        $this->select("
            ip_quotes.*,
            ip_clients.client_name,
            ip_clients.client_surname,
            ip_clients.client_address_1,
            ip_clients.client_address_2,
            ip_clients.client_city,
            ip_clients.client_state,
            ip_clients.client_zip,
            ip_clients.client_country,
            ip_clients.client_phone,
            ip_clients.client_fax,
            ip_clients.client_mobile,
            ip_clients.client_email,
            ip_clients.client_web,
            ip_clients.client_vat_id,
            ip_clients.client_tax_code,
            ip_clients.client_language,
            ip_clients.client_active,
            ip_users.user_name,
            ip_users.user_company,
            ip_users.user_address_1,
            ip_users.user_address_2,
            ip_users.user_city,
            ip_users.user_state,
            ip_users.user_zip,
            ip_users.user_country,
            ip_users.user_phone,
            ip_users.user_fax,
            ip_users.user_mobile,
            ip_users.user_email,
            ip_users.user_web,
            ip_users.user_vat_id,
            ip_users.user_tax_code,
            ip_companies.company_name              AS emitter_name,
            ip_companies.company_vat_id             AS emitter_vat_id,
            ip_companies.company_tax_code           AS emitter_tax_code,
            ip_companies.company_address_1          AS emitter_address_1,
            ip_companies.company_city               AS emitter_city,
            ip_companies.company_state              AS emitter_state,
            ip_companies.company_zip                AS emitter_zip,
            ip_companies.company_country             AS emitter_country,
            ip_companies.company_phone              AS emitter_phone,
            ip_companies.company_carta_docente_code AS emitter_carta_docente_code,
            ip_quote_amounts.quote_amount_id,
            COALESCE(ip_quote_amounts.quote_item_subtotal, '0.00') AS quote_item_subtotal,
            COALESCE(ip_quote_amounts.quote_item_tax_total, '0.00') AS quote_item_tax_total,
            COALESCE(ip_quote_amounts.quote_tax_total, '0.00') AS quote_tax_total,
            COALESCE(ip_quote_amounts.quote_total, '0.00') AS quote_total,
            ip_invoices.invoice_number,
            (CASE WHEN ip_quotes.quote_status_id NOT IN (1,2) AND ip_quotes.quote_date_expires < CURRENT_DATE AND ip_quotes.quote_date_expires IS NOT NULL THEN 1 ELSE 0 END) AS is_expired,
            (CASE WHEN ip_quotes.invoice_id > 0 THEN 1 ELSE 0 END) AS is_invoiced
        ", false)
        ->join('ip_clients', 'ip_clients.client_id = ip_quotes.client_id', 'left')
        ->join('ip_users', 'ip_users.user_id = ip_quotes.user_id', 'left')
        // Stessa logica di InvoiceModel::withFullJoins(): azienda del
        // documento, con ripiego sulla predefinita per i preventivi senza
        // company_id.
        ->join(
            'ip_companies',
            'ip_companies.company_id = COALESCE(ip_quotes.company_id, '
                . '(SELECT company_id FROM ip_companies WHERE company_is_default IS TRUE ORDER BY company_id ASC LIMIT 1))',
            'left',
            false
        )
        ->join('ip_quote_amounts', 'ip_quote_amounts.quote_id = ip_quotes.quote_id', 'left')
        ->join('ip_invoices', 'ip_invoices.invoice_id = ip_quotes.invoice_id', 'left');

        return $this;
    }

    /**
     * Recupera un preventivo completo tramite ID.
     */
    /**
     * Callback eventi CI4: cifra la password del documento prima di scriverla.
     */
    protected function encryptDocumentPassword(array $data): array
    {
        $value = $data['data']['quote_password'] ?? null;

        if (!empty($value) && strpos((string) $value, 'enc:') !== 0) {
            $data['data']['quote_password'] = encrypt_secret((string) $value);
        }

        return $data;
    }

    public function getByIdScoped(int $quoteId): ?array
    {
        return $this->withFullJoins()
            ->where('ip_quotes.quote_id', $quoteId)
            ->scopeToCompany()
            ->first();
    }

    /**
     * Recupera un preventivo completo tramite ID, senza perimetro azienda.
     *
     * Vale parola per parola la spiegazione su InvoiceModel::getById(): il
     * filtro non puo' stare qui perche' questo metodo serve anche al portale
     * cliente e ai link pubblici con url key, dove active_company_id() ripiega
     * sull'azienda predefinita e nasconderebbe i preventivi di tutte le altre.
     * Nell'area amministrativa usare getByIdScoped().
     */
    public function getById(int $quoteId): ?array
    {
        return $this->withFullJoins()
            ->where('ip_quotes.quote_id', $quoteId)
            ->first();
    }

    /**
     * Alias per compatibilità con i controller.
     */
    public function getByIdWithJoins(int $quoteId): ?array
    {
        return $this->getById($quoteId);
    }

    /**
     * Autore del documento e dati dell'azienda emittente, per i template PDF.
     *
     * Il template del preventivo legge l'intestazione da $user perche'
     * condivide l'impianto con partial_document.php delle fatture, ma la
     * ragione sociale arriva dai emitter_* del join con ip_companies, non
     * dall'account: senza questa composizione il PDF esce col nome dell'utente
     * al posto dell'azienda e senza P.IVA ne' indirizzo. Sta qui una volta
     * sola perche' i percorsi PDF sono tre (area amministrativa, portale
     * cliente, link pubblico) e le versioni guest chiamavano find(), che non
     * ha i join: il cliente riceveva un preventivo intestato all'account.
     */
    public function pdfUser(array $quote): array
    {
        return array_merge(
            (new UserModel())->find((int) ($quote['user_id'] ?? 0)) ?? [],
            array_intersect_key($quote, array_flip([
                'emitter_name', 'emitter_vat_id', 'emitter_tax_code',
                'emitter_address_1', 'emitter_city', 'emitter_state', 'emitter_zip',
                'emitter_country', 'emitter_phone', 'emitter_carta_docente_code',
            ]))
        );
    }

    /**
     * Preventivo tramite url key (per visualizzazione guest).
     */
    public function getByUrlKey(string $urlKey): ?array
    {
        return $this->withFullJoins()
            ->where('ip_quotes.quote_url_key', $urlKey)
            ->first();
    }

    /**
     * Lista preventivi filtrata per stato, anno, mese e preferiti.
     */
    public function getAllWithStatus($status = 'all', int $year = 0, int|array $month = 0, $favourite = '', int $perPage = 0, int $page = 1): array
    {
        $this->withFullJoins();

        $this->applyStatusFilter($this, $status);

        if ($year > 0) {
            $this->where(year_sql('ip_quotes.quote_date_created'), $year);
        }

        $this->applyMonthFilter($this, 'ip_quotes.quote_date_created', $month);
        $this->applyFavouriteFilter($this, $favourite);

        $this->scopeToCompany();

        $this->orderBy('ip_quotes.quote_date_created', 'DESC')
             ->orderBy('ip_quotes.quote_id', 'DESC');

        if ($perPage > 0) {
            return $this->paginate($perPage, 'ip_quotes', $page);
        }

        return $this->findAll();
    }

    /**
     * Preventivi emessi nell'intervallo indicato, estremi inclusi, in ordine
     * cronologico; con entrambi gli estremi a null ritorna tutto l'archivio.
     *
     * Gemello di InvoiceModel::getInPeriod(), e per lo stesso motivo: le
     * esportazioni filtravano il periodo in PHP dopo aver letto tutto.
     */
    public function getInPeriod(?string $from = null, ?string $to = null, ?int $year = null, array $months = []): array
    {
        $this->withFullJoins();

        if ($from !== null) {
            $this->where('ip_quotes.quote_date_created >=', $from);
        }

        if ($to !== null) {
            $this->where('ip_quotes.quote_date_created <=', $to);
        }

        if ($year !== null && $year > 0) {
            $this->where(year_sql('ip_quotes.quote_date_created'), $year);
        }

        $this->applyMonthFilter($this, 'ip_quotes.quote_date_created', $months);

        $this->scopeToCompany();

        return $this->orderBy('ip_quotes.quote_date_created', 'ASC')
                    ->orderBy('ip_quotes.quote_id', 'ASC')
                    ->findAll();
    }

    /**
     * Crea un nuovo preventivo con record iniziale ip_quote_amounts
     * e imposte di default (equivalente di create() CI3).
     *
     * Come InvoiceModel::create(): quando il cliente ha preferenze di
     * fatturazione valorizzate, l'aliquota predefinita e lo sconto del
     * cliente sovrascrivono i default globali. I giorni di pagamento
     * restano una cosa della fattura: la validita' del preventivo segue
     * quotes_expire_after e non i termini di pagamento.
     */
    public function create(?array $dbArray = null): int
    {
        $dbArray ??= [];

        // Preferenze del cliente: sovrascrivono i default globali
        $clientPrefs = ClientModel::billingPreferencesFor($dbArray['client_id'] ?? null);

        if (empty($dbArray['user_id'])) {
            $dbArray['user_id'] = session()->get('user_id') ?? 1;
        }

        if (empty($dbArray['quote_date_created'])) {
            $dbArray['quote_date_created'] = date('Y-m-d');
        } else {
            $dbArray['quote_date_created'] = date_to_mysql($dbArray['quote_date_created']);
        }

        if (empty($dbArray['quote_time_created'])) {
            $dbArray['quote_time_created'] = date('H:i:s');
        }

        if (empty($dbArray['quote_date_expires'])) {
            $dbArray['quote_date_expires'] = $this->getDateDue($dbArray['quote_date_created']);
        } else {
            $dbArray['quote_date_expires'] = date_to_mysql($dbArray['quote_date_expires']);
        }

        if (empty($dbArray['notes'])) {
            // Default per-azienda, non globale: le note di preventivo sono
            // contenuto proprio di ciascuna azienda.
            $dbArray['notes'] = company_setting('default_quote_notes', '');
        }

        if (!isset($dbArray['quote_status_id'])) {
            $dbArray['quote_status_id'] = 1;
        }

        if (empty($dbArray['invoice_group_id'])) {
            // Gruppo di numerazione per-azienda: evita di ereditare la serie
            // dell'azienda predefinita se una creazione arriva senza group_id.
            $dbArray['invoice_group_id'] = (new InvoiceGroupModel())->defaultGroupId(0, 'default_quote_group');
        }

        if (empty($dbArray['quote_url_key'])) {
            $dbArray['quote_url_key'] = bin2hex(random_bytes(16));
        }

        $generateQuoteNumber = (int) get_setting('generate_quote_number_for_draft', 0);
        if (!empty($dbArray['quote_number'])) {
            // Numero manuale
        } elseif ((int) $dbArray['quote_status_id'] === 1 && $generateQuoteNumber === 1) {
            $dbArray['quote_number'] = $this->generateQuoteNumber((int) $dbArray['invoice_group_id']);
        } elseif ((int) $dbArray['quote_status_id'] === 1) {
            [$draftNum, $isDraftFlag] = $this->draftNumber();
            if ($draftNum) {
                $dbArray['quote_number']          = $draftNum;
                $dbArray['quote_number_is_draft'] = (bool) $isDraftFlag;
            } else {
                $dbArray['quote_number'] = '';
            }
        } elseif ((int) $dbArray['quote_status_id'] !== 1) {
            $dbArray['quote_number'] = $this->generateQuoteNumber((int) $dbArray['invoice_group_id']);
        }

        // Sconto predefinito del cliente: solo se il POST non ne ha gia'
        // uno (copyQuote() passa quello del preventivo copiato e deve restare).
        if (!isset($dbArray['quote_discount_percent']) && $clientPrefs['client_discount_default'] !== null) {
            $dbArray['quote_discount_percent'] = $clientPrefs['client_discount_default'];
        }

        $quoteId = (int) $this->saveRecord(null, $dbArray);

        // Record ip_quote_amounts iniziale
        $this->db->table('ip_quote_amounts')->insert([
            'quote_id' => $quoteId,
        ]);

        // Aliquota di default: quella del cliente se valorizzata, altrimenti
        // il default globale (stessa priorita' di InvoiceModel::create()).
        $defaultTaxRate = $clientPrefs['client_default_tax_rate_id']
            ?? (int) company_setting('default_invoice_tax_rate', 0);

        if ($defaultTaxRate > 0) {
            $this->db->table('ip_quote_tax_rates')->insert([
                'quote_id'               => $quoteId,
                'tax_rate_id'            => $defaultTaxRate,
                'include_item_tax'       => (int) company_setting('default_include_item_tax', 0),
                'quote_tax_rate_amount'  => 0,
            ]);
        }

        return $quoteId;
    }

    /**
     * Copia un preventivo completo (equivalente di copy_quote() CI3).
     * Se $targetId è null, crea prima una nuova bozza a partire da $sourceId.
     */
    public function copyQuote(int $sourceId, ?int $targetId = null): int
    {
        $sourceQuote = $this->find($sourceId);

        if (!$sourceQuote) {
            return 0;
        }

        if ($targetId === null) {
            $dbArray = [
                'user_id'                => $sourceQuote['user_id'],
                'client_id'              => $sourceQuote['client_id'],
                'quote_date_created'     => date('Y-m-d'),
                'quote_date_expires'     => $this->getDateDue(date('Y-m-d')),
                'invoice_group_id'       => $sourceQuote['invoice_group_id'],
                'quote_status_id'        => 1,
                'quote_number'           => null,
                'quote_discount_amount'  => $sourceQuote['quote_discount_amount'],
                'quote_discount_percent' => $sourceQuote['quote_discount_percent'],
                'notes'                  => $sourceQuote['notes'],
            ];

            $targetId = $this->create($dbArray);
        }

        $itemModel           = new QuoteItemModel();
        $quoteItemAmountModel = new QuoteItemAmountModel();

        // 1. Copia le righe
        $items = $this->db->table('ip_quote_items')
            ->where('quote_id', $sourceId)
            ->get()
            ->getResultArray();

        foreach ($items as $item) {
            $dbArray = [
                'quote_id'              => $targetId,
                'item_tax_rate_id'      => $item['item_tax_rate_id'] ?? 0,
                'item_product_id'       => $item['item_product_id'] ?? null,
                // Il codice riga (MEPA o articolo concordato) resta anche
                // duplicando il preventivo.
                'item_code'             => $item['item_code'] ?? null,
                'item_name'             => $item['item_name'],
                'item_description'      => $item['item_description'],
                'item_quantity'         => $item['item_quantity'],
                'item_price'            => $item['item_price'],
                'item_discount_amount'  => $item['item_discount_amount'],
                'item_discount_percent' => $item['item_discount_percent'],
                'item_order'            => $item['item_order'],
                'item_product_unit'     => $item['item_product_unit'] ?? null,
                'item_product_unit_id'  => $item['item_product_unit_id'] ?? null,
            ];

            $newItemId = (int) $itemModel->insert($dbArray, true);
            $quoteItemAmountModel->calculate($newItemId);
        }

        // 2. Copia le imposte
        $taxRates = $this->db->table('ip_quote_tax_rates')
            ->where('quote_id', $sourceId)
            ->get()
            ->getResultArray();

        foreach ($taxRates as $taxRate) {
            $this->db->table('ip_quote_tax_rates')->insert([
                'quote_id'              => $targetId,
                'tax_rate_id'           => $taxRate['tax_rate_id'],
                'include_item_tax'      => $taxRate['include_item_tax'],
                'quote_tax_rate_amount' => $taxRate['quote_tax_rate_amount'],
            ]);
        }

        // 3. Copia i campi custom
        $customFields = $this->db->table('ip_quote_custom')
            ->where('quote_id', $sourceId)
            ->get()
            ->getResultArray();

        if (!empty($customFields)) {
            $formData = [];
            foreach ($customFields as $field) {
                $formData[$field['quote_custom_fieldid']] = $field['quote_custom_fieldvalue'];
            }
            (new QuoteCustomModel())->saveCustom($targetId, $formData);
        }

        // 4. Ricalcola i totali
        (new QuoteAmountModel())->calculate($targetId);

        return $targetId;
    }

    /**
     * Calcola la data di scadenza preventivo.
     */
    public function getDateDue(string $quoteDateCreated): string
    {
        $days = (int) get_setting('quotes_expire_after', 15);
        $due  = new \DateTime($quoteDateCreated);
        $due->modify("+{$days} days");

        return $due->format('Y-m-d');
    }
    /**
     * Genera il numero preventivo dal gruppo specificato.
     */
    public function generateQuoteNumber(int $invoiceGroupId): string
    {
        return (new InvoiceGroupModel())->generateInvoiceNumber($invoiceGroupId);
    }

    /**
     * Helper per recuperare il gruppo preventivo.
     */
    public function getInvoiceGroupId(int $quoteId): ?int
    {
        $quote = $this->find($quoteId);

        return $quote ? (int) ($quote['invoice_group_id'] ?? 0) : null;
    }

    /**
     * Numero provvisorio dalla serie bozze, se configurata.
     */
    public function draftNumber(): array
    {
        $draftGroupId = (int) company_setting('draft_quote_group', 0);

        if ($draftGroupId <= 0) {
            return [null, 0];
        }

        $groupModel = new InvoiceGroupModel();

        if (!$groupModel->find($draftGroupId)) {
            return [null, 0];
        }

        return [$groupModel->generateInvoiceNumber($draftGroupId), 1];
    }

    /**
     * Assegna il numero definitivo quando il documento esce dalla bozza.
     */
    public function finalizeNumber(int $quoteId): void
    {
        $quote = $this->find($quoteId);

        if (!$quote) {
            return;
        }

        if (empty($quote['quote_number']) || db_bool($quote['quote_number_is_draft'] ?? false)) {
            $this->update($quoteId, [
                'quote_number'          => $this->generateQuoteNumber((int) $quote['invoice_group_id']),
                'quote_number_is_draft' => false,
            ]);
        }
    }

    /**
     * Genera il numero preventivo se applicabile.
     */
    public function generateQuoteNumberIfApplicable(int $quoteId): void
    {
        $quote = $this->find($quoteId);

        if (!$quote) {
            return;
        }

        if ((int) $quote['quote_status_id'] === 1
            && (empty($quote['quote_number']) || db_bool($quote['quote_number_is_draft'] ?? false))) {
            if ((int) get_setting('generate_quote_number_for_draft', 0) === 0) {
                $quoteNumber = $this->generateQuoteNumber((int) $quote['invoice_group_id']);
                $this->update($quoteId, [
                    'quote_number'          => $quoteNumber,
                    'quote_number_is_draft' => false,
                ]);
            }
        }
    }

    /**
     * Marca il preventivo come approvato (stato 4).
     *
     * Ritorna true solo se la riga e' cambiata davvero: il controllo di
     * QuoteDecision e l'UPDATE non sono atomici, e con due decisioni
     * concorrenti (due tab, portale e link pubblico) la seconda trova il
     * preventivo gia' deciso. Il chiamante deve poterlo dire invece di
     * mostrare un successo mai avvenuto. Gli stati aperti sono quelli di
     * QuoteDecision, cosi' verifica e scrittura non possono divergere.
     */
    public function approveQuoteById(int $quoteId): bool
    {
        return $this->decide(['quote_id' => $quoteId], 4);
    }

    public function approveQuoteByKey(string $quoteUrlKey): bool
    {
        return $this->decide(['quote_url_key' => $quoteUrlKey], 4);
    }

    /**
     * Marca il preventivo come rifiutato (stato 5). Vedi approveQuoteById().
     */
    public function rejectQuoteById(int $quoteId): bool
    {
        return $this->decide(['quote_id' => $quoteId], 5);
    }

    public function rejectQuoteByKey(string $quoteUrlKey): bool
    {
        return $this->decide(['quote_url_key' => $quoteUrlKey], 5);
    }

    /**
     * UPDATE condizionato sugli stati aperti; true se ha toccato una riga.
     */
    private function decide(array $where, int $statusId): bool
    {
        $this->db->table($this->table)
            ->where($where)
            ->whereIn('quote_status_id', \App\Services\QuoteDecision::OPEN_STATUSES)
            ->update(['quote_status_id' => $statusId]);

        return $this->db->affectedRows() > 0;
    }

    /**
     * Marca il preventivo come inviato (stato 2).
     */
    public function markSent(int $quoteId): void
    {
        $quote = $this->find($quoteId);

        if ($quote && (int) $quote['quote_status_id'] === 1) {
            $this->update($quoteId, ['quote_status_id' => 2]);
        }
    }

    /**
     * Marca il preventivo come visto (stato 3).
     */
    public function markViewed(int $quoteId): void
    {
        $quote = $this->find($quoteId);

        if ($quote && (int) $quote['quote_status_id'] === 2) {
            $this->update($quoteId, ['quote_status_id' => 3]);
        }
    }

    // ------------------------------------------------------------------
    // Filtri e Scopes
    // ------------------------------------------------------------------

    public function isDraft(): static
    {
        $this->where('ip_quotes.quote_status_id', 1);

        return $this;
    }

    public function isSent(): static
    {
        $this->where('ip_quotes.quote_status_id', 2);

        return $this;
    }

    public function isViewed(): static
    {
        $this->where('ip_quotes.quote_status_id', 3);

        return $this;
    }

    public function isApproved(): static
    {
        $this->where('ip_quotes.quote_status_id', 4);

        return $this;
    }

    public function isRejected(): static
    {
        $this->where('ip_quotes.quote_status_id', 5);

        return $this;
    }

    public function isCanceled(): static
    {
        $this->where('ip_quotes.quote_status_id', 6);

        return $this;
    }

    public function isOpen(): static
    {
        $this->whereIn('ip_quotes.quote_status_id', [2, 3]);

        return $this;
    }

    public function guestVisible(): static
    {
        $this->whereIn('ip_quotes.quote_status_id', [2, 3, 4, 5]);

        return $this;
    }

    public function byClient(int $clientId): static
    {
        $this->where('ip_quotes.client_id', $clientId);

        return $this;
    }

    public function statuses(): array
    {
        return [
            '1' => ['label' => trans('draft'), 'class' => 'draft', 'href' => 'quotes/status/draft'],
            '2' => ['label' => trans('sent'), 'class' => 'sent', 'href' => 'quotes/status/sent'],
            '3' => ['label' => trans('viewed'), 'class' => 'viewed', 'href' => 'quotes/status/viewed'],
            '4' => ['label' => trans('approved'), 'class' => 'approved', 'href' => 'quotes/status/approved'],
            '5' => ['label' => trans('rejected'), 'class' => 'rejected', 'href' => 'quotes/status/rejected'],
            '6' => ['label' => trans('canceled'), 'class' => 'canceled', 'href' => 'quotes/status/canceled'],
        ];
    }

    public function getLatest(int $limit = 10, int $year = 0): array
    {
        $this->withFullJoins()->limit($limit)->orderBy('ip_quotes.quote_date_created', 'DESC')->orderBy('ip_quotes.quote_id', 'DESC');
        if ($year > 0) {
            $this->where(year_sql('ip_quotes.quote_date_created'), $year);
        }
        $this->scopeToCompany();

        return $this->findAll();
    }

    /**
     * Anni con almeno un preventivo.
     */
    public function getAvailableYears(): array
    {
        $rows = $this->companyBuilder()
            ->select('DISTINCT EXTRACT(YEAR FROM quote_date_created) AS y', false)
            ->orderBy('y', 'DESC')
            ->get()
            ->getResultArray();

        return array_map('intval', array_column($rows, 'y'));
    }

    /**
     * Preventivi dei clienti assegnati a un utente guest.
     */
    public function getForGuestClients(array $clientIds, string $status = 'open'): array
    {
        if (empty($clientIds)) {
            return [];
        }

        $this->withFullJoins()->whereIn('ip_quotes.client_id', $clientIds);
        $this->applyGuestStatus($status);

        return $this->orderBy('ip_quotes.quote_id', 'DESC')->findAll();
    }

    /**
     * Quanti preventivi vedrebbe getForGuestClients() con lo stesso filtro
     * (vedi InvoiceModel::countForGuestClients()).
     */
    public function countForGuestClients(array $clientIds, string $status = 'open'): int
    {
        if (empty($clientIds)) {
            return 0;
        }

        $this->whereIn('ip_quotes.client_id', $clientIds);
        $this->applyGuestStatus($status);

        return $this->countAllResults();
    }

    /** Filtro di stato del portale: un posto solo per elenco e conteggio. */
    private function applyGuestStatus(string $status): void
    {
        switch ($status) {
            case 'approved':
                $this->isApproved();
                break;
            case 'rejected':
                $this->isRejected();
                break;
            case 'canceled':
                $this->isCanceled();
                break;
            default:
                $this->isOpen();
                break;
        }
    }

    /**
     * Ricerca per parole chiave (modulo filter).
     */
    public function filterByKeywords(array $keywords): array
    {
        $this->withFullJoins();

        foreach ($keywords as $keyword) {
            if ($keyword === '') {
                continue;
            }

            $keyword = strtolower($keyword);
            $this->likeExpression(
                "CONCAT_WS('^', LOWER(ip_quotes.quote_number), ip_quotes.quote_date_created,
                    ip_quotes.quote_date_expires, LOWER(CONCAT(ip_clients.client_name, ' ', ip_clients.client_surname)),
                    COALESCE(ip_quote_amounts.quote_total, '0.00'))",
                $keyword
            );
        }

        $this->scopeToCompany();

        return $this->orderBy('ip_quotes.quote_id', 'DESC')->findAll();
    }

    /**
     * Totali aggregati del periodo e dello stato filtrati (usato da
     * period_nav). I preventivi non hanno saldo: conteggio, imponibile, IVA e
     * totale lordo.
     *
     * @return array{count: int, net: float, tax: float, total: float}
     */
    public function getPeriodTotals($status = 'all', int $year = 0, int|array $month = 0, $favourite = ''): array
    {
        $builder = $this->db->table('ip_quotes')
            ->select('COUNT(*) AS cnt, COALESCE(SUM(a.quote_item_subtotal), 0) AS net, COALESCE(SUM(a.quote_item_tax_total + a.quote_tax_total), 0) AS tax, COALESCE(SUM(a.quote_total), 0) AS total', false)
            ->join('ip_quote_amounts a', 'a.quote_id = ip_quotes.quote_id', 'left');

        $this->applyStatusFilter($builder, $status);

        if ($year > 0) {
            $builder->where(year_sql('ip_quotes.quote_date_created'), $year);
        }

        $this->applyMonthFilter($builder, 'ip_quotes.quote_date_created', $month);
        $this->applyFavouriteFilter($builder, $favourite);

        $builder = scope_builder_to_company($builder, 'ip_quotes');

        $row = $builder->get()->getRowArray();

        return [
            'count' => (int) ($row['cnt'] ?? 0),
            'net'   => (float) ($row['net'] ?? 0),
            'tax'   => (float) ($row['tax'] ?? 0),
            'total' => (float) ($row['total'] ?? 0),
        ];
    }

    /**
     * Totali per mese dell'anno, con la stessa selezione della lista (stato e
     * preferiti): la barra periodo li mostra sotto il nome del mese, e devono
     * parlare dello stesso perimetro delle righe sotto.
     *
     * @return array<int, array{net: float, tax: float, total: float}>
     */
    public function getMonthlyTotals($status = 'all', int $year = 0, $favourite = ''): array
    {
        $out = [];

        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = ['net' => 0.0, 'tax' => 0.0, 'total' => 0.0];
        }

        $builder = $this->db->table('ip_quotes')
            ->select('EXTRACT(MONTH FROM ip_quotes.quote_date_created) AS m, COALESCE(SUM(a.quote_item_subtotal), 0) AS net, COALESCE(SUM(a.quote_item_tax_total + a.quote_tax_total), 0) AS tax, COALESCE(SUM(a.quote_total), 0) AS total', false)
            ->join('ip_quote_amounts a', 'a.quote_id = ip_quotes.quote_id', 'left');

        $this->applyStatusFilter($builder, $status);

        if ($year > 0) {
            $builder->where(year_sql('ip_quotes.quote_date_created'), $year);
        }

        $this->applyFavouriteFilter($builder, $favourite);

        $builder = scope_builder_to_company($builder, 'ip_quotes')
            ->groupBy('m')
            ->orderBy('m', 'ASC');

        foreach ($builder->get()->getResultArray() as $row) {
            $m = (int) ($row['m'] ?? 0);

            if ($m < 1 || $m > 12) {
                continue;
            }

            $out[$m] = [
                'net'   => (float) ($row['net'] ?? 0),
                'tax'   => (float) ($row['tax'] ?? 0),
                'total' => (float) ($row['total'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Applica il filtro di stato a un builder qualsiasi (lista e totali usano
     * le stesse condizioni: prima erano due switch separati).
     *
     * @param mixed $builder
     *
     * @return mixed
     */
    private function applyStatusFilter($builder, $status)
    {
        if (is_array($status)) {
            return $builder->whereIn('ip_quotes.quote_status_id', $status);
        }

        if (is_numeric($status)) {
            return $builder->where('ip_quotes.quote_status_id', (int) $status);
        }

        return match ($status) {
            'draft'    => $builder->where('ip_quotes.quote_status_id', 1),
            'sent'     => $builder->where('ip_quotes.quote_status_id', 2),
            'viewed'   => $builder->where('ip_quotes.quote_status_id', 3),
            'approved' => $builder->where('ip_quotes.quote_status_id', 4),
            'rejected' => $builder->where('ip_quotes.quote_status_id', 5),
            'canceled' => $builder->where('ip_quotes.quote_status_id', 6),
            'open'     => $builder->whereIn('ip_quotes.quote_status_id', [2, 3]),
            default    => $builder,
        };
    }

    /**
     * Filtro preferiti: '' tutti, '1' solo preferiti, '0' solo gli altri.
     *
     * @param mixed $builder
     *
     * @return mixed
     */
    private function applyFavouriteFilter($builder, $favourite)
    {
        if ($favourite === '1' || $favourite === 1) {
            return $builder->where('ip_quotes.quote_is_favourite', true);
        }

        if ($favourite === '0' || $favourite === 0) {
            return $builder->where('(ip_quotes.quote_is_favourite = FALSE OR ip_quotes.quote_is_favourite IS NULL)', null, false);
        }

        return $builder;
    }

    /**
     * Filtro mesi condiviso dalla lista preventivi (mese singolo o selezione
     * multipla dalla barra periodo).
     *
     * @param mixed         $builder
     * @param int|list<int> $month
     *
     * @return mixed
     */
    private function applyMonthFilter($builder, string $column, int|array $month)
    {
        $months = is_array($month) ? $month : [$month];
        $months = array_values(array_filter(
            array_map('intval', $months),
            static fn (int $m): bool => $m >= 1 && $m <= 12
        ));

        if ($months === []) {
            return $builder;
        }

        if (count($months) === 1) {
            return $builder->where('EXTRACT(MONTH FROM ' . $column . ')', $months[0]);
        }

        return $builder->where('EXTRACT(MONTH FROM ' . $column . ') IN (' . implode(', ', $months) . ')', null, false);
    }

    /**
     * Elimina il preventivo con i suoi orfani.
     */
    public function delete($id = null, bool $purge = false)
    {
        if (!$id) {
            return false;
        }

        $this->db->table('ip_quote_item_amounts')
            ->whereIn('item_id', $this->db->table('ip_quote_items')->select('item_id')->where('quote_id', $id))
            ->delete();

        $this->db->table('ip_quote_items')->where('quote_id', $id)->delete();
        $this->db->table('ip_quote_amounts')->where('quote_id', $id)->delete();
        $this->db->table('ip_quote_tax_rates')->where('quote_id', $id)->delete();
        $this->db->table('ip_quote_custom')->where('quote_id', $id)->delete();

        return parent::delete($id, $purge);
    }
}