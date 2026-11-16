<?php

use App\Models\SettingModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * I grafici della Bacheca sono interattivi (ip-chart-tips.js): ogni mese è un
 * gruppo con i valori nel tooltip e, dove c'è un elenco, un link filtrato.
 *
 * Il comportamento sta nel JavaScript e si prova nel browser; qui si verifica
 * il contratto che il JavaScript si aspetta dal server, che è ciò che si rompe
 * in silenzio: un data-tip non valido o un link senza mese lasciano il grafico
 * muto senza alcun errore.
 *
 * @internal
 */
final class DashboardChartsInteractiveTest extends CIUnitTestCase
{
    use FeatureTestTrait;

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
            $this->testDb->query('SELECT 1 FROM ip_invoices LIMIT 1');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non disponibile: ' . $e->getMessage());
        }

        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);

        $_SESSION['active_company_id'] = 1;
    }

    protected function tearDown(): void
    {
        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, null);
        $this->restoreConnections();

        parent::tearDown();
    }

    public function testOgniMeseHaITooltipEILinkAlDettaglio(): void
    {
        $html = $this->bacheca();

        preg_match_all('/<g class="ip-chart-month"([^>]*)>/', $html, $gruppi);

        // Tre grafici da sei mesi: ricavi, costi, indice di redditività.
        $this->assertGreaterThanOrEqual(18, count($gruppi[1]), 'Mancano i gruppi mese dei grafici');

        foreach ($gruppi[1] as $attributi) {
            $this->assertMatchesRegularExpression('/tabindex="0"/', $attributi, 'Un mese non è raggiungibile da tastiera');
            $this->assertMatchesRegularExpression('/aria-label="[^"]+"/', $attributi, 'Un mese non ha un nome per i lettori di schermo');

            $this->assertSame(1, preg_match('/data-tip="([^"]*)"/', $attributi, $tip), 'Un mese non ha i valori del tooltip');

            $dati = json_decode(html_entity_decode($tip[1], ENT_QUOTES), true);

            $this->assertIsArray($dati, 'data-tip non è JSON valido');
            $this->assertNotSame('', (string) ($dati['title'] ?? ''), 'Il tooltip non ha il titolo del mese');
            $this->assertNotEmpty($dati['rows'] ?? [], 'Il tooltip non ha valori');

            foreach ($dati['rows'] as $riga) {
                $this->assertArrayHasKey('label', $riga);
                $this->assertArrayHasKey('value', $riga);
                // Il valore va nel tooltip come testo: markup rimasto nella
                // cifra comparirebbe alla lettera.
                $this->assertStringNotContainsString('<', (string) $riga['value'], 'Markup dentro il valore del tooltip');
            }
        }
    }

    public function testILinkDeiGraficiApronoLElencoDelMese(): void
    {
        $html = $this->bacheca();

        preg_match_all('/data-href="([^"]*)"/', $html, $link);
        $href = array_map(static fn(string $h): string => html_entity_decode($h, ENT_QUOTES), $link[1]);

        $this->assertNotEmpty($href, 'I grafici non aprono nessun elenco');

        $fatture = array_filter($href, static fn(string $h): bool => str_contains($h, 'invoices/status/all'));
        $spese   = array_filter($href, static fn(string $h): bool => str_contains($h, '/expenses'));

        $this->assertNotEmpty($fatture, 'Il grafico dei ricavi non porta alle fatture');
        $this->assertNotEmpty($spese, 'Il grafico dei costi non porta alle spese');

        foreach ($fatture as $h) {
            $this->assertMatchesRegularExpression('/[?&]year=\d{4}&month=(?:[1-9]|1[0-2])$/', $h, 'Link senza anno e mese: ' . $h);
        }
    }

    public function testLaLegendaDeiRicaviAccendeESpegneLeSerie(): void
    {
        $html = $this->bacheca();

        foreach (['invoiced', 'paid'] as $serie) {
            $this->assertStringContainsString('data-series="' . $serie . '"', $html, 'Manca la voce di legenda ' . $serie);
        }

        $this->assertStringContainsString('aria-pressed="true"', $html);
    }

    public function testIGraficiNonUsanoPiuITitoliNativi(): void
    {
        $html = $this->bacheca();

        // Il tooltip nativo (<title>) e quello del JavaScript comparirebbero
        // insieme, sovrapposti.
        preg_match_all('/<svg class="ip-(?:chart|area)".*?<\/svg>/s', $html, $svg);

        $this->assertNotEmpty($svg[0]);

        foreach ($svg[0] as $grafico) {
            $this->assertStringNotContainsString('<title>', $grafico, 'Un grafico ha ancora il tooltip nativo');
        }
    }

    private function bacheca(): string
    {
        $utente = $this->testDb->query('SELECT user_id FROM ip_users ORDER BY user_id LIMIT 1')->getRow();

        $pagina = $this->withSession([
            'user_id'           => (int) ($utente->user_id ?? 1),
            'user_type'         => 1,
            'user_name'         => 'Admin',
            'active_company_id' => 1,
        ])->get('/dashboard');

        $this->assertSame(200, $pagina->response()->getStatusCode(), 'La Bacheca non si apre');

        return (string) $pagina->response()->getBody();
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
