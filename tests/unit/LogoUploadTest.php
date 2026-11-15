<?php

use App\Libraries\LogoUpload;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Regole dei loghi dei documenti, senza database né upload reali.
 *
 * Due cose qui non possono rompersi in silenzio: quale logo finisce su un
 * documento fiscale (quello dell'azienda, con ripiego su quello globale) e i
 * controlli sul file caricato, che ora servono due moduli — Impostazioni e
 * scheda azienda — invece di uno.
 *
 * @internal
 */
final class LogoUploadTest extends CIUnitTestCase
{
    public function testIlLogoDellAziendaVinceSuQuelloGlobale(): void
    {
        $azienda = ['invoice_logo' => 'company3-invoice_logo-novatech.svg'];

        $this->assertSame(
            'company3-invoice_logo-novatech.svg',
            LogoUpload::pick($azienda, 'invoice_logo', 'invoice_logo-comune.png')
        );
    }

    public function testSenzaLogoProprioSiUsaQuelloGlobale(): void
    {
        foreach ([[], ['invoice_logo' => null], ['invoice_logo' => ''], ['invoice_logo' => '  ']] as $azienda) {
            $this->assertSame(
                'invoice_logo-comune.png',
                LogoUpload::pick($azienda, 'invoice_logo', 'invoice_logo-comune.png')
            );
        }
    }

    public function testIDueLoghiSiScelgonoSeparatamente(): void
    {
        // Un'azienda con il solo logo d'intestazione non eredita per il piè
        // di pagina il proprio logo esteso: lì vale quello globale, o niente.
        $azienda = ['invoice_logo' => 'esteso.svg', 'invoice_logo_footer' => null];

        $this->assertSame('esteso.svg', LogoUpload::pick($azienda, 'invoice_logo', ''));
        $this->assertSame('', LogoUpload::pick($azienda, 'invoice_logo_footer', ''));
    }

    public function testIlPieDiPaginaSegueLaStessaRegola(): void
    {
        // Il testo a piè di pagina usa la stessa scelta dei loghi: quello
        // dell'azienda se compilato, altrimenti quello delle Impostazioni.
        $srl = ['pdf_invoice_footer' => 'Iacovino S.r.l. — Cap. soc. 10.000 € i.v.'];

        $this->assertSame(
            'Iacovino S.r.l. — Cap. soc. 10.000 € i.v.',
            LogoUpload::pick($srl, 'pdf_invoice_footer', 'Testo comune')
        );
        $this->assertSame('Testo comune', LogoUpload::pick(['pdf_invoice_footer' => ''], 'pdf_invoice_footer', 'Testo comune'));
        // Il preventivo non eredita il testo delle fatture.
        $this->assertSame('', LogoUpload::pick($srl, 'pdf_quote_footer', ''));
    }

    public function testIlNomeDelFileNonSiFidaDelBrowser(): void
    {
        $this->assertSame(
            'company3-invoice_logo-passwd.png',
            LogoUpload::fileName('../../etc/passwd.png', 'PNG', 'company3-invoice_logo')
        );
        $this->assertSame(
            'invoice_logo-logo.svg',
            LogoUpload::fileName('°°°.svg', 'svg', 'invoice_logo')
        );
    }

    public function testDueAziendeConLoStessoFileNonSiSovrascrivono(): void
    {
        $this->assertNotSame(
            LogoUpload::fileName('logo.png', 'png', 'company1-invoice_logo'),
            LogoUpload::fileName('logo.png', 'png', 'company2-invoice_logo')
        );
    }

    public function testSvgConContenutiAttiviRifiutato(): void
    {
        $dir = sys_get_temp_dir();

        $buono = tempnam($dir, 'svg');
        file_put_contents($buono, '<svg xmlns="http://www.w3.org/2000/svg"><rect width="4" height="4"/></svg>');

        $this->assertTrue(LogoUpload::isSafeSvg($buono));

        foreach ([
            '<svg><script>alert(1)</script></svg>',
            '<svg onload="alert(1)"></svg>',
            '<svg><foreignObject><b>x</b></foreignObject></svg>',
            '<svg><a href="javascript:alert(1)">x</a></svg>',
            'non è un svg',
        ] as $contenuto) {
            $cattivo = tempnam($dir, 'svg');
            file_put_contents($cattivo, $contenuto);

            $this->assertFalse(LogoUpload::isSafeSvg($cattivo), $contenuto);

            unlink($cattivo);
        }

        unlink($buono);
    }

    public function testSvgSizeFollowsViewBox(): void
    {
        helper('ip');

        $out = svg_size_from_viewbox('<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="250" viewBox="0 0 264.58 66.15"><path d="M0 0"/></svg>');

        $this->assertStringContainsString('width="264.58" height="66.15"', $out);
        $this->assertStringNotContainsString('1000', $out);
        $this->assertSame('<svg><g/></svg>', svg_size_from_viewbox('<svg><g/></svg>'));
    }

    public function testMonogramSkipsParticles(): void
    {
        helper('ip');

        // Tre parole che contano, tre iniziali: con due, nella scheda cliente,
        // tre istituti comprensivi diversi si presentavano tutti come "IC".
        $this->assertSame('CMA', name_monogram('Comune di Monte Alto'));
        $this->assertSame('MAZ', name_monogram('Maria Angela Zema'));
        $this->assertSame('IDA', name_monogram("ICS Duca d'Aosta"));
        $this->assertSame('IFM', name_monogram('ICS Filippo Maria Beltrami'));
        // Due parole significative: il risultato resta quello di prima.
        $this->assertSame('SV', name_monogram('Scuola "Verdi"'));
        $this->assertSame('N', name_monogram('Novatech'));
        $this->assertSame('DI', name_monogram('di il'));
        $this->assertSame('?', name_monogram('  '));
        // Il tetto si può abbassare: la scheda utente ha una piastrella più
        // piccola e resta a due (chiamata esplicita, non default).
        $this->assertSame('CM', name_monogram('Comune di Monte Alto', 2));
        $this->assertSame('CMAN', name_monogram('Comune di Monte Alto Nord', 4));
    }
}
