<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ripara lo stato lasciato in archivio da tre difetti a monte, corretti
 * insieme a questa migrazione.
 *
 * 1. Serie documenti cancellate mentre erano ancora in uso. Invoice_Groups
 *    ::delete() non controllava se qualcuno puntasse al gruppo, quindi in
 *    archivio c'erano fatture e preventivi con invoice_group_id inesistente:
 *    in modifica la tendina della serie risultava vuota e il primo
 *    salvataggio spostava di nascosto il documento su un'altra serie.
 *
 * 2. Documenti senza la riga degli importi. Le viste li reggono con IFNULL,
 *    ma qualsiasi somma scritta a mano su ip_quote_amounts li perde.
 *
 * 3. invoice_paid / invoice_balance a NULL su fatture con un totale. È il
 *    caso peggiore dei tre, perché è silenzioso: scadenzario e insoluti
 *    filtrano su "IFNULL(invoice_balance, 0) > 0", e una fattura con saldo
 *    NULL sparisce dai crediti da incassare pur essendo aperta.
 *
 * Gli importi si ricostruiscono dai pagamenti registrati, che sono la fonte
 * di verità: non si inventa nulla. Le righe che hanno già un invoice_paid
 * valorizzato non vengono toccate, anche quando non hanno pagamenti dietro —
 * sono documenti storici caricati a mano e riscriverli qui significherebbe
 * cambiare d'ufficio dei numeri contabili.
 */
class RepairOrphanReferencesAndAmounts extends Migration
{
    public function up()
    {
        $this->reassignOrphanGroups('ip_invoices', 'invoice_id', 'default_invoice_group');
        $this->reassignOrphanGroups('ip_quotes', 'quote_id', 'default_quote_group');

        // Righe importi mancanti: si creano vuote, come fanno
        // InvoiceModel::create() e QuoteModel::create(), e i totali vengono
        // poi normalizzati dal passo successivo.
        $this->db->query("
            INSERT INTO ip_invoice_amounts (invoice_id)
            SELECT i.invoice_id FROM ip_invoices i
            LEFT JOIN ip_invoice_amounts a ON a.invoice_id = i.invoice_id
            WHERE a.invoice_id IS NULL");

        $this->db->query("
            INSERT INTO ip_quote_amounts (quote_id)
            SELECT q.quote_id FROM ip_quotes q
            LEFT JOIN ip_quote_amounts a ON a.quote_id = q.quote_id
            WHERE a.quote_id IS NULL");

        // Totali mai calcolati su documenti senza righe: valgono zero, ed è
        // quello che scriverebbe calculate(). Si escludono i documenti che le
        // righe ce le hanno, perché lì lo zero sarebbe una bugia: quelli vanno
        // ricalcolati dall'applicazione.
        $this->db->query("
            UPDATE ip_invoice_amounts a
            SET invoice_item_subtotal  = COALESCE(a.invoice_item_subtotal, 0.00),
                invoice_item_tax_total = COALESCE(a.invoice_item_tax_total, 0.00),
                invoice_tax_total      = COALESCE(a.invoice_tax_total, 0.00),
                invoice_total          = COALESCE(a.invoice_total, 0.00)
            WHERE a.invoice_total IS NULL
              AND NOT EXISTS (SELECT 1 FROM ip_invoice_items it WHERE it.invoice_id = a.invoice_id)");

        $this->db->query("
            UPDATE ip_quote_amounts a
            SET quote_item_subtotal  = COALESCE(a.quote_item_subtotal, 0.00),
                quote_item_tax_total = COALESCE(a.quote_item_tax_total, 0.00),
                quote_tax_total      = COALESCE(a.quote_tax_total, 0.00),
                quote_total          = COALESCE(a.quote_total, 0.00)
            WHERE a.quote_total IS NULL
              AND NOT EXISTS (SELECT 1 FROM ip_quote_items it WHERE it.quote_id = a.quote_id)");

        // Incassato e saldo: dai pagamenti registrati, solo dove mancano.
        $this->db->query("
            UPDATE ip_invoice_amounts a
            SET invoice_paid = COALESCE(
                    (SELECT SUM(p.payment_amount) FROM ip_payments p WHERE p.invoice_id = a.invoice_id), 0.00)
            WHERE a.invoice_paid IS NULL");

        $this->db->query("
            UPDATE ip_invoice_amounts a
            SET invoice_balance = COALESCE(a.invoice_total, 0.00) - COALESCE(a.invoice_paid, 0.00)
            WHERE a.invoice_balance IS NULL");
    }

    public function down()
    {
        // Rimettere a NULL dei saldi ricostruiti, o riportare i documenti su
        // una serie che non esiste, non ripristina niente: si resta così.
    }

    /**
     * Riporta i documenti che puntano a una serie inesistente (o a 0) sulla
     * serie predefinita della loro azienda; in mancanza, sulla prima serie
     * disponibile per quell'azienda. Se l'azienda non ha nessuna serie il
     * documento resta com'è: meglio un riferimento rotto che spostare una
     * fattura sulla numerazione di un'altra società.
     */
    private function reassignOrphanGroups(string $table, string $key, string $companyColumn): void
    {
        $orphans = $this->db->query("
            SELECT d.{$key} AS id, d.company_id
            FROM {$table} d
            LEFT JOIN ip_invoice_groups g ON g.invoice_group_id = d.invoice_group_id
            WHERE g.invoice_group_id IS NULL")->getResultArray();

        foreach ($orphans as $orphan) {
            $target = $this->fallbackGroup((int) ($orphan['company_id'] ?? 0), $companyColumn);

            if ($target > 0) {
                $this->db->table($table)->where($key, $orphan['id'])
                    ->update(['invoice_group_id' => $target]);
            }
        }
    }

    private function fallbackGroup(int $companyId, string $companyColumn): int
    {
        if ($companyId > 0) {
            $company = $this->db->table('ip_companies')
                ->select($companyColumn)
                ->where('company_id', $companyId)
                ->get()->getRowArray();

            $preferred = (int) ($company[$companyColumn] ?? 0);

            if ($preferred > 0 && $this->groupBelongsTo($preferred, $companyId)) {
                return $preferred;
            }
        }

        $builder = $this->db->table('ip_invoice_groups')->select('invoice_group_id');

        if ($companyId > 0) {
            $builder->groupStart()
                    ->where('company_id', $companyId)
                    ->orWhere('company_id IS NULL', null, false)
                    ->groupEnd();
        }

        $row = $builder->orderBy('invoice_group_id', 'ASC')->get(1)->getRowArray();

        return (int) ($row['invoice_group_id'] ?? 0);
    }

    private function groupBelongsTo(int $groupId, int $companyId): bool
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS n FROM ip_invoice_groups
             WHERE invoice_group_id = ? AND (company_id = ? OR company_id IS NULL)',
            [$groupId, $companyId]
        )->getRowArray()['n'] > 0;
    }
}
