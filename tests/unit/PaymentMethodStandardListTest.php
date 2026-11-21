<?php

use App\Models\PaymentMethodModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * L'elenco standard dei metodi di pagamento è anche la lista con cui la
 * migrazione completa le installazioni esistenti: se perdesse una modalità o
 * un nome superasse il limite del form, il buco si vedrebbe solo su
 * un'installazione vera.
 *
 * @internal
 */
final class PaymentMethodStandardListTest extends CIUnitTestCase
{
    public function testCopreTutteLeModalitaSdi(): void
    {
        $codes = array_column(PaymentMethodModel::standardMethods(), 'sdi_code');
        sort($codes);

        $this->assertSame(array_keys(PaymentMethodModel::SDI_CODES), $codes);
    }

    public function testINomiSonoUniciEEntroIlLimiteDelForm(): void
    {
        $names = array_column(PaymentMethodModel::standardMethods(), 'name');

        $this->assertSame(count($names), count(array_unique($names)));

        foreach ($names as $name) {
            $this->assertLessThanOrEqual(35, mb_strlen($name), 'Nome troppo lungo: ' . $name);
        }
    }

    public function testOgniMetodoHaUnCodiceValidoEIlFlagBooleano(): void
    {
        foreach (PaymentMethodModel::standardMethods() as $method) {
            $this->assertArrayHasKey($method['sdi_code'], PaymentMethodModel::SDI_CODES);
            $this->assertIsBool($method['requires_bank_account'], $method['name']);
        }
    }
}
