<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * I toast non devono interpretare HTML che arriva dai dati.
 *
 * Gli import mettono nei flashdata nomi di file, P.IVA e messaggi letti dal
 * documento caricato: un XML o un CSV artigianale poteva eseguire JavaScript
 * nella sessione dell'amministratore che lo importava. Qui si fissa il patto:
 * flash_html() scappa sempre e converte solo i fine riga in <br>.
 *
 * @internal
 */
final class FlashMessageEscapingTest extends CIUnitTestCase
{
    public function testIlMarkupProvenienteDaiDatiVieneEscapato(): void
    {
        helper('ip_helpers');

        $rendered = flash_html('<img src=x onerror=alert(1)>');

        $this->assertStringContainsString('&lt;img', $rendered);
        $this->assertStringNotContainsString('<img', $rendered);
    }

    public function testIFineRigaDiventanoBrSenzaPerdereIlTesto(): void
    {
        helper('ip_helpers');

        $rendered = flash_html("Prima riga\nSeconda riga");

        $this->assertSame("Prima riga<br>\nSeconda riga", $rendered);
    }

    public function testErrorsToHtmlNonInserisceBrGrezzo(): void
    {
        $errors = [
            'Il campo "Nome" & cognome è obbligatorio',
            'Valore <non valido>',
        ];

        $joined = $this->errorsToHtml($errors);

        $this->assertStringNotContainsString('<br>', $joined);
        $this->assertSame(implode("\n", $errors), $joined);
    }

    public function testIlToastScappaUnaVoltaSolaIMessaggiDiValidazione(): void
    {
        helper('ip_helpers');

        $errors = [
            'A & B',
            '<valore>',
            'virgolette "doppie" e \'singole\'',
        ];

        $rendered = flash_html($this->errorsToHtml($errors));

        // Un solo passaggio di esc(): le entità non vengono riescapate.
        $this->assertStringContainsString('A &amp; B', $rendered);
        $this->assertStringContainsString('&lt;valore&gt;', $rendered);
        $this->assertStringContainsString('&quot;doppie&quot;', $rendered);
        $this->assertStringContainsString('&#039;singole&#039;', $rendered);

        $this->assertStringNotContainsString('&amp;amp;', $rendered);
        $this->assertStringNotContainsString('&amp;lt;', $rendered);
        $this->assertStringNotContainsString('&amp;quot;', $rendered);

        // Tre messaggi su righe separate: un <br> fra l'uno e l'altro, non uno
        // in più che sarebbe il segno di un <br> grezzo già presente in input.
        $this->assertSame(2, substr_count($rendered, '<br>'));
    }

    private function errorsToHtml(array $errors): string
    {
        $controller = new \App\Controllers\Settings();
        $method     = new \ReflectionMethod($controller, 'errorsToHtml');

        return (string) $method->invoke($controller, $errors);
    }
}
