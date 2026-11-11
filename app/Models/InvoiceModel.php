<?php

namespace App\Models;

class InvoiceModel extends IpBaseModel
{
    protected $table            = 'ip_invoices';
    protected $primaryKey       = 'invoice_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    // Fatture e spese sono gli ingressi principali della stima in "Utile e
    // imposte", che resta in cache un'ora: senza queste callback si emetteva
    // o si cancellava una fattura e il prospetto continuava a mostrare utile
    // e imposte calcolati sui dati precedenti. Il metodo clearFinanceCache()
    // esisteva gia' qui sotto ma non era registrato, quindi CI4 non lo
    // chiamava mai.

    // La password del documento viaggia fino al cliente e deve restare
    // leggibile dallo staff (la vista di modifica la mostra, perche' e' lo
    // staff a comunicarla): quindi si cifra a riposo, non si hasha. Hasharla
    // toglierebbe una funzione invece di proteggerla, e a ogni salvataggio
    // ri-hasherebbe l'hash.
    //
    // Il "enc:" davanti rende la cifratura idempotente: un valore gia' cifrato
    // -- per esempio quello copiato da un preventivo -- viene lasciato stare.
    protected $beforeInsert = ['encryptDocumentPassword'];
    protected $beforeUpdate = ['encryptDocumentPassword'];

    protected $afterInsert = ['clearFinanceCache'];
    protected $afterUpdate = ['clearFinanceCache'];
    protected $afterDelete = ['clearFinanceCache'];

    protected $allowedFields = [
        'user_id',
        'client_id',
        'invoice_group_id',
        'invoice_status_id',
        'is_read_only',
        'invoice_password',
        'invoice_date_created',
        'invoice_time_created',
        'invoice_date_due',
        'invoice_number',
        'invoice_number_is_draft',
        'invoice_discount_amount',
        'invoice_discount_percent',
        'invoice_terms',
        'invoice_url_key',
        'payment_method',
        'creditinvoice_parent_id',
        'invoice_is_favourite',
        'bank_account_id',
        'invoice_last_reminder_sent',
        'company_id',
        'invoice_carta_docente',
        'invoice_voucher_code',
        'riba_flow_ref',
        // Campi della fattura elettronica. Senza di loro qui, insert() e
        // update() del model li scartavano in silenzio (protectFields):
        // CIG, split payment, tipo documento, bollo e ritenuta si potevano
        // compilare a video ma non arrivavano mai in tabella né nell'XML.
        'invoice_cig',
        'invoice_cup',
        'invoice_admin_reference',
        'invoice_order_number',
        'invoice_order_date',
        'invoice_order_line',
        'invoice_contract_number',
        'invoice_contract_date',
        'invoice_convention_number',
        'invoice_convention_date',
        'invoice_receipt_number',
        'invoice_receipt_date',
        'invoice_doc_type',
        'invoice_vat_exigibility',
        'invoice_stamp_mode',
        'invoice_stamp_amount',
        'invoice_withholding_type',
        'invoice_withholding_rate',
        'invoice_withholding_reason',
        'invoice_fund_type',
        'invoice_fund_rate',
    ];

    public function validationRulesLegacy(): array
    {
        return [
            'client_id'            => 'required',
            'invoice_date_created' => 'required',
            'invoice_time_created' => 'permit_empty',
            'invoice_group_id'     => 'required',
            'invoice_number'           => 'permit_empty|max_length[100]',
            'invoice_password'     => 'permit_empty',
            'user_id'              => 'permit_empty',
            'payment_method'       => 'permit_empty',
            // Campi della fattura elettronica: il salvataggio parziale via
            // InvoicesAjax::updateInvoiceFromPost li scrive direttamente, quindi
            // le lunghezze vanno garantite qui o la UPDATE va in crash su una
            // colonna VARCHAR troppo corta (come era successo per company_country).
            'invoice_cig'              => 'permit_empty|max_length[15]',
            'invoice_cup'              => 'permit_empty|max_length[15]',
            'invoice_admin_reference'  => 'permit_empty|max_length[20]',
            'invoice_order_number'     => 'permit_empty|max_length[20]',
            'invoice_order_line'       => 'permit_empty|max_length[20]',
            'invoice_contract_number'  => 'permit_empty|max_length[20]',
            'invoice_convention_number' => 'permit_empty|max_length[20]',
            'invoice_receipt_number'   => 'permit_empty|max_length[20]',
        ];
    }

