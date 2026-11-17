<?php

use App\Models\SupplierModel;

/**
 * Regola dell'arricchimento dell'anagrafica fornitori.
 *
 * Il riempimento automatico è utile solo finché non tocca quello che c'è: un
 * fornitore con più sedi manda la fattura dallo stabilimento, e sovrascrivere
 * sposterebbe l'anagrafica a ogni import. Qui si prova la regola in sé, senza
 * database: si scrive solo dove il campo è vuoto e c'è un valore da mettere.
 *
 * @internal
 */
final class SupplierEnrichmentTest extends CodeIgniter\Test\CIUnitTestCase
{
    public function testFillsOnlyEmptyFields(): void
    {
        $patch = SupplierModel::emptyFields(
            [
                'supplier_address_1' => '',
                'supplier_city'      => 'Roma',
                'supplier_zip'       => '   ',
            ],
            [
                'supplier_address_1' => 'Via del Lavoro 40',
                'supplier_city'      => 'Firenze',
                'supplier_zip'       => '20010',
            ]
        );

        // Il comune scritto a mano resta: la fattura non lo corregge.
        $this->assertSame([
            'supplier_address_1' => 'Via del Lavoro 40',
            'supplier_zip'       => '20010',
        ], $patch);
    }

    public function testIgnoresEmptyCandidates(): void
    {
        $this->assertSame([], SupplierModel::emptyFields([], [
            'supplier_email' => '',
            'supplier_iban'  => null,
            'supplier_city'  => '   ',
        ]));
    }

    public function testCreationTakesEverythingProvided(): void
    {
        $this->assertSame(
            ['supplier_name' => 'Fornitore Srl', 'supplier_city' => 'Pisa'],
            SupplierModel::emptyFields([], ['supplier_name' => 'Fornitore Srl', 'supplier_city' => 'Pisa'])
        );
    }

    public function testTrimsValuesBeforeWriting(): void
    {
        $this->assertSame(
            ['supplier_city' => 'Pisa'],
            SupplierModel::emptyFields([], ['supplier_city' => '  Pisa  '])
        );
    }

    public function testKeepsExistingValueEvenWhenDifferent(): void
    {
        $this->assertSame([], SupplierModel::emptyFields(
            ['supplier_iban' => 'IT39C0326822300052205863520'],
            ['supplier_iban' => 'IT15L0306945221100000008405']
        ));
    }
}
