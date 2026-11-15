<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * L'anno scelto nella sidebar è di ogni azienda, non dell'utente in generale.
 *
 * Chi lavora su due soggetti tiene periodi diversi — su una chiude il 2025,
 * sull'altra emette il 2026 — e una scelta sola faceva cambiare il periodo
 * sotto le mani a ogni cambio azienda. Qui si fissa la mappa
 * `[company_id => anno]` che la tiene, e il cookie che la fa sopravvivere a
 * logout e chiusura del browser: nel cookie c'è solo la mappa degli anni,
 * nessun dato del documento.
 *
 * La lettura dell'anno con i documenti veri (available_years) è provata in
 * tests/integration/YearFilterTest.php: serve il database.
 *
 * @internal
 */
final class SelectedYearTest extends CIUnitTestCase
{
    public function testScegliereLAnnoLoScriveSoloPerLAziendaAttiva(): void
    {
        helper('ip_helpers');

        $_SESSION['active_company_id'] = 1;
        session()->set('selected_years', [2 => 2025]);

        set_selected_year(2026);

        $this->assertSame(
            [2 => 2025, 1 => 2026],
            session('selected_years'),
            'L\'azienda 2 deve restare sul suo anno'
        );
    }

    public function testRememberSelectedYearWritesTheWholeMapInALongLivedCookie(): void
    {
        helper('ip_helpers');

        $_SESSION['active_company_id'] = 2;
        session()->set('selected_years', [1 => 2026, 2 => 2025]);

        $response = service('response');
        remember_selected_year($response, 2024);

        $cookie = null;

        // La chiave dell'array comprende path e dominio ("nome;/;"), quindi si
        // cerca per nome del cookie invece di indovinare la chiave.
        foreach ($response->getCookies() as $candidate) {
            if ($candidate->getName() === 'ip_selected_years') {
                $cookie = $candidate;
                break;
            }
        }

        $this->assertNotNull($cookie, 'Il cookie con gli anni non è stato impostato.');
        $this->assertSame(
            ['1' => 2026, '2' => 2024],
            json_decode($cookie->getValue(), true),
            'Il cookie porta la mappa di tutte le aziende, con l\'ultima scelta aggiornata'
        );
        $this->assertGreaterThan(
            time() + 300 * DAY,
            $cookie->getExpiresTimestamp(),
            'Il cookie deve durare circa un anno, non quanto la sessione.'
        );
    }

    public function testIlCookieVecchioDiUnAnnoSoloValePerLAziendaInCuiSiEntra(): void
    {
        helper('ip_helpers');

        // Chi ha già il cookie della versione precedente non deve ritrovarsi
        // "Tutti gli anni" al primo accesso dopo l'aggiornamento.
        $this->assertSame([3 => 2026], selected_years_from_cookies('', '2026', 3));

        // E la mappa ha la precedenza: quando c'è, il vecchio non si guarda più.
        $this->assertSame(
            [1 => 2026, 2 => 2025],
            selected_years_from_cookies('{"1":2026,"2":2025}', '2024', 3)
        );
    }
}
