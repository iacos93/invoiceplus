<?php

namespace App\Commands;

use App\Models\CostingAccountModel;
use App\Services\CostingService;
use App\Services\ManagementReport;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Verifica temporanea di coerenza (audit della logica). Non fa parte
 * dell'applicazione: va cancellata dopo l'uso.
 */
class AuditCheck extends BaseCommand
{
    protected $group       = 'Audit';
    protected $name        = 'audit:check';
    protected $description = 'Controlli di coerenza su Bilancino, piano dei conti e budget';

    public function run(array $params)
    {
        $year   = (int) ($params[0] ?? date('Y'));
        $report = (new ManagementReport())->forYear($year);
        $db     = db_connect();
        $company = (int) active_company_id();

        CLI::write("Anno {$year}, azienda {$company}", 'yellow');

        /* ---------------------------------------------------------------
         * 1. Ricavi: tabella + non assegnati vs somma righe
         * ------------------------------------------------------------ */
        $dbRevenue = (float) $db->query("
            SELECT COALESCE(SUM(ia.item_subtotal - COALESCE(ia.item_discount,0)),0) AS v
            FROM ip_invoices i
            JOIN ip_invoice_items it ON it.invoice_id = i.invoice_id
            LEFT JOIN ip_invoice_item_amounts ia ON ia.item_id = it.item_id
            WHERE EXTRACT(YEAR FROM i.invoice_date_created) = ?
              AND i.invoice_status_id <> 1 AND (i.company_id = ? OR i.company_id IS NULL)
        ", [$year, $company])->getRow()->v;

        $shownRevenue = CostingService::total($report['totals']['ricavo'])
            + CostingService::total($report['unassigned']['ricavo']);

        $this->compare('Ricavi (tabella + non assegnati)', $shownRevenue, $dbRevenue);

        /* ---------------------------------------------------------------
         * 2. Costi: tabella + non assegnati vs somma spese
         * ------------------------------------------------------------ */
        $dbCosts = (float) $db->query("
            SELECT COALESCE(SUM(expense_amount),0) AS v
            FROM ip_expenses
            WHERE EXTRACT(YEAR FROM expense_date) = ?
              AND (company_id = ? OR company_id IS NULL)
        ", [$year, $company])->getRow()->v;

        $shownCosts = CostingService::total($report['totals']['costo_variabile'])
            + CostingService::total($report['totals']['costo_fisso'])
            + CostingService::total($report['unassigned']['costo']);

        $this->compare('Costi (tabella + non assegnati)', $shownCosts, $dbCosts);

        /* ---------------------------------------------------------------
         * 3. EBITDA = ricavi - variabili - fissi
         * ------------------------------------------------------------ */
        $ebitdaCalc = round(
            CostingService::total($report['totals']['ricavo'])
            - CostingService::total($report['totals']['costo_variabile'])
            - CostingService::total($report['totals']['costo_fisso']),
            2
        );
        $this->compare('EBITDA', CostingService::total($report['totals']['ebitda']), $ebitdaCalc);

        /* ---------------------------------------------------------------
         * 4. Budget: somma righe vs totale calcolato
         * ------------------------------------------------------------ */
        $dbBudget = (float) $db->query("
            SELECT COALESCE(SUM(b.budget_amount),0) AS v
            FROM ip_costing_budgets b
            JOIN ip_costing_accounts a ON a.account_id = b.account_id
            WHERE b.budget_year = ? AND (b.company_id = ? OR b.company_id IS NULL)
        ", [$year, $company])->getRow()->v;

        $shownBudget = array_sum($report['budget']['totals']);

        $this->compare('Budget annuo', $shownBudget, $dbBudget);

        /* ---------------------------------------------------------------
         * 5. Integrità voci: padre inesistente, tipo diverso, cicli
         * ------------------------------------------------------------ */
        $accounts = (new CostingAccountModel())->allForCompany();
        $byId     = array_column($accounts, null, 'account_id');

        foreach ($accounts as $a) {
            $pid = (int) ($a['parent_id'] ?? 0);

            if ($pid !== 0 && !isset($byId[$pid])) {
                CLI::write('  ! voce ' . $a['account_code'] . ': padre ' . $pid . ' inesistente', 'red');
            } elseif ($pid !== 0 && $byId[$pid]['account_type'] !== $a['account_type']) {
                CLI::write('  ! voce ' . $a['account_code'] . ': tipo diverso dal padre', 'red');
            }
        }

        foreach ($accounts as $a) {
            $seen  = [];
            $cur   = (int) $a['parent_id'];
            $depth = 0;

            while ($cur !== 0 && $depth < 50) {
                if (isset($seen[$cur])) {
                    CLI::write('  ! ciclo sull\'albero a partire da ' . $a['account_code'], 'red');
                    break;
                }
                $seen[$cur] = true;
                $cur = (int) ($byId[$cur]['parent_id'] ?? 0);
                $depth++;
            }
        }

        /* ---------------------------------------------------------------
         * 6. Movimenti con voce inesistente o di tipo sbagliato
         * ------------------------------------------------------------ */
        $badItems = $db->query("
            SELECT COUNT(*) AS v FROM ip_invoice_items it
            LEFT JOIN ip_costing_accounts a ON a.account_id = it.costing_account_id
            WHERE it.costing_account_id IS NOT NULL
              AND (a.account_id IS NULL OR a.account_type <> 'ricavo')
        ")->getRow()->v;
        CLI::write('Righe con voce inesistente o non di ricavo: ' . $badItems, $badItems > 0 ? 'red' : 'green');

        $badExpenses = $db->query("
            SELECT COUNT(*) AS v FROM ip_expenses e
            LEFT JOIN ip_costing_accounts a ON a.account_id = e.costing_account_id
            WHERE e.costing_account_id IS NOT NULL
              AND (a.account_id IS NULL OR a.account_type = 'ricavo')
        ")->getRow()->v;
        CLI::write('Spese con voce inesistente o di ricavo: ' . $badExpenses, $badExpenses > 0 ? 'red' : 'green');

        $badBudget = $db->query("
            SELECT COUNT(*) AS v FROM ip_costing_budgets b
            LEFT JOIN ip_costing_accounts a ON a.account_id = b.account_id
            WHERE a.account_id IS NULL OR b.budget_month < 1 OR b.budget_month > 12
        ")->getRow()->v;
        CLI::write('Budget orfani o con mese fuori range: ' . $badBudget, $badBudget > 0 ? 'red' : 'green');

        /* ---------------------------------------------------------------
         * 7. Numerazione: duplicati per azienda e anno
         * ------------------------------------------------------------ */
        $dupes = $db->query("
            SELECT company_id, EXTRACT(YEAR FROM invoice_date_created) AS y, invoice_number, COUNT(*) AS n
            FROM ip_invoices
            WHERE invoice_number IS NOT NULL AND invoice_number <> ''
            GROUP BY company_id, y, invoice_number
            HAVING COUNT(*) > 1
        ")->getResultArray();
        CLI::write('Numeri fattura duplicati: ' . count($dupes), $dupes !== [] ? 'red' : 'green');

        foreach ($dupes as $d) {
            CLI::write('  ! ' . $d['invoice_number'] . ' anno ' . $d['y'] . ' x' . $d['n'], 'red');
        }

        /* ---------------------------------------------------------------
         * 8. Righe/spese orfane (integrità referenziale)
         * ------------------------------------------------------------ */
        $orphans = [
            'righe senza fattura'      => 'SELECT COUNT(*) AS v FROM ip_invoice_items it LEFT JOIN ip_invoices i ON i.invoice_id = it.invoice_id WHERE i.invoice_id IS NULL',
            'righe senza totali'       => 'SELECT COUNT(*) AS v FROM ip_invoice_items it LEFT JOIN ip_invoice_item_amounts ia ON ia.item_id = it.item_id WHERE ia.item_id IS NULL',
            'fatture senza totali'     => 'SELECT COUNT(*) AS v FROM ip_invoices i LEFT JOIN ip_invoice_amounts a ON a.invoice_id = i.invoice_id WHERE a.invoice_id IS NULL',
            'pagamenti senza fattura'  => 'SELECT COUNT(*) AS v FROM ip_payments p LEFT JOIN ip_invoices i ON i.invoice_id = p.invoice_id WHERE i.invoice_id IS NULL',
            'preventivi senza totali'  => 'SELECT COUNT(*) AS v FROM ip_quotes q LEFT JOIN ip_quote_amounts a ON a.quote_id = q.quote_id WHERE a.quote_id IS NULL',
            'righe preventivo orfane'  => 'SELECT COUNT(*) AS v FROM ip_quote_items it LEFT JOIN ip_quotes q ON q.quote_id = it.quote_id WHERE q.quote_id IS NULL',
            'note di credito senza padre' => 'SELECT COUNT(*) AS v FROM ip_invoices i LEFT JOIN ip_invoices p ON p.invoice_id = i.creditinvoice_parent_id WHERE i.creditinvoice_parent_id IS NOT NULL AND p.invoice_id IS NULL',
        ];

        foreach ($orphans as $label => $sql) {
            $n = (int) $db->query($sql)->getRow()->v;
            CLI::write($label . ': ' . $n, $n > 0 ? 'red' : 'green');
        }

        /* ---------------------------------------------------------------
         * 9. Saldo delle fatture vs stato
         * ------------------------------------------------------------ */
        $stuck = $db->query("
            SELECT COUNT(*) AS v FROM ip_invoices i
            JOIN ip_invoice_amounts a ON a.invoice_id = i.invoice_id
            WHERE i.invoice_status_id NOT IN (1,4)
              AND a.invoice_balance <= 0
        ")->getRow()->v;
        CLI::write('Fatture aperte con saldo <= 0 (dovrebbero essere pagate): ' . $stuck, $stuck > 0 ? 'red' : 'green');

        $paidButOpen = $db->query("
            SELECT COUNT(*) AS v FROM ip_invoices i
            JOIN ip_invoice_amounts a ON a.invoice_id = i.invoice_id
            WHERE i.invoice_status_id = 4 AND a.invoice_balance > 0.004
        ")->getRow()->v;
        CLI::write('Fatture segnate pagate con saldo > 0: ' . $paidButOpen, $paidButOpen > 0 ? 'red' : 'green');

        return EXIT_SUCCESS;
    }

    private function compare(string $label, float $shown, float $expected): void
    {
        $diff = round($shown - $expected, 2);
        $ok   = abs($diff) < 0.005;

        CLI::write(
            sprintf('%s: mostrato %.2f, atteso %.2f%s', $label, $shown, $expected, $ok ? '' : ' — DIFF ' . $diff),
            $ok ? 'green' : 'red'
        );
    }
}
