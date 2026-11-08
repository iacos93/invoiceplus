<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * L'anno scelto nella sidebar deve sopravvivere alla sessione.
 *
 * La sessione dura due ore ed è distrutta dal logout: senza il cookie, chi
 * riapre il browser o rientra il giorno dopo ritrovava "Tutti gli anni" e
 * doveva rifiltrare da capo. Qui si fissa il patto: in sessione vince la
 * scelta esplicita, e remember_selected_year() ne lascia una copia in un
 * cookie di lungo periodo — sulla Response che viene davvero inviata, non sul
 * servizio globale (redirect() costruisce un oggetto nuovo).
 *
 * @internal
 */
final class SelectedYearTest extends CIUnitTestCase
{
    public function testSessionValueWinsOverEverything(): void
    {
        helper('ip_helpers');
        session()->set('selected_year', 2024);

        $this->assertSame(2024, selected_year());
    }

    public function testRememberSelectedYearWritesALongLivedCookie(): void
    {
        helper('ip_helpers');

        $response = service('response');
        remember_selected_year($response, 2023);

        $cookies = $response->getCookies();

        // La chiave dell'array comprende path e dominio ("nome;/;"), quindi
        // si cerca per nome del cookie invece di indovinare la chiave.
        $cookie = null;

        foreach ($cookies as $candidate) {
            if ($candidate->getName() === 'ip_selected_year') {
                $cookie = $candidate;
                break;
            }
        }

        $this->assertNotNull($cookie, 'Il cookie con l\'anno non è stato impostato.');
        $this->assertSame('2023', $cookie->getValue());
        $this->assertGreaterThan(
            time() + 300 * DAY,
            $cookie->getExpiresTimestamp(),
            'Il cookie deve durare circa un anno, non quanto la sessione.'
        );
    }
}
