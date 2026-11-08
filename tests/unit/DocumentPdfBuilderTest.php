<?php

use App\Libraries\DocumentRow;
use App\Models\SettingModel;
use App\Services\DocumentPdfBuilder;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCache;

/**
 * Composizione dei PDF: una sola strada per fatture e preventivi.
 *
 * Prima esistevano otto copie della stessa composizione e avevano gia'
 * divergito: l'allegato email perdeva la colonna sconti e i solleciti il
 * metodo di pagamento. Questi test fissano due cose: il nome del file nasce
 * solo qui, e i template si aspettano davvero le righe in DocumentRow (la
 * vista preventivo aveva una closure tipizzata array e andava in 500 appena
 * le righe diventavano oggetti).
 *
 * @internal
 */
final class DocumentPdfBuilderTest extends CIUnitTestCase
{
    /**
     * I template leggono le impostazioni (logo, pìe di pagina, valuta) tramite
     * get_setting(), che passa dalla cache. Una cache vuota basta a renderli
     * senza base dati, e la cache statica va svuotata perché è condivisa fra i
     * test dello stesso processo.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $cache = new MockCache();
        $cache->save('ip_settings_all', []);
        Services::injectMock('cache', $cache);

        $prop = (new ReflectionClass(SettingModel::class))->getProperty('settingsCache');
        $prop->setValue(null, null);
    }

    public function testNomeFileFatturaUsaIlNumero(): void
    {
        $builder = new DocumentPdfBuilder();

        $this->assertSame('Fattura_NC2.pdf', $builder->invoiceFilename([
            'invoice_number' => 'NC2',
            'invoice_id'     => 115,
        ]));
    }

    /** Bozza senza numero: si usa l'id, come prima. */
    public function testNomeFileFatturaSenzaNumeroUsaLId(): void
    {
        $this->assertSame('Fattura_115.pdf', (new DocumentPdfBuilder())->invoiceFilename([
            'invoice_number' => '',
            'invoice_id'     => 115,
        ]));
    }

    /** Il numero puo' contenere separatori: non devono diventare cartelle. */
    public function testSeparatoriNelNumeroNonCreanoCartelle(): void
    {
        $builder = new DocumentPdfBuilder();

        $this->assertSame('Fattura_1_2026.pdf', $builder->invoiceFilename([
            'invoice_number' => '1/2026',
            'invoice_id'     => 1,
        ]));
        $this->assertSame(trans('quote') . '_P-132_2026.pdf', $builder->quoteFilename([
            'quote_number' => 'P-132/2026',
            'quote_id'     => 139,
        ]));
    }

    /**
     * Il preventivo porta l'estensione nel nome: lo stesso nome viaggia come
     * nome dell'allegato email, dove Dompdf non la aggiunge.
     */
    public function testNomeFilePreventivoHaPrefissoTradottoEdEstensione(): void
    {
        $name = (new DocumentPdfBuilder())->quoteFilename([
            'quote_number' => 'P-132/2026',
            'quote_id'     => 139,
        ]);

        $this->assertSame(trans('quote') . '_P-132_2026.pdf', $name);
    }

    public function testNomeFilePreventivoSenzaNumeroUsaLId(): void
    {
        $this->assertSame(trans('quote') . '_139.pdf', (new DocumentPdfBuilder())->quoteFilename([
            'quote_number' => '',
            'quote_id'     => 139,
        ]));
    }

