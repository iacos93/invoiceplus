<?php

namespace App\Libraries;

use App\Models\SettingModel;
use DateTime;
use DOMDocument;

/**
 * ZugferdXml
 *
 * Porting CI4 della libreria CI3 ZugferdXml (generazione XML
 * ZUGFeRD 1p0 basic per le fatture). Riceve fattura e righe come
 * oggetti stdClass (come in CI3) e legge la valuta da ip_settings.
 */
class ZugferdXml
{
    public $invoice;
    public $items;
    public $currencyCode;

    /** @var \DOMDocument */
    protected $doc;

    /** @var \DOMElement */
    protected $root;

    public function __construct(array $params)
    {
        $this->invoice       = $params['invoice'];
        $this->items         = $params['items'];
        $this->currencyCode  = (new SettingModel())->setting('currency_code', 'EUR');
    }

    public function xml()
    {
        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;

        $this->root = $this->xmlRoot();
        $this->root->appendChild($this->xmlSpecifiedExchangedDocumentContext());
        $this->root->appendChild($this->xmlHeaderExchangedDocument());
        $this->root->appendChild($this->xmlSpecifiedSupplyChainTradeTransaction());

        $this->doc->appendChild($this->root);

        return $this->doc->saveXML();
    }

    protected function xmlRoot()
    {
        $node = $this->doc->createElement('rsm:CrossIndustryDocument');
        $node->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $node->setAttribute('xmlns:rsm', 'urn:ferd:CrossIndustryDocument:invoice:1p0');
        $node->setAttribute('xmlns:ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:12');
        $node->setAttribute('xmlns:udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:15');

        return $node;
    }

    protected function xmlSpecifiedExchangedDocumentContext()
    {
        $node = $this->doc->createElement('rsm:SpecifiedExchangedDocumentContext');
        $guidelineNode = $this->doc->createElement('ram:GuidelineSpecifiedDocumentContextParameter');
        $guidelineNode->appendChild($this->el('ram:ID', 'urn:ferd:CrossIndustryDocument:invoice:1p0:basic'));
        $node->appendChild($guidelineNode);

        return $node;
    }

    protected function xmlHeaderExchangedDocument()
    {
        $node = $this->doc->createElement('rsm:HeaderExchangedDocument');

        $node->appendChild($this->el('ram:ID', $this->invoice->invoice_number));
        $node->appendChild($this->el('ram:Name', trans('invoice')));
        $node->appendChild($this->el('ram:TypeCode', 380));

        // IssueDateTime
        $dateNode = $this->doc->createElement('ram:IssueDateTime');
        $dateNode->appendChild($this->dateElement($this->invoice->invoice_date_created));
        $node->appendChild($dateNode);

        // IncludedNote
        $noteNode = $this->doc->createElement('ram:IncludedNote');
        $noteNode->appendChild($this->el('ram:Content', htmlsc($this->invoice->invoice_terms)));
        $node->appendChild($noteNode);

        return $node;
    }

    /**
     * Elemento con contenuto testuale. Il secondo argomento di
     * DOMDocument::createElement() NON viene escapato: una "&" in una
     * ragione sociale ("C&C S.p.A.") era letta come inizio di entita' e
     * faceva fallire la generazione dell'intero XML. Il nodo di testo
     * invece escapa da se'.
     */
    protected function el(string $name, $value): \DOMElement
    {
        $node = $this->doc->createElement($name);
        $node->appendChild($this->doc->createTextNode((string) $value));

        return $node;
    }

    protected function dateElement($date)
    {
        $el = $this->el('udt:DateTimeString', $this->zugferdFormattedDate($date));
        $el->setAttribute('format', 102);

        return $el;
    }

    public function zugferdFormattedDate($date)
    {
        if ($date && $date != '0000-00-00' && $date !== null) {
            $date = DateTime::createFromFormat('Y-m-d', $date);

            return $date->format('Ymd');
        }

        return '';
    }

