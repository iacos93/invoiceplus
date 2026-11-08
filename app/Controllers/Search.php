<?php

namespace App\Controllers;

/**
 * Search controller — ricerca globale dalla topbar.
 *
 * Interroga in una sola volta le entita' su cui si cerca davvero qualcosa
 * (fatture, preventivi, clienti, DDT, spese, prodotti) e restituisce JSON
 * raggruppato: il dropdown deve poter mostrare il tipo di ciascun risultato.
 *
 * Risponde in GET perche' non modifica nulla — e perche' con la protezione
 * CSRF via cookie httponly il token non e' leggibile dal JS.
 */
class Search extends BaseController
{
    /** Risultati per gruppo: oltre questo il dropdown smette di essere scorribile. */
    private const PER_GROUP = 5;

    public function globalSearch()
    {
        $query = trim((string) service('request')->getGet('q'));

        // Sotto i due caratteri ogni ricerca restituirebbe mezzo archivio.
        if (mb_strlen($query) < 2) {
            return $this->response->setJSON(['query' => $query, 'groups' => []]);
        }

        $groups = array_values(array_filter([
            $this->invoices($query),
            $this->quotes($query),
            $this->clients($query),
            $this->deliveryNotes($query),
            $this->expenses($query),
            $this->products($query),
        ], static fn ($group) => $group !== null));

        return $this->response->setJSON(['query' => $query, 'groups' => $groups]);
    }

    /**
     * Costruisce un gruppo di risultati, o null se la ricerca non ha prodotto
     * nulla per quell'entita' (il dropdown non mostra sezioni vuote).
     */
    private function group(string $label, string $icon, array $items): ?array
    {
        return $items === [] ? null : ['label' => $label, 'icon' => $icon, 'items' => $items];
    }

    /**
     * LIKE su un'espressione con il valore correttamente quotato.
     * Stesso motivo del likeExpression() dei model: like(..., false)
     * disattiverebbe l'escaping anche del termine cercato.
     */
    private function like($builder, string $expression, string $query)
    {
        $db    = db_connect();
        $value = $db->escape('%' . $db->escapeLikeString($query) . '%');

        // Case-insensitive: PostgreSQL (il driver in uso) considera LIKE
        // sensibile alle maiuscole, quindi "rossi" non trovava "Rossi".
        // LOWER() sui due lati rende il confronto indifferente al caso senza
        // legarsi al collation del database. Stessa scelta di
        // IpBaseModel::likeExpression(), che copre le ricerche nelle liste.
        return $builder->where("LOWER({$expression}) LIKE LOWER({$value}) ESCAPE '!'", null, false);
    }

    /**
     * Restringe all'azienda attiva le tabelle che hanno company_id, includendo
     * le righe senza azienda (dati precedenti al multi-azienda).
     */
    private function scoped(string $table)
    {
        $builder   = db_connect()->table($table);
        $companyId = active_company_id();

        if ($companyId && db_connect()->fieldExists('company_id', $table)) {
            $builder->groupStart()
                    ->where($table . '.company_id', $companyId)
                    ->orWhere($table . '.company_id IS NULL', null, false)
                    ->groupEnd();
        }

        return $builder;
    }


    /**
     * Etichetta di un documento. Le bozze non hanno ancora un numero: senza
     * ripiego comparirebbero come "Fattura" nuda, indistinguibili fra loro.
     */
    private function documentLabel(string $type, ?string $number, int $id): string
    {
        return $number !== null && trim($number) !== ''
            ? $type . ' ' . $number
            : $type . ' #' . $id . ' (' . trans('draft', '', 'bozza') . ')';
    }

