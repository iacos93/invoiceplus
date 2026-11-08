<?php

namespace App\Models;

use Config\Fiscal;

/**
 * FiscalYearModel — parametri fiscali per anno.
 *
 * Sostituisce Config\Fiscal come sorgente: quella resta come seed della
 * migrazione e come ripiego quando la tabella e' vuota, cosi' un'installazione
 * appena migrata (o svuotata per errore) mostra comunque un prospetto.
 *
 * paramsForYear() restituisce esattamente la forma che FinanceService gia' si
 * aspettava: cambia da dove arrivano i numeri, non il contratto fra i due.
 */
class FiscalYearModel extends IpBaseModel
{
    protected $table            = 'ip_fiscal_years';
    protected $primaryKey       = 'fiscal_id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;

    // Il prospetto sta in cache un'ora: senza queste callback si aggiornava
    // un'aliquota e si continuava a vedere la stima calcolata con la vecchia.
    protected $afterInsert = ['clearFinanceCache'];
    protected $afterUpdate = ['clearFinanceCache'];
    protected $afterDelete = ['clearFinanceCache'];

    protected $allowedFields = [
        'company_id', 'fiscal_year', 'fiscal_is_projection',
        'fiscal_add_regionale', 'fiscal_add_comunale',
        'fiscal_inps_art_minimale', 'fiscal_inps_art_massimale',
        'fiscal_inps_art_aliquota', 'fiscal_inps_art_fisso',
        'fiscal_inps_gs_aliquota',
        'fiscal_ires_aliquota', 'fiscal_irap_aliquota',
        'fiscal_forf_ordinaria', 'fiscal_forf_startup', 'fiscal_forf_limite',
    ];

    protected $validationRules = [
        'fiscal_year'              => 'required|is_natural|greater_than[1999]|less_than[2100]',
        'fiscal_is_projection'     => 'permit_empty',
        'fiscal_add_regionale'     => 'permit_empty|decimal|greater_than_equal_to[0]',
        'fiscal_add_comunale'      => 'permit_empty|decimal|greater_than_equal_to[0]',
        'fiscal_inps_art_minimale' => 'permit_empty|decimal|greater_than_equal_to[0]',
        'fiscal_inps_art_massimale' => 'permit_empty|decimal|greater_than_equal_to[0]',
        'fiscal_inps_art_aliquota' => 'permit_empty|decimal|greater_than_equal_to[0]',
        'fiscal_inps_art_fisso'    => 'permit_empty|decimal|greater_than_equal_to[0]',
        'fiscal_inps_gs_aliquota'  => 'permit_empty|decimal|greater_than_equal_to[0]',
        'fiscal_ires_aliquota'     => 'permit_empty|decimal|greater_than_equal_to[0]',
        'fiscal_irap_aliquota'     => 'permit_empty|decimal|greater_than_equal_to[0]',
        'fiscal_forf_ordinaria'    => 'permit_empty|decimal|greater_than_equal_to[0]',
        'fiscal_forf_startup'      => 'permit_empty|decimal|greater_than_equal_to[0]',
        'fiscal_forf_limite'       => 'permit_empty|decimal|greater_than_equal_to[0]',
    ];

    protected $validationMessages = [
        'fiscal_year' => [
            'required'    => 'L\'anno è obbligatorio',
            'is_natural'  => 'L\'anno dev\'essere un numero',
        ],
    ];

    public function validationRulesLegacy(): array
    {
        return $this->validationRules;
    }

    /**
     * Righe "in vigore" per l'azienda: le sue, più quelle condivise
     * (company_id NULL) degli anni che l'azienda non ha ridefinito.
     *
     * scopeToCompany() da solo non basta: mostrerebbe sia la riga condivisa
     * sia quella dell'azienda per lo stesso anno, e la stima userebbe l'una o
     * l'altra a seconda dell'ordinamento. Qui la riga dell'azienda nasconde
     * quella condivisa, che resta il punto di partenza per chi non ha ancora
     * personalizzato quell'anno.
     */
    public function scopeEffective(?int $companyId = null): static
    {
        $companyId ??= active_company_id();

        if (!$companyId) {
            return $this;
        }

        $this->groupStart()
             ->where($this->table . '.company_id', $companyId)
             ->orGroupStart()
                 ->where($this->table . '.company_id IS NULL', null, false)
                 ->where('NOT EXISTS (SELECT 1 FROM ip_fiscal_years own WHERE own.company_id = '
                     . (int) $companyId . ' AND own.fiscal_year = ' . $this->table . '.fiscal_year)', null, false)
             ->groupEnd()
             ->groupEnd();

        return $this;
    }

