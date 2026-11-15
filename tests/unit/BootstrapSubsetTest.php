<?php

use App\Libraries\BootstrapSubset;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il foglio Bootstrap ridotto alle classi in uso.
 *
 * Ridurre un vendor è comodo e pericoloso insieme: una classe tagliata non dà
 * errori, la pagina si apre e qualcosa non è più nel posto giusto. Questi test
 * sono la rete — ogni classe scritta a mano in un attributo `class=` deve
 * esistere nel foglio servito, e il foglio servito deve essere quello che il
 * generatore produrrebbe adesso.
 *
 * @internal
 */
final class BootstrapSubsetTest extends CIUnitTestCase
{
    public function testNessunaClasseUsataMancaDalFoglio(): void
    {
        $missing = BootstrapSubset::missingClasses(ROOTPATH);

        $this->assertSame([], array_keys($missing),
            'Classi usate dalle view e assenti dal foglio Bootstrap: '
            . json_encode($missing, JSON_UNESCAPED_SLASHES)
            . ' — esegui `php tools/subset_bootstrap.php`.');
    }

    /**
     * Il foglio versionato deve essere quello che il generatore produrrebbe
     * adesso: aggiungere una classe in una view senza rigenerare lascerebbe
     * l'app a servire un foglio vecchio, cioè senza quello stile.
     */
    public function testIlFoglioServitoECoerenteConISorgenti(): void
    {
        $report = BootstrapSubset::build(ROOTPATH);

        $this->assertSame(
            $report['css'],
            (string) file_get_contents(ROOTPATH . BootstrapSubset::OUTPUT_CSS),
            'bootstrap.subset.min.css non corrisponde alle classi usate: esegui `php tools/subset_bootstrap.php` e versiona il risultato.'
        );
    }

    public function testLeRegoleInutilizzateSpariscono(): void
    {
        $subset = (string) file_get_contents(ROOTPATH . BootstrapSubset::OUTPUT_CSS);

        // Utility che nessuna view scrive. (Le classi dei componenti che
        // Bootstrap monta da sé restano: il bundle le nomina, e tenerle è il
        // prezzo per non rompere un tooltip al primo hover.)
        foreach (['.vstack', '.hstack', '.ratio-4x3', '.link-offset-2', '.lh-1', '.z-3'] as $assente) {
            $this->assertStringNotContainsString($assente, $subset, 'Regola inutile rimasta: ' . $assente);
        }

        // Componenti che usa: se sparissero, la pagina perderebbe lo stile.
        foreach (['.modal', '.modal-backdrop', '.tooltip', '.tooltip-arrow', '.dropdown-menu', '.btn', '.form-control', '.table'] as $selettore) {
            $this->assertStringContainsString($selettore, $subset, 'Manca ' . $selettore . ' nel foglio servito');
        }

        // Le classi di posizionamento dei tooltip sono composte a runtime
        // (`bs-tooltip-top`, `bs-popover-end`): il prefisso le tiene tutte.
        $this->assertStringContainsString('.bs-tooltip-top', $subset);
        $this->assertStringContainsString('.bs-popover-auto', $subset);

        // La licenza di Bootstrap viaggia con il foglio ridotto.
        $this->assertStringContainsString('Bootstrap', substr($subset, 0, 400));
    }
}
