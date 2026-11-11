<?php

namespace App\Services;

use App\Libraries\DocumentRow;
use App\Models\BankAccountModel;
use App\Models\ClientModel;
use App\Models\InvoiceTaxRateModel;
use App\Models\ItemModel;
use App\Models\PaymentMethodModel;
use App\Models\QuoteAmountModel;
use App\Models\QuoteItemModel;
use App\Models\QuoteModel;
use App\Models\QuoteTaxRateModel;

/**
 * HTML e nome file del PDF di un documento: fatture e preventivi insieme.
 *
 * La composizione era copiata otto volte fra i controller e il Mailer, e le
 * copie avevano gia' divergito: l'allegato email perdeva la colonna sconti e
 * i solleciti il metodo di pagamento. Qui il percorso e' uno: cambiano il
 * model interrogato, la vista e il nome del file, non la logica.
 *
 * Anche la forma dei dati e' una sola: i fogli non erano d'accordo (la
 * fattura legge oggetti, il preventivo array), quindi il documento e le righe
 * viaggiano in DocumentRow, che risponde a entrambe le sintassi senza toccare
 * i template.
 */
class DocumentPdfBuilder
{
    public const INVOICE = 'invoice';
    public const QUOTE   = 'quote';

    public function invoice(array $invoice, ?string $template = null): string
    {
        return $this->html(self::INVOICE, $invoice, $template);
    }

    public function quote(array $quote, ?string $template = null): string
    {
        return $this->html(self::QUOTE, $quote, $template);
    }

    public function invoiceFilename(array $invoice): string
    {
        return 'Fattura_' . $this->documentNumber(
            (string) ($invoice['invoice_number'] ?? ''),
            (int) ($invoice['invoice_id'] ?? 0)
        ) . '.pdf';
    }

    public function quoteFilename(array $quote): string
    {
        // Estensione esplicita: il nome viaggia anche come nome dell'allegato
        // email, dove Dompdf non interviene.
        return trans('quote') . '_' . $this->documentNumber(
            (string) ($quote['quote_number'] ?? ''),
            (int) ($quote['quote_id'] ?? 0)
        ) . '.pdf';
    }

    /**
     * Numero da usare nel nome file: quello del documento, o l'id se la bozza
     * non ne ha ancora uno. I separatori di percorso non possono finire nel
     * nome (verrebbero interpretati come cartelle).
     */
    private function documentNumber(string $number, int $id): string
    {
        return str_replace(['\\', '/'], '_', $number !== '' ? $number : (string) $id);
    }

    private function html(string $type, array $document, ?string $template): string
    {
        $isInvoice = $type === self::INVOICE;
        $id        = $isInvoice ? (int) $document['invoice_id'] : (int) $document['quote_id'];

        $items = $isInvoice
            ? (new ItemModel())->getByInvoice($id)
            : (new QuoteItemModel())->getByQuote($id);

        $taxRates = $isInvoice
            ? (new InvoiceTaxRateModel())->getByInvoice($id)
            : (new QuoteTaxRateModel())->getByQuote($id);

        // La colonna "Sconto" compare solo se almeno una riga ne ha uno: senza
        // questo calcolo la tabella cambia numero di colonne da un documento
        // all'altro.
        $showItemDiscounts = false;
        foreach ($items as $item) {
            if ((float) ($item['item_discount'] ?? 0) != 0.0) {
                $showItemDiscounts = true;
                break;
            }
        }

        $data = [
            'items'               => array_map([DocumentRow::class, 'wrap'], $items),
            'output_type'         => 'pdf',
            'show_item_discounts' => $showItemDiscounts,
        ];

        if ($isInvoice) {
            $data['invoice']           = DocumentRow::wrap($document);
            $data['invoice_tax_rates'] = array_map([DocumentRow::class, 'wrap'], $taxRates);
            $data['payment_method']    = $this->paymentMethod($document);
            // Conto indicato sulla fattura, o il predefinito: cosi' le
            // istruzioni di pagamento riportano l'IBAN giusto.
            $data['bank_account']      = (new BankAccountModel())->forInvoice(
                isset($document['bank_account_id']) ? (int) $document['bank_account_id'] : null
            );

            return view('invoice_templates/' . $this->templateName($type, $template) . '.pdf.php', $data);
        }

        $data['quote']           = DocumentRow::wrap($document);
        $data['quote_tax_rates'] = array_map([DocumentRow::class, 'wrap'], $taxRates);
        $data['client']          = (new ClientModel())->find((int) $document['client_id']) ?? [];
        // Emittente completo, non il solo account: senza i emitter_* del join
        // il preventivo usciva intestato all'utente invece che all'azienda
        // (vedi QuoteModel::pdfUser()).
        $data['user']            = (new QuoteModel())->pdfUser($document);
        $data['amounts']         = (new QuoteAmountModel())->getByQuote($id) ?? [];

        return view('quote_templates/pdf/' . $this->templateName($type, $template), $data);
    }

    /**
     * Metodo di pagamento della fattura, o false quando non e' stato scelto.
     */
    private function paymentMethod(array $invoice): mixed
    {
        if (empty($invoice['payment_method']) || (int) $invoice['payment_method'] === 0) {
            return false;
        }

        $method = (new PaymentMethodModel())->find((int) $invoice['payment_method']);

        return $method ? DocumentRow::wrap($method) : false;
    }

    /**
     * Template valido: quello richiesto, altrimenti l'impostazione, altrimenti
     * il predefinito del tipo. L'impostazione puo' esistere ma essere vuota.
     */
    private function templateName(string $type, ?string $template): string
    {
        // basename(): il nome arriva anche dal form di invio email, e con un
        // "../" is_file() avrebbe accettato qualsiasi vista sotto app/Views,
        // resa poi come PDF. Un template e' un file di questa cartella.
        if ($type === self::INVOICE) {
            $name = basename((string) preg_replace(
                '/\.(pdf\.php|php)$/',
                '',
                str_replace('\\', '/', (string) ($template ?? get_setting('pdf_invoice_template', 'default')))
            ));

            return is_file(APPPATH . 'Views/invoice_templates/' . $name . '.pdf.php') ? $name : 'default';
        }

        $name = basename((string) preg_replace(
            '/\.php$/',
            '',
            str_replace('\\', '/', trim((string) ($template ?? get_setting('pdf_quote_template', 'InvoicePlane'))))
        ));

        return ($name !== '' && is_file(APPPATH . 'Views/quote_templates/pdf/' . $name . '.php')) ? $name : 'InvoicePlane';
    }
}
