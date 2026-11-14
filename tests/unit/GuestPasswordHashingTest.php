<?php

use App\Libraries\GuestAccessThrottle;
use App\Libraries\GuestPassword;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockCache;

/**
 * Password del portale guest: hash e tetto ai tentativi.
 *
 * I test non toccano il database: la verifica, la migrazione del vecchio
 * formato cifrato e il throttling sono metodi puri (la cache è un mock in
 * memoria). Il percorso completo -- scrivere la colonna, rileggerla,
 * riscriverla dopo lo sblocco -- resta coperto solo a mano, perché richiede
 * PostgreSQL.
 *
 * @internal
 */
final class GuestPasswordHashingTest extends CIUnitTestCase
{
    public function testLaPasswordHashataAccettaSoloQuellaGiusta(): void
    {
        $hash = GuestPassword::hash('password-segreta');

        $this->assertTrue(GuestPassword::isHash($hash), 'Deve essere un hash, non un testo cifrato reversibile');
        $this->assertSame('bcrypt', password_get_info($hash)['algoName']);

        $giusta = GuestPassword::verify($hash, 'password-segreta');
        $this->assertTrue($giusta['ok']);
        $this->assertNull($giusta['upgrade'], 'Un hash bcrypt corrente non va riscritto');

        $sbagliata = GuestPassword::verify($hash, 'password-sbagliata');
        $this->assertFalse($sbagliata['ok']);
        $this->assertNull($sbagliata['upgrade']);
    }

    public function testLHashGiaCalcolatoNonVieneRiHashato(): void
    {
        // È il caso della copia preventivo → fattura e dei salvataggi che
        // ripassano dal model con il valore già presente in colonna.
        $hash = GuestPassword::hash('password-segreta');

        $this->assertSame($hash, GuestPassword::hash($hash));
    }

    public function testLHashBcryptStaDentroLaColonnaDa90Caratteri(): void
    {
        $hash = GuestPassword::hash(str_repeat('a', 200));

        $this->assertLessThanOrEqual(
            GuestPassword::COLUMN_LENGTH,
            strlen($hash),
            'La colonna è VARCHAR(90): un hash più lungo verrebbe troncato e rifiutato per sempre'
        );
    }

    public function testIlVecchioValoreCifratoVieneAccettatoEMigrato(): void
    {
        try {
            $legacy = encrypt_secret('password-vecchia');
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('encryption.key non configurata: impossibile costruire un valore legacy.');
        }

        $this->assertTrue(GuestPassword::isLegacy($legacy));
        $this->assertFalse(GuestPassword::isHash($legacy));

        $check = GuestPassword::verify($legacy, 'password-vecchia');
        $this->assertTrue($check['ok'], 'Il formato vecchio deve continuare a funzionare');
        $this->assertNotNull($check['upgrade'], 'Dopo il primo accesso si deve migrare');
        $this->assertTrue(GuestPassword::isHash($check['upgrade']));

        // L'hash scritto al posto del valore cifrato accetta la stessa password.
        $dopo = GuestPassword::verify((string) $check['upgrade'], 'password-vecchia');
        $this->assertTrue($dopo['ok']);
        $this->assertNull($dopo['upgrade']);

        // Una password sbagliata sul valore vecchio non migra niente.
        $sbagliata = GuestPassword::verify($legacy, 'password-sbagliata');
        $this->assertFalse($sbagliata['ok']);
        $this->assertNull($sbagliata['upgrade']);
    }

    public function testUnValoreVuotoNonSbloccaMai(): void
    {
        $this->assertFalse(GuestPassword::verify('', '')['ok']);
        $this->assertFalse(GuestPassword::verify('', 'password')['ok']);
    }

    public function testIlThrottleBloccaDopoCinqueErrori(): void
    {
        $throttle = new GuestAccessThrottle(new MockCache());
        $key      = GuestAccessThrottle::key('invoice', 'chiave-url-fattura', '203.0.113.7');

        for ($i = 0; $i < GuestAccessThrottle::MAX_ATTEMPTS - 1; $i++) {
            $throttle->hit($key);
        }

        $this->assertFalse($throttle->blocked($key), 'Quattro errori non bastano a bloccare');
        $this->assertSame(GuestAccessThrottle::MAX_ATTEMPTS - 1, $throttle->attempts($key));

        $throttle->hit($key);

        $this->assertTrue($throttle->blocked($key), 'Il quinto errore blocca');
    }

    public function testUnSuccessoAzzeraIlContatore(): void
    {
        $throttle = new GuestAccessThrottle(new MockCache());
        $key      = GuestAccessThrottle::key('quote', 'chiave-url-preventivo', '203.0.113.7');

        for ($i = 0; $i < GuestAccessThrottle::MAX_ATTEMPTS; $i++) {
            $throttle->hit($key);
        }

        $this->assertTrue($throttle->blocked($key));

        $throttle->clear($key);

        $this->assertFalse($throttle->blocked($key));
        $this->assertSame(0, $throttle->attempts($key));

        // Dopo l'azzeramento si riparte da zero tentativi.
        $throttle->hit($key);
        $this->assertSame(1, $throttle->attempts($key));
    }

    public function testIlContatoreEDocumentoEIpSpecifico(): void
    {
        $throttle = new GuestAccessThrottle(new MockCache());
        $fattura  = GuestAccessThrottle::key('invoice', 'fattura-a', '203.0.113.7');
        $altroDoc = GuestAccessThrottle::key('invoice', 'fattura-b', '203.0.113.7');
        $altroIp  = GuestAccessThrottle::key('invoice', 'fattura-a', '198.51.100.9');
        $quote    = GuestAccessThrottle::key('quote', 'fattura-a', '203.0.113.7');

        for ($i = 0; $i < GuestAccessThrottle::MAX_ATTEMPTS; $i++) {
            $throttle->hit($fattura);
        }

        $this->assertTrue($throttle->blocked($fattura));
        $this->assertFalse($throttle->blocked($altroDoc), 'Un altro documento non eredita il blocco');
        $this->assertFalse($throttle->blocked($altroIp), 'Un altro IP non eredita il blocco');
        $this->assertFalse($throttle->blocked($quote), 'Fattura e preventivo hanno contatori separati');
    }
}