    /**
     * Join completo con clienti, importi, utenti e quote collegata.
     */
    private function withFullJoins(): static
    {
        $this->select("
            ip_invoices.*,
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
            ip_clients.client_type,
            ip_clients.client_sdi_code,
            ip_clients.client_sdi_pec,
            ip_clients.client_split_payment,
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
            ip_invoice_amounts.invoice_amount_id,
            COALESCE(ip_invoice_amounts.invoice_item_subtotal, '0.00') AS invoice_item_subtotal,
            COALESCE(ip_invoice_amounts.invoice_item_tax_total, '0.00') AS invoice_item_tax_total,
            COALESCE(ip_invoice_amounts.invoice_tax_total, '0.00') AS invoice_tax_total,
            COALESCE(ip_invoice_amounts.invoice_discount_total, '0.00') AS invoice_discount_total,
            COALESCE(ip_invoice_amounts.invoice_fund_amount, '0.00') AS invoice_fund_amount,
            COALESCE(ip_invoice_amounts.invoice_withholding_amount, '0.00') AS invoice_withholding_amount,
            COALESCE(ip_invoice_amounts.invoice_total, '0.00') AS invoice_total,
            COALESCE(ip_invoice_amounts.invoice_paid, '0.00') AS invoice_paid,
            COALESCE(ip_invoice_amounts.invoice_balance, '0.00') AS invoice_balance,
            ip_invoice_amounts.invoice_sign AS invoice_sign,
            ip_quotes.quote_number,
            ip_quotes.quote_id,
            (CASE WHEN ip_invoices.invoice_status_id NOT IN (1,4) AND (CURRENT_DATE - ip_invoices.invoice_date_due) > 0 THEN 1 ELSE 0 END) AS is_overdue,
            (CURRENT_DATE - ip_invoices.invoice_date_due) AS days_overdue,
            (CASE (SELECT COUNT(*) FROM ip_invoices_recurring WHERE ip_invoices_recurring.invoice_id = ip_invoices.invoice_id AND ip_invoices_recurring.recur_next_date IS NOT NULL) WHEN 0 THEN 0 ELSE 1 END) AS invoice_is_recurring
        ", false)
        ->join('ip_clients', 'ip_clients.client_id = ip_invoices.client_id', 'left')
        ->join('ip_users', 'ip_users.user_id = ip_invoices.user_id', 'left')
        // Azienda emittente del documento: quella indicata sulla fattura, o
        // la predefinita per le fatture precedenti al multi-azienda (senza
        // company_id). Non l'azienda attiva di sessione, che dipende solo
        // da cosa si sta guardando ora, non da chi ha emesso il documento.
        ->join(
            'ip_companies',
            'ip_companies.company_id = COALESCE(ip_invoices.company_id, '
                . '(SELECT company_id FROM ip_companies WHERE company_is_default IS TRUE ORDER BY company_id ASC LIMIT 1))',
            'left',
            false
        )
        ->join('ip_invoice_amounts', 'ip_invoice_amounts.invoice_id = ip_invoices.invoice_id', 'left')
        ->join('ip_quotes', 'ip_quotes.invoice_id = ip_invoices.invoice_id', 'left');

        return $this;
    }

    /**
     * Recupera una fattura completa tramite ID (equivalente di get_by_id() CI3).
     */
    public function getByIdScoped(int $invoiceId): ?array
    {
        return $this->withFullJoins()
            ->where('ip_invoices.invoice_id', $invoiceId)
            ->scopeToCompany()
            ->first();
    }

    /**
     * Recupera una fattura completa tramite ID, senza perimetro azienda.
     *
     * Il filtro non sta qui di proposito: questo metodo serve anche ai percorsi
     * dove una sessione amministrativa non c'e' -- link pubblici protetti da
     * url key, portale cliente, cron dei ricorrenti e dei solleciti, Mailer
     * chiamato dal cron. In quei contesti active_company_id() non vale 0:
     * ripiega sull'azienda predefinita e se la scrive pure in sessione, quindi
     * uno scopeToCompany() qui dentro non sarebbe un innocuo no-op, farebbe
     * sparire ogni documento che non appartiene all'azienda predefinita.
     *
     * Nell'area amministrativa usare getByIdScoped(): li' l'id arriva
     * dall'URL o dal POST e il perimetro va sempre applicato.
     */
    public function getById(int $invoiceId): ?array
    {
        return $this->withFullJoins()
            ->where('ip_invoices.invoice_id', $invoiceId)
            ->first();
    }

    /**
     * Fattura tramite url key (per la visualizzazione guest).
     */
    public function getByUrlKey(string $urlKey): ?array
    {
        return $this->withFullJoins()
            ->where('ip_invoices.invoice_url_key', $urlKey)
            ->first();
    }

    /**
     * Lista fatture filtrate per stato, anno, mese e preferiti con paginazione opzionale.
     */
    public function getAllWithStatus($status = 'all', int $year = 0, int|array $month = 0, $favourite = '', int $perPage = 0, int $page = 1, string $sign = ''): array
    {
        $this->withFullJoins();

        $this->applyStatusFilter($this, $status);

        if ($year > 0) {
            $this->where(year_sql('ip_invoices.invoice_date_created'), $year);
        }

        $this->applyMonthFilter($this, 'ip_invoices.invoice_date_created', $month);

        $this->applyFavouriteFilter($this, $favourite);
        $this->applySignFilter($this, $sign);

        $this->scopeToCompany();

        $this->orderBy('ip_invoices.invoice_date_created', 'DESC')
             ->orderBy('ip_invoices.invoice_id', 'DESC');

        if ($perPage > 0) {
            return $this->paginate($perPage, 'ip_invoices', $page);
        }

        return $this->findAll();
    }

    /**
     * Fatture emesse nell'intervallo indicato, estremi inclusi, in ordine
     * cronologico; con entrambi gli estremi a null ritorna tutto l'archivio.
     *
     * Esiste per le esportazioni: Exports chiamava getAllWithStatus() senza
     * filtri e scartava poi in PHP le fatture fuori periodo, quindi per
     * estrarre un trimestre leggeva comunque l'intero archivio con tutti i
     * join. Qui il periodo e' una clausola WHERE, e l'ordine e' crescente
     * perche' un estratto per il commercialista si legge dal piu' vecchio.
     */
    public function getInPeriod(?string $from = null, ?string $to = null, ?int $year = null, array $months = [], string $sign = ''): array
    {
        $this->withFullJoins();

        if ($from !== null) {
            $this->where('ip_invoices.invoice_date_created >=', $from);
        }

        if ($to !== null) {
            $this->where('ip_invoices.invoice_date_created <=', $to);
        }

        if ($year !== null && $year > 0) {
            $this->where(year_sql('ip_invoices.invoice_date_created'), $year);
        }

        $this->applyMonthFilter($this, 'ip_invoices.invoice_date_created', $months);
        $this->applySignFilter($this, $sign);

        $this->scopeToCompany();

        return $this->orderBy('ip_invoices.invoice_date_created', 'ASC')
                    ->orderBy('ip_invoices.invoice_id', 'ASC')
                    ->findAll();
    }

    /**
     * Totali aggregati per il periodo e stato filtrati (usato da period_nav).
     *
     * @return array{count: int, total: float, balance: float}
     */
    public function getPeriodTotals($status = 'all', int $year = 0, int|array $month = 0, $favourite = '', string $sign = ''): array
    {
        $builder = $this->db->table('ip_invoices')
            ->select('COUNT(*) AS cnt, COALESCE(SUM(COALESCE(a.invoice_item_tax_total, 0) + COALESCE(a.invoice_tax_total, 0)), 0) AS tax, COALESCE(SUM(a.invoice_total), 0) AS total, COALESCE(SUM(a.invoice_balance), 0) AS balance', false)
            ->join('ip_invoice_amounts a', 'a.invoice_id = ip_invoices.invoice_id', 'left');

        $this->applyStatusFilter($builder, $status);

        if ($year > 0) {
            $builder->where(year_sql('ip_invoices.invoice_date_created'), $year);
        }

        $this->applyMonthFilter($builder, 'ip_invoices.invoice_date_created', $month);
        $this->applyFavouriteFilter($builder, $favourite);
        $this->applySignFilter($builder, $sign, 'a.invoice_sign');

        $builder = scope_builder_to_company($builder, 'ip_invoices');

        $row = $builder->get()->getRowArray();

        $total = (float) ($row['total'] ?? 0);
        $tax   = (float) ($row['tax'] ?? 0);

        // Il netto è lordo meno IVA, non la somma dell'imponibile di riga: con
        // sconti di documento o contributo cassa l'imponibile della barra
        // periodo non tornava col totale che gli sta accanto.
        return [
            'count'   => (int) ($row['cnt'] ?? 0),
            'net'     => $total - $tax,
            'tax'     => $tax,
            'total'   => $total,
            'balance' => (float) ($row['balance'] ?? 0),
        ];
    }

    /**
     * Totali per mese dell'anno, con la stessa selezione della lista (stato e
     * preferiti): la barra periodo li mostra sotto il nome del mese, e devono
     * parlare dello stesso perimetro delle righe sotto.
     *
     * @return array<int, array{net: float, tax: float, total: float, balance: float}>
     */
    public function getMonthlyTotals($status = 'all', int $year = 0, $favourite = '', string $sign = ''): array
    {
        $out = [];

        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = ['net' => 0.0, 'tax' => 0.0, 'total' => 0.0, 'balance' => 0.0];
        }

        $builder = $this->db->table('ip_invoices')
            ->select('EXTRACT(MONTH FROM ip_invoices.invoice_date_created) AS m, COALESCE(SUM(COALESCE(a.invoice_item_tax_total, 0) + COALESCE(a.invoice_tax_total, 0)), 0) AS tax, COALESCE(SUM(a.invoice_total), 0) AS total, COALESCE(SUM(a.invoice_balance), 0) AS balance', false)
            ->join('ip_invoice_amounts a', 'a.invoice_id = ip_invoices.invoice_id', 'left');

        $this->applyStatusFilter($builder, $status);

        if ($year > 0) {
            $builder->where(year_sql('ip_invoices.invoice_date_created'), $year);
        }

        $this->applyFavouriteFilter($builder, $favourite);
        $this->applySignFilter($builder, $sign, 'a.invoice_sign');

        $builder = scope_builder_to_company($builder, 'ip_invoices')
            ->groupBy('m')
            ->orderBy('m', 'ASC');

        foreach ($builder->get()->getResultArray() as $row) {
            $m = (int) ($row['m'] ?? 0);

            if ($m < 1 || $m > 12) {
                continue;
            }

            $monthTotal = (float) ($row['total'] ?? 0);
            $monthTax   = (float) ($row['tax'] ?? 0);

            // Netto = lordo meno IVA, come nella barra periodo: imponibile e
            // imposta del mese devono sommare al totale dello stesso mese.
            $out[$m] = [
                'net'     => $monthTotal - $monthTax,
                'tax'     => $monthTax,
                'total'   => $monthTotal,
                'balance' => (float) ($row['balance'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Applica il filtro di stato a un builder qualsiasi (lista e totali
     * usano le stesse condizioni: prima erano due switch separati).
     *
     * @param mixed $builder
     *
     * @return mixed
     */
    private function applyStatusFilter($builder, $status)
    {
        if (is_array($status)) {
            return $builder->whereIn('ip_invoices.invoice_status_id', $status);
        }

        if (is_numeric($status)) {
            return $builder->where('ip_invoices.invoice_status_id', (int) $status);
        }

        switch ($status) {
            case 'draft':
                return $builder->where('ip_invoices.invoice_status_id', 1);
            case 'sent':
                return $builder->where('ip_invoices.invoice_status_id', 2);
            case 'viewed':
                return $builder->where('ip_invoices.invoice_status_id', 3);
            case 'paid':
                return $builder->where('ip_invoices.invoice_status_id', 4);
            case 'overdue':
                return $builder->where('(CASE WHEN ip_invoices.invoice_status_id NOT IN (1,4) AND (CURRENT_DATE - ip_invoices.invoice_date_due) > 0 THEN 1 ELSE 0 END) = 1', null, false);
            case 'open':
                return $builder->whereIn('ip_invoices.invoice_status_id', [2, 3]);
            case 'all':
            default:
                return $builder;
        }
    }

    /**
     * Filtro mesi condiviso da lista e totali. Accetta un mese o una lista
     * (selezione multipla con shift+clic nella barra periodo).
     *
     * @param mixed    $builder
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
     * Filtro "note di credito / fatture" condiviso da lista e totali.
     *
     * Una nota di credito è una fattura con invoice_sign = -1 (stessa
     * convenzione di CI3): non è un tipo documento separato, quindi va
     * filtrata sul segno, non sullo stato. 'invoice' esclude le note, ma
     * tiene anche le righe con segno NULL (documenti precedenti al campo).
     *
     * Il segno vive in ip_invoice_amounts, non in ip_invoices. La colonna la
     * indica il chiamante perché i totali aggregano la stessa tabella con
     * l'alias "a", e in SQL un alias sostituisce il nome della tabella: la
     * forma qualificata con il nome intero lì non risolverebbe.
     *
     * @param mixed $builder
     *
     * @return mixed
     */
    private function applySignFilter($builder, string $sign, string $column = 'ip_invoice_amounts.invoice_sign')
    {
        if ($sign === 'credit') {
            return $builder->where($column, '-1');
        }

        if ($sign === 'invoice') {
            return $builder->where('(' . $column . ' IS NULL OR ' . $column . ' <> \'-1\')', null, false);
        }

        return $builder;
    }

    /**
     * @param mixed $builder
     *
     * @return mixed
     */
    private function applyFavouriteFilter($builder, $favourite)
    {
        if ($favourite === '1' || $favourite === 1) {
            return $builder->where('ip_invoices.invoice_is_favourite', true);
        }

        if ($favourite === '0' || $favourite === 0) {
            return $builder->where('(ip_invoices.invoice_is_favourite = FALSE OR ip_invoices.invoice_is_favourite IS NULL)');
        }

        return $builder;
    }

    /**
     * Crea una nuova fattura con record iniziale ip_invoice_amounts
     * e imposte di default (equivalente di create() CI3).
     *
     * Quando il cliente ha preferenze di fatturazione valorizzate
     * (client_default_tax_rate_id, client_default_payment_method_id,
     * client_payment_days) queste sovrascrivono il default globale.
     */
    public function create(?array $dbArray = null, bool $includeInvoiceTaxRates = true): int
    {
        $dbArray ??= [];

        // Preferenze del cliente: sovrascrivono i default globali
        $clientPrefs = $this->resolveClientPreferences($dbArray['client_id'] ?? null);

        if (empty($dbArray['user_id'])) {
            $dbArray['user_id'] = session()->get('user_id') ?? 1;
        }

        if (empty($dbArray['invoice_date_created'])) {
            $dbArray['invoice_date_created'] = date('Y-m-d');
        } else {
            $dbArray['invoice_date_created'] = date_to_mysql($dbArray['invoice_date_created']);
        }

        if (empty($dbArray['invoice_time_created'])) {
            $dbArray['invoice_time_created'] = date('H:i:s');
        }

        if (empty($dbArray['invoice_date_due'])) {
            $dbArray['invoice_date_due'] = $this->getDateDue($dbArray['invoice_date_created'], $clientPrefs['client_payment_days']);
        } else {
            $dbArray['invoice_date_due'] = date_to_mysql($dbArray['invoice_date_due']);
        }

        if (empty($dbArray['invoice_terms'])) {
            // Default per-azienda, non globale: le condizioni di pagamento sono
            // contenuto proprio di ciascuna azienda.
            $dbArray['invoice_terms'] = company_setting('default_invoice_terms', '');
        }

        if (!isset($dbArray['invoice_status_id'])) {
            $dbArray['invoice_status_id'] = 1;
        }

        if (empty($dbArray['invoice_group_id'])) {
            // Gruppo di numerazione per-azienda: evita di ereditare la serie
            // dell'azienda predefinita se una creazione arriva senza group_id.
            $dbArray['invoice_group_id'] = (new InvoiceGroupModel())->defaultGroupId(0, 'default_invoice_group');
        }

        // Metodo di pagamento: 0 o assente = "non scelto" e vince la
        // preferenza del cliente. Il form di creazione ora lo mostra con un
        // campo che nasce sul predefinito: se l'utente ne sceglie uno a mano
        // arriva un id esplicito e resta quello.
        if (empty($dbArray['payment_method'])) {
            $dbArray['payment_method'] = $clientPrefs['client_default_payment_method_id'] ?? 0;
        }

        if (empty($dbArray['invoice_url_key'])) {
            $dbArray['invoice_url_key'] = bin2hex(random_bytes(16));
        }

        $generateInvoiceNumber = (int) get_setting('generate_invoice_number_for_draft', 0);
        if (!empty($dbArray['invoice_number'])) {
            // Numero impostato manualmente o passato esplicitamente
        } elseif ((int) $dbArray['invoice_status_id'] === 1 && $generateInvoiceNumber === 1) {
            $dbArray['invoice_number'] = $this->generateInvoiceNumber((int) $dbArray['invoice_group_id']);
        } elseif ((int) $dbArray['invoice_status_id'] === 1) {
            [$draftNum, $isDraftFlag] = $this->draftNumber();
            if ($draftNum) {
                $dbArray['invoice_number']          = $draftNum;
                $dbArray['invoice_number_is_draft'] = (bool) $isDraftFlag;
            } else {
                $dbArray['invoice_number'] = '';
            }
        } elseif ((int) $dbArray['invoice_status_id'] !== 1) {
            $dbArray['invoice_number'] = $this->generateInvoiceNumber((int) $dbArray['invoice_group_id']);
        }

        // Sconto predefinito del cliente: lo salva sulla fattura per
        // applicarlo alle righe aggiunte successivamente
        if (!isset($dbArray['invoice_discount_percent']) && $clientPrefs['client_discount_default'] !== null) {
            $dbArray['invoice_discount_percent'] = $clientPrefs['client_discount_default'];
        }

        $invoiceId = (int) $this->saveRecord(null, $dbArray);

        // Record ip_invoice_amounts iniziale
        $this->db->table('ip_invoice_amounts')->insert([
            'invoice_id' => $invoiceId,
        ]);

        if ($includeInvoiceTaxRates) {
            $taxRateId = $clientPrefs['client_default_tax_rate_id']
                ?? (int) company_setting('default_invoice_tax_rate', 0);
            if ($taxRateId > 0) {
                $this->db->table('ip_invoice_tax_rates')->insert([
                    'invoice_id'              => $invoiceId,
                    'tax_rate_id'             => $taxRateId,
                    'include_item_tax'        => (int) company_setting('default_include_item_tax', 0),
                    'invoice_tax_rate_amount' => 0,
                ]);
            }
        }

        return $invoiceId;
    }

    /**
     * Recupera le preferenze di fatturazione dal cliente, se presente.
     * La lettura sta in ClientModel::billingPreferencesFor(): stessa fonte
     * per fatture e preventivi, cosi' non possono divergere.
     *
     * @return array{client_default_tax_rate_id: ?int, client_default_payment_method_id: ?int, client_payment_days: ?int, client_discount_default: ?float}
     */
    private function resolveClientPreferences($clientId): array
    {
        return ClientModel::billingPreferencesFor($clientId);
    }

    /**
     * Calcola la data di scadenza a partire dalla data di creazione.
     * Se $clientPaymentDays e' valorizzato sovrascrive il default globale.
     */
    public function getDateDue(string $invoiceDateCreated, ?int $clientPaymentDays = null): string
    {
        $days = $clientPaymentDays ?? (int) get_setting('invoices_due_after', 30);
        $due  = new \DateTime($invoiceDateCreated);
        $due->modify("+{$days} days");

        return $due->format('Y-m-d');
    }

    /**
     * Genera il nuovo numero fattura dal gruppo specificato.
     */
    public function generateInvoiceNumber(int $invoiceGroupId): string
    {
        return (new InvoiceGroupModel())->generateInvoiceNumber($invoiceGroupId);
    }

    /**
     * Helper per recuperare il gruppo fattura.
     */
    public function getInvoiceGroupId(int $invoiceId): ?int
    {
        $invoice = $this->find($invoiceId);

        return $invoice ? (int) ($invoice['invoice_group_id'] ?? 0) : null;
    }
    /**
     * Copia righe, imposte e campi custom da una fattura sorgente a una destinazione
     * (equivalente di copy_invoice() CI3).
     */
    public function copyInvoice(int $sourceId, int $targetId, bool $copyRecurringItemsOnly = false): void
    {
        $itemModel      = new ItemModel();
        $itemAmountModel = new ItemAmountModel();

        // 1. Copia le righe
        $items = $this->db->table('ip_invoice_items')
            ->where('invoice_id', $sourceId)
            ->get()
            ->getResultArray();

        foreach ($items as $item) {
            if ($copyRecurringItemsOnly && empty($item['item_is_recurring'])) {
                continue;
            }

            $dbArray = [
                'invoice_id'            => $targetId,
                'item_tax_rate_id'      => $item['item_tax_rate_id'] ?? 0,
                'item_product_id'       => $item['item_product_id'] ?? null,
                // Il codice scritto sulla riga viaggia con la riga: duplicando
                // una fattura o generandone una ricorrente il codice MEPA
                // dell'ordine non deve sparire lasciando solo quello del
                // prodotto.
                'item_code'             => $item['item_code'] ?? null,
                'item_task_id'          => $item['item_task_id'] ?? null,
                'item_name'             => $item['item_name'],
                'item_description'      => $item['item_description'],
                'item_quantity'         => $item['item_quantity'],
                'item_price'            => $item['item_price'],
                'item_discount_amount'  => $item['item_discount_amount'],
                'item_discount_percent' => $item['item_discount_percent'],
                'item_order'            => $item['item_order'],
                'item_is_recurring'     => $item['item_is_recurring'] ?? 0,
                'item_product_unit'     => $item['item_product_unit'] ?? null,
                'item_product_unit_id'  => $item['item_product_unit_id'] ?? null,
            ];

            $newItemId = (int) $itemModel->insert($dbArray, true);
            $itemAmountModel->calculate($newItemId);
        }

        // 2. Copia le imposte a livello di fattura
        $taxRates = $this->db->table('ip_invoice_tax_rates')
            ->where('invoice_id', $sourceId)
            ->get()
            ->getResultArray();

        foreach ($taxRates as $taxRate) {
            $this->db->table('ip_invoice_tax_rates')->insert([
                'invoice_id'              => $targetId,
                'tax_rate_id'             => $taxRate['tax_rate_id'],
                'include_item_tax'        => $taxRate['include_item_tax'],
                'invoice_tax_rate_amount' => $taxRate['invoice_tax_rate_amount'],
            ]);
        }

        // 3. Copia i campi personalizzati
        $customFields = $this->db->table('ip_invoice_custom')
            ->where('invoice_id', $sourceId)
            ->get()
            ->getResultArray();

        if (!empty($customFields)) {
            $formData = [];
            foreach ($customFields as $field) {
                $formData[$field['invoice_custom_fieldid']] = $field['invoice_custom_fieldvalue'];
            }
            (new InvoiceCustomModel())->saveCustom($targetId, $formData);
        }

        // 4. Ricalcola i totali
        (new InvoiceAmountModel())->calculate($targetId);
    }

    /**
     * Crea una nota di credito da una fattura sorgente invertendo importi e quantità
     * (equivalente di copy_credit_invoice() CI3).
     */
    public function copyCreditInvoice(int $sourceId, int $targetId): void
    {
        $itemModel      = new ItemModel();
        $itemAmountModel = new ItemAmountModel();

        // 1. Copia le righe con quantita' invertita (-1 * qty)
        $items = $this->db->table('ip_invoice_items')
            ->where('invoice_id', $sourceId)
            ->get()
            ->getResultArray();

        foreach ($items as $item) {
            $qty = -1 * (float) $item['item_quantity'];

            $dbArray = [
                'invoice_id'            => $targetId,
                'item_tax_rate_id'      => $item['item_tax_rate_id'] ?? 0,
                'item_product_id'       => $item['item_product_id'] ?? null,
                // Anche la nota di credito riporta il codice della riga
                // stornata: e' cosi' che il documento resta riconoscibile
                // nella contabilita' dell'ente.
                'item_code'             => $item['item_code'] ?? null,
                'item_task_id'          => $item['item_task_id'] ?? null,
                'item_name'             => $item['item_name'],
                'item_description'      => $item['item_description'],
                'item_quantity'         => $qty,
                'item_price'            => $item['item_price'],
                'item_discount_amount'  => $item['item_discount_amount'],
                'item_discount_percent' => $item['item_discount_percent'],
                'item_order'            => $item['item_order'],
                'item_is_recurring'     => $item['item_is_recurring'] ?? 0,
                'item_product_unit'     => $item['item_product_unit'] ?? null,
                'item_product_unit_id'  => $item['item_product_unit_id'] ?? null,
            ];

            $newItemId = (int) $itemModel->insert($dbArray, true);
            $itemAmountModel->calculate($newItemId);
        }

        // 2. Copia le imposte con segno invertito
        $taxRates = $this->db->table('ip_invoice_tax_rates')
            ->where('invoice_id', $sourceId)
            ->get()
            ->getResultArray();

        foreach ($taxRates as $taxRate) {
            $this->db->table('ip_invoice_tax_rates')->insert([
                'invoice_id'              => $targetId,
                'tax_rate_id'             => $taxRate['tax_rate_id'],
                'include_item_tax'        => $taxRate['include_item_tax'],
                'invoice_tax_rate_amount' => -1 * (float) $taxRate['invoice_tax_rate_amount'],
            ]);
        }

        // 3. Copia i campi personalizzati
        $customFields = $this->db->table('ip_invoice_custom')
            ->where('invoice_id', $sourceId)
            ->get()
            ->getResultArray();

        if (!empty($customFields)) {
            $formData = [];
            foreach ($customFields as $field) {
                $formData[$field['invoice_custom_fieldid']] = $field['invoice_custom_fieldvalue'];
            }
            (new InvoiceCustomModel())->saveCustom($targetId, $formData);
        }

        // 4. Ricalcola i totali
        (new InvoiceAmountModel())->calculate($targetId);
    }

    /**
     * Marca la fattura come inviata (stato 2) se e' una bozza e la
     * passa a sola lettura se read_only_toggle == 2
     * (equivalente di mark_sent() CI3).
     */
    public function markSent(int $invoiceId): void
    {
        $invoice = $this->find($invoiceId);

        if (!$invoice) {
            return;
        }

        if ((int) $invoice['invoice_status_id'] === 1) {
            $this->update($invoiceId, ['invoice_status_id' => 2]);
        }

        if ((int) get_setting('read_only_toggle', 0) === 2 && (int) $invoice['is_read_only'] !== 1) {
            $this->update($invoiceId, ['is_read_only' => 1]);
        }
    }

    /**
     * Marca la fattura come vista (stato 3) se e' inviata e la passa
     * a sola lettura se read_only_toggle == 3
     * (equivalente di mark_viewed() CI3).
     */
    public function markViewed(int $invoiceId): void
    {
        $invoice = $this->find($invoiceId);

        if (!$invoice) {
            return;
        }

        if ((int) $invoice['invoice_status_id'] === 2) {
            $this->update($invoiceId, ['invoice_status_id' => 3]);
        }

        if ((int) get_setting('read_only_toggle', 0) === 3 && (int) $invoice['is_read_only'] !== 1) {
            $this->update($invoiceId, ['is_read_only' => 1]);
        }
    }

    /**
     * Genera il numero fattura se applicabile: bozza senza numero e
     * setting generate_invoice_number_for_draft == 0
     * (equivalente di generate_invoice_number_if_applicable() CI3).
     */
    public function generateInvoiceNumberIfApplicable(int $invoiceId): void
    {
        $invoice = $this->find($invoiceId);

        if (!$invoice) {
            return;
        }

        if ((int) $invoice['invoice_status_id'] === 1
            && (empty($invoice['invoice_number']) || db_bool($invoice['invoice_number_is_draft'] ?? false))) {
            if ((int) get_setting('generate_invoice_number_for_draft', 0) === 0) {
                $invoiceNumber = $this->generateInvoiceNumber((int) $invoice['invoice_group_id']);
                $this->update($invoiceId, [
                    'invoice_number'          => $invoiceNumber,
                    'invoice_number_is_draft' => false,
                ]);
            }
        }
    }

    /**
     * Numero provvisorio dalla serie bozze, se configurata.
     *
     * @return array{0: string|null, 1: int} numero (null se nessuna serie) e flag "provvisorio"
     */
    public function draftNumber(): array
    {
        $draftGroupId = (int) company_setting('draft_invoice_group', 0);

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
    public function finalizeNumber(int $invoiceId): void
    {
        $invoice = $this->find($invoiceId);

        if (!$invoice) {
            return;
        }

        if (empty($invoice['invoice_number']) || db_bool($invoice['invoice_number_is_draft'] ?? false)) {
            $this->update($invoiceId, [
                'invoice_number'          => $this->generateInvoiceNumber((int) $invoice['invoice_group_id']),
                'invoice_number_is_draft' => false,
            ]);
        }
    }

    /**
     * Pagamenti di una fattura con metodo di pagamento
     * (equivalente di get_payments() CI3).
     */
    public function getPayments(int $invoiceId): array
    {
        return $this->db->table('ip_payments')
            ->select('ip_payments.*, ip_payment_methods.payment_method_name')
            ->join('ip_payment_methods', 'ip_payment_methods.payment_method_id = ip_payments.payment_method_id', 'left')
            ->where('ip_payments.invoice_id', $invoiceId)
            ->orderBy('ip_payments.payment_date', 'ASC')
            ->orderBy('ip_payments.payment_id', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Scadenze aperte con saldo residuo (usato da Schedule e CalendarService).
     */
    public function getDueSchedule(?int $year = null): array
    {
        $this->withFullJoins()
            ->whereNotIn('ip_invoices.invoice_status_id', [1, 4])
            ->where('ip_invoice_amounts.invoice_balance >', 0);

        if ($year !== null && $year > 0) {
            $this->where(year_sql('ip_invoices.invoice_date_due'), $year);
        }

        $this->scopeToCompany();

        return $this->orderBy('ip_invoices.invoice_date_due', 'ASC')
            ->orderBy('ip_invoices.invoice_id', 'ASC')
            ->findAll();
    }

    // ------------------------------------------------------------------
    // Filtri (equivalente dei metodi is_*() di CI3)
    // ------------------------------------------------------------------

    public function isOpen(): static
    {
        $this->whereIn('ip_invoices.invoice_status_id', [2, 3]);

        return $this;
    }

    public function isDraft(): static
    {
        $this->where('ip_invoices.invoice_status_id', 1);

        return $this;
    }

    public function isSent(): static
    {
        $this->where('ip_invoices.invoice_status_id', 2);

        return $this;
    }

    public function isViewed(): static
    {
        $this->where('ip_invoices.invoice_status_id', 3);

        return $this;
    }

    public function isPaid(): static
    {
        $this->where('ip_invoices.invoice_status_id', 4);

        return $this;
    }

    public function isOverdue(): static
    {
        $this->where('(CASE WHEN ip_invoices.invoice_status_id NOT IN (1,4) AND (CURRENT_DATE - ip_invoices.invoice_date_due) > 0 THEN 1 ELSE 0 END) = 1', null, false);

        return $this;
    }

    public function guestVisible(): static
    {
        $this->whereIn('ip_invoices.invoice_status_id', [2, 3, 4]);

        return $this;
    }

    /**
     * Fatture di un cliente.
     */
    public function byClient(int $clientId): array
    {
        return $this->withFullJoins()
            ->where('ip_invoices.client_id', $clientId)
            ->scopeToCompany()
            ->orderBy('ip_invoices.invoice_date_created', 'DESC')
            ->orderBy('ip_invoices.invoice_id', 'DESC')
            ->findAll();
    }

    public function statuses(): array
    {
        return [
            '1' => ['label' => trans('draft'), 'class' => 'draft', 'href' => 'invoices/status/draft'],
            '2' => ['label' => trans('sent'), 'class' => 'sent', 'href' => 'invoices/status/sent'],
            '3' => ['label' => trans('viewed'), 'class' => 'viewed', 'href' => 'invoices/status/viewed'],
            '4' => ['label' => trans('paid'), 'class' => 'paid', 'href' => 'invoices/status/paid'],
        ];
    }

    public function getLatest(int $limit = 10, int $year = 0): array
    {
        $this->withFullJoins()->limit($limit)->orderBy('ip_invoices.invoice_date_created', 'DESC')->orderBy('ip_invoices.invoice_id', 'DESC');
        if ($year > 0) {
            $this->where(year_sql('ip_invoices.invoice_date_created'), $year);
        }
        $this->scopeToCompany();

        return $this->findAll();
    }

    /**
     * Anni con almeno una fattura, dal più recente (per il selettore anno
     * globale in topbar, vedi available_years() in ip_helpers.php).
     */
    public function getAvailableYears(): array
    {
        $rows = $this->companyBuilder()
            ->select('DISTINCT EXTRACT(YEAR FROM invoice_date_created) AS y', false)
            ->orderBy('y', 'DESC')
            ->get()
            ->getResultArray();

        return array_map('intval', array_column($rows, 'y'));
    }

    /**
     * Fatture dei clienti assegnati a un utente guest, filtrate per stato
     * (open = inviato/visto, paid, overdue). Usato dal modulo guest.
     */
    public function getForGuestClients(array $clientIds, string $status = 'open'): array
    {
        if (empty($clientIds)) {
            return [];
        }

        $this->withFullJoins()->whereIn('ip_invoices.client_id', $clientIds);
        $this->applyGuestStatus($status);

        return $this->orderBy('ip_invoices.invoice_id', 'DESC')->findAll();
    }

    /**
     * Quante fatture vedrebbe getForGuestClients() con lo stesso filtro.
     *
     * La dashboard del portale mostra solo il numero: caricare l'elenco con
     * tutti i join per poi contarlo in PHP costava l'intero archivio del
     * cliente a ogni apertura. I filtri di stato guardano solo ip_invoices,
     * quindi il conteggio non ha bisogno dei join.
     */
    public function countForGuestClients(array $clientIds, string $status = 'open'): int
    {
        if (empty($clientIds)) {
            return 0;
        }

        $this->whereIn('ip_invoices.client_id', $clientIds);
        $this->applyGuestStatus($status);

        return $this->countAllResults();
    }

    /** Filtro di stato del portale: un posto solo per elenco e conteggio. */
    private function applyGuestStatus(string $status): void
    {
        switch ($status) {
            case 'paid':
                $this->isPaid();
                break;
            case 'overdue':
                $this->isOverdue();
                break;
            default:
                $this->isOpen();
                break;
        }
    }

    /**
     * Ricerca per parole chiave su numero, date, cliente e importi
     * (equivalente di filter_invoices() CI3).
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
                "CONCAT_WS('^', LOWER(ip_invoices.invoice_number), ip_invoices.invoice_date_created,
                    ip_invoices.invoice_date_due, LOWER(CONCAT(ip_clients.client_name, ' ', ip_clients.client_surname)),
                    COALESCE(ip_invoice_amounts.invoice_total, '0.00'),
                    COALESCE(ip_invoice_amounts.invoice_balance, '0.00'))",
                $keyword
            );
        }

        $this->scopeToCompany();

        return $this->orderBy('ip_invoices.invoice_id', 'DESC')->findAll();
    }

    /**
     * Elimina la fattura e i relativi orfani (items + amounts, imposte,
     * campi custom, uploads) come delete_orphans() in CI3.
     */
    public function delete($id = null, bool $purge = false)
    {
        $invoice = $id ? $this->find($id) : null;

        // Il risultato del model va restituito: chi chiama lo controlla, e
        // senza return una cancellazione fallita passava per riuscita.
        $result = parent::delete($id, $purge);

        if (!$invoice) {
            return $result;
        }

        $invoiceId = (int) $invoice['invoice_id'];

        $this->db->table('ip_invoice_items')->where('invoice_id', $invoiceId)->delete();
        $this->db->table('ip_invoice_item_amounts')->where(
            'item_id IN (SELECT item_id FROM ip_invoice_items WHERE invoice_id = ' . $invoiceId . ')',
            null,
            false
        )->delete();
        $this->db->table('ip_invoice_amounts')->where('invoice_id', $invoiceId)->delete();
        $this->db->table('ip_invoice_tax_rates')->where('invoice_id', $invoiceId)->delete();
        $this->db->table('ip_invoice_custom')->where('invoice_id', $invoiceId)->delete();

        // Preventivi che indicavano questa fattura: senza azzerare il
        // riferimento il preventivo resta "fatturato" per sempre e la sua
        // vista offre un collegamento a un documento che non esiste piu'.
        // Lo zero e' il valore "nessuna fattura" della colonna, che e'
        // NOT NULL con default 0.
        $this->db->table('ip_quotes')->where('invoice_id', $invoiceId)->update(['invoice_id' => 0]);

        // Uploads orfani della fattura (tramite url key)
        if (!empty($invoice['invoice_url_key'])) {
            $this->db->table('ip_uploads')->where('url_key', $invoice['invoice_url_key'])->delete();
        }

        return $result;
    }

    /**
     * Callback eventi CI4: invalida la cache della pagina Finance.
     */
    /**
     * Callback eventi CI4: cifra la password del documento prima di scriverla.
     */
    protected function encryptDocumentPassword(array $data): array
    {
        $value = $data['data']['invoice_password'] ?? null;

        if (!empty($value) && strpos((string) $value, 'enc:') !== 0) {
            $data['data']['invoice_password'] = encrypt_secret((string) $value);
        }

        return $data;
    }

    protected function clearFinanceCache(array $data): array
    {
        if (function_exists('invalidate_finance_cache')) {
            invalidate_finance_cache();
        }

        return $data;
    }

    // =========================================================================
    // Finance — metodi per la pagina "Utile e imposte"
    // =========================================================================

    /**
     * Ricavi del periodo: netto, IVA, lordo e incassato.
     *
     * Il netto è lordo meno IVA, non la somma di invoice_item_subtotal: quella
     * è l'imponibile delle righe, che non conosce lo sconto di documento (che
     * riduce il ricavo) né il contributo cassa (che lo aumenta). Con la somma
     * delle righe il prospetto e la dashboard raccontavano due fatturati
     * diversi sugli stessi documenti, e il netto non tornava col lordo.
     *
     * 'collected' resta un dato di cassa e comprende l'IVA: è l'importo
     * effettivamente versato dai clienti, non la sua quota netta.
     *
     * @return array{net: float, tax: float, gross: float, collected: float}
     */
    public function incomeTotalsForPeriod(string $from, string $to): array
    {
        $scope = company_scope_sql('i');

        $row = $this->db->query("
            SELECT COALESCE(SUM(a.invoice_total), 0)                            AS gross,
                   COALESCE(SUM(COALESCE(a.invoice_item_tax_total, 0)
                              + COALESCE(a.invoice_tax_total, 0)), 0)         AS tax,
                   COALESCE(SUM(a.invoice_paid), 0)                             AS collected
            FROM ip_invoices i
            LEFT JOIN ip_invoice_amounts a ON a.invoice_id = i.invoice_id
            WHERE i.invoice_date_created >= ? AND i.invoice_date_created <= ?
              AND i.invoice_status_id <> 1{$scope}",
            [$from, $to . ' 23:59:59']
        )->getRowArray();

        $gross = (float) ($row['gross'] ?? 0);
        $tax   = (float) ($row['tax'] ?? 0);

        return [
            'net'       => $gross - $tax,
            'tax'       => $tax,
            'gross'     => $gross,
            'collected' => (float) ($row['collected'] ?? 0),
        ];
    }

    /**
     * Entrate mese per mese per un anno specifico.
     *
     * @return array<int, float> Importi indicizzati per mese (1-12)
     */
    public function incomeByMonth(int $year): array
    {
        $months = array_fill(1, 12, 0.0);

        $scope = company_scope_sql('i');

        $rows = $this->db->query("
            SELECT EXTRACT(MONTH FROM i.invoice_date_created) AS m,
                   SUM(COALESCE(a.invoice_total, 0)
                       - COALESCE(a.invoice_item_tax_total, 0)
                       - COALESCE(a.invoice_tax_total, 0)) AS total
            FROM ip_invoices i
            LEFT JOIN ip_invoice_amounts a ON a.invoice_id = i.invoice_id
            WHERE EXTRACT(YEAR FROM i.invoice_date_created) = ? AND i.invoice_status_id <> 1{$scope}
            GROUP BY m", [$year])->getResultArray();

        foreach ($rows as $row) {
            $months[(int) $row['m']] = (float) $row['total'];
        }

        return $months;
    }
}