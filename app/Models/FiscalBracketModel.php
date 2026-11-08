<?php

namespace App\Models;

/**
 * FiscalBracketModel — scaglioni IRPEF di un anno.
 *
 * Tabella separata perche' il numero di scaglioni cambia con la legge: in
 * colonne fisse si sarebbe dovuto decidere in anticipo quanti possono essere.
 */
class FiscalBracketModel extends IpBaseModel
{
    protected $table            = 'ip_fiscal_brackets';
    protected $primaryKey       = 'bracket_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    protected $afterInsert = ['clearFinanceCache'];
    protected $afterUpdate = ['clearFinanceCache'];
    protected $afterDelete = ['clearFinanceCache'];

    protected $allowedFields = [
        'company_id', 'fiscal_year', 'bracket_limit', 'bracket_rate', 'bracket_order',
    ];

    /**
     * Scaglioni di un anno nella forma attesa da FinanceService:
     * [[soglia|null, aliquota], ...] in ordine crescente.
     *
     * @return list<array{0: float|null, 1: float}>
     */
    public function forYear(int $year, ?int $companyId = null): array
    {
        $rows = $this->ofCompany($companyId)
                     ->where('fiscal_year', $year)
                     ->orderBy('bracket_order', 'ASC')
                     ->findAll();

        return array_map(
            static fn(array $r): array => [
                $r['bracket_limit'] === null ? null : (float) $r['bracket_limit'],
                (float) $r['bracket_rate'],
            ],
            $rows
        );
    }

    /**
     * Scaglioni di un'azienda precisa, o quelli condivisi (company_id NULL).
     *
     * Non è scopeToCompany(): qui non si vogliono "i miei più i condivisi",
     * ma esattamente l'insieme che accompagna una riga di ip_fiscal_years —
     * mescolarli darebbe una scala con gli scaglioni di due tabelle diverse.
     */
    public function ofCompany(?int $companyId): static
    {
        if ($companyId === null) {
            $this->where('company_id IS NULL', null, false);
        } else {
            $this->where('company_id', $companyId);
        }

        return $this;
    }

    /**
     * Riscrive gli scaglioni di un anno.
     *
     * Cancella e reinserisce invece di aggiornare riga per riga: il numero di
     * scaglioni puo' cambiare fra un salvataggio e l'altro, e appaiare le
     * vecchie righe alle nuove significherebbe decidere quale scaglione e'
     * "lo stesso" quando ne togli uno in mezzo.
     *
     * @param list<array{limit: float|null, rate: float}> $brackets
     */
    public function replaceForYear(int $year, array $brackets, ?int $companyId = null): void
    {
        $this->ofCompany($companyId)->where('fiscal_year', $year)->delete();

        $brackets = array_values($brackets);
        $last     = count($brackets) - 1;

        foreach ($brackets as $i => $b) {
            // L'ultimo scaglione non ha tetto, per definizione di scala
            // progressiva. La scheda lascia compilare anche quel campo, e un
            // tetto li' significherebbe reddito non tassato sopra la soglia:
            // si normalizza qui, invece di fidarsi di chi compila.
            $limit = $i === $last ? null : $b['limit'];

            $this->insert([
                'company_id'    => $companyId,
                'fiscal_year'   => $year,
                'bracket_limit' => $limit,
                'bracket_rate'  => $b['rate'],
                'bracket_order' => $i,
            ]);
        }
    }

    protected function clearFinanceCache(array $data): array
    {
        if (function_exists('invalidate_finance_cache')) {
            invalidate_finance_cache();
        }

        return $data;
    }
}
