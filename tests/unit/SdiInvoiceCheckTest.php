<?php

use App\Libraries\Sdi\SdiInvoiceCheck;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * I campi che la XSD pretende nella <Sede> e il messaggio per cui li si
 * controlla prima di generare il tracciato.
 *
 * Il caso che ha motivato la classe e' una cliente persona fisica senza
 * indirizzo: il file usciva con la sola <Nazione> e la validazione rispondeva
 * "Element 'Nazione': This element is not expected", cioe' il contrario di
 * quello che serviva sapere.
 *
 * @internal
 */
final class SdiInvoiceCheckTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper('ip_helpers');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function invoice(array $overrides = []): array
    {
        return array_merge([
            'invoice_carta_docente' => false,
            'client_id'             => 71,
            'client_name'           => 'Maria Angela',
            'client_surname'        => 'Zema',
            'client_type'           => 'person',
            'client_address_1'      => 'Via Vergano, 6',
            'client_zip'            => '28021',
            'client_city'           => 'Borgomanero',
            'emitter_name'          => 'Novatech di Fabio Iacovino',
            'emitter_address_1'     => 'Via Vergano, 6',
            'emitter_zip'           => '28021',
            'emitter_city'          => 'Borgomanero',
            'company_id'            => 1,
        ], $overrides);
    }

    public function testAnagraficheCompleteNonDannoProblemi(): void
    {
        $this->assertSame([], SdiInvoiceCheck::missingSede($this->invoice()));
    }

    public function testClienteSenzaIndirizzoCapEComune(): void
    {
        $problems = SdiInvoiceCheck::missingSede($this->invoice([
            'client_address_1' => null,
            'client_zip'       => null,
            'client_city'      => null,
        ]));

        $this->assertCount(1, $problems);
        $this->assertSame('client', $problems[0]['party']);
        $this->assertSame('Maria Angela Zema', $problems[0]['name']);
        $this->assertSame(['Indirizzo', 'CAP', 'Comune'], $problems[0]['fields']);
    }

    public function testSoloIlCapMancante(): void
    {
        $problems = SdiInvoiceCheck::missingSede($this->invoice(['client_zip' => '  ']));

        $this->assertSame(['CAP'], $problems[0]['fields']);
    }

    public function testAziendaIncompleta(): void
    {
        $problems = SdiInvoiceCheck::missingSede($this->invoice(['emitter_city' => '']));

        $this->assertCount(1, $problems);
        $this->assertSame('company', $problems[0]['party']);
        $this->assertSame('Novatech di Fabio Iacovino', $problems[0]['name']);
        $this->assertSame(['Comune'], $problems[0]['fields']);
    }

    public function testCartaDelDocenteNonGuardaIlCliente(): void
    {
        // Il destinatario del tracciato e' l'ufficio del Ministero: la scheda
        // del docente puo' restare senza indirizzo.
        $problems = SdiInvoiceCheck::missingSede($this->invoice([
            'invoice_carta_docente' => true,
            'client_address_1'      => null,
            'client_zip'            => null,
            'client_city'           => null,
        ]));

        $this->assertSame([], $problems);
    }

    public function testCartaDelDocenteNonNascondeLAzienda(): void
    {
        $problems = SdiInvoiceCheck::missingSede($this->invoice([
            'invoice_carta_docente' => true,
            'emitter_address_1'     => null,
        ]));

        $this->assertCount(1, $problems);
        $this->assertSame('company', $problems[0]['party']);
    }

    public function testNomeDelClienteSecondoIlTipo(): void
    {
        $this->assertSame('Maria Angela Zema', SdiInvoiceCheck::clientLabel($this->invoice()));
        $this->assertSame('ICS Piero Fornara', SdiInvoiceCheck::clientLabel($this->invoice([
            'client_name'    => 'ICS Piero Fornara',
            'client_surname' => '',
            'client_type'    => 'company',
        ])));
    }
}
