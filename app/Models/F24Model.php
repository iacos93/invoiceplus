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

    public function validationRulesLegacy(): array
    {
        return [
            'f24_due_date'    => 'required',
            'f24_amount'      => 'required',
            'f24_period'      => 'permit_empty|max_length[20]',
            'f24_paid_date'   => 'permit_empty',
            'bank_account_id' => 'permit_empty',
            'f24_note'        => 'permit_empty',
            'f24_file'        => 'permit_empty',
            'f24_receipt_protocol' => 'permit_empty|max_length[60]',
        ];
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
