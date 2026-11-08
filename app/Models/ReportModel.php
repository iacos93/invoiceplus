<?php

namespace App\Models;

use CodeIgniter\Database\BaseConnection;

/**
 * ReportModel
 *
 * Porting CI4 di Mdl_Reports (CI3): report vendite per cliente,
 * storico pagamenti, scadenze fatture e vendite per anno.
 *
 * Non ha una tabella propria (come Mdl_Reports in CI3 non estende
 * MY_Model): usa direttamente il connection builder di CodeIgniter.
 */
class ReportModel
{
    protected BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Vendite per cliente nel periodo (equivalente di sales_by_client() CI3):
     * numero fatture, imponibile e totale fatturato per cliente.
     */
    public function salesByClient(string $from, string $to): array
    {
        $fromEsc = $this->db->escape($from);
        $toEsc   = $this->db->escape($to);

        // I report sono per azienda: senza questo filtro sommerebbero i
        // documenti di tutte le aziende in un unico prospetto.
        // Le bozze restano fuori come ovunque nel conteggio del fatturato: una
        // fattura non emessa non è una vendita, e qui entrava nel totale (e nel
        // conteggio dei documenti) del cliente a cui era stata preparata. Il
        // filtro viaggia con lo scope perché è agganciato a ogni sottoquery.
        $scope = company_scope_sql('ip_invoices') . ' AND ip_invoices.invoice_status_id <> 1';

        $builder = scope_builder_to_company($this->db->table('ip_clients'), 'ip_clients');
        $builder->select('ip_clients.client_id, ip_clients.client_name, ip_clients.client_surname,
            CONCAT(ip_clients.client_name,\' \', ip_clients.client_surname) AS client_namesurname', false);

        $builder->select("(
            SELECT COUNT(*) FROM ip_invoices
                WHERE ip_invoices.client_id = ip_clients.client_id
                    AND invoice_date_created >= {$fromEsc}
                    AND invoice_date_created <= {$toEsc}{$scope}
        ) AS invoice_count", false);

        $builder->select("(
            SELECT SUM(COALESCE(invoice_total, 0) - COALESCE(invoice_item_tax_total, 0) - COALESCE(invoice_tax_total, 0)) FROM ip_invoice_amounts
                WHERE ip_invoice_amounts.invoice_id IN (
                    SELECT invoice_id FROM ip_invoices
                        WHERE ip_invoices.client_id = ip_clients.client_id
                            AND invoice_date_created >= {$fromEsc}
                            AND invoice_date_created <= {$toEsc}{$scope}
                )
        ) AS sales", false);

        $builder->select("(
            SELECT SUM(invoice_total) FROM ip_invoice_amounts
                WHERE ip_invoice_amounts.invoice_id IN (
                    SELECT invoice_id FROM ip_invoices
                        WHERE ip_invoices.client_id = ip_clients.client_id
                            AND invoice_date_created >= {$fromEsc}
                            AND invoice_date_created <= {$toEsc}{$scope}
                )
        ) AS sales_with_tax", false);

        $builder->where("ip_clients.client_id IN (
            SELECT client_id FROM ip_invoices
                WHERE invoice_date_created >= {$fromEsc}
                    AND invoice_date_created <= {$toEsc}{$scope}
        )", null, false);

        // Ordine per fatturato decrescente, non alfabetico: la domanda del
        // report e' "chi fattura di piu'", e in ordine di nome la risposta si
        // ottiene solo rileggendo tutte le righe. Il nome resta come criterio
        // di spareggio, cosi' l'ordine e' stabile fra due esecuzioni.
        // (MySQL mette gli alias NULL in fondo con DESC: i clienti senza
        // importi finiscono dopo quelli con fatturato, che e' l'ordine giusto.)
        $builder->orderBy('sales', 'DESC');
        $builder->orderBy('client_namesurname');

        return $builder->get()->getResultArray();
    }

    /**
     * Storico pagamenti nel periodo (equivalente di payment_history() CI3,
     * basata sulla default_select/default_join di Mdl_Payments).
     */
    public function paymentHistory(string $from, string $to): array
    {
        return scope_builder_to_company($this->db->table('ip_payments'), 'ip_invoices')
            ->select("ip_payment_methods.payment_method_name, ip_invoice_amounts.*,
                ip_clients.client_name, ip_clients.client_surname, ip_clients.client_id,
                ip_invoices.invoice_number, ip_invoices.invoice_date_created, ip_payments.*", false)
            ->join('ip_invoices', 'ip_invoices.invoice_id = ip_payments.invoice_id')
            ->join('ip_clients', 'ip_clients.client_id = ip_invoices.client_id')
            ->join('ip_invoice_amounts', 'ip_invoice_amounts.invoice_id = ip_invoices.invoice_id', 'left')
            ->join('ip_payment_methods', 'ip_payment_methods.payment_method_id = ip_payments.payment_method_id', 'left')
            ->where('ip_payments.payment_date >=', $from)
            ->where('ip_payments.payment_date <=', $to)
            ->orderBy('ip_payments.payment_date', 'DESC')
            ->orderBy('ip_payments.payment_id', 'DESC')
            ->get()
            ->getResultArray();
    }

    /**
     * Scadenze fatture per cliente (equivalente di invoice_aging() CI3):
     * saldo residuo (fatture con saldo > 0 e scadenza passata) diviso in
     * fasce 1-30, 31-60, 61-90, 91-120 e oltre 120 giorni.
     */
    public function invoiceAging(): array
    {
        // I report sono per azienda: senza questo filtro sommerebbero i
        // documenti di tutte le aziende in un unico prospetto.
        // Fuori le bozze, come nello scadenzario e in getOverdueSummary(): una
        // fattura non emessa non ha una scadenza da sollecitare, ma occupava
        // una fascia di ritardo e gonfiava l'insoluto del cliente.
        $scope = company_scope_sql('ip_invoices') . ' AND ip_invoices.invoice_status_id <> 1';

        $builder = scope_builder_to_company($this->db->table('ip_clients'), 'ip_clients');
        $builder->select('ip_clients.client_id, ip_clients.client_name, ip_clients.client_surname,
            CONCAT(ip_clients.client_name,\' \', ip_clients.client_surname) AS client_namesurname', false);

        $ranges = [
            'range_1' => 'BETWEEN 1 AND 30',
            'range_2' => 'BETWEEN 31 AND 60',
            'range_3' => 'BETWEEN 61 AND 90',
            'range_4' => 'BETWEEN 91 AND 120',
            'range_5' => '> 120',
        ];

        foreach ($ranges as $alias => $range) {
            $builder->select("(
                SELECT SUM(ip_invoice_amounts.invoice_balance) FROM ip_invoice_amounts
                    WHERE ip_invoice_amounts.invoice_id IN (
                        SELECT invoice_id FROM ip_invoices
                            WHERE ip_invoices.client_id = ip_clients.client_id
                                AND ip_invoices.invoice_date_due < CURRENT_DATE
                                AND (CURRENT_DATE - ip_invoices.invoice_date_due) {$range}{$scope}
                    )
                    AND ip_invoice_amounts.invoice_balance > 0
            ) AS {$alias}", false);
        }

        $builder->select("(
            SELECT SUM(ip_invoice_amounts.invoice_balance) FROM ip_invoice_amounts
                WHERE ip_invoice_amounts.invoice_id IN (
                    SELECT invoice_id FROM ip_invoices
                        WHERE ip_invoices.client_id = ip_clients.client_id
                            AND ip_invoices.invoice_date_due < CURRENT_DATE{$scope}
                )
                AND ip_invoice_amounts.invoice_balance > 0
        ) AS total_balance", false);

        // PostgreSQL non consente alias di colonna in HAVING: si compila
        // la SELECT interna e la si avvolge in una subquery filtrando con WHERE.
        $innerSql = $builder->getCompiledSelect();

        $sql = "SELECT * FROM ({$innerSql}) AS aging
            WHERE COALESCE(range_1,0) > 0
               OR COALESCE(range_2,0) > 0
               OR COALESCE(range_3,0) > 0
               OR COALESCE(range_4,0) > 0
               OR COALESCE(range_5,0) > 0
               OR COALESCE(total_balance,0) > 0
            ORDER BY client_namesurname";

        return $this->db->query($sql)->getResultArray();
    }

    /**
     * Vendite per anno e trimestre per cliente (equivalente di
     * sales_by_year() CI3): totale annuo (con o senza imposte) e
     * totali trimestrali per ogni anno dell'intervallo, con filtro
     * opzionale su importo minimo/massimo.
     */
    public function salesByYear(int $from, int $to, ?int $minQuantity = null, ?int $maxQuantity = null, bool $checkboxTax = false): array
    {
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $fromDate = $from . '-01-01';
        $toDate   = $to . '-12-31';

        $fromEsc = $this->db->escape($fromDate);
        $toEsc   = $this->db->escape($toDate);

        // Senza IVA si usa lordo meno imposta, non l'imponibile di riga: sconto
        // di documento e contributo cassa stanno sul totale, quindi con la
        // somma delle righe il totale del report non tornava col documento.
        $sumColumn = $checkboxTax
            ? 'amounts.invoice_total'
            : '(amounts.invoice_total - COALESCE(amounts.invoice_item_tax_total, 0) - COALESCE(amounts.invoice_tax_total, 0))';

        // Fuori le bozze: stesso criterio del report per cliente e della
        // dashboard, dove il fatturato sono le fatture emesse.
        $scopeInv = company_scope_sql('inv') . ' AND inv.invoice_status_id <> 1';

        $builder = scope_builder_to_company($this->db->table('ip_clients'), 'ip_clients');
        $builder->select('ip_clients.client_id');
        $builder->select('ip_clients.client_vat_id AS VAT_ID');
        $builder->select('ip_clients.client_name AS Name');
        // La vista stampa il cliente con format_client(), che legge
        // client_name/client_surname: con il solo alias "Name" la colonna
        // Cliente usciva vuota su ogni riga.
        $builder->select('ip_clients.client_name, ip_clients.client_surname');
        $builder->select('CONCAT(ip_clients.client_name,\' \', ip_clients.client_surname) AS client_namesurname', false);

        $builder->select("(
            SELECT SUM({$sumColumn}) FROM ip_invoice_amounts amounts
                WHERE amounts.invoice_id IN (
                    SELECT inv.invoice_id FROM ip_invoices inv
                        WHERE inv.client_id = ip_clients.client_id
                            AND {$fromEsc} <= inv.invoice_date_created
                            AND {$toEsc} >= inv.invoice_date_created{$scopeInv}
                )
        ) AS total_payment", false);

        for ($year = $from; $year <= $to; $year++) {
            for ($quarter = 1; $quarter <= 4; $quarter++) {
                $months = implode(', ', range(($quarter - 1) * 3 + 1, $quarter * 3));

                $builder->select("(
                    SELECT SUM({$sumColumn}) FROM ip_invoice_amounts amounts
                        WHERE amounts.invoice_id IN (
                            SELECT inv.invoice_id FROM ip_invoices inv
                                WHERE inv.client_id = ip_clients.client_id
                                    AND {$fromEsc} <= inv.invoice_date_created
                                    AND {$toEsc} >= inv.invoice_date_created{$scopeInv}
                                    AND EXTRACT(YEAR FROM inv.invoice_date_created) = {$year}
                                    AND EXTRACT(MONTH FROM inv.invoice_date_created) IN ({$months})
                        )
                ) AS payment_t{$quarter}_{$year}", false);
            }
        }

        // Filtro quantità (minimo/massimo) sul totale del periodo
        $totalSubquery = "(
            SELECT SUM({$sumColumn}) FROM ip_invoice_amounts amounts
                WHERE amounts.invoice_id IN (
                    SELECT inv.invoice_id FROM ip_invoices inv
                        WHERE inv.client_id = ip_clients.client_id
                            AND {$fromEsc} <= inv.invoice_date_created
                            AND {$toEsc} >= inv.invoice_date_created{$scopeInv}
                )
        )";

        $builder->where($totalSubquery . ' >= ' . (int) ($minQuantity ?? 0), null, false);

        if ($maxQuantity !== null && $maxQuantity > 0) {
            $builder->where($totalSubquery . ' <= ' . (int) $maxQuantity, null, false);
        }

        $builder->orderBy('client_namesurname');

        return $builder->get()->getResultArray();
    }
}