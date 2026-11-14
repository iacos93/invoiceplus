<?php

use App\Services\SystemStatus;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * La scadenza del certificato client SDICoop.
 *
 * Un certificato scaduto ferma invio e ricezione senza un errore che qualcuno
 * veda: lo SdI chiude l'handshake e basta. Il controllo deve avvisare con
 * anticipo, dire che e' scaduto quando lo e', e non gridare per un canale che
 * non si usa.
 *
 * @internal
 */
final class SdiCertificateCheckTest extends CIUnitTestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '') {
            @unlink($this->file);
        }

        parent::tearDown();
    }

    /** Certificato di prova valido $giorni a partire da adesso. */
    private function certificato(int $giorni): string
    {
        $chiave = @openssl_pkey_new(['private_key_bits' => 2048]);

        if ($chiave === false) {
            $this->markTestSkipped('OpenSSL non riesce a generare una chiave in questo ambiente');
        }

        $csr = openssl_csr_new(['commonName' => 'prova-sdicoop'], $chiave);
        $crt = openssl_csr_sign($csr, null, $chiave, $giorni);

        openssl_x509_export($crt, $pem);
        openssl_pkey_export($chiave, $pemChiave);

        $this->file = tempnam(sys_get_temp_dir(), 'crt') . '.pem';
        // Come lo si tiene di solito: chiave e certificato nello stesso file.
        file_put_contents($this->file, $pemChiave . $pem);

        return $this->file;
    }

    private function servizio(?int $adesso = null): SystemStatus
    {
        return new SystemStatus(sys_get_temp_dir(), sys_get_temp_dir(), sys_get_temp_dir(), $adesso ?? time());
    }

    public function testConUnAltroCanaleNonSegnalaNiente(): void
    {
        foreach (['manual', 'pec', 'api'] as $canale) {
            $r = $this->servizio()->sdiCertificateCheck($canale, '');

            $this->assertSame(SystemStatus::OK, $r['state'], $canale);
            $this->assertSame('sdi_cert', $r['key']);
        }
    }

    public function testUnCertificatoLontanoDallaScadenzaERegolare(): void
    {
        $r = $this->servizio()->sdiCertificateCheck('sdicoop', $this->certificato(300));

        $this->assertSame(SystemStatus::OK, $r['state']);
        $this->assertStringContainsString('Valido fino al', $r['detail']);
    }

    public function testInScadenzaEUnAvvisoConIGiorniRimasti(): void
    {
        $r = $this->servizio()->sdiCertificateCheck('sdicoop', $this->certificato(20));

        $this->assertSame(SystemStatus::WARNING, $r['state']);
        $this->assertMatchesRegularExpression('/Scade tra (19|20) giorni/', $r['detail']);
        $this->assertStringContainsString('senza errori visibili', $r['hint']);
    }

    public function testScadutoEUnGuasto(): void
    {
        $percorso = $this->certificato(10);

        $r = $this->servizio(time() + 30 * 86400)->sdiCertificateCheck('sdicoop', $percorso);

        $this->assertSame(SystemStatus::ERROR, $r['state']);
        $this->assertStringContainsString('Scaduto il', $r['detail']);
    }

    public function testLaSogliaDellAvvisoEQuellaDichiarata(): void
    {
        $r = $this->servizio()->sdiCertificateCheck('sdicoop', $this->certificato(SystemStatus::CERT_WARNING_DAYS + 5));
        $this->assertSame(SystemStatus::OK, $r['state'], 'Appena sopra la soglia: ancora regolare');

        $r = $this->servizio()->sdiCertificateCheck('sdicoop', $this->certificato(SystemStatus::CERT_WARNING_DAYS - 5));
        $this->assertSame(SystemStatus::WARNING, $r['state'], 'Appena sotto la soglia: avviso');
    }

    public function testPercorsoMancanteIllegibileOVuoto(): void
    {
        $this->assertSame(SystemStatus::WARNING, $this->servizio()->sdiCertificateCheck('sdicoop', '')['state'], 'Non impostato');
        $this->assertSame(SystemStatus::ERROR, $this->servizio()->sdiCertificateCheck('sdicoop', '/percorso/che/non/esiste.pem')['state'], 'Non esiste');

        $this->file = tempnam(sys_get_temp_dir(), 'crt');
        file_put_contents($this->file, 'questo non e\' un certificato');

        $r = $this->servizio()->sdiCertificateCheck('sdicoop', $this->file);
        $this->assertSame(SystemStatus::WARNING, $r['state'], 'File non PEM');
        $this->assertStringContainsString('PEM', $r['hint']);
    }
}
