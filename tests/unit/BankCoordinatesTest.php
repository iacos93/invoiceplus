<?php

use App\Models\BankAccountModel;
use App\Models\PaymentMethodModel;
use App\Validation\FiscalRules;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Copertura delle coordinate di pagamento: IBAN e modalità SdI.
 *
 * È l'altro punto che sbaglia in silenzio. Un IBAN con uno spazio o una cifra
 * storta non fa fallire nulla nell'applicazione: viene scritto nel nodo <IBAN>
 * della fattura elettronica e torna indietro come scarto giorni dopo l'invio,
 * quando la fattura è già numerata e consegnata al cliente. Stesso discorso
 * per la modalità MP: senza codice il blocco DatiPagamento non viene creato,
 * e la fattura parte muta.
 *
 * @internal
 */
final class BankCoordinatesTest extends CIUnitTestCase
{
    private FiscalRules $rules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rules = new FiscalRules();
    }

    public function testAcceptsValidIban(): void
    {
        $this->assertTrue($this->rules->valid_iban('IT60X0542811101000000123456'));
        $this->assertTrue($this->rules->valid_iban('DE89370400440532013000'));
    }

    public function testAcceptsIbanPastedWithSpaces(): void
    {
        // È la forma con cui l'home banking lo mostra: rifiutarla costringerebbe
        // a ripulirlo a mano proprio nel momento in cui si copia.
        $this->assertTrue($this->rules->valid_iban('IT60 X054 2811 1010 0000 0123 456'));
    }

    public function testEmptyIbanIsLeftToRequiredRule(): void
    {
        $this->assertTrue($this->rules->valid_iban(''));
        $this->assertTrue($this->rules->valid_iban(null));
    }

    public function testRejectsWrongCheckDigits(): void
    {
        // Ultima cifra cambiata: formato corretto, controllo mod-97 fallito.
        $this->assertFalse($this->rules->valid_iban('IT60X0542811101000000123457'));
    }

    public function testRejectsWrongLengthForCountry(): void
    {
        $this->assertFalse($this->rules->valid_iban('IT60X05428111010000001234'));
    }

    public function testRejectsMalformedIban(): void
    {
        $this->assertFalse($this->rules->valid_iban('XX00ABC'));
        $this->assertFalse($this->rules->valid_iban('60X0542811101000000123456'));
    }

    public function testCleanIbanStripsSeparatorsAndUppercases(): void
    {
        $this->assertSame(
            'IT60X0542811101000000123456',
            BankAccountModel::cleanIban('it60 x054-2811.1010 0000 0123 456')
        );
    }

    public function testFormatIbanGroupsByFour(): void
    {
        $this->assertSame(
            'IT60 X054 2811 1010 0000 0123 456',
            BankAccountModel::formatIban('IT60X0542811101000000123456')
        );
        $this->assertSame('', BankAccountModel::formatIban(''));
    }

    public function testNormalizePostCleansBankFields(): void
    {
        $post = BankAccountModel::normalizePost([
            'bank_account_name'     => ' Conto principale ',
            'bank_account_iban'     => 'it60 x054 2811 1010 0000 0123 456',
            'bank_account_bic'      => 'bcititmm',
            'bank_account_sia_code' => 'ab 123',
        ]);

        $this->assertSame('IT60X0542811101000000123456', $post['bank_account_iban']);
        $this->assertSame('BCITITMM', $post['bank_account_bic']);
        $this->assertSame('AB123', $post['bank_account_sia_code']);
        // Il nome non è una coordinata: non viene toccato.
        $this->assertSame(' Conto principale ', $post['bank_account_name']);
    }

    public function testSdiCodeComesFromTheRecord(): void
    {
        $this->assertSame('MP05', PaymentMethodModel::sdiCodeFor([
            'payment_method_id'       => 1,
            'payment_method_sdi_code' => 'MP05',
        ]));
    }

    public function testSdiCodeIsNullWhenNotConfigured(): void
    {
        // Senza codice il chiamante non deve creare il blocco DatiPagamento.
        $this->assertNull(PaymentMethodModel::sdiCodeFor([
            'payment_method_id'       => 999,
            'payment_method_sdi_code' => null,
        ]));
    }

    public function testSdiCodeLabelDescribesTheCode(): void
    {
        $this->assertSame('MP05 · Bonifico', PaymentMethodModel::sdiCodeLabel('MP05'));
        $this->assertSame('', PaymentMethodModel::sdiCodeLabel(null));
    }

    public function testSdiCodeTableCoversTheOfficialRange(): void
    {
        // L'elenco è chiuso: un codice fuori tabella fa scartare il file.
        $this->assertCount(23, PaymentMethodModel::SDI_CODES);
        $this->assertArrayHasKey('MP01', PaymentMethodModel::SDI_CODES);
        $this->assertArrayHasKey('MP23', PaymentMethodModel::SDI_CODES);
    }
}
