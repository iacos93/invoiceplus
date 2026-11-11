<?php

use App\Models\SettingModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;

/**
 * Le scritture sulle fatture: le varianti che il flusso felice non tocca.
 *
 * SimulazioneFunzionamentoTest crea una fattura, la incassa e converte un
 * preventivo: è il percorso principale, uno solo. Qui si coprono i casi che
 * cambiano i numeri e che, se sbagliati, si vedono solo sui dati veri — più
 * righe con aliquote diverse, sconti di riga e di documento, modifica e
 * rimozione delle righe, duplicazione, eliminazione, incasso parziale.
 *
 * Ogni test gira in una transazione chiusa con rollback: la copia del
 * database resta identica a com'era e i test si possono ripetere.
 *
 * @internal
 */
final class InvoiceWritesTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const DB_COPY = 'invoiceplane_posttest';

    private ?BaseConnection $testDb = null;

    /** @var array<string, mixed> */
    private array $originalTests = [];

    private int $utente = 1;

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

        // Impostazioni in cache statica (numerazione, valuta, eliminazione):
        // senza il reset il test leggerebbe quelle del processo precedente.
        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);

        $_SESSION['active_company_id'] = 1;

        $row          = $this->testDb->query('SELECT user_id FROM ip_users ORDER BY user_id LIMIT 1')->getRow();
        $this->utente = (int) ($row->user_id ?? 1);

        $this->testDb->transBegin();
    }

    protected function tearDown(): void
    {
        if ($this->testDb !== null && $this->testDb->transDepth > 0) {
            $this->testDb->transRollback();
        }

        $this->restoreConnections();

        parent::tearDown();
    }

    /**
     * Righe con aliquote diverse e uno sconto di riga: il totale della
     * fattura deve sommare gli imponibili già scontati e le imposte riga per
     * riga, non applicare un'unica aliquota al totale.
     */
    public function testPiuRigheConAliquoteEScontiDannoITotaliGiusti(): void
    {
        $id   = $this->creaFattura($this->cliente());
        $iva22 = $this->aliquota(22);

        $this->salvaRighe($id, [
            ['item_name' => 'Consulenza', 'item_quantity' => '2', 'item_price' => '100', 'item_tax_rate_id' => $iva22],
            ['item_name' => 'Materiale', 'item_quantity' => '1', 'item_price' => '50', 'item_tax_rate_id' => $iva22, 'item_discount_percent' => '10'],
            ['item_name' => 'Spese', 'item_quantity' => '4', 'item_price' => '25', 'item_tax_rate_id' => 0],
        ]);

        $righe = $this->testDb->query(
            'SELECT i.item_name, a.item_subtotal, a.item_discount, a.item_tax_total
             FROM ip_invoice_items i
             JOIN ip_invoice_item_amounts a ON a.item_id = i.item_id
             WHERE i.invoice_id = ?
             ORDER BY i.item_id',
            [$id]
        )->getResultArray();

        $this->assertCount(3, $righe, 'Righe: attese tre righe salvate');

        $this->assertSame(200.0, (float) $righe[0]['item_subtotal']);
        $this->assertSame(0.0, (float) $righe[0]['item_discount']);
        $this->assertSame(44.0, (float) $righe[0]['item_tax_total'], 'Riga 22%: IVA errata');

        $this->assertSame(50.0, (float) $righe[1]['item_subtotal']);
        $this->assertSame(5.0, (float) $righe[1]['item_discount'], 'Sconto riga: atteso il 10% di 50');
        $this->assertSame(9.9, (float) $righe[1]['item_tax_total'], 'Riga scontata: IVA sull\'imponibile scontato');

        $this->assertSame(100.0, (float) $righe[2]['item_subtotal']);
        $this->assertSame(0.0, (float) $righe[2]['item_tax_total'], 'Riga senza aliquota: IVA non dovuta');

        $totali = $this->totali($id);

        $this->assertSame(345.0, (float) $totali['invoice_item_subtotal'], 'Imponibile: atteso 200 + 45 + 100');
        $this->assertSame(53.9, (float) $totali['invoice_item_tax_total'], 'IVA: attesa 44 + 9,90');
        // Lo sconto di riga vive nella riga, non in invoice_discount_total:
        // questa colonna somma solo lo sconto di documento.
        $this->assertSame(0.0, (float) $totali['invoice_discount_total'], 'Sconto documento: qui non deve finire quello di riga');
        $this->assertSame(398.9, (float) $totali['invoice_total'], 'Totale: atteso 345 + 53,90');
        $this->assertSame(398.9, (float) $totali['invoice_balance'], 'Saldo: senza incassi coincide col totale');
    }

    /**
     * Modifica di una riga, aggiunta di una seconda e rimozione della
     * seconda: a ogni passaggio il totale deve seguire, senza righe orfane.
     */
    public function testModificaERimozioneRigheRicalcolaTotaleESaldo(): void
    {
        $id = $this->creaFattura($this->cliente());

        $this->salvaRighe($id, [
            ['item_name' => 'A', 'item_quantity' => '2', 'item_price' => '100', 'item_tax_rate_id' => 0],
        ]);

        $this->assertTotale($id, 200.0);
        $rigaA = $this->id('SELECT item_id FROM ip_invoice_items WHERE invoice_id = ' . $id . ' ORDER BY item_id LIMIT 1');

        $this->salvaRighe($id, [
            ['item_id' => $rigaA, 'item_name' => 'A', 'item_quantity' => '3', 'item_price' => '100', 'item_tax_rate_id' => 0],
            ['item_name' => 'B', 'item_quantity' => '1', 'item_price' => '50', 'item_tax_rate_id' => 0],
        ]);

        $this->assertTotale($id, 350.0);
        $this->assertSame(2, $this->conta('ip_invoice_items', 'invoice_id = ' . $id));

        $rigaB = $this->id(
            'SELECT item_id FROM ip_invoice_items WHERE invoice_id = ' . $id . ' AND item_name = \'B\' LIMIT 1'
        );
        $this->assertNotNull($rigaB, 'Riga B: non salvata');

        $this->salvaRighe($id, [
            ['item_id' => $rigaA, 'item_name' => 'A', 'item_quantity' => '3', 'item_price' => '100', 'item_tax_rate_id' => 0],
        ], (string) $rigaB);

        $this->assertTotale($id, 300.0);
        $this->assertSame(1, $this->conta('ip_invoice_items', 'invoice_id = ' . $id), 'Riga rimossa: ne resta una sola');
    }

    /**
     * Sconto di documento: si applica all'imponibile e il saldo scende. Uno
     * sconto oltre l'imponibile viene rifiutato senza perdere il resto della
     * scheda (il totale non deve andare sotto zero).
     */
    public function testScontoDiDocumentoSiApplicaESiRifiutaSeEccessivo(): void
    {
        $id = $this->creaFattura($this->cliente());

        $this->salvaRighe($id, [
            ['item_name' => 'A', 'item_quantity' => '1', 'item_price' => '200', 'item_tax_rate_id' => 0],
        ]);

        $this->salvaTestata($id, ['invoice_discount_percent' => '10']);

        $totali = $this->totali($id);
        $this->assertSame(20.0, (float) $totali['invoice_discount_total'], 'Sconto 10%: atteso 20 su 200');
        $this->assertSame(180.0, (float) $totali['invoice_total'], 'Totale scontato: atteso 180');
        $this->assertSame(180.0, (float) $totali['invoice_balance']);

        // 150% di 200 = 300: porterebbe il totale sotto zero, va scartato.
        $this->salvaTestata($id, ['invoice_discount_percent' => '150']);

        $totali = $this->totali($id);
        $this->assertSame(180.0, (float) $totali['invoice_total'], 'Sconto eccessivo: il totale non deve cambiare');
        $this->assertSame(10.0, (float) $this->campo('invoice_discount_percent', $id), 'Sconto eccessivo: resta quello valido');
    }

    /**
     * Duplicazione: nasce una bozza con le stesse righe e lo stesso totale,
     * mentre la fattura di partenza non viene toccata.
     */
    public function testDuplicazioneCopiaLeRigheInUnaBoza(): void
    {
        $id = $this->creaFattura($this->cliente());

        $this->salvaRighe($id, [
            ['item_name' => 'A', 'item_quantity' => '1', 'item_price' => '100', 'item_tax_rate_id' => 0],
            ['item_name' => 'B', 'item_quantity' => '1', 'item_price' => '200', 'item_tax_rate_id' => 0],
        ]);
        $this->emetti($id);

        $risposta = $this->inviaConToken('/invoices/ajax/copy_invoice', [
            'invoice_id' => $id,
        ], '/invoices/view/' . $id);

        $posizione = (string) $risposta->response()->getHeaderLine('Location');
        $this->assertStringContainsString('/invoices/view/', $posizione, 'Duplicazione: nessun redirect alla copia');

        $nuova = (int) basename(parse_url($posizione, PHP_URL_PATH));
        $this->assertNotSame($id, $nuova, 'Duplicazione: è stata restituita la fattura di partenza');

        $copia = $this->testDb->query(
            'SELECT invoice_status_id, client_id FROM ip_invoices WHERE invoice_id = ?',
            [$nuova]
        )->getRowArray();

        $this->assertSame(1, (int) $copia['invoice_status_id'], 'Copia: deve nascere come bozza');
        $this->assertSame((int) $this->campo('client_id', $id), (int) $copia['client_id'], 'Copia: cliente diverso');
        $this->assertSame(2, $this->conta('ip_invoice_items', 'invoice_id = ' . $nuova), 'Copia: righe non copiate');
        $this->assertTotale($nuova, 300.0);

        $this->assertTotale($id, 300.0);
        $this->assertSame(2, (int) $this->campo('invoice_status_id', $id), 'Originale: lo stato non deve cambiare');
    }

    /**
     * Una bozza si può eliminare, e con lei devono sparire righe e importi:
     * lasciarli attaccati a un id riciclato falserebbe i totali del
     * documento successivo.
     */
    public function testEliminazioneDiUnaBozaRimuoveRigheEImporti(): void
    {
        $id = $this->creaFattura($this->cliente());

        $this->salvaRighe($id, [
            ['item_name' => 'A', 'item_quantity' => '1', 'item_price' => '100', 'item_tax_rate_id' => 0],
        ]);

        $risposta = $this->inviaConToken('/invoices/delete/' . $id, [], '/invoices/view/' . $id);

        $this->assertSame(302, $risposta->response()->getStatusCode());
        $this->assertStringContainsString('/invoices', (string) $risposta->response()->getHeaderLine('Location'));

        $this->assertSame(0, $this->conta('ip_invoices', 'invoice_id = ' . $id), 'Fattura non eliminata');
        $this->assertSame(0, $this->conta('ip_invoice_items', 'invoice_id = ' . $id), 'Righe rimaste orfane');
        $this->assertSame(0, $this->conta('ip_invoice_amounts', 'invoice_id = ' . $id), 'Importi rimasti orfani');
    }

    /**
     * Una fattura già emessa non si elimina finché il setting non lo
     * consente: è una protezione sui documenti fiscali, non una preferenza.
     */
    public function testFatturaEmessaNonSiEliminaSenzaIlSetting(): void
    {
        $id = $this->creaFattura($this->cliente());

        $this->salvaRighe($id, [
            ['item_name' => 'A', 'item_quantity' => '1', 'item_price' => '100', 'item_tax_rate_id' => 0],
        ]);
        $this->emetti($id);

        $this->inviaConToken('/invoices/delete/' . $id, [], '/invoices/view/' . $id);

        $this->assertSame(1, $this->conta('ip_invoices', 'invoice_id = ' . $id), 'Fattura emessa eliminata senza il setting');
        $this->assertSame(2, (int) $this->campo('invoice_status_id', $id));
    }

    /**
     * Incasso parziale: il saldo scende ma la fattura resta aperta; solo
     * l'ultimo pagamento la porta a "pagata". Prima di questo test il
     * percorso coperto era soltanto quello dell'incasso a saldo.
     */
    public function testIncassoParzialeLasciaApertaEPoiSalda(): void
    {
        $id = $this->creaFattura($this->cliente());

        $this->salvaRighe($id, [
            ['item_name' => 'A', 'item_quantity' => '1', 'item_price' => '200', 'item_tax_rate_id' => 0],
        ]);
        $this->emetti($id);

        $this->paga($id, '50,00');

        $totali = $this->totali($id);
        $this->assertSame(50.0, (float) $totali['invoice_paid'], 'Acconto: atteso 50 pagati');
        $this->assertSame(150.0, (float) $totali['invoice_balance'], 'Acconto: atteso un saldo di 150');
        $this->assertSame(2, (int) $this->campo('invoice_status_id', $id), 'Acconto: la fattura deve restare aperta');

        $this->paga($id, '150,00');

        $totali = $this->totali($id);
        $this->assertSame(200.0, (float) $totali['invoice_paid']);
        $this->assertSame(0.0, (float) $totali['invoice_balance'], 'Saldo: atteso zero dopo il secondo incasso');
        $this->assertSame(4, (int) $this->campo('invoice_status_id', $id), 'Saldo: la fattura deve risultare pagata');
    }

    /**
     * Creazione senza cliente: si torna al form con un messaggio, e non deve
     * restare una fattura senza intestatario (l'insert fallirebbe comunque,
     * ma il test fissa il comportamento visibile).
     */
    public function testCreazioneSenzaClienteNonScriveNiente(): void
    {
        $prima = $this->conta('ip_invoices');

        $risposta = $this->inviaConToken('/invoices/ajax/create', [
            'invoice_date_created' => date('Y-m-d'),
            'invoice_group_id'     => $this->gruppo(),
        ], '/invoices/form_create');

        $this->assertSame(302, $risposta->response()->getStatusCode());
        $this->assertStringContainsString(
            '/invoices/form_create',
            (string) $risposta->response()->getHeaderLine('Location'),
            'Senza cliente si deve tornare al form'
        );
        $this->assertSame($prima, $this->conta('ip_invoices'), 'Nessuna fattura deve essere scritta');
        $this->assertNotEmpty($_SESSION['alert_error'] ?? null, 'Il form deve spiegare perché non è stato salvato');
    }

    // -----------------------------------------------------------------
    // Aiuti
    // -----------------------------------------------------------------

    private function creaFattura(int $cliente): int
    {
        $risposta = $this->inviaConToken('/invoices/ajax/create', [
            'client_id'            => $cliente,
            'invoice_date_created' => date('Y-m-d'),
            'invoice_group_id'     => $this->gruppo(),
        ], '/invoices/form_create');

        $posizione = (string) $risposta->response()->getHeaderLine('Location');
        $this->assertStringContainsString(
            '/invoices/view/',
            $posizione,
            'Creazione fattura non riuscita. Corpo: ' . mb_substr((string) $risposta->response()->getBody(), 0, 300)
        );

        return (int) basename(parse_url($posizione, PHP_URL_PATH));
    }

    /**
     * @param list<array<string, mixed>> $righe
     */
    private function salvaRighe(int $fattura, array $righe, string $eliminate = ''): void
    {
        $risposta = $this->inviaConToken('/invoices/ajax/save', [
            'invoice_id'    => $fattura,
            'items'         => json_encode($righe),
            'deleted_items' => $eliminate,
        ], '/invoices/view/' . $fattura);

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Righe: atteso un redirect');
    }

    /**
     * @param array<string, mixed> $campi
     */
    private function salvaTestata(int $fattura, array $campi): void
    {
        $risposta = $this->inviaConToken(
            '/invoices/ajax/save',
            ['invoice_id' => $fattura] + $campi,
            '/invoices/view/' . $fattura
        );

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Testata: atteso un redirect');
    }

    /** Porta la fattura fuori dalla bozza: il numero definitivo nasce qui. */
    private function emetti(int $fattura): void
    {
        $this->salvaTestata($fattura, ['invoice_status_id' => 2]);

        $this->assertNotSame(
            '',
            trim((string) $this->campo('invoice_number', $fattura)),
            'Emissione: numero definitivo non assegnato'
        );
    }

    private function paga(int $fattura, string $importo): void
    {
        $metodo = $this->id('SELECT payment_method_id FROM ip_payment_methods ORDER BY payment_method_id LIMIT 1');

        $risposta = $this->inviaConToken('/payments/form', [
            'invoice_id'        => $fattura,
            'payment_date'      => date('Y-m-d'),
            'payment_amount'    => $importo,
            'payment_method_id' => (int) $metodo,
            'payment_note'      => 'Incasso di prova',
        ], '/payments/form?invoice_id=' . $fattura);

        $this->assertSame(
            302,
            $risposta->response()->getStatusCode(),
            'Pagamento non salvato. Flash: ' . json_encode($_SESSION['alert_error'] ?? null)
        );
    }

    /** @return array<string, mixed> */
    private function totali(int $fattura): array
    {
        $row = $this->testDb->query(
            'SELECT * FROM ip_invoice_amounts WHERE invoice_id = ?',
            [$fattura]
        )->getRowArray();

        $this->assertNotNull($row, "Importi mancanti per la fattura {$fattura}");

        return $row;
    }

    private function assertTotale(int $fattura, float $atteso): void
    {
        $totali = $this->totali($fattura);

        $this->assertSame($atteso, (float) $totali['invoice_total'], "Totale fattura {$fattura}");
        $this->assertSame($atteso, (float) $totali['invoice_balance'], "Saldo fattura {$fattura}");
    }

    private function campo(string $colonna, int $fattura): mixed
    {
        $row = $this->testDb->query(
            "SELECT {$colonna} FROM ip_invoices WHERE invoice_id = ?",
            [$fattura]
        )->getRowArray();

        return $row[$colonna] ?? null;
    }

    private function conta(string $tabella, string $dove = ''): int
    {
        $sql = 'SELECT COUNT(*) AS n FROM ' . $tabella . ($dove !== '' ? ' WHERE ' . $dove : '');

        return (int) $this->testDb->query($sql)->getRow()->n;
    }

    /**
     * Cliente senza aliquota predefinita: InvoiceModel::create() ne copia la
     * preferenza su ip_invoice_tax_rates, e un'imposta di documento si
     * sommerebbe a quella delle righe falsando i totali attesi. La colonna è
     * NULL o 0 sulla maggior parte dei clienti, compresi quelli del seed.
     */
    private function cliente(): int
    {
        $id = $this->id(
            'SELECT client_id FROM ip_clients
             WHERE company_id = 1 AND COALESCE(client_default_tax_rate_id, 0) = 0
             ORDER BY client_id LIMIT 1'
        );

        $this->assertNotNull($id, 'Serve un cliente senza aliquota predefinita nell\'azienda 1');

        return (int) $id;
    }

    /** Id dell'aliquota con quella percentuale: cambia da database a database. */
    private function aliquota(float $percentuale): int
    {
        $id = $this->id(
            'SELECT tax_rate_id FROM ip_tax_rates WHERE tax_rate_percent = ' . $percentuale
            . ' ORDER BY tax_rate_id LIMIT 1'
        );

        $this->assertNotNull($id, "Serve un'aliquota al {$percentuale}% nella copia");

        return (int) $id;
    }

    private function gruppo(): int
    {
        $id = $this->id(
            'SELECT invoice_group_id FROM ip_invoice_groups WHERE company_id = 1 ORDER BY invoice_group_id LIMIT 1'
        );

        $this->assertNotNull($id, 'Serve un gruppo di numerazione per l\'azienda 1');

        return (int) $id;
    }

    private function id(string $sql): ?int
    {
        $row = $this->testDb->query($sql)->getRow();

        return $row ? (int) array_values((array) $row)[0] : null;
    }

    /**
     * POST con un token CSRF vero, preso dalla pagina del form come farebbe
     * il browser: il flusso passa dai filtri reali, non da una protezione
     * disattivata per comodità.
     */
    private function inviaConToken(string $url, array $dati, string $paginaModulo): TestResponse
    {
        $pagina = $this->withSession($this->sessione())->get($paginaModulo);
        $html   = (string) $pagina->response()->getBody();

        $token = null;

        foreach ([
            '/name="csrf_test_name"[^>]*value="([^"]+)"/',
            '/value="([^"]+)"[^>]*name="csrf_test_name"/',
        ] as $schema) {
            if (preg_match($schema, $html, $trovato)) {
                $token = $trovato[1];
                break;
            }
        }

        $this->assertNotNull($token, "Nessun token CSRF nella pagina {$paginaModulo}");

        return $this->withSession($this->sessione())
            ->post($url, $dati + ['csrf_test_name' => $token]);
    }

    /** @return array<string, mixed> */
    private function sessione(): array
    {
        return [
            'user_id'           => $this->utente,
            'user_type'         => 1,
            'user_name'         => 'Admin',
            'active_company_id' => 1,
        ];
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