    protected function xmlSpecifiedSupplyChainTradeTransaction()
    {
        $node = $this->doc->createElement('rsm:SpecifiedSupplyChainTradeTransaction');
        $node->appendChild($this->xmlApplicableSupplyChainTradeAgreement());
        $node->appendChild($this->xmlApplicableSupplyChainTradeDelivery());
        $node->appendChild($this->xmlApplicableSupplyChainTradeSettlement());

        foreach ($this->items as $index => $item) {
            $node->appendChild($this->xmlIncludedSupplyChainTradeLineItem($index + 1, $item));
        }

        return $node;
    }

    protected function xmlApplicableSupplyChainTradeAgreement()
    {
        $node = $this->doc->createElement('ram:ApplicableSupplyChainTradeAgreement');
        $node->appendChild($this->xmlSellerTradeParty());
        $node->appendChild($this->xmlBuyerTradeParty());

        return $node;
    }

    protected function xmlSellerTradeParty($index = '', $item = '')
    {
        $node = $this->doc->createElement('ram:SellerTradeParty');
        $node->appendChild($this->el('ram:Name', htmlsc($this->invoice->user_name)));

        // PostalTradeAddress
        $addressNode = $this->doc->createElement('ram:PostalTradeAddress');
        $addressNode->appendChild($this->el('ram:PostcodeCode', htmlsc($this->invoice->user_zip)));
        $addressNode->appendChild($this->el('ram:LineOne', htmlsc($this->invoice->user_address_1)));
        $addressNode->appendChild($this->el('ram:LineTwo', htmlsc($this->invoice->user_address_2)));
        $addressNode->appendChild($this->el('ram:CityName', htmlsc($this->invoice->user_city)));
        $addressNode->appendChild($this->el('ram:CountryID', htmlsc($this->invoice->user_country)));

        $node->appendChild($addressNode);

        return $node;
    }

    protected function xmlBuyerTradeParty($index = '', $item = '')
    {
        $node = $this->doc->createElement('ram:BuyerTradeParty');
        $node->appendChild($this->el('ram:Name', $this->invoice->client_name));

        // PostalTradeAddress
        $addressNode = $this->doc->createElement('ram:PostalTradeAddress');
        $addressNode->appendChild($this->el('ram:PostcodeCode', htmlsc($this->invoice->client_zip)));
        $addressNode->appendChild($this->el('ram:LineOne', htmlsc($this->invoice->client_address_1)));
        $addressNode->appendChild($this->el('ram:LineTwo', htmlsc($this->invoice->client_address_2)));
        $addressNode->appendChild($this->el('ram:CityName', htmlsc($this->invoice->client_city)));
        $addressNode->appendChild($this->el('ram:CountryID', htmlsc($this->invoice->client_country)));
        $node->appendChild($addressNode);

        // SpecifiedTaxRegistration
        $node->appendChild($this->xmlSpecifiedTaxRegistration('VA', $this->invoice->client_vat_id));
        $node->appendChild($this->xmlSpecifiedTaxRegistration('FC', htmlsc($this->invoice->client_tax_code)));

        return $node;
    }

    protected function xmlSpecifiedTaxRegistration($schemeID, $content)
    {
        $node = $this->doc->createElement('ram:SpecifiedTaxRegistration');
        $el = $this->el('ram:ID', $content);
        $el->setAttribute('schemeID', $schemeID);
        $node->appendChild($el);

        return $node;
    }

    protected function xmlApplicableSupplyChainTradeDelivery()
    {
        $node = $this->doc->createElement('ram:ApplicableSupplyChainTradeDelivery');

        // ActualDeliverySupplyChainEvent
        $eventNode = $this->doc->createElement('ram:ActualDeliverySupplyChainEvent');
        $dateNode = $this->doc->createElement('ram:OccurrenceDateTime');
        $dateNode->appendChild($this->dateElement($this->invoice->invoice_date_created));
        $eventNode->appendChild($dateNode);

        $node->appendChild($eventNode);

        return $node;
    }

