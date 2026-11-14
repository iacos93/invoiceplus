<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * I flashdata "alert_warning" devono diventare toast come gli altri livelli.
 *
 * Il warning è il livello usato dagli import e dalle anagrafiche per dire
 * "operazione riuscita, ma con qualcosa da controllare": prima di questa
 * aggiunta finiva in sessione e non veniva mai mostrato. Il test fissa anche
 * il patto di sicurezza: il testo del toast passa da flash_html(), quindi il
 * markup che arriva da fuori resta escapato.
 *
 * CIUnitTestCase inietta una sessione mock (ArrayHandler), quindi
 * Services::session() e la view condividono la stessa istanza.
 *
 * @internal
 */
final class AlertWarningToastTest extends CIUnitTestCase
{
    public function testAlertWarningProduceUnToast(): void
    {
        helper('ip_helpers');

        Services::session()->setFlashdata('alert_warning', 'Riga non importata: P.IVA mancante');

        $html = view('layout/alerts');

        $this->assertStringContainsString('ip-toast--warning', $html);
        $this->assertStringContainsString('fa-solid fa-circle-exclamation', $html);
        $this->assertStringContainsString('Riga non importata: P.IVA mancante', $html);
    }

    public function testIlWarningSiChiudeDaSoloComeInfoESuccess(): void
    {
        helper('ip_helpers');

        Services::session()->setFlashdata('alert_warning', 'Avviso');

        $html = view('layout/alerts');

        // Solo l'errore resta a schermo; il warning deve autochiudersi.
        $this->assertMatchesRegularExpression(
            '/ip-toast--warning[^>]*data-bs-autohide="true"/s',
            $html
        );
    }

    public function testIlContenutoDelWarningVieneEscapato(): void
    {
        helper('ip_helpers');

        Services::session()->setFlashdata(
            'alert_warning',
            '<img src=x onerror=alert(1)> & "virgolette"'
        );

        $html = view('layout/alerts');

        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
    }

    public function testSenzaWarningNonCompareIlToast(): void
    {
        helper('ip_helpers');

        $html = view('layout/alerts');

        $this->assertStringNotContainsString('ip-toast--warning', $html);
    }
}
