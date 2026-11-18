<?php

use App\Libraries\Sdi\SdiSoapMessage;
use App\Models\InvoiceModel;
use App\Models\SdiTransmissionModel;
use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Le notifiche dello SdI al webservice TrasmissioneFatture.
 *
 * Si verifica quello che cambia per l'utente: l'esito arriva sulla fattura, lo
 * scarto la sblocca perche' si possa correggere, la consegna la blocca, e una
 * mail che non puo' partire (qui non c'e' nessuna casella di invio) non
 * trasforma la risposta allo SdI in un errore: lo SdI non rimanda una
 * notifica a cui ha avuto risposta.
 *
 * Gira sulla copia del database dentro una transazione annullata alla fine; le
 * impostazioni stanno in memoria, non nella cache condivisa.
 *
 * @internal
 */
final class SdiCoopNotificationTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const DB_COPY = 'invoiceplane_posttest';
    private const TOKEN   = 'token-notifiche-di-prova';

    private ?BaseConnection $testDb = null;

    /** @var array<string, mixed> */
    private array $originalTests = [];

    private int $fattura = 0;
    private string $file = '';

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
            $this->testDb->query('SELECT 1 FROM ip_invoices LIMIT 1');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non disponibile: ' . $config->tests['database']);
        }

        // Il test fa partire gli avvisi: con una casella di invio configurata
        // nella copia spedirebbe email vere al titolare. Meglio saltarlo.
        if ((int) $this->testDb->query('SELECT COUNT(*) AS n FROM ip_email_senders')->getRow()->n > 0) {
            $this->restoreConnections();
            $this->markTestSkipped('La copia ha caselle di invio: il test spedirebbe email vere');
        }

        $this->testDb->transBegin();

        $_SESSION['active_company_id'] = 1;

        $riga = $this->testDb->query(
            'SELECT invoice_id FROM ip_invoices WHERE (company_id = 1 OR company_id IS NULL) AND invoice_status_id > 1 ORDER BY invoice_id LIMIT 1'
        )->getRow();

        if ($riga === null) {
            $this->testDb->transRollback();
            $this->restoreConnections();
            $this->markTestSkipped('Nessuna fattura emessa nella copia');
        }

        $this->fattura = (int) $riga->invoice_id;
        $this->file    = 'IT01234567890_' . bin2hex(random_bytes(3)) . '.xml';

        $this->testDb->query('DELETE FROM ip_sdi_transmissions WHERE invoice_id = ?', [$this->fattura]);
        $this->testDb->query(
            'INSERT INTO ip_sdi_transmissions (invoice_id, filename, channel, esito, sent_at) VALUES (?, ?, ?, ?, ?)',
            [$this->fattura, $this->file, 'sdicoop', 'pending', date('Y-m-d H:i:s')]
        );
        $id = (int) $this->testDb->insertID();

        // Fattura appena trasmessa: lo SdI ce l'ha, quindi e' bloccata.
        (new SdiTransmissionModel())->setEsito($id, 'pending');

        $tutte = [];
        foreach ($this->testDb->query('SELECT setting_key, setting_value FROM ip_settings')->getResultArray() as $r) {
            $tutte[$r['setting_key']] = $r['setting_value'];
        }

        helper('ip_helpers');
        $tutte['sdi_coop_inbound_token'] = encrypt_secret(self::TOKEN);
        // Nessuna casella di invio: la mail non puo' partire, ed e' il caso da provare.
        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, $tutte);
    }

    protected function tearDown(): void
    {
        if ($this->testDb !== null && $this->testDb->transDepth > 0) {
            $this->testDb->transRollback();
        }

        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);

        $this->restoreConnections();

        parent::tearDown();
    }

    private function notifica(string $elemento, string $tipoXml, string $extra = ''): \CodeIgniter\Test\TestResponse
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><types:' . $tipoXml . ' xmlns:types="http://www.fatturapa.gov.it/sdi/messaggi/v1.0" versione="1.0">'
            . '<IdentificativoSdI>123456</IdentificativoSdI><NomeFile>' . $this->file . '</NomeFile>' . $extra
            . '</types:' . $tipoXml . '>';

        $body = '<?xml version="1.0"?><S:Envelope xmlns:S="http://schemas.xmlsoap.org/soap/envelope/"><S:Body>'
            . '<t:' . $elemento . ' xmlns:t="' . SdiSoapMessage::NS_TRASMISSIONE . '">'
            . '<IdentificativoSdI>123456</IdentificativoSdI><NomeFile>' . $this->file . '</NomeFile><File>' . base64_encode($xml) . '</File>'
            . '</t:' . $elemento . '></S:Body></S:Envelope>';

        return $this->withBody($body)
            ->withHeaders(['Content-Type' => 'text/xml; charset=utf-8'])
            ->call('post', 'sdi-coop/' . self::TOKEN);
    }

    private function esito(): ?string
    {
        return $this->testDb->query('SELECT esito FROM ip_sdi_transmissions WHERE filename = ?', [$this->file])->getRow()->esito ?? null;
    }

    private function bloccata(): bool
    {
        return (int) $this->testDb->query('SELECT is_read_only FROM ip_invoices WHERE invoice_id = ?', [$this->fattura])->getRow()->is_read_only === 1;
    }

    public function testLaFatturaTrasmessaEBloccata(): void
    {
        $this->assertTrue($this->bloccata());
    }

    public function testLoScartoArrivaSullaFatturaELaSblocca(): void
    {
        $risposta = $this->notifica(
            'notificaScarto',
            'NotificaScarto',
            '<ListaErrori><Errore><Codice>00305</Codice><Descrizione>IdFiscaleIVA non valido</Descrizione></Errore></ListaErrori>'
        );

        $this->assertContains($risposta->response()->getStatusCode(), [200, 202], 'Senza casella di invio la risposta allo SdI non cambia');
        $this->assertSame('NS', $this->esito());
        $this->assertFalse($this->bloccata(), 'Scartata: va corretta e rinviata');
        $this->assertStringContainsString('00305', (string) $this->testDb->query('SELECT messaggio FROM ip_sdi_transmissions WHERE filename = ?', [$this->file])->getRow()->messaggio);
    }

    public function testLaRicevutaDiConsegnaLasciaLaFatturaBloccata(): void
    {
        $risposta = $this->notifica('ricevutaConsegna', 'RicevutaConsegna');

        $this->assertContains($risposta->response()->getStatusCode(), [200, 202]);
        $this->assertSame('RC', $this->esito());
        $this->assertTrue($this->bloccata());
    }

    public function testLaMancataConsegnaNonSblocca(): void
    {
        $this->notifica('notificaMancataConsegna', 'NotificaMancataConsegna');

        $this->assertSame('MC', $this->esito());
        $this->assertTrue($this->bloccata(), 'La fattura risulta emessa: si corregge solo con una nota di credito');
    }

    public function testUnoScartoRipetutoNonCambiaNiente(): void
    {
        $this->notifica('notificaScarto', 'NotificaScarto');
        (new InvoiceModel())->setLocked($this->fattura, false);

        $risposta = $this->notifica('notificaScarto', 'NotificaScarto');

        $this->assertContains($risposta->response()->getStatusCode(), [200, 202]);
        $this->assertSame('NS', $this->esito());
    }

    private function resetConnections(): void
    {
        $ref = new ReflectionClass(\CodeIgniter\Database\Config::class);

        // Le connessioni si chiudono prima di dimenticarle: lasciate aperte
        // restano vive fino alla fine della suite, e su MariaDB, con un test
        // in piu', esauriscono max_connections e gli ultimi test si saltano.
        foreach ($ref->getProperty('instances')->getValue() as $connection) {
            $connection->close();
        }

        $ref->getProperty('instances')->setValue(null, []);
        $ref->getProperty('factory')->setValue(null, null);
    }

    private function restoreConnections(): void
    {
        config('Database')->tests = $this->originalTests;

        $this->resetConnections();
    }
}
