<?php

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Il filtro anno della sidebar resta in memoria, e ogni azienda ha il suo.
 *
 * Una scelta che sparisce è peggio di un filtro che non c'è: sembra che
 * l'archivio si sia svuotato. Qui si fissa il giro vero — POST del selettore,
 * risposta di redirect, cookie — e il patto fra aziende: cambiando azienda si
 * riprende il periodo di quella, non si eredita quello appena lasciato.
 *
 * Gira sulla copia del database e non scrive nulla.
 *
 * @internal
 */
final class YearFilterTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private const DB_COPY = 'invoiceplane_posttest';

    // $testDb e non $db: CIUnitTestCase dichiara già $db senza tipo.
    private ?BaseConnection $testDb = null;

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
            $this->testDb->query('SELECT count(*) AS n FROM ip_companies');
        } catch (\Throwable $e) {
            $this->restoreConnections();

            $this->markTestSkipped('Copia del database non disponibile: ' . $config->tests['database']);
        }

        unset($_SESSION['selected_years'], $_COOKIE['ip_selected_year'], $_COOKIE['ip_selected_years']);
    }

    protected function tearDown(): void
    {
        $this->restoreConnections();

        parent::tearDown();
    }

    public function testLAnnoSceltoRestaPerLAziendaAttiva(): void
    {
        $anno = (int) date('Y');

        $risposta = $this->inviaConToken('/year_filter/set', [
            'year' => (string) $anno,
            'back' => '/dashboard',
        ]);

        $this->assertSame(302, $risposta->response()->getStatusCode(), 'Il selettore risponde con un redirect');
        $this->assertSame([1 => $anno], session('selected_years'));

        $cookie = $risposta->response()->getCookie('ip_selected_years');

        $this->assertNotNull($cookie, 'Senza cookie la scelta morirebbe con la sessione');
        $this->assertSame(
            ['1' => $anno],
            json_decode($cookie->getValue(), true),
            'Nel cookie va la mappa delle aziende, non un anno solo'
        );
    }

    /**
     * La prova che il periodo è di ciascuna azienda: due aziende, due anni
     * diversi, e il selettore segue l'azienda attiva.
     */
    public function testOgniAziendaHaIlSuoAnno(): void
    {
        $anno = (int) date('Y');

        // Azienda 1 sull'anno corrente, azienda 2 su "Tutti gli anni" (non ha
        // documenti): sono due periodi diversi, e ognuno deve restare il suo.
        $sessione = $this->sessione(['selected_years' => [1 => $anno, 2 => 0]]);

        $this->assertSame([$anno], $this->anniSelezionati($this->apri('/dashboard', $sessione)));

        $sessione['active_company_id'] = 2;

        $this->assertSame([0], $this->anniSelezionati($this->apri('/dashboard', $sessione)));
    }

    /**
     * Cambiando azienda non si perde niente e non si eredita niente: si
     * riprende il periodo di quella in cui si entra, e tornando indietro
     * l'altra è ancora dove la si era lasciata.
     */
    public function testIlCambioAziendaRiprendeIlPeriodoDellAziendaScelta(): void
    {
        $anno     = (int) date('Y');
        $sessione = $this->sessione(['selected_years' => [1 => $anno, 2 => 0]]);

        $risposta = $this->inviaConToken(
            '/companies/switch',
            ['company_id' => '2', 'back' => '/dashboard'],
            $sessione
        );

        $this->assertSame(302, $risposta->response()->getStatusCode());
        $this->assertSame(
            [1 => $anno, 2 => 0],
            session('selected_years'),
            'L\'anno dell\'azienda 1 non deve essere copiato sull\'azienda 2'
        );

        // E tornando alla 1 si ritrova il 2026, non "Tutti gli anni".
        $sessione['active_company_id'] = 1;

        $this->assertSame([$anno], $this->anniSelezionati($this->apri('/dashboard', $sessione)));
    }

    /**
     * Un anno rimasto in memoria ma ormai vuoto in quell'azienda torna a
     * "Tutti gli anni": una lista vuota sembrerebbe un archivio perduto.
     */
    public function testUnAnnoSenzaDocumentiNellAziendaSiAzzera(): void
    {
        $risposta = $this->inviaConToken(
            '/companies/switch',
            ['company_id' => '2', 'back' => '/dashboard'],
            ['selected_years' => [1 => 2026, 2 => 1999], 'active_company_id' => 1]
        );

        $this->assertSame(302, $risposta->response()->getStatusCode());
        $this->assertSame(
            [1 => 2026, 2 => 0],
            session('selected_years'),
            'Si azzera solo l\'anno dell\'azienda in cui non ha documenti'
        );
        $this->assertSame(
            ['1' => 2026, '2' => 0],
            json_decode($risposta->response()->getCookie('ip_selected_years')?->getValue() ?? '', true),
            'Il cookie va corretto insieme alla sessione, o al prossimo accesso tornerebbe l\'anno vecchio'
        );
    }

    // -----------------------------------------------------------------
    // Aiuti
    // -----------------------------------------------------------------

    /** Anni segnati come `selected` nella tendina dell'anno in sidebar. */
    private function anniSelezionati(string $html): array
    {
        $this->assertSame(
            1,
            preg_match('/<select name="year" id="ip-context-year".*?<\/select>/s', $html, $trovato),
            'Tendina dell\'anno non trovata nella barra laterale'
        );

        preg_match_all('/<option value="(\d+)"\s*(selected)?/', $trovato[0], $opzioni, PREG_SET_ORDER);

        $selezionati = [];

        foreach ($opzioni as $opzione) {
            if (($opzione[2] ?? '') === 'selected') {
                $selezionati[] = (int) $opzione[1];
            }
        }

        return $selezionati;
    }

    /** @param array<string, mixed> $sessione */
    private function apri(string $url, array $sessione): string
    {
        return (string) $this->withSession($sessione)->get($url)->response()->getBody();
    }

    /**
     * POST con un token CSRF vero preso da una pagina, come farebbe il browser.
     *
     * @param array<string, mixed> $dati
     * @param array<string, mixed> $sessione
     */
    private function inviaConToken(
        string $url,
        array $dati,
        array $sessione = []
    ): \CodeIgniter\Test\TestResponse {
        $html = $this->apri('/dashboard', $this->sessione($sessione));

        $this->assertSame(
            1,
            preg_match('/name="csrf_test_name"[^>]*value="([^"]+)"/', $html, $trovato),
            'Nessun token CSRF nella pagina di partenza'
        );

        return $this->withSession($this->sessione($sessione))
            ->post($url, $dati + ['csrf_test_name' => $trovato[1]]);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function sessione(array $extra = []): array
    {
        return $extra + [
            'user_id'           => 1,
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
