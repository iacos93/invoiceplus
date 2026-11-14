<?php

namespace Tests\Unit;

use App\Services\CompanyOrphanReport;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Classificazione delle tabelle e conteggi delle righe senza company_id.
 *
 * La classificazione è la parte che non si può sbagliare: mettere una tabella
 * per-azienda fra le "globali" nasconderebbe orfani veri, e il contrario
 * assegnerebbe all'azienda predefinita i parametri fiscali condivisi, togliendo
 * il modello alle altre aziende.
 *
 * Conteggi e backfill girano su un database SQLite in memoria creato qui: la
 * query è volutamente portabile, quindi il test copre davvero il conteggio e
 * l'UPDATE, non solo l'impalcatura. Non copre PostgreSQL (tipi boolean,
 * escapeIdentifiers reale) né il comando spark, che usa la connessione attiva.
 *
 * @internal
 */
final class CompanyOrphanReportTest extends CIUnitTestCase
{
    private BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();

        // Connessione privata e non condivisa: in ambiente testing il gruppo
        // predefinito è SQLite :memory: con prefisso "db_", che qui non
        // vogliamo, perché i nomi tabella devono restare quelli veri.
        $this->conn = \Config\Database::connect([
            'DBDriver'    => 'SQLite3',
            'database'    => ':memory:',
            'DBPrefix'    => '',
            'DBDebug'     => true,
            'foreignKeys' => false,
        ], false);

        $this->conn->initialize();

