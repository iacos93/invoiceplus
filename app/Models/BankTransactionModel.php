<?php

namespace App\Models;

/**
 * BankTransactionModel — movimenti di estratto conto e loro abbinamento a
 * fatture (incassi) e spese (pagamenti).
 */
class BankTransactionModel extends IpBaseModel
{
    protected $table            = 'ip_bank_transactions';
    protected $primaryKey       = 'transaction_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'bank_account_id', 'transaction_date', 'transaction_amount', 'transaction_description',
        'transaction_hash', 'invoice_id', 'expense_id', 'is_reconciled',
        'company_id',
    ];

    protected $validationRules = [
        'transaction_date'   => 'required',
        'transaction_amount' => 'required',
        'transaction_hash'   => 'required',
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * @param string $state '' tutti, 'open' da abbinare, 'done' abbinati
     */
    public function getAll(string $state = '', int $year = 0): array
    {
        $this->select('ip_bank_transactions.*, ip_bank_accounts.bank_account_name', false)
             ->join('ip_bank_accounts', 'ip_bank_accounts.bank_account_id = ip_bank_transactions.bank_account_id', 'left');

        $this->scopeToCompany();

        if ($state === 'open') {
            $this->where('ip_bank_transactions.is_reconciled', false);
        } elseif ($state === 'done') {
            $this->where('ip_bank_transactions.is_reconciled', true);
        }

        if ($year > 0) {
            $this->where(year_sql('ip_bank_transactions.transaction_date'), $year);
        }

        return $this->orderBy('ip_bank_transactions.transaction_date', 'DESC')
                    ->orderBy('ip_bank_transactions.transaction_id', 'DESC')
                    ->findAll();
    }

    /**
     * Impronta della riga: conto + data + importo + descrizione. Reimportare
     * lo stesso estratto conto, o un file che si sovrappone al precedente, non
     * deve duplicare i movimenti.
     *
     * Il conto fa parte dell'impronta perché senza di esso lo stesso bonifico
     * presente sull'estratto di due banche diverse veniva scartato al secondo
     * import: il movimento c'è davvero su entrambi i conti e va registrato su
     * entrambi.
     */
    public function hashFor(string $date, float $amount, string $description, ?int $bankAccountId = null): string
    {
        // Descrizione normalizzata (spazi multipli collassati, maiuscole):
        // un reimport dello stesso estratto conto con la causale riformattata
        // anche di un solo spazio produceva un hash diverso e quindi un
        // duplicato non rilevato. Non tocca gli hash già salvati: dopo questo
        // cambio un primo reimport di un estratto già importato può mostrare
        // righe che sembrano nuove finché non si riallineano ai nuovi hash.
        $normalizedDescription = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $description) ?? $description));

        return hash('sha256', ($bankAccountId ?? 0) . '|' . $date . '|'
            . number_format($amount, 2, '.', '') . '|' . $normalizedDescription);
    }

    /**
     * Il duplicato si cerca dentro l'azienda attiva: un movimento identico di
     * un'altra società non è lo stesso movimento, e trattarlo come tale
     * significava farlo sparire dalla riconciliazione di chi lo stava
     * importando, per giunta in silenzio.
     */
    public function existsByHash(string $hash): bool
    {
        return $this->scopeToCompany()->where('transaction_hash', $hash)->countAllResults() > 0;
    }
}