    protected function xmlApplicableSupplyChainTradeSettlement()
    {
        $node = $this->doc->createElement('ram:ApplicableSupplyChainTradeSettlement');

        $node->appendChild($this->el('ram:PaymentReference', $this->invoice->invoice_number));
        $node->appendChild($this->el('ram:InvoiceCurrencyCode', $this->currencyCode));

        // taxes
        foreach ($this->itemsSubtotalGroupedByTaxPercent() as $percent => $subtotal) {
            $node->appendChild($this->xmlApplicableTradeTax($percent, $subtotal));
        }

        // sums
        $node->appendChild($this->xmlSpecifiedTradeSettlementMonetarySummation());

        return $node;
    }

    public function itemsSubtotalGroupedByTaxPercent()
    {
        $result = [];

        foreach ($this->items as $item) {
            if ($item->item_tax_rate_percent == 0) {
                continue;
            }

            if (!isset($result[$item->item_tax_rate_percent])) {
                $result[$item->item_tax_rate_percent] = 0;
            }

            $result[$item->item_tax_rate_percent] += $item->item_subtotal;
        }

        return $result;
    }

    protected function xmlApplicableTradeTax($percent, $subtotal)
    {
        $node = $this->doc->createElement('ram:ApplicableTradeTax');
        $node->appendChild($this->currencyElement('ram:CalculatedAmount', $subtotal * $percent / 100));
        $node->appendChild($this->el('ram:TypeCode', 'VAT'));
        $node->appendChild($this->currencyElement('ram:BasisAmount', $subtotal));
        $node->appendChild($this->el('ram:CategoryCode', 'S'));
        $node->appendChild($this->el('ram:ApplicablePercent', $percent));

        return $node;
    }

    protected function currencyElement($name, $amount, $nb_decimals = 2)
    {
        $el = $this->doc->createElement($name, $this->zugferdFormattedFloat($amount, $nb_decimals));
        $el->setAttribute('currencyID', $this->currencyCode);

        return $el;
    }

    public function zugferdFormattedFloat($amount, $nb_decimals = 2)
    {
        // Terzo e quarto argomento espliciti: number_format() con due soli
        // argomenti usa la virgola come separatore delle migliaia, quindi un
        // totale di 1234,50 finiva nell'XML come "1,234.50" -- valore non
        // ammesso da xs:decimal, cioe' documento malformato. Sotto i mille
        // euro il risultato era corretto per caso, ed e' il motivo per cui il
        // difetto non e' mai emerso. FatturaPA::dec() li passava gia' entrambi.
        return number_format((float) $amount, $nb_decimals, '.', '');
    }

    protected function xmlSpecifiedTradeSettlementMonetarySummation()
    {
        $subtotal = (float) ($this->invoice->invoice_item_subtotal ?? 0);
        // Sconto a livello di documento: combina importo fisso e percentuale
        // esattamente come fa InvoiceAmountModel::calculate(), altrimenti con uno
        // sconto percentuale il totale del XML risulta incoerente rispetto a
        // invoice_total (LineTotal − Allowance + Tax ≠ GrandTotal).
        $discountAmount = (float) ($this->invoice->invoice_discount_amount ?? 0);
        $discountPercent = (float) ($this->invoice->invoice_discount_percent ?? 0);
        $discount = $discountAmount + round(($subtotal - $discountAmount) * $discountPercent / 100, 2);

        $node = $this->doc->createElement('ram:SpecifiedTradeSettlementMonetarySummation');
        $node->appendChild($this->currencyElement('ram:LineTotalAmount', $subtotal));
        $node->appendChild($this->currencyElement('ram:ChargeTotalAmount', 0));
        $node->appendChild($this->currencyElement('ram:AllowanceTotalAmount', $discount));
        $node->appendChild($this->currencyElement('ram:TaxBasisTotalAmount', $subtotal - $discount));
        $node->appendChild($this->currencyElement('ram:TaxTotalAmount', $this->invoice->invoice_item_tax_total));
        $node->appendChild($this->currencyElement('ram:GrandTotalAmount', $this->invoice->invoice_total));
        $node->appendChild($this->currencyElement('ram:TotalPrepaidAmount', $this->invoice->invoice_paid));
        $node->appendChild($this->currencyElement('ram:DuePayableAmount', $this->invoice->invoice_balance));

        return $node;
    }

