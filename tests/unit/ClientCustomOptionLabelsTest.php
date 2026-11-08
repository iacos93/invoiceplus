<?php

namespace Tests\Unit;

use App\Models\ClientCustomModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * ip_client_custom conserva l'id dell'opzione per i campi a scelta, non il
 * testo: senza risoluzione la scheda cliente stampava un numero.
 */
final class ClientCustomOptionLabelsTest extends CIUnitTestCase
{
    private array $labels = [3 => 'Mario', 4 => 'Luisa', 5 => 'Edilizia', 6 => 'Impianti'];

    public function testSingleChoiceShowsTheOptionText(): void
    {
        $rows = [['custom_field_type' => 'SINGLE-CHOICE', 'client_custom_fieldvalue' => '3']];

        $out = ClientCustomModel::resolveOptionLabels($rows, $this->labels);

        $this->assertSame('Mario', $out[0]['client_custom_fieldvalue']);
    }

    public function testMultipleChoiceExpandsTheCsvOfIds(): void
    {
        $rows = [['custom_field_type' => 'MULTIPLE-CHOICE', 'client_custom_fieldvalue' => '5,6']];

        $out = ClientCustomModel::resolveOptionLabels($rows, $this->labels);

        $this->assertSame('Edilizia, Impianti', $out[0]['client_custom_fieldvalue']);
    }

    public function testNonChoiceFieldsAreLeftAlone(): void
    {
        $rows = [
            ['custom_field_type' => 'TEXT', 'client_custom_fieldvalue' => '3'],
            ['custom_field_type' => 'DATE', 'client_custom_fieldvalue' => '2026-08-20'],
        ];

        $out = ClientCustomModel::resolveOptionLabels($rows, $this->labels);

        $this->assertSame('3', $out[0]['client_custom_fieldvalue']);
        $this->assertSame('2026-08-20', $out[1]['client_custom_fieldvalue']);
    }

    public function testUnknownOptionKeepsTheRawValue(): void
    {
        // L'opzione puo' essere stata cancellata dopo il salvataggio: meglio
        // mostrare l'id che una cella vuota.
        $rows = [['custom_field_type' => 'SINGLE-CHOICE', 'client_custom_fieldvalue' => '99']];

        $out = ClientCustomModel::resolveOptionLabels($rows, $this->labels);

        $this->assertSame('99', $out[0]['client_custom_fieldvalue']);
    }

    public function testEmptyValueStaysEmpty(): void
    {
        $rows = [['custom_field_type' => 'SINGLE-CHOICE', 'client_custom_fieldvalue' => '']];

        $out = ClientCustomModel::resolveOptionLabels($rows, $this->labels);

        $this->assertSame('', $out[0]['client_custom_fieldvalue']);
    }
}
