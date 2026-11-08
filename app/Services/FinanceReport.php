<?php

namespace App\Services;

use App\Models\ExpenseModel;
use App\Models\FiscalYearModel;
use App\Models\InvoiceModel;

/**
 * FinanceReport — raccoglie i dati dell'anno per "Utile e imposte".
 *
 * Sta fra i modelli e FinanceService: interroga il database, applica il regime
 * dell'azienda attiva e restituisce il prospetto completo. FinanceService resta
 * matematica pura, il controller resta sottile.
 *
 * Esiste perché il prospetto ora serve a due chiamanti — la pagina e
 * l'esportazione — e la stessa assemblatura scritta due volte sarebbe divergita
 * alla prima modifica, con l'export che mostra numeri diversi dalla pagina che
 * dice di esportare.
 */
class FinanceReport
{
    /**
     * Durata della cache in secondi (1 ora).
     * I dati cambiano solo quando si creano/modificano fatture o spese,
     * quindi un'ora è un buon compromesso tra freschezza e performance.
     */
    private const CACHE_TTL = 3600;

    /**
     * Prospetto completo dell'anno per l'azienda attiva.
     *
     * @return array{
     *     year: int, company: array, forms: array, form: string,
     *     coefficient: float, contributions: float,
     *     income: array, expenses: array, profit: float,
     *     by_month: array, by_category: array, taxes: array,
     *     fiscal_year: int, fiscal_exact: bool, fiscal_projection: bool
     * }
     */
    public function forYear(int $year): array
    {
        $from = $year . '-01-01';
        $to   = $year . '-12-31';

        // Forma aziendale e aliquote sono dati dell'azienda attiva, non
        // scelte del singolo utente: holding e societa' operativa hanno regimi
        // diversi, e leggerle dalle impostazioni globali applicava a entrambe
        // quello di una sola. Qui si leggono dalla scheda dell'azienda.
        $company   = active_company();
        $companyId = active_company_id();

        $forms = FinanceService::companyForms();
        $form  = (string) ($company['company_form'] ?? '');
        $form  = isset($forms[$form]) ? $form : 'semplificato';

        $coefficient = (float) ($company['company_coefficient'] ?? 0) ?: 78;

        // Aliquote e scaglioni dell'anno in esame, non piu' costanti fisse: il
        // filtro anno adesso cambia anche la stima, non solo i documenti contati.
        // Vengono dalla tabella dei parametri fiscali, che si aggiorna
        // dall'interfaccia quando cambia la legge; se e' vuota si ripiega sui
        // valori di riferimento inclusi nell'applicazione.
        $fiscal = (new FiscalYearModel())->paramsForYear($year);

        // Aliquota contributiva: quella della scheda azienda se compilata,
        // altrimenti quella dell'anno per lo schema scelto. Prima il ripiego
        // era 26,23 fisso — l'aliquota della gestione separata — e finiva
        // applicato anche a chi aveva scelto artigiani e commercianti, che ha
        // un'aliquota propria: la stima usciva sbagliata senza che nulla nella
        // pagina lo lasciasse capire.
        $scheme = (string) ($company['company_inps_scheme'] ?? 'separata');
        $scheme = in_array($scheme, ['separata', 'artigiani'], true) ? $scheme : 'separata';

        $contributions = $this->nullableRate($company['company_contributions'] ?? null)
            ?? (float) ($scheme === 'artigiani'
                ? $fiscal['params']['inps_artigiani']['aliquota']
                : $fiscal['params']['inps_gestione_separata']['aliquota']);

        // Cache key basata su anno + azienda: cambiando azienda o anno si
        // invalida automaticamente. La cache viene anche invalidata quando si
        // salvano fatture, spese, la scheda azienda o i parametri fiscali.
        $cacheKey = "finance_{$companyId}_{$year}";
        $data     = cache($cacheKey);

        if ($data === null) {
            $invoiceModel = new InvoiceModel();
            $expenseModel = new ExpenseModel();

            $incomeByMonth   = $invoiceModel->incomeByMonth($year);
            $expensesByMonth = $expenseModel->expensesByMonth($year);

            $byMonth = [];
            for ($m = 1; $m <= 12; $m++) {
                $byMonth[$m] = [
                    'income'  => $incomeByMonth[$m],
                    'expense' => $expensesByMonth[$m],
                ];
            }

            $income   = $invoiceModel->incomeTotalsForPeriod($from, $to);
            $expenses = $expenseModel->totalsForPeriod($from, $to);

            // Utile di competenza: ricavi meno costi, entrambi al netto
            // dell'IVA. Il netto dei ricavi è lordo meno imposta (vedi
            // InvoiceModel::incomeTotalsForPeriod), così comprende sconto di
            // documento e contributo cassa: stanno sul totale, non sulle righe.
            $profit = $income['net'] - $expenses['amount'];

            $data = [
                'income'      => $income,
                'expenses'    => $expenses,
                'profit'      => $profit,
                'by_category' => $expenseModel->byCategory($from, $to),
                'by_month'    => $byMonth,
                'taxes'       => (new FinanceService())->estimateTaxes(
                    $forms[$form]['mode'],
                    $income['net'],
                    $profit,
                    $fiscal['params'],
                    [
                        'coefficient'         => $coefficient,
                        'contributions'       => $contributions,
                        'inps_scheme'         => $scheme,
                        'forfettario_startup' => !empty($company['company_forfettario_startup']),
                        // Solo se valorizzate: NULL vuol dire "usa la media
                        // nazionale dell'anno", non "zero".
                        'add_regionale'       => $this->nullableRate($company['company_add_regionale'] ?? null),
                        'add_comunale'        => $this->nullableRate($company['company_add_comunale'] ?? null),
                        // Voci aggiuntive della scheda azienda (INAIL, fondi...)
                        'extras'              => $this->taxExtras($company['company_tax_extras'] ?? null),
                    ]
                ),
            ];

            cache()->save($cacheKey, $data, self::CACHE_TTL);
        }

        return $data + [
            'year'          => $year,
            'company'       => $company,
            'forms'         => $forms,
            'form'          => $form,
            'coefficient'   => $coefficient,
            'contributions' => $contributions,
            // Se l'anno richiesto non ha parametri propri la stima usa quelli
            // dell'anno coperto piu' vicino: va detto, non lasciato intendere.
            'fiscal_year'       => $fiscal['year'],
            'fiscal_exact'      => $fiscal['exact'],
            'fiscal_projection' => $fiscal['projection'] ?? false,
        ];
    }

    /**
     * Aliquota facoltativa: null se non valorizzata.
     *
     * Il campo vuoto significa "usa la media nazionale dell'anno", non "zero":
     * castare direttamente a float avrebbe azzerato l'addizionale per tutti
     * quelli che non la compilano. FinanceService distingue i due casi con
     * isset(), che su null è già false.
     */
    private function nullableRate($value): ?float
    {
        return ($value === null || $value === '') ? null : (float) $value;
    }

    /**
     * Voci contributive aggiuntive della scheda azienda.
     *
     * Il JSON non valido o vuoto non deve far cadere il prospetto: senza voci
     * la stima è quella di prima, non un errore.
     *
     * @return list<array<string, mixed>>
     */
    private function taxExtras($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $extras = json_decode($value, true);

        return is_array($extras) ? $extras : [];
    }

    /**
     * Anno di riferimento del prospetto.
     *
     * Con "Tutti gli anni" (0) resta comunque un anno solo: la stima delle
     * imposte è per definizione annuale (scaglioni IRPEF, accantonamenti),
     * quindi il periodo di riferimento è l'anno corrente.
     */
    public static function currentYear(): int
    {
        return selected_year() ?: (int) date('Y');
    }
}
