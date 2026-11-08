<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * clients/partial_custom_fields sostituisce cinque copie identiche che stavano
 * dentro clients/form.php, una per riquadro. Il test fissa le due cose che una
 * modifica futura potrebbe rompere senza accorgersene: che il filtro per
 * location selezioni davvero solo i campi del riquadro, e che l'etichetta
 * resti agganciata al controllo (era scollegata in tutte e cinque le copie).
 *
 * Il partial viene esercitato attraverso View::include(), cioe' la stessa
 * strada che usa la view: il parametro finisce in renderVars['options'], non
 * fra le variabili estratte.
 */
final class ClientCustomFieldsPartialTest extends CIUnitTestCase
{
    private function render(array $fields, array $values, int $location): string
    {
        helper(['ip_helpers', 'setting', 'date']);

        $model = new class {
            public array $values = [];

            public function formValue($key)
            {
                return $this->values[$key] ?? '';
            }
        };
        $model->values = ['custom[7]' => '3', 'custom[8]' => '5', 'custom[9]' => 'ABC-1'];

        $view = \Config\Services::renderer(null, null, false);
        $view->setData(compact('fields', 'values', 'model') + [
            'custom_fields' => $fields,
            'custom_values' => $values,
            'model'         => $model,
        ]);

        return $view->include('clients/partial_custom_fields', ['location' => $location]);
    }

    private function fields(): array
    {
        return [
            ['custom_field_id' => 7, 'custom_field_label' => 'Referente',  'custom_field_type' => 'SINGLE-CHOICE',   'custom_field_location' => 1],
            ['custom_field_id' => 8, 'custom_field_label' => 'Settori',    'custom_field_type' => 'MULTIPLE-CHOICE', 'custom_field_location' => 1],
            ['custom_field_id' => 9, 'custom_field_label' => 'Codice ERP', 'custom_field_type' => 'TEXT',            'custom_field_location' => 2],
        ];
    }

    private function values(): array
    {
        return [
            7 => [
                ['custom_values_id' => 3, 'custom_values_value' => 'Mario'],
                ['custom_values_id' => 4, 'custom_values_value' => 'Luisa'],
            ],
            8 => [['custom_values_id' => 5, 'custom_values_value' => 'Edilizia']],
        ];
    }

    public function testRendersOnlyTheRequestedLocation(): void
    {
        $html = $this->render($this->fields(), $this->values(), 1);

        $this->assertStringContainsString('Referente', $html);
        $this->assertStringContainsString('Settori', $html);
        $this->assertStringNotContainsString('Codice ERP', $html);
    }

    public function testSingleChoiceKeepsLabelBoundToSelect(): void
    {
        $html = $this->render($this->fields(), $this->values(), 1);

        $this->assertStringContainsString('<label class="ip-doc-label" for="custom_7">', $html);
        $this->assertStringContainsString('name="custom[7]" id="custom_7"', $html);
        $this->assertMatchesRegularExpression('/value="3"\s+selected/', $html);
    }

    public function testMultipleChoiceIsAnAriaLabelledGroup(): void
    {
        $html = $this->render($this->fields(), $this->values(), 1);

        // Niente for= su una lista di checkbox: l'etichetta e' il titolo del gruppo.
        $this->assertStringContainsString('role="group" aria-labelledby="custom_8-label"', $html);
        $this->assertStringContainsString('<span class="ip-doc-label" id="custom_8-label">', $html);
        $this->assertStringContainsString('name="custom[8][]"', $html);
    }

    public function testPlainFieldFallsBackToTextInput(): void
    {
        $html = $this->render($this->fields(), $this->values(), 2);

        $this->assertStringContainsString('<label class="ip-doc-label" for="custom_9">', $html);
        $this->assertStringContainsString('<input type="text" name="custom[9]" id="custom_9"', $html);
    }
}
