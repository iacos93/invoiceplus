<?php

use App\Libraries\FatturaXml;
use App\Libraries\Sdi\P7mSignature;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Verifica best-effort delle buste .p7m (CMS/CAdES).
 *
 * Le buste si generano al volo con la CLI openssl (certificato self-signed +
 * `openssl cms -sign`), perché una busta finta non proverebbe nulla: qui
 * interessa il percorso reale digest/firma di OpenSSL. Se la CLI non c'è, o
 * non supporta `cms`, i test si saltano invece di fallire.
 *
 * Restano fuori le buste vere dello SdI: sono BER a lunghezza indefinita come
 * quelle prodotte con `-stream`, ma firmate con la CA SOGEI e con catena di
 * certificati. Qui la catena non si verifica (PKCS7_NOVERIFY), quindi il
 * percorso è lo stesso; non è però una prova di interoperabilità con SOGEI.
 *
 * @internal
 */
final class P7mSignatureTest extends CIUnitTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('openssl_pkcs7_verify')) {
            $this->markTestSkipped('estensione openssl di PHP non disponibile');
        }

        exec('openssl version 2>&1', $output, $status);

        if ($status !== 0) {
            $this->markTestSkipped('CLI openssl non disponibile');
        }

        $this->dir = sys_get_temp_dir() . '/p7m-signature-' . bin2hex(random_bytes(6));

        if (!mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            $this->markTestSkipped('impossibile creare la directory temporanea dei test');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
        $this->dir = '';

        parent::tearDown();
    }

    public function testBustaValidaHaFirmaVerificata(): void
    {
        $inspection = P7mSignature::inspect($this->signed($this->invoiceXml('FIRMA-MARKER')));

        $this->assertTrue($inspection['checked']);
        $this->assertTrue($inspection['valid']);
        $this->assertCount(1, $inspection['signers']);
        $this->assertSame('Test', $inspection['signers'][0]['cn']);
        $this->assertFalse($inspection['signers'][0]['expired']);
        $this->assertSame([], $inspection['problems']);
    }

    public function testBustaBase64VieneNormalizzata(): void
    {
        $envelope = $this->signed($this->invoiceXml('FIRMA-MARKER'));
        $inspection = P7mSignature::inspect(chunk_split(base64_encode($envelope), 64, "\n"));

        $this->assertTrue($inspection['checked']);
        $this->assertTrue($inspection['valid']);
        $this->assertSame('Test', $inspection['signers'][0]['cn']);
    }

    /**
     * Le buste dello SdI sono BER a lunghezza indefinita (`cms -stream`),
     * non DER a lunghezza definita: è il formato che deve continuare a
     * funzionare senza finire nel ramo "non verificabile".
     */
    public function testBustaBerIndefinitaVieneVerificata(): void
    {
        $envelope   = $this->signed($this->invoiceXml('FIRMA-MARKER'), true);
        $inspection = P7mSignature::inspect($envelope);

        $this->assertStringStartsWith("\x30\x80", $envelope, 'la busta di prova deve essere BER a lunghezza indefinita');
        $this->assertTrue($inspection['checked']);
        $this->assertTrue($inspection['valid']);
        $this->assertSame('Test', $inspection['signers'][0]['cn']);
    }

    public function testContenutoAlteratoNonRisultaValido(): void
    {
        $envelope = $this->signed($this->invoiceXml('FIRMA-MARKER'));
        $position = strpos($envelope, 'FIRMA-MARKER');

        $this->assertNotFalse($position, 'il marcatore deve essere dentro la busta');

        // Stessa lunghezza, un byte diverso nel contenuto incapsulato: la
        // struttura CMS resta leggibile, il digest no.
        $envelope[$position] = 'X';

        $inspection = P7mSignature::inspect($envelope);

        $this->assertNotSame(true, $inspection['valid'], 'una busta alterata non deve risultare valida');
        $this->assertNotEmpty($inspection['problems']);

        // Con l'openssl attuale la busta è leggibile ma il digest non torna.
        // Se una versione diversa non riuscisse a leggerla, checked sarebbe
        // false e valid null: in nessun caso il documento è "valido".
        if ($inspection['checked']) {
            $this->assertFalse($inspection['valid']);
        }
    }

    public function testContenutoNonCmsNonVerificabile(): void
    {
        $inspection = P7mSignature::inspect($this->invoiceXml('SENZA-FIRMA'));

        $this->assertFalse($inspection['checked']);
        $this->assertNull($inspection['valid']);
        $this->assertSame([], $inspection['signers']);
        $this->assertNotEmpty($inspection['problems']);
    }

    public function testXmlInChiaroBase64NonECms(): void
    {
        $inspection = P7mSignature::inspect(base64_encode($this->invoiceXml('SENZA-FIRMA')));

        $this->assertFalse($inspection['checked']);
        $this->assertNull($inspection['valid']);
    }

    public function testAvvisoPerFirmaNonVerificabile(): void
    {
        $warning = P7mSignature::warning([
            'checked'  => false,
            'valid'    => null,
            'signers'  => [],
            'problems' => ['OpenSSL: no content type'],
        ]);

        $this->assertIsString($warning);
        $this->assertStringContainsString('non verificabile', $warning);
        $this->assertStringContainsString('senza verifica crittografica', $warning);
    }

    public function testAvvisoPerCertificatoScaduto(): void
    {
        $warning = P7mSignature::warning([
            'checked'  => true,
            'valid'    => true,
            'signers'  => [[
                'cn'         => 'Test',
                'issuer'     => 'Test',
                'valid_from' => '2020-01-01 00:00:00',
                'valid_to'   => '2021-01-01 00:00:00',
                'expired'    => true,
            ]],
            'problems' => [],
        ]);

        $this->assertIsString($warning);
        $this->assertStringContainsString('scaduto il 2021-01-01', $warning);
    }

    public function testNessunAvvisoPerFirmaValida(): void
    {
        $this->assertNull(P7mSignature::warning([
            'checked'  => true,
            'valid'    => true,
            'signers'  => [[
                'cn'         => 'Test',
                'issuer'     => 'Test',
                'valid_from' => '2026-01-01 00:00:00',
                'valid_to'   => '2030-01-01 00:00:00',
                'expired'    => false,
            ]],
            'problems' => [],
        ]));
    }

    /**
     * Integrazione con FatturaXml: la busta valida si estrae e l'esito resta
     * leggibile; quella alterata viene rifiutata con un motivo esplicito.
     */
    public function testFatturaXmlEsponeEsitoERifiutaBustaAlterata(): void
    {
        $content  = $this->invoiceXml('FIRMA-MARKER');
        $envelope = $this->signed($content);
        $path     = $this->dir . '/valida.xml.p7m';

        file_put_contents($path, $envelope);

        $reader    = new FatturaXml();
        $documents = $reader->extract($path, 'valida.xml.p7m');

        $this->assertCount(1, $documents);
        $this->assertSame($content, $documents[0]['xml']);
        $this->assertTrue($documents[0]['signature']['valid']);
        $this->assertTrue($reader->lastSignature()['valid']);

        $tampered = $envelope;
        $marker   = strpos($tampered, 'FIRMA-MARKER');
        $this->assertNotFalse($marker, 'il marcatore deve essere dentro la busta');
        $tampered[$marker] = 'X';
        $path = $this->dir . '/alterata.xml.p7m';
        file_put_contents($path, $tampered);

        $reader = new FatturaXml();

        $this->assertSame([], $reader->extract($path, 'alterata.xml.p7m'));
        $this->assertStringContainsString('firma digitale non valida', (string) $reader->lastError());
    }

    private function invoiceXml(string $marker): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<p:FatturaElettronica xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2" versione="FPR12">
  <FatturaElettronicaHeader>
    <CedentePrestatore>
      <DatiAnagrafici>
        <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>01234567890</IdCodice></IdFiscaleIVA>
        <Anagrafica><Denominazione>Fornitore Srl</Denominazione></Anagrafica>
      </DatiAnagrafici>
    </CedentePrestatore>
    <CessionarioCommittente>
      <DatiAnagrafici>
        <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>02369560038</IdCodice></IdFiscaleIVA>
        <Anagrafica><Denominazione>Cliente</Denominazione></Anagrafica>
      </DatiAnagrafici>
    </CessionarioCommittente>
  </FatturaElettronicaHeader>
  <FatturaElettronicaBody>
    <DatiGenerali>
      <DatiGeneraliDocumento>
        <TipoDocumento>TD01</TipoDocumento>
        <Data>2026-05-07</Data>
        <Numero>42</Numero>
      </DatiGeneraliDocumento>
    </DatiGenerali>
    <DatiBeniServizi>
      <DatiRiepilogo>
        <AliquotaIVA>22.00</AliquotaIVA>
        <ImponibileImporto>100.00</ImponibileImporto>
        <Imposta>22.00</Imposta>
      </DatiRiepilogo>
    </DatiBeniServizi>
  </FatturaElettronicaBody>
  <!-- {$marker} -->