    public function getAllOrdered(?int $perPage = null, int $page = 1): array
    {
        $this->scopeEffective()->orderBy('fiscal_year', 'DESC');

        return $this->listFetch($perPage, $page);
    }

    public function findByYear(int $year): ?array
    {
        return $this->scopeEffective()->where('fiscal_year', $year)->first();
    }

    /**
     * Riga di quell'anno appartenente proprio all'azienda (non la condivisa):
     * è il duplicato che il form deve impedire.
     */
    public function findOwnByYear(int $year, ?int $companyId = null): ?array
    {
        $companyId ??= active_company_id();

        return $this->where('fiscal_year', $year)
            ->where('company_id', $companyId)
            ->first();
    }

    /**
     * Riga apribile dall'azienda attiva (sua o condivisa), o null.
     */
    public function findEffective(int $id): ?array
    {
        return $this->scopeToCompany()->where('fiscal_id', $id)->first();
    }

    /**
     * Parametri dell'anno richiesto, nella forma attesa da FinanceService.
     *
     * Un anno non coperto non deve far fallire la pagina: l'archivio contiene
     * documenti piu' vecchi di qualunque tabella si decida di mantenere, e chi
     * apre il 2019 si aspetta un prospetto, non un errore. Si ripiega sull'anno
     * definito piu' vicino per difetto (la legge in vigore piu' prossima), e in
     * mancanza sul piu' antico disponibile.
     *
     * @return array{params: array<string, mixed>, year: int, exact: bool, projection: bool}
     */
    public function paramsForYear(int $year): array
    {
        $rows = $this->scopeEffective()->orderBy('fiscal_year', 'ASC')->findAll();

        if (!$rows) {
            // Tabella vuota: si torna alla configurazione di riferimento
            // invece di lasciare la pagina senza aliquote.
            $fallback = (new Fiscal())->forYear($year);

            return $fallback + ['projection' => false];
        }

        $chosen = $rows[0];
        foreach ($rows as $row) {
            if ((int) $row['fiscal_year'] <= $year) {
                $chosen = $row;
            }
        }

        $chosenYear = (int) $chosen['fiscal_year'];

        return [
            'params'     => $this->toParams($chosen),
            'year'       => $chosenYear,
            'exact'      => $chosenYear === $year,
            'projection' => db_bool($chosen['fiscal_is_projection']),
        ];
    }

    /**
     * Riga di tabella → array di parametri per il calcolo.
     */
    private function toParams(array $row): array
    {
        $year     = (int) $row['fiscal_year'];
        // Gli scaglioni seguono la riga: quelli dell'azienda per una riga
        // sua, quelli condivisi per una riga condivisa.
        $companyId = isset($row['company_id']) && $row['company_id'] !== null ? (int) $row['company_id'] : null;
        $brackets  = (new FiscalBracketModel())->forYear($year, $companyId);

        if (!$brackets) {
            // Un anno senza scaglioni produrrebbe IRPEF zero, cioe' una stima
            // silenziosamente sbagliata: meglio quelli di riferimento.
            $config   = (new Fiscal())->forYear($year);
            $brackets = $config['params']['irpef']['scaglioni'];
        }

        return [
            'irpef' => [
                'scaglioni'             => $brackets,
                'addizionale_regionale' => (float) $row['fiscal_add_regionale'],
                'addizionale_comunale'  => (float) $row['fiscal_add_comunale'],
            ],
            'inps_artigiani' => [
                'minimale'         => (float) $row['fiscal_inps_art_minimale'],
                'massimale'        => (float) $row['fiscal_inps_art_massimale'],
                'aliquota'         => (float) $row['fiscal_inps_art_aliquota'],
                'contributo_fisso' => (float) $row['fiscal_inps_art_fisso'],
            ],
            'inps_gestione_separata' => ['aliquota' => (float) $row['fiscal_inps_gs_aliquota']],
            'ires' => ['aliquota' => (float) $row['fiscal_ires_aliquota']],
            'irap' => ['aliquota' => (float) $row['fiscal_irap_aliquota']],
            'forfettario' => [
                'aliquota_ordinaria' => (float) $row['fiscal_forf_ordinaria'],
                'aliquota_startup'   => (float) $row['fiscal_forf_startup'],
                'limite_ricavi'      => (float) $row['fiscal_forf_limite'],
            ],
        ];
    }

    protected function clearFinanceCache(array $data): array
    {
        if (function_exists('invalidate_finance_cache')) {
            invalidate_finance_cache();
        }

        return $data;
    }
}
