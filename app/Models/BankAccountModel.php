<?php

namespace App\Models;

/**
 * BankAccountModel — conti di saldo (coordinate di pagamento).
 *
 * Sostituisce l'unico IBAN che stava nel profilo utente: i conti sono
 * un'anagrafica, se ne può indicare uno predefinito e sceglierne uno diverso
 * sulla singola fattura.
 */
class BankAccountModel extends IpBaseModel
{
    protected $table            = 'ip_bank_accounts';
    protected $primaryKey       = 'bank_account_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'bank_account_name',
        'bank_account_holder',
        'bank_account_bank',
        'bank_account_iban',
        'bank_account_bic',
        'bank_account_sia_code',
        // Punto di partenza del saldo: quello che si legge dall'estratto conto
        // il giorno in cui si comincia a tenere la prima nota, e da quando vale.
        'bank_account_opening_balance',
        'bank_account_opening_date',
        'bank_account_note',
        'bank_account_is_default',
        'company_id',
    ];

    // dbArrayFromPost() tiene solo i campi presenti qui: un campo senza regola
    // verrebbe scartato in silenzio al salvataggio, anche se compilato nel form.
    protected $validationRules = [
        'bank_account_name'       => 'required|max_length[100]',
        'bank_account_holder'     => 'permit_empty|max_length[150]',
        'bank_account_bank'       => 'permit_empty|max_length[150]',
        // L'IBAN salvato viene copiato tale e quale nel nodo <IBAN> della
        // fattura elettronica: se e' errato lo scarto arriva dallo SdI giorni
        // dopo, a fattura gia' numerata. Meglio accorgersene qui.
        'bank_account_iban'       => 'permit_empty|valid_iban',
        'bank_account_bic'        => 'permit_empty|alpha_numeric|min_length[8]|max_length[11]',
        // Il SIA e' di lunghezza fissa: cinque caratteri assegnati dalla banca.
        // Uno piu' corto passa dritto nel tracciato e sposta i campi a destra.
        // La regola e' in FiscalRules perche' lo standard CBI esclude la lettera
        // O (si confonderebbe con lo zero) e alpha_numeric la lasciava passare.
        'bank_account_sia_code'   => 'permit_empty|valid_sia_code',
        // Il segno fa parte del dato: un conto in rosso si apre in negativo.
        'bank_account_opening_balance' => 'permit_empty|decimal',
        'bank_account_opening_date'    => 'permit_empty|valid_date[Y-m-d]',
        'bank_account_note'       => 'permit_empty',
        'bank_account_is_default' => 'permit_empty',
    ];

    protected $validationMessages = [
        'bank_account_name' => [
            'required'   => 'Il nome del conto è obbligatorio',
            'max_length' => 'Il nome non può superare i 100 caratteri',
        ],
        'bank_account_bic' => [
            'alpha_numeric' => 'Il BIC contiene solo lettere e cifre',
            'min_length'    => 'Il BIC è di 8 o 11 caratteri',
            'max_length'    => 'Il BIC è di 8 o 11 caratteri',
        ],
        // Nessun messaggio qui: valid_sia_code() descrive da sé che cosa non va
        // (cinque caratteri alfanumerici, senza la O), e un testo generico al
        // suo posto nasconderebbe proprio il caso da correggere.
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * Il saldo arriva dal form nel formato locale (1.234,56) e come data
     * italiana: qui torna nei due formati che il database e i calcoli si
     * aspettano. Una data vuota resta vuota: «da quando vale il saldo» è
     * facoltativo, e inventare oggi come data di apertura falserebbe la
     * lettura di un conto vecchio.
     */
    public function dbArrayFromPost(array $post): array
    {
        $dbArray = parent::dbArrayFromPost($post);

        if (array_key_exists('bank_account_opening_balance', $dbArray)) {
            $importo = $dbArray['bank_account_opening_balance'];
            $dbArray['bank_account_opening_balance'] = ($importo === null || trim((string) $importo) === '')
                ? null
                : standardize_amount($importo);
        }

        if (! empty($dbArray['bank_account_opening_date'])) {
            $dbArray['bank_account_opening_date'] = date_to_mysql($dbArray['bank_account_opening_date']);
        } else {
            $dbArray['bank_account_opening_date'] = null;
        }

        return $dbArray;
    }

    public function getAllOrdered(?int $perPage = null, int $page = 1): array
    {
        $this->scopeToCompany()
             ->orderBy('bank_account_is_default', 'DESC')
             ->orderBy('bank_account_name', 'ASC');

        return $this->listFetch($perPage, $page);
    }

    /**
     * Conti utilizzabili per generare flussi RiBa: devono avere sia IBAN
     * dell'azienda creditrice sia codice SIA assegnato dalla banca.
     * Il perimetro aziendale è lo stesso di getAllOrdered().
     *
     * @return list<array<string, mixed>>
     */
    public function getRibaEligibleOrdered(): array
    {
        return $this->scopeToCompany()
                    ->where('bank_account_iban IS NOT NULL', null, false)
                    ->where('bank_account_iban !=', '')
                    ->where('bank_account_sia_code IS NOT NULL', null, false)
                    ->where('bank_account_sia_code !=', '')
                    ->orderBy('bank_account_is_default', 'DESC')
                    ->orderBy('bank_account_name', 'ASC')
                    ->findAll();
    }

    /** Recupera un conto RiBa idoneo nel perimetro dell'azienda attiva. */
    public function findRibaEligibleScoped(int $id): ?array
    {
        return $this->scopeToCompany()
                    ->where('bank_account_id', $id)
                    ->where('bank_account_iban IS NOT NULL', null, false)
                    ->where('bank_account_iban !=', '')
                    ->where('bank_account_sia_code IS NOT NULL', null, false)
                    ->where('bank_account_sia_code !=', '')
                    ->first();
    }

    /**
     * Conto predefinito dell'azienda attiva, o il suo primo conto se nessuno è
     * marcato tale.
     *
     * Il filtro azienda non c'era, mentre getAllOrdered() ce l'aveva: il conto
     * "predefinito" veniva quindi scelto fra quelli di tutte le aziende. Poiché
     * forInvoice() ripiega qui quando la fattura non indica un conto, e l'IBAN
     * che ne esce finisce nel nodo di pagamento dell'XML, una fattura della
     * seconda azienda poteva riportare le coordinate bancarie della prima.
     */
    public function getDefault(): ?array
    {
        $account = $this->scopeToCompany()
                        ->where('bank_account_is_default', true)
                        ->first();

        return $account ?: $this->scopeToCompany()
                                ->orderBy('bank_account_name', 'ASC')
                                ->first();
    }

    /**
     * Conto da riportare sul documento: quello indicato sulla fattura, con
     * ripiego sul predefinito.
     */
    public function forInvoice(?int $bankAccountId): ?array
    {
        if ($bankAccountId) {
            // findScoped(): il conto deve appartenere all'azienda attiva, altrimenti
            // un bank_account_id di un'altra azienda (da POST non validato o via
            // MCP) farebbe finire l'IBAN altrui nel PDF e nel nodo DatiPagamento.
            $account = $this->findScoped($bankAccountId);

            if ($account) {
                return $account;
            }
        }

        return $this->getDefault();
    }

    /**
     * Normalizza i campi bancari del POST prima di validare e salvare.
     *
     * L'IBAN si copia dall'home banking con gli spazi ogni quattro caratteri e
     * a volte in minuscolo. Il tracciato FatturaPA vuole invece la stringa
     * continua in maiuscolo: uno spazio nel nodo <IBAN> fa scartare il file, e
     * lo stesso vale per il flusso RiBa, che finora si difendeva ripulendo il
     * valore a ogni lettura (RibaFlow::cleanIban). Pulire una volta sola al
     * salvataggio evita che ogni consumatore debba ricordarsene.
     */
    public static function normalizePost(array $post): array
    {
        foreach (['bank_account_iban', 'bank_account_bic', 'bank_account_sia_code'] as $field) {
            if (isset($post[$field])) {
                $post[$field] = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $post[$field]));
            }
        }

        return $post;
    }

    /**
     * IBAN nella forma continua accettata dal tracciato: maiuscolo, senza
     * spazi né punteggiatura. Serve anche in lettura perché l'IBAN di ripiego
     * (quello del profilo utente) non passa da questo model e può contenere
     * ancora gli spazi con cui è stato incollato.
     */
    public static function cleanIban(?string $iban): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $iban));
    }

    /** IBAN a gruppi di quattro, come è stampato sull'estratto conto. */
    public static function formatIban(?string $iban): string
    {
        $iban = strtoupper((string) preg_replace('/\s+/', '', (string) $iban));

        return $iban === '' ? '' : trim(chunk_split($iban, 4, ' '));
    }

    /**
     * Conto dell'azienda attiva, o null se l'id è di un'altra azienda.
     *
     * find() non filtra per azienda: con l'id nell'URL si aprivano in modifica
     * — e si cancellavano — i conti di un altro soggetto giuridico. Il perimetro
     * azienda è una regola di correttezza, non un filtro di comodo.
     */
    public function findScoped(int $id): ?array
    {
        return $this->scopeToCompany()
                    ->where('bank_account_id', $id)
                    ->first();
    }

    /**
     * Righe che puntano a ciascun conto, per tabella. Un conto cancellato
     * lascerebbe fatture, spese e movimenti con un riferimento morto: la
     * fattura ripiegherebbe in silenzio sul conto predefinito, cioè su un IBAN
     * diverso da quello con cui era stata emessa.
     *
     * Una query aggregata per tabella (non una per conto) così l'elenco resta
     * a costo fisso al crescere dei conti.
     *
     * Con $accountIds si aggregano solo quei conti: l'elenco paginato ne
     * mostra pochi, e senza filtro ogni pagina faceva quattro scansioni
     * complete di tabella (le stesse che la cancellazione rifà per un solo
     * conto). Il filtro lascia lavorare gli indici su bank_account_id.
     *
     * @param list<int>|null $accountIds
     *
     * @return array<int, array<string, int>>
     */
    public function usageCounts(?array $accountIds = null): array
    {
        if ($accountIds !== null) {
            $accountIds = array_values(array_unique(array_map('intval', $accountIds)));

            if ($accountIds === []) {
                return [];
            }
        }

        $usage  = [];
        $tables = [
            'ip_invoices'          => 'invoices',
            'ip_expenses'          => 'expenses',
            'ip_bank_transactions' => 'transactions',
            'ip_f24'               => 'f24',
        ];

        foreach ($tables as $table => $key) {
            if (!$this->db->tableExists($table)) {
                continue;
            }

            $builder = $this->db->table($table)
                                ->select('bank_account_id, COUNT(*) AS total')
                                ->where('bank_account_id >', 0);

            if ($accountIds !== null) {
                $builder->whereIn('bank_account_id', $accountIds);
            }

            $rows = $builder->groupBy('bank_account_id')->get()->getResultArray();

            foreach ($rows as $row) {
                $usage[(int) $row['bank_account_id']][$key] = (int) $row['total'];
            }
        }

        return $usage;
    }

    /** Totale dei riferimenti al conto: zero significa cancellabile. */
    public function usageFor(int $id): int
    {
        return array_sum($this->usageCounts([$id])[$id] ?? []);
    }

    /**
     * Saldo corrente di ogni conto dell'azienda attiva.
     *
     * saldo = saldo iniziale + incassi - spese. Le due fonti sono quelle che
     * spostano davvero i soldi: l'incasso di una fattura (`ip_payments`) e la
     * spesa (`ip_expenses`).
     *
     * I movimenti di estratto conto NON entrano nella somma: sono la
     * fotografia della stessa banca, non soldi in più. L'incasso di una
     * fattura compare di norma anche lì, e sommarlo due volte gonfierebbe il
     * saldo del doppio; servono alla riconciliazione, non al totale.
     *
     * Per gli incassi il perimetro azienda arriva dalla fattura, perché
     * ip_payments non ha company_id. Le righe con company_id nullo (archivio
     * precedente al multi-azienda) restano visibili come nel resto dell'app:
     * escluderle farebbe sparire dal saldo soldi che ci sono.
     *
     * Con $accountIds si calcolano solo quei conti: la lista è paginata, e
     * senza filtro si rileggeva l'intera tabella già caricata dal controller.
     *
     * @param list<int>|null $accountIds
     *
     * @return array<int, array{opening: float, date: ?string, in: float, out: float, balance: float, declared: bool}>
     */
    public function balances(?array $accountIds = null): array
    {
        if ($accountIds !== null) {
            $accountIds = array_values(array_unique(array_map('intval', $accountIds)));

            if ($accountIds === []) {
                return [];
            }
        }

        $companyId = active_company_id();
        $saldo     = [];

        $accounts = $this->scopeToCompany()->orderBy('bank_account_id', 'ASC');

        if ($accountIds !== null) {
            $accounts->whereIn('bank_account_id', $accountIds);
        }

        foreach ($accounts->findAll() as $account) {
            $id = (int) $account['bank_account_id'];

            $saldo[$id] = [
                'opening'  => (float) ($account['bank_account_opening_balance'] ?? 0),
                'date'     => $account['bank_account_opening_date'] ?? null,
                'in'       => 0.0,
                'out'      => 0.0,
                'balance'  => 0.0,
                'declared' => ($account['bank_account_opening_balance'] ?? null) !== null,
            ];
        }

        if ($saldo === []) {
            return [];
        }

        $perimetro = static function ($builder, string $alias) use ($companyId) {
            if ($companyId > 0) {
                $builder->groupStart()
                        ->where($alias . '.company_id', $companyId)
                        ->orWhere($alias . '.company_id', null)
                        ->groupEnd();
            }

            return $builder;
        };

        // Incassi: le note di credito rimborsate sono già negative, quindi la
        // somma le toglie senza doverle distinguere.
        //
        // Il join sul conto serve a una cosa sola: i movimenti precedenti alla
        // data di apertura restano fuori dal totale. Sono già dentro il saldo
        // iniziale — è quello che si legge dall'estratto conto quel giorno — e
        // sommarli di nuovo li conterebbe due volte. Un conto senza data li
        // conta tutti, che è il comportamento di prima.
        $incassiQuery = $this->db->table('ip_payments p')
                                 ->select('p.bank_account_id AS conto, SUM(p.payment_amount) AS totale')
                                 ->join('ip_invoices i', 'i.invoice_id = p.invoice_id', 'inner')
                                 ->join('ip_bank_accounts a', 'a.bank_account_id = p.bank_account_id', 'inner')
                                 ->where('p.bank_account_id >', 0);

        if ($accountIds !== null) {
            $incassiQuery->whereIn('p.bank_account_id', $accountIds);
        }

        $incassi = $perimetro(
            $incassiQuery
                     ->groupStart()
                     ->where('a.bank_account_opening_date', null)
                     ->orWhere('p.payment_date >=', 'a.bank_account_opening_date', false)
                     ->groupEnd()
                     ->groupBy('p.bank_account_id'),
            'i'
        )->get()->getResultArray();

        foreach ($incassi as $riga) {
            $id = (int) $riga['conto'];

            if (isset($saldo[$id])) {
                $saldo[$id]['in'] = (float) $riga['totale'];
            }
        }

        $speseQuery = $this->db->table('ip_expenses e')
                               ->select('e.bank_account_id AS conto, SUM(e.expense_amount) AS totale')
                               ->join('ip_bank_accounts a', 'a.bank_account_id = e.bank_account_id', 'inner')
                               ->where('e.bank_account_id >', 0);

        if ($accountIds !== null) {
            $speseQuery->whereIn('e.bank_account_id', $accountIds);
        }

        $spese = $perimetro(
            $speseQuery
                     ->groupStart()
                     ->where('a.bank_account_opening_date', null)
                     ->orWhere('e.expense_date >=', 'a.bank_account_opening_date', false)
                     ->groupEnd()
                     ->groupBy('e.bank_account_id'),
            'e'
        )->get()->getResultArray();

        foreach ($spese as $riga) {
            $id = (int) $riga['conto'];

            if (isset($saldo[$id])) {
                $saldo[$id]['out'] = (float) $riga['totale'];
            }
        }

        foreach ($saldo as $id => $conto) {
            $saldo[$id]['balance'] = $conto['opening'] + $conto['in'] - $conto['out'];
        }

        return $saldo;
    }

    /**
     * Il predefinito è uno solo: impostandone uno gli altri decadono, altrimenti
     * "quale conto finisce in fattura" dipenderebbe dall'ordine di lettura.
     */
    public function saveAccount(?int $id, array $dbArray): int
    {
        $savedId = (int) $this->saveRecord($id, $dbArray);

        // Con un salvataggio rifiutato (0) il conto non esiste e azzerare il
        // predefinito di tutti gli altri lascerebbe nessun conto predefinito.
        if ($savedId !== 0 && !empty($dbArray['bank_account_is_default'])) {
            $this->companyBuilder()
                 ->where('bank_account_id !=', $savedId)
                 ->update(['bank_account_is_default' => false]);
        }

        return $savedId;
    }
}