    private function invoices(string $query): ?array
    {
        $builder = $this->scoped('ip_invoices')
            ->select("ip_invoices.invoice_id, ip_invoices.invoice_number, ip_invoices.invoice_date_created,
                      COALESCE(ip_invoice_amounts.invoice_total, 0) AS total,
                      CONCAT_WS(' ', ip_clients.client_name, ip_clients.client_surname) AS client", false)
            ->join('ip_clients', 'ip_clients.client_id = ip_invoices.client_id', 'left')
            ->join('ip_invoice_amounts', 'ip_invoice_amounts.invoice_id = ip_invoices.invoice_id', 'left');

        // CONCAT_WS ignora i NULL: passare l'importo così com'è evita il
        // COALESCE(..., '') che in PostgreSQL è un errore di tipo (numerico
        // contro stringa) e faceva fallire tutta la ricerca globale.
        $this->like($builder, "CONCAT_WS('^', ip_invoices.invoice_number,
            CONCAT_WS(' ', ip_clients.client_name, ip_clients.client_surname),
            ip_invoice_amounts.invoice_total)", $query);

        $rows = $builder->orderBy('ip_invoices.invoice_id', 'DESC')->limit(self::PER_GROUP)->get()->getResultArray();

        // L'icona viaggia nel JSON e il pannello la stampa dentro
        // <i class="fa ...">: la famiglia va quindi scritta qui, perche' dopo la
        // rimozione di v4-shims.css nessuno la aggiunge piu' per noi.
        return $this->group(trans('invoices', '', 'Fatture'), 'fa-solid fa-file-lines', array_map(fn ($r) => [
            'title'    => $this->documentLabel(trans('invoice', '', 'Fattura'), $r['invoice_number'], (int) $r['invoice_id']),
            'subtitle' => trim($r['client'] ?: '') . ' · ' . date_from_mysql($r['invoice_date_created']),
            'meta'     => format_currency($r['total']),
            'url'      => site_url('invoices/view/' . (int) $r['invoice_id']),
        ], $rows));
    }

    private function quotes(string $query): ?array
    {
        $builder = $this->scoped('ip_quotes')
            ->select("ip_quotes.quote_id, ip_quotes.quote_number, ip_quotes.quote_date_created,
                      COALESCE(ip_quote_amounts.quote_total, 0) AS total,
                      CONCAT_WS(' ', ip_clients.client_name, ip_clients.client_surname) AS client", false)
            ->join('ip_clients', 'ip_clients.client_id = ip_quotes.client_id', 'left')
            ->join('ip_quote_amounts', 'ip_quote_amounts.quote_id = ip_quotes.quote_id', 'left');

        $this->like($builder, "CONCAT_WS('^', ip_quotes.quote_number,
            CONCAT_WS(' ', ip_clients.client_name, ip_clients.client_surname))", $query);

        $rows = $builder->orderBy('ip_quotes.quote_id', 'DESC')->limit(self::PER_GROUP)->get()->getResultArray();

        return $this->group(trans('quotes', '', 'Preventivi'), 'fa-solid fa-file', array_map(fn ($r) => [
            'title'    => $this->documentLabel(trans('quote', '', 'Preventivo'), $r['quote_number'], (int) $r['quote_id']),
            'subtitle' => trim($r['client'] ?: '') . ' · ' . date_from_mysql($r['quote_date_created']),
            'meta'     => format_currency($r['total']),
            'url'      => site_url('quotes/view/' . (int) $r['quote_id']),
        ], $rows));
    }

    private function clients(string $query): ?array
    {
        $builder = $this->scoped('ip_clients')
            ->select('client_id, client_name, client_surname, client_email, client_phone, client_vat_id');

        $this->like($builder, "CONCAT_WS('^', ip_clients.client_name, ip_clients.client_surname,
            ip_clients.client_email, ip_clients.client_phone, ip_clients.client_vat_id)", $query);

        $rows = $builder->orderBy('client_name', 'ASC')->limit(self::PER_GROUP)->get()->getResultArray();

        return $this->group(trans('clients', '', 'Clienti'), 'fa-solid fa-user', array_map(static fn ($r) => [
            'title'    => trim($r['client_name'] . ' ' . $r['client_surname']),
            'subtitle' => $r['client_email'] ?: ($r['client_phone'] ?: ''),
            'meta'     => $r['client_vat_id'] ?: '',
            'url'      => site_url('clients/view/' . (int) $r['client_id']),
        ], $rows));
    }

    private function deliveryNotes(string $query): ?array
    {
        $builder = $this->scoped('ip_delivery_notes')
            ->select("ip_delivery_notes.delivery_note_id, ip_delivery_notes.delivery_note_number,
                      ip_delivery_notes.delivery_note_date,
                      CONCAT_WS(' ', ip_clients.client_name, ip_clients.client_surname) AS client", false)
            ->join('ip_clients', 'ip_clients.client_id = ip_delivery_notes.client_id', 'left');

        $this->like($builder, "CONCAT_WS('^', ip_delivery_notes.delivery_note_number,
            CONCAT_WS(' ', ip_clients.client_name, ip_clients.client_surname),
            COALESCE(ip_delivery_notes.carrier, ''))", $query);

        $rows = $builder->orderBy('ip_delivery_notes.delivery_note_id', 'DESC')->limit(self::PER_GROUP)->get()->getResultArray();

        return $this->group(trans('delivery_notes', '', 'Documenti di trasporto'), 'fa-truck', array_map(fn ($r) => [
            'title'    => $this->documentLabel('DDT', $r['delivery_note_number'], (int) $r['delivery_note_id']),
            'subtitle' => trim($r['client'] ?: '') . ' · ' . date_from_mysql($r['delivery_note_date']),
            'meta'     => '',
            'url'      => site_url('delivery_notes/view/' . (int) $r['delivery_note_id']),
        ], $rows));
    }

    private function expenses(string $query): ?array
    {
        $builder = $this->scoped('ip_expenses')
            ->select('expense_id, expense_number, expense_date, supplier_name, expense_total');

        $this->like($builder, "CONCAT_WS('^', COALESCE(ip_expenses.expense_number, ''), ip_expenses.supplier_name,
            COALESCE(ip_expenses.supplier_vat, ''), COALESCE(ip_expenses.expense_note, ''))", $query);

        $rows = $builder->orderBy('expense_date', 'DESC')->limit(self::PER_GROUP)->get()->getResultArray();

        return $this->group(trans('expenses', '', 'Spese'), 'fa-arrow-down', array_map(static fn ($r) => [
            'title'    => $r['supplier_name'],
            'subtitle' => trim(($r['expense_number'] ?: '') . ' · ' . date_from_mysql($r['expense_date']), ' ·'),
            'meta'     => format_currency($r['expense_total']),
            'url'      => site_url('expenses/form/' . (int) $r['expense_id']),
        ], $rows));
    }

    private function products(string $query): ?array
    {
        $builder = $this->scoped('ip_products')
            ->select('product_id, product_sku, product_name, product_price');

        $this->like($builder, "CONCAT_WS('^', COALESCE(ip_products.product_sku, ''), ip_products.product_name,
            COALESCE(ip_products.product_description, ''))", $query);

        $rows = $builder->orderBy('product_name', 'ASC')->limit(self::PER_GROUP)->get()->getResultArray();

        return $this->group(trans('products', '', 'Prodotti'), 'fa-cube', array_map(static fn ($r) => [
            'title'    => $r['product_name'],
            'subtitle' => $r['product_sku'] ?: '',
            'meta'     => format_currency($r['product_price']),
            'url'      => site_url('products/form/' . (int) $r['product_id']),
        ], $rows));
    }
}
