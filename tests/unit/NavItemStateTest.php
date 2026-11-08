<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Quale voce della sidebar si accende.
 *
 * Regola sbagliata più volte: prima nessuna voce di primo livello si accendeva
 * (selettore CSS che non combaciava col markup), poi si accendeva solo con
 * corrispondenza esatta — e aprendo un cliente dall'elenco il menu smetteva di
 * dire dove ci si trovava. Infine le sotto-voci esatte non coprivano le pagine
 * figlie: su /clients/status/active e /clients/view/5 restava accesa solo
 * "Anagrafiche". Qui la decisione è fissata caso per caso.
 *
 * @internal
 */
final class NavItemStateTest extends CIUnitTestCase
{
    /** Simula la navigazione su un indirizzo. */
    private function at(string $path): void
    {
        $uri     = new \CodeIgniter\HTTP\SiteURI(config('App'), ltrim($path, '/'));
        $request = new \CodeIgniter\HTTP\IncomingRequest(
            config('App'),
            $uri,
            null,
            new \CodeIgniter\HTTP\UserAgent()
        );

        \CodeIgniter\Config\Services::injectMock('request', $request);
    }

    /** Mostra cosa vede davvero is_nav_active(), per diagnosi. */
    private function seenPath(string $path): string
    {
        $this->at($path);

        return service('request')->getUri()->getPath();
    }

    private function state(string $path, string $route, array $children = []): array
    {
        $this->at($path);
        helper('ip_helpers');

        return nav_item_state($route, $children);
    }

    /**
     * Le quattro sotto-voci reali di Anagrafiche.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function anagraficheChildren(): array
    {
        return [
            ['clients', 'Clienti'],
            ['clients/form', 'Aggiungi cliente'],
            ['suppliers', 'Fornitori'],
            ['suppliers/form', 'Nuovo fornitore'],
        ];
    }

    public function testExactMatchIsHighlighted(): void
    {
        $s = $this->state('/clients', 'clients', [['clients/form', 'Aggiungi']]);

        $this->assertTrue($s['here'], 'Su /clients la voce Clienti deve essere piena');
        $this->assertTrue($s['open']);
        $this->assertSame('page', $s['aria']);
    }

    /**
     * Aprendo un record dall'elenco nessuna voce combacia esattamente, ma si è
     * dentro Clienti: il "sei qui" va alla sotto-voce, non alla madre.
     */
    public function testDeepPageHighlightsItsSubItem(): void
    {
        $s = $this->state('/clients/form/5', 'clients', [['clients/form', 'Aggiungi']]);

        $this->assertTrue($s['open'], 'La sezione resta aperta');
        $this->assertFalse($s['here'], 'Il "sei qui" spetta alla sotto-voce');
        $this->assertSame('', $s['aria'], 'La madre non duplica l\'aria-current');
        $this->assertSame('clients/form', $s['child']);
        $this->assertSame('true', $s['child_aria'], 'Non è la pagina esatta della sotto-voce');
    }

    /** Se combacia una sotto-voce, il pieno spetta a lei, non alla madre. */
    public function testChildMatchLeavesTheParentDim(): void
    {
        $s = $this->state('/clients/form', 'clients', [['clients/form', 'Aggiungi']]);

        $this->assertTrue($s['open'], 'La sezione resta aperta');
        $this->assertFalse($s['here'], 'La madre non deve contendere il "sei qui" alla sotto-voce');
        $this->assertSame('', $s['aria'], 'Un solo aria-current per pagina, ed è della sotto-voce');
        $this->assertSame('clients/form', $s['child']);
        $this->assertSame('page', $s['child_aria']);
    }

    /**
     * Le pagine di elenco non sono la rotta "clients" esatta: /clients
     * reindirizza a /clients/status/active. La sotto-voce deve restare accesa.
     */
    public function testListStatusPageLightsTheListSubItem(): void
    {
        $s = $this->state('/clients/status/active', 'clients', $this->anagraficheChildren());

        $this->assertTrue($s['open']);
        $this->assertFalse($s['here'], 'Il pieno è di "Clienti"');
        $this->assertSame('', $s['aria']);
        $this->assertSame('clients', $s['child']);
        $this->assertSame('true', $s['child_aria'], 'L\'indirizzo non è esattamente /clients');
    }