    /**
     * La composizione resta in un posto solo: nessun controller o command
     * puo' tornare a chiamare view() sui template PDF per conto proprio,
     * altrimenti le copie ricominciano a divergere.
     */
    public function testSoloIlBuilderRendeITemplatePdf(): void
    {
        $offenders = [];
        $files     = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php' || $file->getPathname() === APPPATH . 'Services/DocumentPdfBuilder.php') {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match('/view\((\'|")(invoice_templates\/|quote_templates\/pdf\/)/', $line)) {
                    $offenders[] = str_replace(APPPATH, 'app/', $file->getPathname()) . ':' . ($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'La composizione dei PDF passa da App\Services\DocumentPdfBuilder');
    }

    public function testINomiDeiVecchiBuilderNonEsistonoPiu(): void
    {
        $offenders = [];
        $files     = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APPPATH, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $body = file_get_contents($file->getPathname());
            if (preg_match('/InvoicePdfBuilder|QuotePdfBuilder/', $body)) {
                $offenders[] = str_replace(APPPATH, 'app/', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders);
    }

    /** Entrambi i template rendono le righe in DocumentRow, senza errori. */
    public function testEntrambiITemplateRendonoLeRigheUnificate(): void
    {
        $invoiceHtml = view('invoice_templates/default.pdf.php', $this->invoiceData(true));
        $quoteHtml   = view('quote_templates/pdf/InvoicePlane', $this->quoteData(true));

        foreach (['fattura' => $invoiceHtml, 'preventivo' => $quoteHtml] as $label => $html) {
            $this->assertStringContainsString('Consulenza', $html, $label);
            $this->assertStringContainsString('Cliente di Prova', $html, $label);
        }
    }

    /**
     * La colonna sconti compare in tutti e due i documenti quando una riga ha
     * uno sconto, e in nessuno quando non ce l'ha: prima l'allegato email la
     * perdeva proprio perche' il calcolo stava in una copia della logica.
     */
    public function testColonnaScontoUgualeNeiDueDocumenti(): void
    {
        $invoiceWith    = view('invoice_templates/default.pdf.php', $this->invoiceData(true));
        $invoiceWithout = view('invoice_templates/default.pdf.php', $this->invoiceData(false));
        $quoteWith      = view('quote_templates/pdf/InvoicePlane', $this->quoteData(true));
        $quoteWithout   = view('quote_templates/pdf/InvoicePlane', $this->quoteData(false));

        $this->assertSame(1, substr_count($invoiceWith, 'class="c-discount num"'));
        $this->assertSame(1, substr_count($quoteWith, 'class="c-discount num"'));
        $this->assertSame(0, substr_count($invoiceWithout, 'class="c-discount num"'));
        $this->assertSame(0, substr_count($quoteWithout, 'class="c-discount num"'));
    }

    /** Dati minimi ma completi: i template leggono i campi del builder. */
    private function items(bool $discount): array
    {
        return array_map([DocumentRow::class, 'wrap'], [[
            'item_name'          => 'Consulenza',
            'item_description'   => 'Attivita di analisi',
            'item_quantity'      => 1,
            'item_price'         => 100.0,
            'item_discount'      => $discount ? 10.0 : 0.0,
            'item_total'         => $discount ? 90.0 : 100.0,
            'item_product_unit'  => '',
            'item_code'          => '',
            'product_sku'        => '',
        ]]);
    }

    private function invoiceData(bool $discount): array
    {
        $document = [
            'invoice_id'                  => 115,
            'invoice_number'              => 'NC2',
            'invoice_date_created'        => '2026-01-26',
            'invoice_date_due'            => '2026-02-25',
            'invoice_item_subtotal'       => 100.0,
            'invoice_item_tax_total'      => 22.0,
            'invoice_discount_total'      => 0.0,
            'invoice_discount_percent'    => 0.0,
            'invoice_fund_amount'         => 0.0,
            'invoice_fund_type'           => '',
            'invoice_fund_rate'           => 0.0,
            'invoice_withholding_amount'  => 0.0,
            'invoice_total'               => 122.0,
            'invoice_paid'                => 0.0,
            'invoice_balance'             => 122.0,
            'invoice_terms'               => '',
            'emitter_name'                => 'Novatech Srl',
            'user_name'                   => 'Fabio',
            'emitter_vat_id'              => '',
            'emitter_tax_code'            => '',
            'emitter_address_1'           => '',
            'emitter_city'                => '',
            'emitter_zip'                 => '',
            'emitter_state'               => '',
            'emitter_country'             => '',
            'emitter_phone'               => '',
            'user_fax'                    => '',
            'client_name'                 => 'Cliente di Prova',
            'client_surname'              => '',
            'client_vat_id'               => '',
            'client_tax_code'             => '',
            'client_address_1'            => '',
            'client_address_2'            => '',
            'client_city'                 => '',
            'client_zip'                  => '',
            'client_state'                => '',
            'client_country'              => '',
            'client_phone'                => '',
        ];

        return [
            'invoice'             => DocumentRow::wrap($document),
            'items'               => $this->items($discount),
            'invoice_tax_rates'   => [],
            'payment_method'      => false,
            'custom_fields'       => [],
            'bank_account'        => [],
            'output_type'         => 'pdf',
            'show_item_discounts' => $discount,
        ];
    }

    private function quoteData(bool $discount): array
    {
        $document = [
            'quote_id'               => 139,
            'quote_number'           => 'P-132/2026',
            'quote_date_created'     => '2026-01-26',
            'quote_date_expires'     => '2026-02-25',
            'quote_status_id'        => 1,
            'quote_discount_percent' => 0.0,
            'quote_discount_amount'  => 0.0,
            'notes'                  => '',
        ];

        return [
            'quote'               => DocumentRow::wrap($document),
            'items'               => $this->items($discount),
            'quote_tax_rates'     => [],
            'client'              => [
                'client_name' => 'Cliente di Prova', 'client_surname' => '',
                'client_vat_id' => '', 'client_tax_code' => '',
                'client_address_1' => '', 'client_address_2' => '',
                'client_city' => '', 'client_zip' => '', 'client_state' => '', 'client_country' => '',
                'client_phone' => '',
            ],
            'user'                => [
                'emitter_name' => 'Novatech Srl', 'user_name' => 'Fabio',
                'emitter_vat_id' => '', 'emitter_tax_code' => '',
                'emitter_address_1' => '', 'user_address_2' => '',
                'emitter_city' => '', 'emitter_zip' => '', 'emitter_state' => '', 'emitter_country' => '',
                'emitter_phone' => '', 'user_fax' => '',
            ],
            'amounts'             => ['quote_item_subtotal' => 100.0, 'quote_item_tax_total' => 22.0, 'quote_total' => 122.0],
            'output_type'         => 'pdf',
            'show_item_discounts' => $discount,
        ];
    }
}
