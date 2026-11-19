<?php

namespace App\Models;

class F24Model extends IpBaseModel
{
    protected $table            = 'ip_f24';
    protected $primaryKey       = 'f24_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'company_id',
        'f24_period',
        'f24_due_date',
        'f24_paid_date',
        'f24_amount',
        'f24_note',
        'f24_file',
        'f24_receipt_file',
        'f24_receipt_protocol',
        'bank_account_id',
        'f24_date_created',
    ];

    /**
     * Le regole vere della delega, non solo l'elenco dei campi scrivibili.
     *
     * Mancavano del tutto: il controller chiamava validate() su un modello
     * senza regole, quindi qualsiasi cosa passava e una scadenza vuota
     * arrivava all'INSERT di una colonna NOT NULL — su PostgreSQL una pagina
     * d'errore al posto dell'avviso sul campo.
     *
     * Le date sono già convertite in Y-m-d dal controller prima di arrivare
     * qui, e la regola è stretta su quel formato: date_to_mysql() lascia
     * passare intatta una stringa che non riesce a interpretare, e senza il
     * formato scritto valid_date() accetterebbe anche «domani» (usa
     * strtotime()). L'importo arriva già normalizzato, quindi il controllo è
     * solo che ci sia e che sia un numero: zero resta un valore, perché una
     * delega interamente compensata esiste.
     */
    protected $validationRules = [
        'f24_due_date'         => 'required|valid_date[Y-m-d]',
        'f24_amount'           => 'required|numeric',
        'f24_period'           => 'permit_empty|max_length[20]',
        'f24_paid_date'        => 'permit_empty|valid_date[Y-m-d]',
        'bank_account_id'      => 'permit_empty|is_natural',
        'f24_note'             => 'permit_empty',
        'f24_file'             => 'permit_empty',
        'f24_receipt_protocol' => 'permit_empty|max_length[60]',
    ];

    protected $validationMessages = [
        'f24_due_date' => [
            'required'   => 'La scadenza è obbligatoria',
            'valid_date' => 'La scadenza non è una data valida',
        ],
        'f24_amount' => [
            'required' => "L'importo è obbligatorio",
            'numeric'  => "L'importo deve essere un numero",
        ],
        'f24_period' => [
            'max_length' => 'Il periodo non può superare i 20 caratteri',
        ],
        'f24_paid_date' => [
            'valid_date' => 'La data di pagamento non è una data valida',
        ],
        'bank_account_id' => [
            'is_natural' => 'Il conto di addebito scelto non è valido',
        ],
        'f24_receipt_protocol' => [
            'max_length' => 'Il protocollo della quietanza non può superare i 60 caratteri',
        ],
    ];

    /**
     * L'elenco dei campi scrivibili è lo stesso delle regole: una seconda
     * lista da tenere allineata è il modo in cui i due elenchi divergono.
     */
    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * Il form manda la stringa vuota per «nessun conto».
     *
     * La colonna è un intero e il campo accetta il vuoto, quindi il vuoto va
     * scritto come NULL. Su PostgreSQL ci pensa già coerceForColumns(), ma
     * solo lì: su MariaDB la stringa arriva al database così com'è e la
     * riga viene rifiutata («Incorrect integer value: ''»). Convertirla qui
     * la rende uguale sui due motori, prima che la differenza si veda.
     */
    public function dbArrayFromPost(array $post): array
    {
        $dbArray = parent::dbArrayFromPost($post);

        if (array_key_exists('bank_account_id', $dbArray) && $dbArray['bank_account_id'] === '') {
            $dbArray['bank_account_id'] = null;
        }

        return $dbArray;
    }

    /**
     * Recupera tutti i modelli F24 con join sul conto bancario.
     *
     * @param int    $year  0 per tutti gli anni, altrimenti filtra per anno scadenza
     * @param string $state 'open', 'paid', o '' per tutti
     */
    public function getAll(int $year = 0, string $state = ''): array
    {
        $this->select('ip_f24.*, ip_bank_accounts.bank_account_name')
            ->join('ip_bank_accounts', 'ip_bank_accounts.bank_account_id = ip_f24.bank_account_id', 'left');

        if ($year > 0) {
            $this->where(year_sql('ip_f24.f24_due_date'), $year);
        }

        if ($state === 'open') {
            $this->where('ip_f24.f24_paid_date IS NULL', null, false);
        } elseif ($state === 'paid') {
            $this->where('ip_f24.f24_paid_date IS NOT NULL', null, false);
        }

        $this->scopeToCompany();

        return $this->orderBy('ip_f24.f24_due_date', 'ASC')
            ->orderBy('ip_f24.f24_id', 'ASC')
            ->findAll();
    }

    /**
     * Allega la quietanza a una delega, purché sia ancora senza.
     *
     * Due importazioni possono leggere lo stesso elenco di deleghe libere e
     * scegliere la stessa: con un update() per sola chiave la seconda
     * sovrascriveva la quietanza della prima, che restava orfana in
     * writable/, e l'esito la diceva abbinata a entrambe. La condizione sulla
     * colonna vuota sta nell'UPDATE e non in una lettura precedente, perché
     * fra la lettura e la scrittura l'altra importazione può arrivare; zero
     * righe toccate vuol dire che la delega è già occupata, non che la
     * scrittura è riuscita.
     *
     * Le regole del modello valgono come in update(): un rifiuto per
     * validazione resta distinguibile da un conflitto, e il motivo finisce in
     * errors() per chi lo deve mostrare.
     *
     * I campi si filtrano su $allowedFields e non su dbArrayFromPost():
     * quest'ultimo tiene solo le chiavi che hanno una regola, e
     * f24_receipt_file non ne ha (arriva da un upload, non dal POST) — con
     * quel filtro la quietanza non sarebbe stata scritta affatto.
     *
     * @param array<string, mixed> $row
     *
     * @return 'written'|'taken'|'rejected' scritto, delega già occupata, scrittura rifiutata
     */
    public function attachReceipt(int $f24Id, array $row): string
    {
        $row = array_intersect_key($row, array_flip($this->allowedFields));

        if ($row === [] || !$this->validate($row)) {
            return 'rejected';
        }

        $scritto = $this->builder()
            ->where('f24_id', $f24Id)
            ->groupStart()
                ->where('f24_receipt_file IS NULL', null, false)
                ->orWhere('f24_receipt_file', '')
            ->groupEnd()
            ->update($row);

        if (!$scritto) {
            return 'rejected';
        }

        return $this->db->affectedRows() > 0 ? 'written' : 'taken';
    }

    /**
     * Esiste già una quietanza con questo protocollo nell'azienda attiva?
     * Reimportare lo stesso PDF non deve segnare pagata una seconda delega
     * di pari importo.
     */
    public function receiptProtocolExists(string $protocol): bool
    {
        return $this->where('f24_receipt_protocol', $protocol)
            ->scopeToCompany()
            ->countAllResults() > 0;
    }

    /**
     * Deleghe che aspettano ancora la quietanza, pagate o no. Quelle già
     * pagate contano: spesso il pagamento si registra dall'estratto conto
     * e la quietanza arriva dopo, e restavano fuori dall'abbinamento.
     */
    public function withoutReceipt(): array
    {
        return $this->groupStart()
                ->where('f24_receipt_file IS NULL', null, false)
                ->orWhere('f24_receipt_file', '')
            ->groupEnd()
            ->scopeToCompany()
            ->orderBy('f24_due_date', 'ASC')
            ->findAll();
    }
}
