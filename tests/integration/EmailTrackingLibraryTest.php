<?php

use App\Libraries\EmailTracking;
use App\Models\EmailTrackModel;
use App\Models\SettingModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * La libreria di tracciamento contro il database vero.
 *
 * I test delle pagine coprono la resa, quelli della rotta il pixel; qui si
 * verificano le regole che decidono cosa resta nella tabella: una riga nasce
 * solo se il tracciamento è attivo, diventa visibile solo dopo un invio
 * riuscito, sparisce se l'invio fallisce, e le aperture si contano solo sulle
 * email partite. Sono i casi in cui un errore fa comparire nella pagina del
 * documento un'email mai spedita, o perde l'apertura di quella vera.
 *
 * Come gli altri test di integrazione usa la copia del database e chiude ogni
 * test con un rollback: la copia resta identica.
 *
 * @internal
 */
final class EmailTrackingLibraryTest extends CIUnitTestCase
{
    private const DB_COPY = 'invoiceplane_posttest';

    private ?\CodeIgniter\Database\BaseConnection $testDb = null;

    /** @var array<string, mixed> */
    private array $originalTests = [];

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
            $this->testDb->query('SELECT 1 FROM ip_email_tracks LIMIT 1');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database (o tabella email_tracks) non disponibile: ' . $e->getMessage());
        }

        $this->resetSettingsCache();
        $this->testDb->transBegin();
    }

    protected function tearDown(): void
    {
        if ($this->testDb !== null && $this->testDb->transDepth > 0) {
            $this->testDb->transRollback();
        }

        $this->resetSettingsCache();
        $this->restoreConnections();

        parent::tearDown();
    }

    public function testStartRispettaLInterruttoreSpento(): void
    {
        $this->impostaTracciamento('0');

        $traccia = (new EmailTracking())->start('invoice', 999001, 'cliente@example.com', 'Fattura');

        $this->assertNull($traccia, 'Con il tracciamento spento non deve nascere una riga');

        $righe = $this->testDb->table('ip_email_tracks')
            ->where('track_document_id', 999001)
            ->countAllResults();

        $this->assertSame(0, $righe);
    }

    public function testLaRigaCompareSoloDopoUnInvioRiuscito(): void
    {
        $this->impostaTracciamento('1');

        $traccia = (new EmailTracking())->start('invoice', 999002, 'cliente@example.com', 'Fattura');
        $this->assertNotNull($traccia);

        $model = new EmailTrackModel();

        $this->assertSame([], $model->latestFor('invoice', 999002), 'Una riga non ancora inviata non va mostrata');

        (new EmailTracking())->markSent((int) $traccia['track_id']);

        $righe = $model->latestFor('invoice', 999002);
        $this->assertCount(1, $righe);
        $this->assertNotEmpty($righe[0]['track_sent_at']);
    }

    public function testUnInvioFallitoNonLasciaLaRiga(): void
    {
        $this->impostaTracciamento('1');

        $traccia = (new EmailTracking())->start('invoice', 999003, 'cliente@example.com', 'Fattura');
        $this->assertNotNull($traccia);

        (new EmailTracking())->discard((int) $traccia['track_id']);

        $this->assertNull((new EmailTrackModel())->findByToken((string) $traccia['track_token']));
    }

    public function testLeApertureSiContanoSoloSulleEmailPartite(): void
    {
        $this->impostaTracciamento('1');

        $traccia = (new EmailTracking())->start('invoice', 999004, 'cliente@example.com', 'Fattura');
        $this->assertNotNull($traccia);

        $tracker = new EmailTracking();

        $this->assertFalse(
            $tracker->recordOpen((string) $traccia['track_token'], '192.168.1.77'),
            'Un pixel caricato prima dell\'invio non deve contare'
        );

        $tracker->markSent((int) $traccia['track_id']);
        $this->assertTrue($tracker->recordOpen((string) $traccia['track_token'], '192.168.1.77'));

        $dopoPrima = (new EmailTrackModel())->findByToken((string) $traccia['track_token']);
        $this->assertSame(1, (int) $dopoPrima['track_open_count']);
        $this->assertNotEmpty($dopoPrima['track_opened_at']);
        $this->assertSame('192.168.1.0', $dopoPrima['track_last_ip'], 'L\'IP deve essere salvato in forma anonima');

        // La prima apertura resta quella mostrata: le successive aggiornano
        // solo il contatore, anche se arrivano a distanza di giorni.
        $this->testDb->table('ip_email_tracks')
            ->where('track_id', (int) $traccia['track_id'])
            ->update(['track_opened_at' => '2020-01-01 10:00:00']);

        $this->assertTrue($tracker->recordOpen((string) $traccia['track_token'], '10.0.0.1'));

        $dopoSeconda = (new EmailTrackModel())->findByToken((string) $traccia['track_token']);
        $this->assertSame(2, (int) $dopoSeconda['track_open_count']);
        $this->assertSame('2020-01-01 10:00:00', $dopoSeconda['track_opened_at']);
    }

    /**
     * La mappa che alimenta le liste: un solo invio per documento (l'ultimo
     * partito), nessun invio mai partito, nessuna riga di altri documenti.
     */
    public function testLaMappaDelleListeUsaLUltimoInvioPartito(): void
    {
        $this->impostaTracciamento('1');

        $tracker = new EmailTracking();

        $primo = $tracker->start('invoice', 999201, 'primo@example.com', 'Fattura');
        $tracker->markSent((int) $primo['track_id']);

        $secondo = $tracker->start('invoice', 999201, 'secondo@example.com', 'Sollecito');
        $tracker->markSent((int) $secondo['track_id']);

        // Mai partito: non deve coprire quello riuscito.
        $tracker->start('invoice', 999201, 'bozza@example.com', 'Bozza');

        // Altro documento e altro tipo: fuori dalla mappa richiesta.
        $altro = $tracker->start('invoice', 999202, 'altro@example.com', 'Fattura');
        $tracker->markSent((int) $altro['track_id']);

        $preventivo = $tracker->start('quote', 999201, 'preventivo@example.com', 'Preventivo');
        $tracker->markSent((int) $preventivo['track_id']);

        $model = new EmailTrackModel();

        $this->assertSame([], $model->latestMap('invoice', []), 'Senza documenti la mappa deve essere vuota');

        $mappa = $model->latestMap('invoice', [999201, 999202]);

        $this->assertCount(2, $mappa);
        $this->assertSame('secondo@example.com', $mappa[999201]['track_recipient'], 'La mappa non mostra l\'ultimo invio partito');
        $this->assertSame('altro@example.com', $mappa[999202]['track_recipient']);
    }

    public function testLaPotaturaToglieVecchieERigheSenzaDocumento(): void
    {
        $this->impostaTracciamento('1');

        $documento = $this->testDb->query(
            "SELECT invoice_id FROM ip_invoices ORDER BY invoice_id DESC LIMIT 1"
        )->getRow();

        $this->assertNotNull($documento, 'Nessuna fattura nella copia del database');

        $vecchia = date('Y-m-d H:i:s', strtotime('-400 days'));
        $adesso  = date('Y-m-d H:i:s');

        $righe = [
            // Scaduta: oltre l'anno di conservazione.
            ['invoice', 999101, $vecchia],
            // Recente ma con fattura esistente: deve restare.
            ['invoice', (int) $documento->invoice_id, $adesso],
            // Recente ma con documento eliminato: deve sparire.
            ['invoice', 999102, $adesso],
            ['quote', 999103, $adesso],
        ];

        foreach ($righe as [$tipo, $documentoId, $creata]) {
            $this->testDb->table('ip_email_tracks')->insert([
                'track_token'         => bin2hex(random_bytes(24)),
                'track_document_type' => $tipo,
                'track_document_id'   => $documentoId,
                'track_recipient'     => 'cliente@example.com',
                'track_sent_at'       => $creata,
                'track_open_count'    => 0,
                'track_created_at'    => $creata,
            ]);
        }

        $esito = (new EmailTrackModel())->prune(365);

        $this->assertSame(1, $esito['expired'], 'La riga più vecchia di un anno non è stata rimossa');
        $this->assertSame(2, $esito['orphans'], 'Le righe di documenti eliminati non sono state rimosse');

        $this->assertNull(
            $this->testDb->table('ip_email_tracks')->where('track_document_id', 999101)->get()->getRow(),
            'La riga scaduta è ancora presente'
        );
        $this->assertNotNull(
            $this->testDb->table('ip_email_tracks')->where('track_document_id', (int) $documento->invoice_id)->get()->getRow(),
            'La riga di una fattura esistente è stata rimossa'
        );
    }

    private function impostaTracciamento(string $valore): void
    {
        (new SettingModel())->setSetting('email_tracking_enabled', $valore);
        $this->resetSettingsCache();
    }

    private function resetSettingsCache(): void
    {
        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);
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