</p:FatturaElettronica>
XML;
    }

    /** Firma il contenuto con un certificato self-signed e torna il DER CMS. */
    private function signed(string $content, bool $ber = false): string
    {
        $this->certificate();

        $payload = $this->dir . '/payload.xml';
        $output  = $this->dir . '/envelope.der';

        file_put_contents($payload, $content);
        @unlink($output);

        $command = sprintf(
            'cms -sign -binary -nodetach %s-outform DER -in %s -signer %s -inkey %s -out %s',
            $ber ? '-stream ' : '',
            escapeshellarg($payload),
            escapeshellarg($this->dir . '/cert.pem'),
            escapeshellarg($this->dir . '/key.pem'),
            escapeshellarg($output)
        );

        exec('openssl ' . $command . ' 2>&1', $errors, $status);

        if ($status !== 0 || !is_file($output)) {
            $this->markTestSkipped('openssl cms non disponibile: ' . implode(' ', $errors));
        }

        return (string) file_get_contents($output);
    }

    private function certificate(): void
    {
        if (is_file($this->dir . '/cert.pem') && is_file($this->dir . '/key.pem')) {
            return;
        }

        $command = sprintf(
            'req -x509 -newkey rsa:2048 -nodes -keyout %s -out %s -days 2 -subj %s',
            escapeshellarg($this->dir . '/key.pem'),
            escapeshellarg($this->dir . '/cert.pem'),
            escapeshellarg('/CN=Test/O=Prova')
        );

        exec('openssl ' . $command . ' 2>&1', $errors, $status);

        if ($status !== 0 || !is_file($this->dir . '/cert.pem') || !is_file($this->dir . '/key.pem')) {
            $this->markTestSkipped('generazione del certificato di prova fallita: ' . implode(' ', $errors));
        }
    }

    private function removeDirectory(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
