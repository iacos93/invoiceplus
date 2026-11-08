<?php

namespace App\Models;

/**
 * PaymentMethodModel
 *
 * Porting CI4 del modulo payment_methods (CI3: Mdl_Payment_Methods).
 *
 * La tabella non ha company_id: il metodo di pagamento ("Bonifico",
 * "Contanti") e' un vocabolario condiviso, mentre il conto di saldo — l'IBAN
 * vero e proprio — e' per azienda e sta in BankAccountModel.
 */
class PaymentMethodModel extends IpBaseModel
{
    protected $table            = 'ip_payment_methods';
    protected $primaryKey       = 'payment_method_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'payment_method_name',
        'payment_method_requires_bank_account',
        'payment_method_sdi_code',
    ];

    protected $validationRules = [
        // is_unique con il terzo parametro esclude la riga in modifica: senza,
        // salvare un metodo senza rinominarlo fallirebbe contro se stesso.
        // Il controllo stava nel controller e girava solo in creazione, quindi
        // rinominando un metodo si ottenevano due "Bonifico" indistinguibili
        // nella select della fattura.
        'payment_method_name'                   => 'required|max_length[35]|is_unique[ip_payment_methods.payment_method_name,payment_method_id,{payment_method_id}]',
        'payment_method_requires_bank_account'  => 'permit_empty',
        'payment_method_sdi_code'               => 'permit_empty|regex_match[/^MP(0[1-9]|1[0-9]|2[0-3])$/]',
        // Non e' un campo del form: CI4 pretende una regola per ogni campo
        // usato come segnaposto {…}, e is_unique qui sopra ne usa uno per
        // escludere la riga in modifica. Resta fuori da validationRulesLegacy(),
        // altrimenti finirebbe fra i campi scritti su database.
        'payment_method_id'                     => 'permit_empty|is_natural',
    ];

    protected $validationMessages = [
        'payment_method_name' => [
            'required'   => 'Il nome del metodo di pagamento e\' obbligatorio',
            'max_length' => 'Il nome non puo\' superare i 35 caratteri',
            'is_unique'  => 'Esiste gia\' un metodo di pagamento con questo nome',
        ],
        'payment_method_sdi_code' => [
            'regex_match' => 'Il codice deve essere una modalita\' da MP01 a MP23',
        ],
    ];

    /**
     * Modalita' di pagamento della fattura elettronica (tabella
     * ModalitaPagamento dell'Agenzia delle Entrate). Sono l'elenco chiuso
     * ammesso dallo SdI: un codice fuori da qui fa scartare il file.
     */
    public const SDI_CODES = [
        'MP01' => 'Contanti',
        'MP02' => 'Assegno',
        'MP03' => 'Assegno circolare',
        'MP04' => 'Contanti presso Tesoreria',
        'MP05' => 'Bonifico',
        'MP06' => 'Vaglia cambiario',
        'MP07' => 'Bollettino bancario',
        'MP08' => 'Carta di pagamento',
        'MP09' => 'RID',
        'MP10' => 'RID utenze',
        'MP11' => 'RID veloce',
        'MP12' => 'RIBA',
        'MP13' => 'MAV',
        'MP14' => 'Quietanza erario',
        'MP15' => 'Giroconto su conti di contabilita\' speciale',
        'MP16' => 'Domiciliazione bancaria',
        'MP17' => 'Domiciliazione postale',
        'MP18' => 'Bollettino di c/c postale',
        'MP19' => 'SEPA Direct Debit',
        'MP20' => 'SEPA Direct Debit CORE',
        'MP21' => 'SEPA Direct Debit B2B',
        'MP22' => 'Trattenuta su somme gia\' riscosse',
        'MP23' => 'PagoPA',
    ];

    /**
     * Campi scrivibili dal form (li filtra dbArrayFromPost).
     *
     * La chiave primaria e' esclusa: e' nel POST solo per il segnaposto di
     * is_unique, e lasciarla passare significherebbe scrivere payment_method_id
     * fra i valori dell'insert.
     */
    public function validationRulesLegacy(): array
    {
        $rules = $this->validationRules;
        unset($rules['payment_method_id']);

        return $rules;
    }

    public function getAllOrdered(?int $perPage = null, int $page = 1): array
    {
        $this->orderBy('payment_method_name', 'ASC');

        return $this->listFetch($perPage, $page);
    }

    public function nameExists(string $name, ?int $exceptId = null): bool
    {
        $builder = $this->where('payment_method_name', $name);

        if ($exceptId) {
            $builder->where('payment_method_id !=', $exceptId);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * Codice ModalitaPagamento da scrivere nell'XML per questo metodo.
     *
     * Il ripiego su .env serve alle installazioni configurate prima che il
     * codice diventasse un campo dell'anagrafica: li' il valore sta in
     * IT_METODO_PAGAMENTO_ID_<id>_CODICE e continua a funzionare finche' non
     * si compila il campo, che ha la precedenza.
     */
    public static function sdiCodeFor(array $method): ?string
    {
        if (!empty($method['payment_method_sdi_code'])) {
            return $method['payment_method_sdi_code'];
        }

        $legacy = env('IT_METODO_PAGAMENTO_ID_' . (int) ($method['payment_method_id'] ?? 0) . '_CODICE');

        return $legacy ?: null;
    }

    /** Descrizione leggibile del codice ("MP05 · Bonifico"). */
    public static function sdiCodeLabel(?string $code): string
    {
        if (!$code) {
            return '';
        }

        return isset(self::SDI_CODES[$code]) ? $code . ' · ' . self::SDI_CODES[$code] : $code;
    }

    /**
     * Id dei metodi che si incassano a ricevuta bancaria.
     *
     * Il riconoscimento passa dal codice SdI (MP12 = RIBA) e non dal nome: il
     * nome e' testo libero — "RiBa", "Ricevuta bancaria", "Ri.Ba." — mentre il
     * codice e' l'elenco chiuso dell'Agenzia delle Entrate, quindi e' l'unico
     * dato su cui un confronto ha senso.
     */
    public function ribaMethodIds(): array
    {
        $rows = $this->select('payment_method_id')
                     ->where('payment_method_sdi_code', 'MP12')
                     ->findAll();

        return array_map(static fn ($row) => (int) $row['payment_method_id'], $rows);
    }

    /**
     * Quante fatture e quanti pagamenti usano ciascun metodo.
     *
     * Due query aggregate invece di un conteggio per riga: l'elenco mostra il
     * dato su ogni metodo e con una query per riga la pagina cresceva a ogni
     * metodo aggiunto. Il risultato e' [id => ['invoices' => n, 'payments' => n]].
     */
    public function usageCounts(): array
    {
        $usage = [];

        $invoices = $this->db->table('ip_invoices')
                             ->select('payment_method, COUNT(*) AS total')
                             ->where('payment_method >', 0)
                             ->groupBy('payment_method')
                             ->get()->getResultArray();

        foreach ($invoices as $row) {
            $usage[(int) $row['payment_method']]['invoices'] = (int) $row['total'];
        }

        $payments = $this->db->table('ip_payments')
                             ->select('payment_method_id, COUNT(*) AS total')
                             ->where('payment_method_id >', 0)
                             ->groupBy('payment_method_id')
                             ->get()->getResultArray();

        foreach ($payments as $row) {
            $usage[(int) $row['payment_method_id']]['payments'] = (int) $row['total'];
        }

        return $usage;
    }

    /**
     * Quante righe puntano al metodo. Serve prima di cancellare: fatture e
     * pagamenti conservano solo l'id, quindi il metodo eliminato lascia una
     * cella vuota nello storico e nel report incassi per metodo.
     */
    public function usageFor(int $id): int
    {
        $usage = $this->usageCounts()[$id] ?? [];

        return (int) ($usage['invoices'] ?? 0) + (int) ($usage['payments'] ?? 0);
    }
}
