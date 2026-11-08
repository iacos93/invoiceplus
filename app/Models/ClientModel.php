<?php

namespace App\Models;

class ClientModel extends IpBaseModel
{
    protected $table = 'ip_clients';
    protected $primaryKey = 'client_id';

    // company_id sta in $allowedFields perché senza il Model lo scarta in
    // silenzio: withCompany() lo aggiungeva in saveRecord(), insert() lo
    // toglieva, e ogni cliente nasceva con company_id NULL -- cioè visibile
    // a tutte le aziende. Dal POST non può arrivare comunque: dbArrayFromPost()
    // filtra sulle chiavi di validationRulesLegacy(), dove company_id non c'è,
    // e qui sotto lo si toglie anche esplicitamente. client_gender esiste in tabella ma non ha un campo nel form:
    // resta scrivibile per altri path (es. import), ma non lo intercetta
    // validationRulesLegacy() qui sotto, quindi da questo form non arriva
    // mai. client_password non è una colonna di questa tabella (il blocco
    // sotto in dbArrayFromPost() è codice morto rimasto dal porting: lo
    // lascio così com'è, ma niente 'client_password' nell'elenco o insert()
    // fallirebbe su una colonna inesistente.
    protected $allowedFields = [
        'client_date_created', 'client_date_modified',
        'client_name', 'client_surname', 'client_type', 'client_language',
        'client_address_1', 'client_address_2', 'client_city', 'client_state',
        'client_zip', 'client_country',
        'client_phone', 'client_fax', 'client_mobile', 'client_email', 'client_web',
        'client_vat_id', 'client_tax_code', 'client_iban',
        'client_active', 'client_birthdate', 'client_gender',
        'client_split_payment',
        'client_default_tax_rate_id', 'client_default_payment_method_id',
        'client_payment_days', 'client_discount_default',
        'client_sdi_code', 'client_sdi_pec',
        'company_id',
    ];

    // Governano anche cosa dbArrayFromPost() (via IpBaseModel) preleva dal
    // POST del form: senza queste regole, ogni campo tranne quelli
    // impostati a mano più sotto (client_active/password/birthdate/
    // split_payment) veniva scartato in silenzio — il form salvava un
    // cliente vuoto invece di dare un errore, quindi il baco è passato
    // inosservato.
    protected $validationRules = [
        'client_name'          => 'required',
        'client_surname'       => 'permit_empty',
        'client_type'          => 'permit_empty|in_list[company,person,pa]',
        'client_language'      => 'permit_empty',
        'client_address_1'     => 'permit_empty',
        'client_address_2'     => 'permit_empty',
        'client_city'          => 'permit_empty',
        'client_state'         => 'permit_empty',
        'client_zip'           => 'permit_empty',
        'client_country'       => 'permit_empty',
        'client_phone'         => 'permit_empty',
        'client_fax'           => 'permit_empty',
        'client_mobile'        => 'permit_empty',
        'client_email'         => 'permit_empty|valid_email',
        'client_web'           => 'permit_empty',
        'client_vat_id'        => 'permit_empty',
        'client_tax_code'      => 'permit_empty',
        'client_iban'          => 'permit_empty',
        'client_active'        => 'permit_empty',
        'client_birthdate'     => 'permit_empty',
        'client_split_payment'             => 'permit_empty|in_list[auto,yes,no]',
        'client_default_tax_rate_id'       => 'permit_empty|is_natural',
        'client_default_payment_method_id' => 'permit_empty|is_natural',
        'client_payment_days'              => 'permit_empty|is_natural',
        'client_discount_default'          => 'permit_empty|decimal',
        'client_sdi_code'                  => 'permit_empty|max_length[7]',
        'client_sdi_pec'                   => 'permit_empty|valid_email',
    ];

