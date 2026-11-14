<?php

namespace Tests\Unit;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il perimetro azienda e' uguaglianza, non "uguale o senza azienda".
 *
 * Fino al backfill `company_scope_sql()`, `scope_builder_to_company()` e
 * `scopeToCompany()` includevano `OR company_id IS NULL`, e una riga senza
 * azienda compariva in tutte. Il test fissa la regola nuova: il SQL generato
 * deve contenere solo `= N`.
 *
 * Le eccezioni restano fuori da queste funzioni: gli anni e gli scaglioni
 * fiscali condivisi passano da scopeEffective()/ofCompany(), non da qui.
 */
final class CompanyScopeStrictTest extends CIUnitTestCase
{
    private BaseConnection $conn;

    protected function setUp(): void
    {
        parent::setUp();

        helper('ip_helpers');

        // Connessione privata, senza prefisso: serve solo a compilare il SQL.
        $this->conn = \Config\Database::connect([
            'DBDriver'    => 'SQLite3',
            'database'    => ':memory:',
            'DBPrefix'    => '',
            'DBDebug'     => true,
            'foreignKeys' => false,
        ], false);

        $this->conn->initialize();
        $this->conn->query('CREATE TABLE ip_invoices (invoice_id INTEGER PRIMARY KEY, company_id INTEGER NULL)');

        session()->set('active_company_id', 7);
    }

    protected function tearDown(): void
    {
        $this->conn->close();

        parent::tearDown();
    }

    public function testCompanyScopeSqlUsaSoloUguaglianza(): void
    {
        $frammento = company_scope_sql('i');

        $this->assertSame(' AND i.company_id = 7', $frammento);
        $this->assertStringNotContainsString('IS NULL', $frammento);
    }

    public function testScopeBuilderToCompanyUsaSoloUguaglianza(): void
    {
        $builder = scope_builder_to_company($this->conn->table('ip_invoices'), 'ip_invoices');
        $sql     = $builder->getCompiledSelect(false);

        // SQLite quota gli identificatori con i backtick: basta il confronto.
        $this->assertStringContainsString('company_id', $sql);
        $this->assertStringContainsString('= 7', $sql);
        $this->assertStringNotContainsString('IS NULL', $sql);
    }

}
