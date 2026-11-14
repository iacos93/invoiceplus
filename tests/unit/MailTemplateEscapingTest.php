<?php

namespace Tests\Unit;

use App\Libraries\MailTemplate;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * I tag {{tag}} delle email non devono portare HTML nel messaggio.
 *
 * Il valore arriva dall'anagrafica (nome cliente, ragione sociale, note): e'
 * testo, non markup. `{{{tag}}}` resta la via esplicita per inserire contenuto
 * grezzo, e serve ai template che costruiscono un link gia' pronto.
 */
final class MailTemplateEscapingTest extends CIUnitTestCase
{
    public function testTagDoppioEscapaIlValore(): void
    {
        $out = MailTemplate::render('Ciao {{client_name}}', ['client_name' => '<b>Rossi</b>']);

        $this->assertSame('Ciao &lt;b&gt;Rossi&lt;/b&gt;', $out);
    }

    public function testTagTriploInserisceGrezzo(): void
    {
        $out = MailTemplate::render('Ciao {{{client_name}}}', ['client_name' => '<b>Rossi</b>']);

        $this->assertSame('Ciao <b>Rossi</b>', $out);
    }

    public function testLeDueSintassiConvIvonoNelloStessoTesto(): void
    {
        $out = MailTemplate::render(
            '{{client_name}} / {{{client_name}}}',
            ['client_name' => 'A&B']
        );

        $this->assertSame('A&amp;B / A&B', $out);
    }

    public function testEscapeDisattivabilePerIOggettiNonHtml(): void
    {
        $out = MailTemplate::render(
            'Fattura {{invoice_number}} per {{client_name}}',
            ['invoice_number' => '2026/001', 'client_name' => 'A&B'],
            false
        );

        $this->assertSame('Fattura 2026/001 per A&B', $out);
    }

    public function testVirgoletteEApiciSonoEscapati(): void
    {
        $out = MailTemplate::render('{{note}}', ['note' => 'L\'"articolo"']);

        $this->assertSame('L&#039;&quot;articolo&quot;', $out);
    }

    public function testTagSconosciutoRestaVisibile(): void
    {
        $out = MailTemplate::render('{{non_esiste}} {{client_name}}', ['client_name' => 'Rossi']);

        $this->assertSame('{{non_esiste}} Rossi', $out);
    }

    public function testTestoSenzaTagRestaIntatto(): void
    {
        $this->assertSame('Nessun tag qui', MailTemplate::render('Nessun tag qui', ['a' => 'b']));
        $this->assertSame('', MailTemplate::render('', ['a' => 'b']));
    }

    public function testTagRipetutoVieneSostituitoOvunque(): void
    {
        $out = MailTemplate::render('{{n}}-{{n}}-{{n}}', ['n' => '1']);

        $this->assertSame('1-1-1', $out);
    }
}