    protected $validationMessages = [
        'client_name' => [
            'required' => "Il nome (o la ragione sociale) del cliente e' obbligatorio",
        ],
        'client_email' => [
            'valid_email' => "L'indirizzo email non e' valido",
        ],
        'client_sdi_pec' => [
            'valid_email' => "L'indirizzo PEC non e' valido",
        ],
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * client_date_created/modified sono NOT NULL senza default: vanno
     * impostati qui perché dbArrayFromPost() non sa se sta per fare un
     * insert o un update (non riceve l'id), mentre saveRecord() sì.
     */
    public function saveRecord(?int $id, array $dbArray)
    {
        $dbArray['client_date_modified'] = date('Y-m-d H:i:s');
        if (!$id) {
            $dbArray['client_date_created'] = date('Y-m-d H:i:s');
        }

        return parent::saveRecord($id, $dbArray);
    }

    /**
     * Cliente singolo con fullname e totali (fatturato, pagato, saldo),
     * per la pagina di dettaglio.
     *
     * A differenza di with_total()/with_total_paid()/with_total_balance()
     * (pensate per liste di N clienti, dove ciascuna resta per forza una
     * subquery correlata separata eseguita riga per riga), qui il cliente
     * e' uno solo: le tre somme si calcolano con un'unica scansione delle
     * sue fatture, tramite una derived table già filtrata su client_id
     * invece di tre subquery indipendenti (una per colonna) che
     * ripetevano ciascuna il proprio JOIN con ip_invoices.
     */
    public function getByIdWithTotals(int $clientId): ?array
    {
        // Perimetro azienda su entrambi i livelli: su ip_clients, altrimenti
        // cambiando l'id nell'URL si apriva la scheda di un cliente di un'altra
        // azienda; e sulla derived table, altrimenti i totali in cima alla
        // scheda sommavano fatture di aziende diverse mentre l'elenco sotto ne
        // mostrava una sola -- l'intestazione contraddiceva il corpo della
        // stessa pagina. Qui l'SQL e' scritto a mano, quindi il perimetro passa
        // da company_scope_sql() e non da scopeToCompany().
        //
        // Query diretta (come altrove nel modulo fiscale) invece del query
        // builder: la derived table filtrata su client_id passata a join()
        // dipende da come CI4 fa il escaping degli identificatori quando il
        // "nome tabella" comincia per "(", comportamento non usato altrove
        // in questo codebase — meglio scrivere l'SQL per esteso che fidarsi
        // di un pattern mai verificato in questo progetto.
        $row = $this->db->query(
            "SELECT ip_clients.*,
                    CONCAT(ip_clients.client_name, ' ', ip_clients.client_surname) AS client_fullname,
                    COALESCE(client_totals.client_invoice_total, 0) AS client_invoice_total,
                    COALESCE(client_totals.client_invoice_paid, 0) AS client_invoice_paid,
                    COALESCE(client_totals.client_invoice_balance, 0) AS client_invoice_balance
             FROM ip_clients
             LEFT JOIN (
                 SELECT ii.client_id,
                        SUM(iva.invoice_total) AS client_invoice_total,
                        SUM(iva.invoice_paid) AS client_invoice_paid,
                        SUM(iva.invoice_balance) AS client_invoice_balance
                 FROM ip_invoices ii
                 JOIN ip_invoice_amounts iva ON iva.invoice_id = ii.invoice_id
                 WHERE ii.client_id = " . (int) $clientId . company_scope_sql('ii') . '
                 GROUP BY ii.client_id
             ) client_totals ON client_totals.client_id = ip_clients.client_id
             WHERE ip_clients.client_id = ' . (int) $clientId . company_scope_sql('ip_clients')
        )->getRowArray();

        return $row ?: null;
    }

    /**
     * Aggiunge fullname e saldo aperto (somma dei saldi fattura) a ogni riga,
     * per le liste (equivalente di with_total_balance() CI3). Il saldo e'
     * una subquery correlata anziche' un JOIN aggregato con GROUP BY: la
     * lista puo' ordinare/paginare su colonne del cliente, e un GROUP BY
     * per cliente imporrebbe di ripetere in ORDER BY ogni colonna non
     * aggregata.
     */
    public function withTotalBalance(): static
    {
        $this->select(
            "ip_clients.*, CONCAT(ip_clients.client_name, ' ', ip_clients.client_surname) AS client_fullname,
             COALESCE((SELECT SUM(iva.invoice_balance) FROM ip_invoice_amounts iva
                 JOIN ip_invoices ii ON ii.invoice_id = iva.invoice_id
                 WHERE ii.client_id = ip_clients.client_id), 0) AS client_invoice_balance",
            false
        );

        return $this;
    }

    /**
     * Lista clienti ordinata per nome (equivalente di default_order_by()
     * CI3), ristretta all'azienda attiva.
     */
    public function getAllOrdered(?int $perPage = null, int $page = 1): array
    {
        $this->scopeToCompany();
        $this->orderBy('ip_clients.client_name', 'ASC')
             ->orderBy('ip_clients.client_surname', 'ASC');

        return $this->listFetch($perPage, $page);
    }

    public function isActive(): static
    {
        $this->where('client_active', 1);
        return $this;
    }

    public function isInactive(): static
    {
        $this->where('client_active', 0);
        return $this;
    }

    /**
     * Ricerca per parole chiave su nome, cognome, fullname, email e saldo
     * (equivalente di filter_clients() CI3).
     */
    public function filterByKeywords(array $keywords): array
    {
        $this->select("ip_clients.*, CONCAT(ip_clients.client_name, ' ', ip_clients.client_surname) AS client_fullname", false)
            ->withTotalBalance();

        $this->scopeToCompany();

        foreach ($keywords as $keyword) {
            if ($keyword === '') {
                continue;
            }

            $keyword = strtolower($keyword);
            $this->likeExpression(
                "CONCAT_WS('^', LOWER(CONCAT(ip_clients.client_name, ' ', ip_clients.client_surname)),
                    LOWER(ip_clients.client_name), LOWER(ip_clients.client_surname),
                    LOWER(ip_clients.client_email), ip_clients.client_phone, ip_clients.client_active,
                    COALESCE((SELECT SUM(invoice_balance) FROM ip_invoice_amounts
                        WHERE invoice_id IN (SELECT invoice_id FROM ip_invoices
                        WHERE ip_invoices.client_id = ip_clients.client_id)), 0))",
                $keyword
            );
        }

        return $this->orderBy('ip_clients.client_name', 'ASC')->findAll();
    }

    /**
     * Verifica se esiste gia' un cliente con lo stesso nome e cognome
     * (equivalente del check anti-duplicato nel controller CI3).
     */
    public function findDuplicate(string $name, string $surname): ?array
    {
        // Senza perimetro azienda questo controllo lavorava sull'anagrafica di
        // tutti i soggetti giuridici: da un lato impediva a un'azienda di
        // creare un cliente omonimo di quello di un'altra, dall'altro l'import
        // contatti agganciava — e sovrascriveva — la scheda di un'altra
        // azienda. Stesso perimetro di SupplierModel::findExisting().
        return $this->scopeToCompany()
            ->where('client_name', $name)
            ->where('client_surname', $surname)
            ->first();
    }

    /**
     * Ultimi $limit clienti attivi (equivalente di get_latest() in CI3).
     */
    public function getLatest(int $limit = 5): array
    {
        return $this->select("ip_clients.*, CONCAT(ip_clients.client_name, ' ', ip_clients.client_surname) AS client_fullname", false)
            ->scopeToCompany()
            ->where('client_active', 1)
            ->orderBy('ip_clients.client_id', 'DESC')
            ->limit($limit)
            ->findAll();
    }

    /**
     * Assegna il cliente a tutti gli utenti con user_all_clients = 1
     * (equivalente di Mdl_User_Clients::get_users_all_clients() in CI3).
     */
    public function assignToAllUsers(int $clientId): void
    {
        $users = $this->db->table('ip_users')
            ->select('user_id')
            ->where('user_all_clients', 1)
            ->get()
            ->getResultArray();

        foreach ($users as $user) {
            $exists = $this->db->table('ip_user_clients')
                ->where('user_id', $user['user_id'])
                ->where('client_id', $clientId)
                ->countAllResults();

            if ($exists === 0) {
                $this->db->table('ip_user_clients')->insert([
                    'user_id'   => $user['user_id'],
                    'client_id' => $clientId,
                ]);
            }
        }
    }

    /**
     * Costruisce l'array da salvare su DB a partire dal POST:
     * client_active di default 0, password hashatta con bcrypt se
     * presente, data di nascita convertita in formato MySQL e AVS
     * normalizzato senza separatori (come db_array() + callback in CI3).
     */
    public function dbArrayFromPost(array $post): array
    {
        $dbArray = parent::dbArrayFromPost($post);

        // L'azienda la decide withCompany() in creazione, mai il form.
        unset($dbArray['company_id']);

        if (!array_key_exists('client_active', $dbArray)) {
            $dbArray['client_active'] = 0;
        }

        if (isset($dbArray['client_password']) && $dbArray['client_password'] !== '') {
            $dbArray['client_password'] = password_hash($dbArray['client_password'], PASSWORD_BCRYPT);
        } else {
            unset($dbArray['client_password']);
        }

        if (!empty($dbArray['client_birthdate'])) {
            $dbArray['client_birthdate'] = date_to_mysql($dbArray['client_birthdate']);
        }

        // Un radio/select con validazione in_list scartato dal POST (nessuna
        // opzione selezionata) sparirebbe da dbArray invece di salvare
        // 'auto': la colonna ha gia' quel default, ma renderlo esplicito qui
        // evita di dipendere in silenzio dal default della colonna.
        if (empty($dbArray['client_split_payment'])) {
            $dbArray['client_split_payment'] = 'auto';
        }

        // Preferenze fatturazione: un campo vuoto = NULL = "usa il default
        // globale"; un valore esplicito sovrascrive il default per questo
        // cliente. Senza questa normalizzazione dbArrayFromPost() lascerebbe
        // la stringa vuota, che in una colonna INT finirebbe come 0 — e zero
        // è un valore legittimo (es. 0 giorni di pagamento), diverso da
        // "non impostato".
        foreach (['client_default_tax_rate_id', 'client_default_payment_method_id', 'client_payment_days'] as $nullable) {
            if (array_key_exists($nullable, $dbArray) && ($dbArray[$nullable] === '' || $dbArray[$nullable] === null)) {
                $dbArray[$nullable] = null;
            }
        }
        if (array_key_exists('client_discount_default', $dbArray) && ($dbArray['client_discount_default'] === '' || $dbArray['client_discount_default'] === null)) {
            $dbArray['client_discount_default'] = null;
        }

        return $dbArray;
    }

    /**
     * Se lo split payment si applica a questo cliente (art. 17-ter DPR
     * 633/72): 'yes'/'no' forzano la scelta, 'auto' (o assente, per i
     * clienti creati prima di questo campo) segue il tipo cliente — solo la
     * pubblica amministrazione è automatica, perché è l'unico caso in cui lo
     * split payment è un obbligo che discende univocamente dal tipo. Le
     * società private soggette per legge (partecipate pubbliche, quotate)
     * non si riconoscono dal tipo cliente: per loro serve forzare 'yes' a
     * mano sulla scheda.
     *
     * Statico e su un array, non sul record caricato: così si verifica senza
     * toccare il database (tests/unit/ClientModelTest.php) e si può
     * applicare anche a un cliente che non è ancora stato salvato.
     *
     * @param array<string, mixed> $client
     */
    public static function splitPaymentApplies(array $client): bool
    {
        $mode = $client['client_split_payment'] ?? 'auto';

        return match ($mode) {
            'yes'   => true,
            'no'    => false,
            default => ($client['client_type'] ?? '') === 'pa',
        };
    }

    /**
     * Restituisce le preferenze di fatturazione del cliente (tax rate,
     * payment method, giorni scadenza, sconto) che sovrascrivono i
     * default globali. I campi NULL significano "usa il default globale".
     *
     * @return array{client_default_tax_rate_id: ?int, client_default_payment_method_id: ?int, client_payment_days: ?int, client_discount_default: ?float}
     */
    public static function billingPreferences(array $client): array
    {
        return [
            'client_default_tax_rate_id'       => isset($client['client_default_tax_rate_id']) && $client['client_default_tax_rate_id'] !== '' ? (int) $client['client_default_tax_rate_id'] : null,
            'client_default_payment_method_id' => isset($client['client_default_payment_method_id']) && $client['client_default_payment_method_id'] !== '' ? (int) $client['client_default_payment_method_id'] : null,
            'client_payment_days'              => isset($client['client_payment_days']) && $client['client_payment_days'] !== '' ? (int) $client['client_payment_days'] : null,
            'client_discount_default'          => isset($client['client_discount_default']) && $client['client_discount_default'] !== '' ? (float) $client['client_discount_default'] : null,
        ];
    }

    /**
     * Preferenze di fatturazione lette dal database per un cliente.
     * Punto unico di lettura usato da InvoiceModel::create() e
     * QuoteModel::create(): la query sta qui e non duplicata nei due
     * modelli, cosi' una variazione (per esempio il perimetro azienda)
     * non puo' essere applicata a un documento e dimenticata sull'altro.
     * Cliente inesistente o senza id = insieme vuoto, come "nessuna
     * preferenza": i create() ripiegano sui default globali.
     *
     * @return array{client_default_tax_rate_id: ?int, client_default_payment_method_id: ?int, client_payment_days: ?int, client_discount_default: ?float}
     */
    public static function billingPreferencesFor($clientId): array
    {
        $empty = [
            'client_default_tax_rate_id'       => null,
            'client_default_payment_method_id' => null,
            'client_payment_days'              => null,
            'client_discount_default'          => null,
        ];

        if (empty($clientId)) {
            return $empty;
        }

        $row = (new static())->db->table('ip_clients')
            ->select('client_default_tax_rate_id, client_default_payment_method_id, client_payment_days, client_discount_default')
            ->where('client_id', (int) $clientId)
            ->get()
            ->getRowArray();

        return $row ? self::billingPreferences($row) : $empty;
    }

    /**
     * Elimina il cliente e i relativi orfani come delete_orphans() in CI3:
     * note, assegnazioni utenti e campi custom.
     */
    public function delete($id = null, bool $purge = false)
    {
        parent::delete($id, $purge);

        if ($id) {
            $this->db->table('ip_client_notes')->where('client_id', $id)->delete();
            $this->db->table('ip_user_clients')->where('client_id', $id)->delete();
            $this->db->table('ip_client_custom')->where('client_id', $id)->delete();
        }
    }
}