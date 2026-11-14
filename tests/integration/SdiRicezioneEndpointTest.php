<?php

use App\Libraries\FatturaXmlStore;
use App\Libraries\Sdi\SdiSoapMessage;
use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Il webservice RicezioneFatture, chiamato come farebbe lo SdI.
 *
 * Lo SdI considera consegnata la fattura appena l'endpoint risponde: se in quel
 * momento il file non e' su disco o la spesa finisce nell'azienda sbagliata,
 * l'errore si scopre solo con il cassetto fiscale. Qui si fissa l'ordine delle
 * operazioni (prima il file, poi l'importazione), l'azienda scelta dal
 * committente, la categoria predefinita, la risposta ER01 anche quando
 * l'importazione non riesce, il rifiuto del token sbagliato e il nome file
 * ripulito dai percorsi.
 *
 * Gira sulla copia del database dentro una transazione annullata alla fine.
 * Le impostazioni non passano dalla cache condivisa: si sostituisce la copia
 * statica in memoria, cosi' il token di prova non finisce in Redis dove lo
 * leggerebbe anche l'applicazione vera. Se la copia non c'e', il test si salta.
 *
 * @internal
 */
final class SdiRicezioneEndpointTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const DB_COPY = 'invoiceplane_posttest';
    private const TOKEN   = 'token-di-prova-ricezione';

    private ?BaseConnection $testDb = null;

    /** @var array<string, mixed> */
    private array $originalTests = [];

    /** @var list<string> */
    private array $files = [];

    private string $partitaIvaAzienda = '';

    protected function setUp(): void
    {
        parent::setUp();

        $config = config('Database');

        $this->originalTests       = $config->tests;
        $config->tests             = $config->default;
        $config->tests['database'] = getenv('IP_PDF_TEST_DB') ?: self::DB_COPY;

        $this->resetConnections();

        try {
            $this->testDb = \Config\Database::connect();
            $this->testDb->query('SELECT 1 FROM ip_settings LIMIT 1');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non disponibile: ' . $config->tests['database']);
        }

        // Tutto cio' che l'endpoint scrive nel database si annulla alla fine.
        $this->testDb->transBegin();

        $_SESSION['active_company_id'] = 1;

        $azienda = $this->testDb->query('SELECT company_vat_id FROM ip_companies WHERE company_id = 1')->getRow();
        $this->partitaIvaAzienda = preg_replace('/\D/', '', (string) ($azienda->company_vat_id ?? ''));

        if ($this->partitaIvaAzienda === '') {
            $this->testDb->transRollback();
            $this->restoreConnections();

            $this->markTestSkipped('L\'azienda 1 della copia non ha una partita IVA');
        }

        $this->impostazioni([]);
    }

    protected function tearDown(): void
    {
        if ($this->testDb !== null) {
            $this->testDb->transRollback();
        }

        foreach ($this->files as $file) {
            @unlink($file);
        }

        // Le impostazioni tornano a leggersi dal database.
        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);

        $this->restoreConnections();

        parent::tearDown();
    }

    /**
     * Impostazioni in memoria: quelle vere della copia piu' le sostituzioni.
     *
     * @param array<string, string> $extra
     */
    private function impostazioni(array $extra): void
    {
        $tutte = [];
        foreach ($this->testDb->query('SELECT setting_key, setting_value FROM ip_settings')->getResultArray() as $riga) {
            $tutte[$riga['setting_key']] = $riga['setting_value'];
        }

        helper('ip_helpers');
        $tutte['sdi_ricezione_inbound_token'] = encrypt_secret(self::TOKEN);

        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, $extra + $tutte);
    }

    private function fattura(string $partitaIvaCommittente, string $numero, string $fornitore = '09876543210'): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<p:FatturaElettronica xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2" versione="FPR12">
  <FatturaElettronicaHeader>
    <CedentePrestatore>
      <DatiAnagrafici>
        <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>{$fornitore}</IdCodice></IdFiscaleIVA>
        <Anagrafica><Denominazione>Fornitore di prova Srl</Denominazione></Anagrafica>
      </DatiAnagrafici>
    </CedentePrestatore>
    <CessionarioCommittente>
      <DatiAnagrafici>
        <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>{$partitaIvaCommittente}</IdCodice></IdFiscaleIVA>
        <Anagrafica><Denominazione>Committente</Denominazione></Anagrafica>
      </DatiAnagrafici>
    </CessionarioCommittente>
  </FatturaElettronicaHeader>
  <FatturaElettronicaBody>
    <DatiGenerali>
      <DatiGeneraliDocumento>
        <TipoDocumento>TD01</TipoDocumento>
        <Data>2026-05-07</Data>
        <Numero>{$numero}</Numero>
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
</p:FatturaElettronica>
XML;
    }

    /** Busta SOAP con il file in base64, come la accetta SdiSoapMessage. */
    private function busta(string $elemento, array $campi): string
    {
        $interno = '';
        foreach ($campi as $nome => $valore) {
            $interno .= '<' . $nome . '>' . $valore . '</' . $nome . '>';
        }

        return '<?xml version="1.0"?><S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"><S:Body>'
            . '<t:' . $elemento . ' xmlns:t="' . SdiSoapMessage::NS_RICEZIONE . '">' . $interno . '</t:' . $elemento . '>'
            . '</S:Body></S:Envelope>';
    }

    private function consegna(string $nomeFile, string $xml, string $token = self::TOKEN): \CodeIgniter\Test\TestResponse
    {
        $this->files[] = (new FatturaXmlStore())->dir() . 'incoming/' . basename($nomeFile);

        $body = $this->busta('fileSdIConMetadati', [
            'IdentificativoSdI' => '123456',
            'NomeFile'          => $nomeFile,
            'File'              => base64_encode($xml),
        ]);

        return $this->withBody($body)
            ->withHeaders(['Content-Type' => 'text/xml; charset=utf-8'])
            ->call('post', 'sdi-ricezione/' . $token);
    }

    private function speseConFile(string $nomeFile): array
    {
        return $this->testDb->query('SELECT * FROM ip_expenses WHERE expense_file = ?', [$nomeFile])->getResultArray();
    }

    private function ricordaXmlDellaSpesa(array $spese): void
    {
        foreach ($spese as $spesa) {
            $this->files[] = (new FatturaXmlStore())->pathFor((int) $spesa['expense_id']);
        }
    }

    public function testUnTokenSbagliatoVieneRifiutato(): void
    {
        $nome = 'IT09876543210_' . bin2hex(random_bytes(3)) . '.xml';

        $risposta = $this->consegna($nome, $this->fattura($this->partitaIvaAzienda, 'T-1'), 'token-sbagliato');

        $this->assertSame(403, $risposta->response()->getStatusCode());
        $this->assertFileDoesNotExist((new FatturaXmlStore())->dir() . 'incoming/' . $nome, 'Con un token errato non si scrive niente su disco');
    }

    public function testSenzaTokenConfiguratoNonSiAccettaNiente(): void
    {
        $this->impostazioni(['sdi_ricezione_inbound_token' => '']);

        $risposta = $this->consegna('IT09876543210_x.xml', $this->fattura($this->partitaIvaAzienda, 'T-2'), 'qualsiasi');

        $this->assertSame(403, $risposta->response()->getStatusCode(), 'Un token non impostato non deve aprire l\'endpoint a chiunque');
    }

    public function testUnaRichiestaIllegibileRestituisceUnFault(): void
    {
        $risposta = $this->withBody('questo non e\' SOAP')
            ->withHeaders(['Content-Type' => 'text/xml'])
            ->call('post', 'sdi-ricezione/' . self::TOKEN);

        $this->assertSame(500, $risposta->response()->getStatusCode(), 'Con un 500 lo SdI riprova: una busta illeggibile non va mai data per consegnata');
        $this->assertStringContainsString('Fault', (string) $risposta->response()->getBody());
    }

    public function testLaFatturaEntraNelleSpeseDellAziendaDelCommittente(): void
    {
        $nome   = 'IT09876543210_' . bin2hex(random_bytes(3)) . '.xml';
        $numero = 'RIC-' . bin2hex(random_bytes(3));

        $risposta = $this->consegna($nome, $this->fattura($this->partitaIvaAzienda, $numero));

        $this->assertSame(200, $risposta->response()->getStatusCode());
        $this->assertStringContainsString('ER01', (string) $risposta->response()->getBody(), 'Lo SdI aspetta ER01 per una fattura consegnata');
        $this->assertFileExists((new FatturaXmlStore())->dir() . 'incoming/' . $nome, 'Prima il file su disco');

        $spese = $this->speseConFile($nome);
        $this->ricordaXmlDellaSpesa($spese);

        $this->assertCount(1, $spese, 'La fattura deve diventare una spesa');
        $this->assertSame(1, (int) $spese[0]['company_id'], 'Nell\'azienda a cui e\' intestata');
        $this->assertEqualsWithDelta(122.00, (float) $spese[0]['expense_total'], 0.001);
    }

    public function testUnaSecondaConsegnaDellaStessaFatturaNonDuplicaLaSpesa(): void
    {
        $nome   = 'IT09876543210_' . bin2hex(random_bytes(3)) . '.xml';
        $xml    = $this->fattura($this->partitaIvaAzienda, 'RIC-' . bin2hex(random_bytes(3)));

        $this->consegna($nome, $xml);
        $risposta = $this->consegna($nome, $xml);

        $spese = $this->speseConFile($nome);
        $this->ricordaXmlDellaSpesa($spese);

        $this->assertSame(200, $risposta->response()->getStatusCode(), 'Lo SdI puo\' rimandare la stessa fattura: la risposta resta ER01');
        $this->assertCount(1, $spese, 'Due consegne, una sola spesa');
    }

    public function testLaCategoriaPredefinitaSiApplica(): void
    {
        $categoria = $this->testDb->query('SELECT expense_category_id FROM ip_expense_categories ORDER BY expense_category_id LIMIT 1')->getRow();

        if ($categoria === null) {
            $this->markTestSkipped('La copia non ha categorie spesa');
        }

        $this->impostazioni(['sdi_ricezione_default_expense_category_id' => (string) $categoria->expense_category_id]);

        $nome = 'IT09876543210_' . bin2hex(random_bytes(3)) . '.xml';
        $this->consegna($nome, $this->fattura($this->partitaIvaAzienda, 'RIC-' . bin2hex(random_bytes(3))));

        $spese = $this->speseConFile($nome);
        $this->ricordaXmlDellaSpesa($spese);

        $this->assertCount(1, $spese);
        $this->assertSame((int) $categoria->expense_category_id, (int) $spese[0]['expense_category_id']);
    }

    public function testUnaCategoriaCancellataNonBloccaLImportazione(): void
    {
        $this->impostazioni(['sdi_ricezione_default_expense_category_id' => '999999']);

        $nome = 'IT09876543210_' . bin2hex(random_bytes(3)) . '.xml';
        $risposta = $this->consegna($nome, $this->fattura($this->partitaIvaAzienda, 'RIC-' . bin2hex(random_bytes(3))));

        $spese = $this->speseConFile($nome);
        $this->ricordaXmlDellaSpesa($spese);

        $this->assertSame(200, $risposta->response()->getStatusCode());
        $this->assertCount(1, $spese, 'La fattura entra comunque');
        $this->assertNull($spese[0]['expense_category_id']);
    }

    public function testUnCommittenteSconosciutoLasciaIlFileDaImportareAMano(): void
    {
        $nome = 'IT09876543210_' . bin2hex(random_bytes(3)) . '.xml';

        $risposta = $this->consegna($nome, $this->fattura('00000000019', 'RIC-' . bin2hex(random_bytes(3))));

        $this->assertSame(200, $risposta->response()->getStatusCode(), 'Per lo SdI la fattura e\' consegnata comunque');
        $this->assertFileExists((new FatturaXmlStore())->dir() . 'incoming/' . $nome, 'Il file resta in incoming/ per l\'import manuale');
        $this->assertSame([], $this->speseConFile($nome), 'Nessuna spesa nell\'azienda sbagliata');
    }

    public function testIlNomeFileNonPuoUscireDaIncoming(): void
    {
        $unico = bin2hex(random_bytes(3));
        $base  = (new FatturaXmlStore())->dir();

        $this->files[] = $base . 'incoming/evil-' . $unico . '.xml';
        $this->files[] = dirname($base) . '/evil-' . $unico . '.xml';

        $this->consegna('../../evil-' . $unico . '.xml', $this->fattura('00000000019', 'X-1'));

        $this->assertFileExists($base . 'incoming/evil-' . $unico . '.xml', 'Dei percorsi resta solo il nome del file');
        $this->assertFileDoesNotExist(dirname($base) . '/evil-' . $unico . '.xml');
        $this->assertFileDoesNotExist(dirname($base, 2) . '/evil-' . $unico . '.xml');
    }

    public function testLaDecorrenzaTerminiSiConservaERispondeSenzaCorpo(): void
    {
        $nome = 'IT09876543210_' . bin2hex(random_bytes(3)) . '_DT.xml';
        $this->files[] = (new FatturaXmlStore())->dir() . 'incoming/' . $nome;

        $body = $this->busta('fileSdI', [
            'IdentificativoSdI' => '777',
            'NomeFile'          => $nome,
            'File'              => base64_encode($this->fattura($this->partitaIvaAzienda, 'DT-1')),
        ]);

        $risposta = $this->withBody($body)
            ->withHeaders(['Content-Type' => 'text/xml; charset=utf-8'])
            ->call('post', 'sdi-ricezione/' . self::TOKEN);

        $this->assertSame(202, $risposta->response()->getStatusCode(), 'Operazione one-way: 202 senza corpo');
        $this->assertFileExists((new FatturaXmlStore())->dir() . 'incoming/' . $nome);
        $this->assertSame([], $this->speseConFile($nome), 'La decorrenza termini non crea spese');
    }

    private function resetConnections(): void
    {
        $ref = new ReflectionClass(\CodeIgniter\Database\Config::class);

        $ref->getProperty('instances')->setValue(null, []);
        $ref->getProperty('factory')->setValue(null, null);
    }

    private function restoreConnections(): void
    {
        config('Database')->tests = $this->originalTests;

        $this->resetConnections();
    }
}
