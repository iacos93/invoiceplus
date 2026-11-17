<?php

use App\Models\SettingModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Gli importi sotto i mesi della barra periodo appartengono a un anno solo.
 *
 * Sommati per anno com'erano prima, con «Tutti gli anni» in topbar il dicembre
 * mostrato era la somma dei dicembre di tutti gli anni, scritta accanto a mesi
 * che si leggono come quelli dell'anno in corso. Ora il numero e il clic
 * parlano dello stesso anno: quello scelto, o quello in corso.
 *
 * Il partial è condiviso da fatture, preventivi e spese, quindi la prova vale
 * per tutte e tre le liste.
 *
 * @internal
 */
final class PeriodNavYearTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // La barra formatta i totali: bastano le impostazioni di valuta in
        // cache, così il test non dipende dal database.
        (new ReflectionClass(SettingModel::class))->getProperty('settingsCache')->setValue(null, [
            'currency_symbol'           => '€',
            'currency_symbol_placement' => 'after',
            'thousands_separator'       => '.',
            'decimal_point'             => ',',
        ]);
    }

    /**
     * @param array<int, array{net: float, tax: float, total: float}> $monthTotals
     */
    private function render(int $year, int $monthYear, array $monthTotals = []): string
    {
        return (string) view('layout/period_nav', [
            'base_url'     => 'invoices/status/all',
            'year'         => $year,
            'month_year'   => $monthYear,
            'months'       => [],
            'totals'       => [
                'count'   => 0,
                'net'     => 0,
                'tax'     => 0,
                'total'   => 0,
                'balance' => 0,
            ],
            'month_totals' => $monthTotals,
        ]);
    }

    /**
     * Il link del mese porta l'anno degli importi, non quello del filtro: con
     * «Tutti gli anni» il numero sotto «Dic» è del dicembre dell'anno in corso,
     * e il clic deve aprire quello stesso mese.
     */
    public function testConTuttiGliAnniIlMesePortaAnnoInCorso(): void
    {
        $anno = (int) date('Y');

        $html = $this->render(0, $anno, [
            12 => ['net' => 1000.0, 'tax' => 220.0, 'total' => 1220.0],
        ]);

        $this->assertMatchesRegularExpression(
            '/data-month="12"\\s+href="[^"]*year=' . $anno . '&amp;month=12"/',
            $html,
            'Il mese non porta con sé l\'anno degli importi'
        );

        // Il suggerimento dice di che anno è l'importo: senza, «Dicembre»
        // sembrerebbe un mese qualunque dell'archivio.
        $this->assertMatchesRegularExpression(
            '/data-month="12"[^>]*title="[^"]*' . $anno . '/s',
            $html,
            'Il mese non dichiara il proprio anno'
        );

        // La voce «Tutti i mesi» resta sull'anno del filtro (0): è il ritorno
        // all'archivio intero, non un salto nell'anno in corso.
        $this->assertMatchesRegularExpression(
            '/data-month="0"\\s+href="[^"]*year=0&amp;month=1-12"/',
            $html,
            'La voce «Tutti i mesi» non deve cambiare anno'
        );
    }

    /**
     * Con un anno scelto niente è cambiato: importi e link sono di quell'anno e
     * il suggerimento non ripete l'anno sotto ogni mese.
     */
    public function testConAnnoSceltoRestaQuellAnno(): void
    {
        $html = $this->render(2025, 2025, [
            12 => ['net' => 1000.0, 'tax' => 220.0, 'total' => 1220.0],
        ]);

        $this->assertMatchesRegularExpression(
            '/data-month="12"\\s+href="[^"]*year=2025&amp;month=12"/',
            $html,
            'Il mese non porta l\'anno scelto'
        );

        // L'anno già scelto in topbar non si ripete sotto ogni mese.
        $this->assertMatchesRegularExpression(
            '/data-month="12"[^>]*title="[^"]*"/s',
            $html
        );
        $this->assertDoesNotMatchRegularExpression('/data-month="12"[^>]*title="[^"]*2025/s', $html, 'L\'anno non va ripetuto quando è già il filtro');
    }

    /**
     * L'anno degli importi si ricava dall'anno del filtro: i controller lo
     * passano, ma il partial deve reggere anche senza (0 = anno in corso).
     */
    public function testSenzaAnnoEsplicitoUsaQuelloInCorso(): void
    {
        $anno = (int) date('Y');

        $this->assertSame($anno, period_month_year(0), 'Con «Tutti gli anni» l\'anno è quello in corso');
        $this->assertSame(2024, period_month_year(2024), 'Un anno scelto resta quello scelto');

        // month_year esplicito a 0: il partial deve ricavarlo dall'anno del
        // filtro (i controller lo passano già calcolato).
        $html = (string) view('layout/period_nav', [
            'base_url'     => 'expenses',
            'year'         => 0,
            'month_year'   => 0,
            'months'       => [],
            'totals'       => [],
            'month_totals' => [],
        ]);

        $this->assertStringContainsString('data-year="' . $anno . '"', $html, 'La barra non ripiega sull\'anno in corso');
    }
}
