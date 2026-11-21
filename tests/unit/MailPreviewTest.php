<?php

namespace Tests\Unit;

use App\Libraries\MailPreview;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * L'anteprima di un modello email deve mostrare ciò che parte davvero: tag
 * sostituiti, testo semplice convertito in HTML, corpo già HTML lasciato com'è.
 *
 * @internal
 */
final class MailPreviewTest extends CIUnitTestCase
{
    public function testSostituisceITagConDatiDiEsempio(): void
    {
        $subject = MailPreview::subject('Fattura {{invoice_number}} per {{client_fullname}}');

        $this->assertSame('Fattura 2026/001 per Mario Rossi', $subject);
    }

    public function testLOggettoNonEscapaGliAccentiCommerciali(): void
    {
        // L'oggetto non è HTML: un "&" deve restare tale, non diventare "&amp;".
        $this->assertSame('Rossi & Figli - 2026/001', MailPreview::subject('Rossi & Figli - {{invoice_number}}'));
    }

    public function testIlTestoSempliceDiventaHtmlConGliACapo(): void
    {
        $html = MailPreview::body("Gentile {{client_name}},\n\nsaldo {{invoice_balance}} < 100 & ok");

        $this->assertStringContainsString('Gentile Mario,', $html);
        $this->assertStringContainsString('<br', $html);
        $this->assertStringContainsString('&lt; 100 &amp; ok', $html);
    }

    public function testIlCorpoGiaInHtmlRestaInvariato(): void
    {
        $this->assertSame(
            '<p>Ciao Mario, saldo 1.220,00 €</p>',
            MailPreview::body('<p>Ciao {{client_name}}, saldo {{invoice_balance}}</p>')
        );
    }

    public function testITagSconosciutiRestanoVisibili(): void
    {
        // I campi personalizzati dipendono dal cliente: restano scritti invece
        // di sparire, così si vede che nel modello c'è un tag da sostituire.
        $this->assertStringContainsString('{{ip_cf_12}}', MailPreview::body('Campo: {{ip_cf_12}}'));
    }
}