    public function testRecordPageLightsTheListSubItem(): void
    {
        $s = $this->state('/clients/view/5', 'clients', $this->anagraficheChildren());

        $this->assertSame('clients', $s['child'], 'La scheda cliente sta dentro "Clienti"');
        $this->assertFalse($s['here']);
    }

    public function testSupplierStatusPageLightsSuppliers(): void
    {
        $s = $this->state('/suppliers/status/active', 'clients', $this->anagraficheChildren());

        $this->assertSame('suppliers', $s['child']);
        $this->assertFalse($s['here']);
    }

    /**
     * Fra due sotto-voci che contengono l'indirizzo vince la più specifica:
     * su /clients/form/5 non devono accendersi insieme "Clienti" e "Aggiungi".
     */
    public function testTheMostSpecificSubItemWins(): void
    {
        $s = $this->state('/clients/form/5', 'clients', $this->anagraficheChildren());

        $this->assertSame('clients/form', $s['child']);
        $this->assertSame('true', $s['child_aria']);
    }

    /** Anche i form dei moduli sotto Impostazioni restano nella loro sotto-voce. */
    public function testModuleFormKeepsItsSettingsSubItem(): void
    {
        $children = [
            ['settings', 'Generali'],
            ['invoice_groups', 'Gruppi fattura'],
            ['tax_rates', 'Imposte'],
            ['fiscal_years', 'Parametri fiscali'],
        ];

        $s = $this->state('/tax_rates/form', 'settings', $children);

        $this->assertSame('tax_rates', $s['child']);
        $this->assertSame('true', $s['child_aria']);
        $this->assertFalse($s['here']);
    }

    /**
     * Quando la rotta della madre coincide con una sotto-voce (clients,
     * settings, management) l'aria-current="page" deve restare solo sulla
     * sotto-voce: due "page" sullo stesso schermo confondono lo screen reader.
     */
    public function testParentDoesNotDuplicateAriaCurrent(): void
    {
        $clients = $this->state('/clients', 'clients', $this->anagraficheChildren());
        $this->assertSame('', $clients['aria'], 'La madre resta senza aria-current');
        $this->assertSame('clients', $clients['child']);
        $this->assertSame('page', $clients['child_aria']);

        $settings = $this->state('/settings', 'settings', [
            ['settings', 'Generali'],
            ['tax_rates', 'Imposte'],
        ]);
        $this->assertSame('', $settings['aria']);
        $this->assertSame('settings', $settings['child']);
        $this->assertSame('page', $settings['child_aria']);
    }

    /** Nessuna sotto-voce contiene una pagina di dettaglio: resta accesa la madre. */
    public function testSectionWithoutMatchingChildKeepsTheParent(): void
    {
        $s = $this->state('/invoices/view/5', 'invoices', [
            ['invoices/form_create', 'Crea fattura'],
            ['invoices/recurring', 'Ricorrenti'],
            ['invoices/archive', 'Archivio'],
        ]);

        $this->assertNull($s['child']);
        $this->assertTrue($s['here']);
        $this->assertSame('true', $s['aria'], 'Non è la pagina esatta di "Fatture"');
    }

    public function testSupplierSubItemOpensAnagrafiche(): void
    {
        $s = $this->state('/suppliers', 'clients', $this->anagraficheChildren());
        $this->assertTrue($s['open'], 'La sezione Anagrafiche si apre anche su /suppliers');
        $this->assertSame('suppliers', $s['child']);
    }

    public function testItemWithoutChildrenIsHighlighted(): void
    {
        foreach (['finance', 'f24', 'riba', 'fiscal_years'] as $route) {
            $s = $this->state('/' . $route, $route);

            $this->assertTrue($s['here'], 'La voce ' . $route . ' deve accendersi');
            $this->assertSame('page', $s['aria']);
        }
    }

