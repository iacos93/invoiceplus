<?php

namespace App\Models;

/**
 * CostingBudgetModel — budget mensile per voce del piano dei conti.
 *
 * Un valore per voce/anno/mese, non un totale annuo: il confronto che serve
 * al controllo di gestione è "questo mese ho speso più di quanto avevo
 * previsto?", e un annuale diviso per dodici nasconderebbe proprio le
 * variazioni stagionali che si vogliono vedere.
 *
 * La riga viene creata al primo salvataggio; un valore a zero viene
 * conservato (è un budget deliberato, non un'assenza di budget) e per
 * questo non si cancella quando si azzera un campo.
 */
class CostingBudgetModel extends IpBaseModel
{
    protected $table            = 'ip_costing_budgets';
    protected $primaryKey       = 'budget_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'company_id',
        'account_id',
        'budget_year',
        'budget_month',
        'budget_amount',
        'budget_updated',
    ];

    protected $validationRules = [
        'account_id'    => 'required|is_natural_no_zero',
        'budget_year'   => 'required|integer',
        'budget_month'  => 'required|integer',
        'budget_amount' => 'permit_empty|numeric',
    ];

    /**
     * Griglia dell'anno: account_id => [1..12 => importo].
     *
     * @return array<int, array<int, float>>
     */
    public function gridForYear(int $year): array
    {
        $rows = $this->scopeToCompany()
            ->where('budget_year', $year)
            ->findAll();

        $grid = [];

        foreach ($rows as $row) {
            $grid[(int) $row['account_id']][(int) $row['budget_month']] = (float) $row['budget_amount'];
        }

        return $grid;
    }

    /**
     * Totale annuo per voce (solo le voci che hanno almeno un mese).
     *
     * @return array<int, float>
     */
    public function annualForYear(int $year): array
    {
        $annual = [];

        foreach ($this->gridForYear($year) as $accountId => $months) {
            $annual[$accountId] = round(array_sum($months), 2);
        }

        return $annual;
    }

    /**
     * Salva la griglia inviata dal form. Accetta solo mesi 1-12 e importi
     * numerici; le voci non presenti nel POST restano com'erano.
     *
     * @param array<int|string, array<int|string, mixed>> $values account_id => [mese => importo]
     * @param list<int>                                   $allowedAccountIds voci dell'azienda attiva
     *
     * @return int numero di celle scritte
     */
    public function saveGrid(int $year, array $values, array $allowedAccountIds, int $companyId): int
    {
        $allowed = array_flip(array_map('intval', $allowedAccountIds));
        $saved   = 0;

        foreach ($values as $accountId => $months) {
            $accountId = (int) $accountId;

            if (!isset($allowed[$accountId]) || !is_array($months)) {
                continue;
            }

            foreach ($months as $month => $raw) {
                $month = (int) $month;

                if ($month < 1 || $month > 12) {
                    continue;
                }

                $amount = $this->parseBudgetAmount($raw);

                $existing = $this->db->table('ip_costing_budgets')
                    ->where('account_id', $accountId)
                    ->where('budget_year', $year)
                    ->where('budget_month', $month)
                    ->get()
                    ->getRowArray();

                if ($existing) {
                    $this->db->table('ip_costing_budgets')
                        ->where('budget_id', $existing['budget_id'])
                        ->update([
                            'budget_amount'  => $amount,
                            'budget_updated' => date('Y-m-d H:i:s'),
                        ]);
                } else {
                    $this->db->table('ip_costing_budgets')->insert([
                        'company_id'     => $companyId,
                        'account_id'     => $accountId,
                        'budget_year'    => $year,
                        'budget_month'   => $month,
                        'budget_amount'  => $amount,
                        'budget_updated' => date('Y-m-d H:i:s'),
                    ]);
                }

                $saved++;
            }
        }

        return $saved;
    }

    /**
     * Gli importi arrivano nel formato locale ("1.200,50") dallo stesso
     * helper dei documenti; vuoto vale zero.
     */
    private function parseBudgetAmount(mixed $raw): float
    {
        if ($raw === null || $raw === '') {
            return 0.0;
        }

        return round((float) standardize_amount($raw), 2);
    }
}
