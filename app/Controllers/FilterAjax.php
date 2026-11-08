<?php

namespace App\Controllers;

use App\Models\ClientModel;
use App\Models\InvoiceModel;
use App\Models\PaymentModel;
use App\Models\QuoteModel;

/**
 * FilterAjax controller
 *
 * Porting CI4 del controller Ajax (CI3, modulo filter): ricerca per
 * parole chiave (split su spazio, LIKE su CONCAT di campi) su fatture,
 * preventivi, clienti e pagamenti. Risponde con le viste parziali
 * in app/Views/filter/.
 */
class FilterAjax extends BaseController
{
    /**
     * Divide la query in parole (minuscole) e filtra le vuote.
     */
    private function keywords(): array
    {
        // GET e non POST: la ricerca non modifica nulla, e su POST il token
        // CSRF sarebbe irraggiungibile dal JS (il cookie e' httponly), che e'
        // esattamente il motivo per cui ogni ricerca rispondeva 403.
        $query = (string) service('request')->getGet('filter_query');

        return array_values(array_filter(explode(' ', $query), static fn ($word) => $word !== ''));
    }

    public function filterInvoices()
    {
        $keywords = $this->keywords();

        if (empty($keywords)) {
            return view('filter/partial_invoices', [
                'invoices'         => [],
                'invoice_statuses' => (new InvoiceModel())->statuses(),
            ]);
        }

        $data = [
            'invoices'         => (new InvoiceModel())->filterByKeywords($keywords),
            'invoice_statuses' => (new InvoiceModel())->statuses(),
        ];

        return view('filter/partial_invoices', $data);
    }

    public function filterQuotes()
    {
        $keywords = $this->keywords();

        $data = [
            'quotes'         => empty($keywords) ? [] : (new QuoteModel())->filterByKeywords($keywords),
            'quote_statuses' => (new QuoteModel())->statuses(),
        ];

        return view('filter/partial_quotes', $data);
    }

    public function filterClients()
    {
        $keywords = $this->keywords();

        $data = [
            'records' => empty($keywords) ? [] : (new ClientModel())->filterByKeywords($keywords),
        ];

        return view('filter/partial_clients', $data);
    }

    public function filterPayments()
    {
        $keywords = $this->keywords();

        $data = [
            'payments' => empty($keywords) ? [] : (new PaymentModel())->filterByKeywords($keywords),
        ];

        return view('filter/partial_payments', $data);
    }
}