    public function testUnrelatedSectionStaysOff(): void
    {
        $s = $this->state('/clients', 'invoices', [['invoices/form_create', 'Crea']]);

        $this->assertFalse($s['here']);
        $this->assertFalse($s['open']);
        $this->assertSame('', $s['aria']);
    }

    /**
     * Nomi che condividono il prefisso non devono accendersi a vicenda:
     * "Spese" non è "Categorie di spesa".
     */
    public function testSimilarRoutesDoNotBleedIntoEachOther(): void
    {
        $this->assertFalse($this->state('/expense_categories', 'expenses')['open']);
        $this->assertFalse($this->state('/expenses', 'expense_categories')['open']);
    }

    /** Sotto-voci fuori dal percorso della madre (Famiglie sotto Prodotti). */
    public function testChildOnADifferentPathOpensItsSection(): void
    {
        $s = $this->state('/families', 'products', [['products/form', 'Nuovo'], ['families', 'Famiglie']]);

        $this->assertTrue($s['open'], 'La sezione Prodotti deve aprirsi');
        $this->assertFalse($s['here'], 'Ma il pieno è della sotto-voce Famiglie');
        $this->assertSame('families', $s['child']);
        $this->assertSame('page', $s['child_aria']);
    }

    /**
     * Terza volta che la voce sbagliava: nella sidebar "Report" puntava a
     * reports/invoice_aging, quindi sull'hub /reports (l'elenco dei quattro
     * report) non si accendeva nessuna voce, e il link saltava l'hub.
     * La sezione deve invece radicarsi sull'hub e lasciare le sottovoci alle
     * singole pagine.
     */
    public function testReportsSectionIsRootedAtItsHub(): void
    {
        $children = [
            ['reports/invoice_aging', 'Storico fatture'],
            ['reports/payment_history', 'Storico pagamenti'],
            ['reports/sales_by_client', 'Vendite per cliente'],
            ['reports/sales_by_year', 'Vendite per data'],
        ];

        $hub = $this->state('/reports', 'reports', $children);
        $this->assertTrue($hub['here'], 'L\'hub /reports deve accendere la voce Report');
        $this->assertSame('page', $hub['aria']);

        $report = $this->state('/reports/payment_history', 'reports', $children);
        $this->assertTrue($report['open'], 'La sezione resta aperta dentro un report');
        $this->assertFalse($report['here'], 'Il pieno spetta alla sotto-voce del report');
        $this->assertSame('reports/payment_history', $report['child']);
        $this->assertSame('page', $report['child_aria']);
    }

    /**
     * Il confronto non deve dipendere da come si chiama l'index page: con il
     * rewrite attivo le URL sono /finance, ma se l'app girasse senza rewrite
     * (/index.php/finance) la voce deve accendersi lo stesso. is_nav_active()
     * usa getRoutePath(), che gia' toglie l'index page, quindi la corrispondenza
     * non dipende dalla configurazione di App::$indexPage.
     */
    public function testMatchIgnoresTheIndexPagePrefix(): void
    {
        // Simula l'app senza rewrite: indexPage = 'index.php' e percorso di
        // rotta 'finance'. SiteURI ricostruisce /index.php/finance, ma
        // getRoutePath() lo ripulisce, quindi la voce deve accendersi lo stesso.
        $config           = clone config('App');
        $config->indexPage = 'index.php';

        $uri     = new \CodeIgniter\HTTP\SiteURI($config, 'finance');
        $request = new \CodeIgniter\HTTP\IncomingRequest(
            $config,
            $uri,
            null,
            new \CodeIgniter\HTTP\UserAgent()
        );
        \CodeIgniter\Config\Services::injectMock('request', $request);

        helper('ip_helpers');
        $s = nav_item_state('finance');

        $this->assertTrue($s['here'], 'La voce deve accendersi anche con il prefisso index.php');
        $this->assertSame('page', $s['aria']);
    }
}
