<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Le pagine pubbliche di fattura e preventivo (link con url key, nessun
 * login) devono essere documenti HTML completi.
 *
 * La vista estendeva il layout solo quando public_view era vuoto, cioe'
 * nell'area guest autenticata: al cliente arrivava un frammento senza <head>,
 * senza CSS e senza viewport. La verifica e' sul sorgente perche' il difetto
 * e' strutturale (l'extend era dentro un if), mentre un test che rendesse la
 * vista avrebbe bisogno di un record completo di tutte le colonne e delle
 * impostazioni lette dal database.
 */
final class GuestPublicLayoutTest extends CIUnitTestCase
{
    private const VIEWS = [
        'guest/invoices_view.php',
        'guest/quotes_view.php',
    ];

    public function testLePaginePubblicheEstendonoSempreIlLayout(): void
    {
        foreach (self::VIEWS as $view) {
            $source = (string) file_get_contents(APPPATH . 'Views/' . $view);

            $this->assertStringContainsString(
                "extend('layout/guest')",
                $source,
                $view . ': manca il layout guest.'
            );

            $this->assertStringNotContainsString(
                'if (empty($public_view))',
                $source,
                $view . ': il layout e/o la sezione sono condizionati a public_view, quindi la '
                . 'pagina pubblica esce senza <head> e senza CSS.'
            );
        }
    }

    public function testIlLayoutNascondeINavGuestAlVisitatorePubblico(): void
    {
        $layout = (string) file_get_contents(APPPATH . 'Views/layout/guest.php');

        $this->assertStringContainsString(
            '$is_public_view = !empty($public_view);',
            $layout,
            'layout/guest.php: manca il flag che distingue il visitatore pubblico.'
        );

        // Sia la navigazione dell'area guest sia il logout devono stare dentro
        // il ramo riservato agli utenti autenticati.
        $markers = ['ip-guest-nav', 'sessions/logout'];

        foreach ($markers as $marker) {
            $pos = strpos($layout, $marker);

            $this->assertNotFalse($pos, 'layout/guest.php: manca ' . $marker . '.');

            $before = substr($layout, 0, $pos);

            $this->assertStringContainsString(
                'if (!$is_public_view):',
                $before,
                'layout/guest.php: ' . $marker . ' non e\' protetto dal flag pubblico.'
            );
        }
    }
}