    protected function xmlIncludedSupplyChainTradeLineItem($lineNumber, $item)
    {
        $node = $this->doc->createElement('ram:IncludedSupplyChainTradeLineItem');

        // AssociatedDocumentLineDocument
        $lineNode = $this->doc->createElement('ram:AssociatedDocumentLineDocument');
        $lineNode->appendChild($this->el('ram:LineID', $lineNumber));
        $node->appendChild($lineNode);

        // SpecifiedSupplyChainTradeAgreement
        $node->appendChild($this->xmlSpecifiedSupplyChainTradeAgreement($item));

        // SpecifiedSupplyChainTradeDelivery
        $deliveyNode = $this->doc->createElement('ram:SpecifiedSupplyChainTradeDelivery');
        $deliveyNode->appendChild($this->quantityElement('ram:BilledQuantity', $item->item_quantity));
        $node->appendChild($deliveyNode);

        // SpecifiedSupplyChainTradeSettlement
        $node->appendChild($this->xmlSpecifiedSupplyChainTradeSettlement($item));

        // SpecifiedTradeProduct
        $tradeNode = $this->doc->createElement('ram:SpecifiedTradeProduct');
        $tradeNode->appendChild($this->el('ram:Name', htmlsc($item->item_name) . "\n" . htmlsc($item->item_description)));
        $node->appendChild($tradeNode);

        return $node;
    }

    protected function xmlSpecifiedSupplyChainTradeAgreement($item)
    {
        $node = $this->doc->createElement('ram:SpecifiedSupplyChainTradeAgreement');

        // GrossPriceProductTradePrice
        $grossPriceNode = $this->doc->createElement('ram:GrossPriceProductTradePrice');
        $grossPriceNode->appendChild($this->currencyElement('ram:ChargeAmount', $item->item_price, 4));
        $node->appendChild($grossPriceNode);

        // NetPriceProductTradePrice
        $netPriceNode = $this->doc->createElement('ram:NetPriceProductTradePrice');
        $netPriceNode->appendChild($this->currencyElement('ram:ChargeAmount', $item->item_price, 4));
        $node->appendChild($netPriceNode);

        return $node;
    }

    protected function quantityElement($name, $quantity)
    {
        $el = $this->doc->createElement($name, $this->zugferdFormattedFloat($quantity, 4));
        $el->setAttribute('unitCode', 'C62');

        return $el;
    }

    protected function xmlSpecifiedSupplyChainTradeSettlement($item)
    {
        $node = $this->doc->createElement('ram:SpecifiedSupplyChainTradeSettlement');

        // ApplicableTradeTax
        if ($item->item_tax_rate_percent > 0) {
            $taxNode = $this->doc->createElement('ram:ApplicableTradeTax');
            $taxNode->appendChild($this->el('ram:TypeCode', 'VAT'));
            $taxNode->appendChild($this->el('ram:ApplicablePercent', $item->item_tax_rate_percent));
            $node->appendChild($taxNode);
        }

        // SpecifiedTradeSettlementMonetarySummation
        $sumNode = $this->doc->createElement('ram:SpecifiedTradeSettlementMonetarySummation');
        $sumNode->appendChild($this->currencyElement('ram:LineTotalAmount', $item->item_subtotal));
        $node->appendChild($sumNode);

        return $node;
    }
}