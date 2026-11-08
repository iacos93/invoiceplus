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
        'bank_account_sia_code'   => 'permit_empty|alpha_numeric|exact_length[5]',
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
        'bank_account_sia_code' => [
            'alpha_numeric' => 'Il codice SIA contiene solo lettere e cifre',
            'exact_length'  => 'Il codice SIA è di cinque caratteri',
        ],
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    public function getAllOrdered(?int $perPage = null, int $page = 1): array
    {
        $this->scopeToCompany()
             ->orderBy('bank_account_is_default', 'DESC')
             ->orderBy('bank_account_name', 'ASC');

        return $this->listFetch($perPage, $page);
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
     */
    public function usageCounts(): array
    {
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

            $rows = $this->db->table($table)
                             ->select('bank_account_id, COUNT(*) AS total')
                             ->where('bank_account_id >', 0)
                             ->groupBy('bank_account_id')
                             ->get()->getResultArray();

            foreach ($rows as $row) {
                $usage[(int) $row['bank_account_id']][$key] = (int) $row['total'];
            }
        }

        return $usage;
    }

    /** Totale dei riferimenti al conto: zero significa cancellabile. */
    public function usageFor(int $id): int
    {
        return array_sum($this->usageCounts()[$id] ?? []);
    }

    /**
     * Il predefinito è uno solo: impostandone uno gli altri decadono, altrimenti
     * "quale conto finisce in fattura" dipenderebbe dall'ordine di lettura.
     */
    public function saveAccount(?int $id, array $dbArray): int
    {
        $savedId = (int) $this->saveRecord($id, $dbArray);

        if (!empty($dbArray['bank_account_is_default'])) {
            $this->companyBuilder()
                 ->where('bank_account_id !=', $savedId)
                 ->update(['bank_account_is_default' => false]);
        }

        return $savedId;
    }
}