        $this->conn->query(
            'CREATE TABLE ip_companies (
                company_id INTEGER PRIMARY KEY AUTOINCREMENT,
                company_name TEXT,
                company_is_default BOOLEAN
            )'
        );
    }

    protected function tearDown(): void
    {
        $this->conn->close();

        parent::tearDown();
    }

    public function testLeTabelleLegacyEGlobaliNonSiSovrappongono(): void
    {
        $this->assertSame(
            [],
            array_values(array_intersect(CompanyOrphanReport::LEGACY_TABLES, CompanyOrphanReport::GLOBAL_TABLES)),
            'Una tabella non può essere insieme "da assegnare" e "NULL legittimo".'
        );
    }

    public function testIParametriFiscaliSonoLeUnicheTabelleGlobali(): void
    {
        $this->assertSame(
            ['ip_fiscal_brackets', 'ip_fiscal_years'],
            CompanyOrphanReport::GLOBAL_TABLES,
            'Gli unici NULL legittimi sono anni e scaglioni condivisi (ScopeFiscalYearsPerCompany).'
        );
    }

    public function testLeTabellePerAziendaNoteSonoTutteLegacy(): void
    {
        $attese = [
            'ip_bank_accounts',
            'ip_bank_transactions',
            'ip_clients',
            'ip_costing_accounts',
            'ip_costing_budgets',
            'ip_delivery_notes',
            'ip_email_senders',
            'ip_expenses',
            'ip_f24',
            'ip_imports',
            'ip_invoice_groups',
            'ip_invoices',
            'ip_products',
            'ip_quotes',
            'ip_suppliers',
        ];

        $legacy = CompanyOrphanReport::LEGACY_TABLES;
        sort($attese);
        sort($legacy);

        $this->assertSame($attese, $legacy);

        $this->assertNotContains(
            'ip_companies',
            CompanyOrphanReport::LEGACY_TABLES,
            'company_id di ip_companies è la chiave primaria, non uno scope aziendale.'
        );
    }

    public function testIConteggiContanoSoloLeTabelleLegacyPresenti(): void
    {
        $this->creaTabella('ip_invoices');
        $this->creaTabella('ip_clients');
        $this->creaTabella('ip_fiscal_years');

        $this->riga('ip_invoices', null);
        $this->riga('ip_invoices', 1);
        $this->riga('ip_invoices', 1);
        $this->riga('ip_clients', 1);

        // I parametri condivisi non sono orfani: anche con NULL non entrano
        // né nella tabella né nel totale.
        $this->riga('ip_fiscal_years', null);
        $this->riga('ip_fiscal_years', null);

        $conteggi = (new CompanyOrphanReport())->counts($this->conn);

        $this->assertSame(1, $conteggi['total']);
        $this->assertSame(4, $conteggi['rows']);
        $this->assertSame(['nulls' => 1, 'total' => 3], $conteggi['tables']['ip_invoices']);
        $this->assertSame(['nulls' => 0, 'total' => 1], $conteggi['tables']['ip_clients']);
        $this->assertArrayNotHasKey('ip_fiscal_years', $conteggi['tables']);
        $this->assertContains('ip_expenses', $conteggi['skipped']);
        $this->assertNotContains('ip_invoices', $conteggi['skipped']);
    }

    public function testInstallazioneSenzaTabelleLegacyNonVaInErrore(): void
    {
        $conteggi = (new CompanyOrphanReport())->counts($this->conn);

        $this->assertSame(0, $conteggi['total']);
        $this->assertSame([], $conteggi['tables']);
        $this->assertSame(CompanyOrphanReport::LEGACY_TABLES, $conteggi['skipped']);
    }

    public function testSenzaAziendaPredefinitaIlBackfillNonToccaNulla(): void
    {
        $this->conn->table('ip_companies')->insert([
            'company_name'       => 'Seconda',
            'company_is_default' => false,
        ]);

        $this->creaTabella('ip_invoices');
        $this->riga('ip_invoices', null);

        $esito = (new CompanyOrphanReport())->backfill($this->conn);

        $this->assertFalse($esito['ok']);
        $this->assertNotSame('', $esito['reason']);
        $this->assertSame(0, $esito['total']);
        $this->assertNull($esito['company_id']);

        $this->assertSame(
            1,
            $this->conn->table('ip_invoices')->where('company_id IS NULL', null, false)->countAllResults(),
            'Senza un\'azienda predefinita la riga deve restare NULL (visibile ovunque), non essere cancellata o spostata a caso.'
        );
    }

    public function testIlBackfillAssegnaAllAziendaPredefinitaEdEIdempotente(): void
    {
        $this->conn->table('ip_companies')->insert([
            'company_name'       => 'Operativa',
            'company_is_default' => false,
        ]);
        $companyId = (int) $this->conn->insertID();

        $this->conn->table('ip_companies')->insert([
            'company_name'       => 'Predefinita',
            'company_is_default' => true,
        ]);
        $predefinita = (int) $this->conn->insertID();

        $this->creaTabella('ip_invoices');
        $this->creaTabella('ip_clients');
        $this->creaTabella('ip_fiscal_years');

        $this->riga('ip_invoices', null);
        $this->riga('ip_invoices', null);
        $this->riga('ip_invoices', $companyId);
        $this->riga('ip_clients', null);
        $this->riga('ip_fiscal_years', null);

        $esito = (new CompanyOrphanReport())->backfill($this->conn);

        $this->assertTrue($esito['ok']);
        $this->assertSame($predefinita, $esito['company_id']);
        $this->assertSame(3, $esito['total']);
        $this->assertSame(2, $esito['tables']['ip_invoices']);
        $this->assertSame(1, $esito['tables']['ip_clients']);

        $this->assertSame(
            2,
            $this->conn->table('ip_invoices')->where('company_id', $predefinita)->countAllResults()
        );
        $this->assertSame(
            1,
            $this->conn->table('ip_invoices')->where('company_id', $companyId)->countAllResults(),
            'La riga che aveva già un\'azienda non deve essere spostata.'
        );
        $this->assertSame(
            0,
            $this->conn->table('ip_invoices')->where('company_id IS NULL', null, false)->countAllResults()
        );

        $this->assertSame(
            1,
            $this->conn->table('ip_fiscal_years')->where('company_id IS NULL', null, false)->countAllResults(),
            'I parametri fiscali condivisi non si toccano: sono il modello di tutte le aziende.'
        );

        // Seconda esecuzione: zero righe, nessun errore.
        $secondo = (new CompanyOrphanReport())->backfill($this->conn);

        $this->assertTrue($secondo['ok']);
        $this->assertSame(0, $secondo['total']);
        $this->assertSame(0, (new CompanyOrphanReport())->counts($this->conn)['total']);
    }

    public function testIlBackfillSaltaLeTabelleSenzaCompanyId(): void
    {
        $this->conn->table('ip_companies')->insert([
            'company_name'       => 'Predefinita',
            'company_is_default' => true,
        ]);
        $predefinita = (int) $this->conn->insertID();

        // Tabella legacy esistente ma con schema vecchio, senza company_id:
        // non deve entrare nel backfill né farlo fallire.
        $this->conn->query('CREATE TABLE ip_invoices (invoice_id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_number TEXT)');
        $this->conn->table('ip_invoices')->insert(['invoice_number' => '2026-001']);

        $esito = (new CompanyOrphanReport())->backfill($this->conn);

        $this->assertTrue($esito['ok']);
        $this->assertSame(0, $esito['total']);
        $this->assertContains('ip_invoices', $esito['skipped']);
        $this->assertSame($predefinita, $esito['company_id']);
    }

    /**
     * Un'azienda "viva" con righe sue: il conteggio è ciò che impedisce di
     * eliminarla lasciando i documenti con un company_id inesistente.
     */
    public function testCountForCompanyContaLeRigheDellAziendaEPuoStareAZero(): void
    {
        $this->creaTabella('ip_invoices');
        $this->creaTabella('ip_clients');
        $this->creaTabella('ip_fiscal_years');

        $this->riga('ip_invoices', 7);
        $this->riga('ip_invoices', 7);
        $this->riga('ip_clients', 7);
        $this->riga('ip_clients', null);
        $this->riga('ip_clients', 8);

        // Un anno fiscale personalizzato appartiene all'azienda: eliminarla lo
        // perderebbe, quindi va contato anche se la tabella è "globale".
        $this->riga('ip_fiscal_years', 7);

        $conto = (new CompanyOrphanReport())->countForCompany(7, $this->conn);

        $this->assertSame(4, $conto['total']);
        $this->assertSame(2, $conto['tables']['ip_invoices']);
        $this->assertSame(1, $conto['tables']['ip_clients']);
        $this->assertSame(1, $conto['tables']['ip_fiscal_years']);

        $vuoto = (new CompanyOrphanReport())->countForCompany(99, $this->conn);

        $this->assertSame(0, $vuoto['total']);
        $this->assertSame([], $vuoto['tables']);
    }

    private function creaTabella(string $tabella): void
    {
        $this->conn->query(
            'CREATE TABLE ' . $this->conn->escapeIdentifiers($tabella)
            . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NULL)'
        );
    }

    private function riga(string $tabella, ?int $companyId): void
    {
        $this->conn->table($tabella)->insert(['company_id' => $companyId]);
    }
}
