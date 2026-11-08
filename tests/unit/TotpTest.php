<?php

use App\Libraries\Totp;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Copertura di TOTP e codici di recupero.
 *
 * L'algoritmo TOTP è scritto a mano invece che preso da una libreria: un
 * errore qui (base32, endianness del contatore, truncation) produrrebbe
 * codici che nessuna app authenticator accetta, e non ce ne accorgeremmo
 * mai in sviluppo perché il login MFA si attiva solo su richiesta. I vettori
 * RFC 6238 e gli scenari di replay/codice monouso tengono la porta chiusa.
 *
 * @internal
 */
final class TotpTest extends CIUnitTestCase
{
    /**
     * Vettori RFC 6238 (appendice B): secret di 20 byte, codici attesi.
     * L'applicazione usa sempre secret da 20 byte, quindi questi coprono
     * esattamente il caso reale (i vettori per 32/64 byte sono coperti dalla
     * stessa implementazione: la decodifica base32 è indipendente).
     */
    public static function rfc6238Vectors(): array
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // "12345678901234567890" in base32

        return [
            't0'    => [59, $secret, '287082'],
            't30'   => [1111111109, $secret, '081804'],
            't60'   => [1111111111, $secret, '050471'],
            't90'   => [1234567890, $secret, '005924'],
            't120'  => [2000000000, $secret, '279037'],
            't150'  => [20000000000, $secret, '353130'],
        ];
    }

    /**
     * @dataProvider rfc6238Vectors
     */
    public function testRfc6238Vectors(int $timestamp, string $secret, string $expected): void
    {
        $this->assertSame($expected, Totp::code($secret, $timestamp));
    }

    public function testVerifyAcceptsDriftAndRejectsGarbage(): void
    {
        $secret    = Totp::generateSecret();
        $now       = time();
        $code      = Totp::code($secret, $now);

        // ±1 passo di tolleranza: il codice di un minuto fa o fra un minuto
        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now - 30)));
        $this->assertTrue(Totp::verify($secret, Totp::code($secret, $now + 30)));
        $this->assertFalse(Totp::verify($secret, Totp::code($secret, $now - 90)));
        $this->assertFalse(Totp::verify($secret, '000000'));
        $this->assertFalse(Totp::verify($secret, ''));
        $this->assertFalse(Totp::verify($secret, 'abcdef'));
    }

    public function testReplayIsRejected(): void
    {
        $secret    = Totp::generateSecret();
        $now       = time();
        $code      = Totp::code($secret, $now);
        $counter   = Totp::counterFor($secret, $code, 1);

        $this->assertNotNull($counter);
        // Lo stesso codice con lastCounter aggiornato al contatore usato:
        // è un replay e va rifiutato, anche se è ancora nel passo valido.
        $this->assertNull(Totp::counterFor($secret, $code, 1, $counter));
    }

    public function testBase32RoundTrip(): void
    {
        $secret = Totp::generateSecret();

        $this->assertSame($secret, strtoupper($secret));
        $this->assertSame(32, strlen($secret)); // 20 byte -> 32 char base32
        $this->assertSame(Totp::code($secret, 123456), Totp::code(strtolower($secret), 123456));
    }

    public function testBackupCodesAreUniqueAndConsumableOnce(): void
    {
        $codes = Totp::generateBackupCodes();

        $this->assertCount(Totp::BACKUP_CODE_COUNT, $codes);
        $this->assertSame($codes, array_unique($codes));

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{10}$/', $code);
        }

        $hashes = json_encode(Totp::hashBackupCodes($codes));

        // Il codice non deve mai comparire in chiaro negli hash salvati.
        $this->assertStringNotContainsString($codes[0], $hashes);
        $this->assertTrue(Totp::verifyBackupCode($codes[0], $hashes));
        $this->assertFalse(Totp::verifyBackupCode('ZZZZZZZZZZ', $hashes));
        $this->assertFalse(Totp::verifyBackupCode('', $hashes));

        // Consumo: il codice usato sparisce, gli altri restano.
        $remaining = json_decode(Totp::consumeBackupCode($codes[0], $hashes), true);
        $this->assertCount(Totp::BACKUP_CODE_COUNT - 1, $remaining);
        $this->assertFalse(Totp::verifyBackupCode($codes[0], json_encode($remaining)));
        $this->assertTrue(Totp::verifyBackupCode($codes[1], json_encode($remaining)));
        $this->assertNull(Totp::consumeBackupCode('ZZZZZZZZZZ', $hashes));
    }

    public function testUriIsScannable(): void
    {
        $secret = Totp::generateSecret();
        $uri    = Totp::uri($secret, 'InvoicePlane', 'a@b.it');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=' . $secret, $uri);
        $this->assertStringContainsString('period=30', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        // Lo spazio in un account reale deve essere url-encoded
        $this->assertStringContainsString('%20', Totp::uri($secret, 'InvoicePlane', 'nome cognome'));
    }
}