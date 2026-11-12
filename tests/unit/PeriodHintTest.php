<?php

use App\Models\SettingModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * La barra dei mesi deve dire come si sceglie più di un mese.
 *
 * La selezione multipla funzionava già (shift/clic per l'intervallo,
 * ctrl/clic per aggiungere o togliere) ma non era scritta da nessuna parte:
 * chi voleva settembre e ottobre ne apriva uno per volta. L'istruzione vive
 * nel partial condiviso da fatture, preventivi e spese, quindi una modifica
 * qui vale per tutte e tre le pagine.
 *
 * @internal
 */
final class PeriodHintTest extends CIUnitTestCase
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

    /** @return array<string, mixed> */
    private function render(): string
    {
        return (string) view('layout/period_nav', [
            'base_url' => 'invoices/status/all',
            'year'     => 2026,
            'months'   => [9, 10],
            'totals'   => [
                'count'   => 0,
                'net'     => 0,
                'tax'     => 0,
                'total'   => 0,
                'balance' => 0,
            ],
        ]);
    }

    public function testLaBarraSpiegaLaSelezioneMultipla(): void
    {
        $html = $this->render();

        $this->assertStringContainsString(
            'id="ip-period-hint"',
            $html,
            'La barra dei mesi non contiene le istruzioni della selezione multipla'
        );

        // Il testo è tradotto: si verifica il riferimento alle due
        // combinazioni, non la frase in una lingua precisa. I tasti sono
        // avvolti in <kbd> per la resa: si confronta il testo del paragrafo
        // senza tag, altrimenti "Shift</kbd>+clic" non è più "Shift+clic" e
        // il confronto sull'HTML intero poteva passare grazie a un commento
        // dello script invece che grazie alla frase visibile.
        $hint = '';

        if (preg_match('/<p class="ip-period-hint"[^>]*>(.*?)<\/p>/s', $html, $m) === 1) {
            $hint = strip_tags($m[1]);
        }

        $this->assertMatchesRegularExpression(
            '/Shift\+clic/',
            $hint,
            'Le istruzioni non nominano Shift+clic'
        );
        $this->assertMatchesRegularExpression(
            '/Ctrl\/⌘\+clic/',
            $hint,
            'Le istruzioni non nominano Ctrl/⌘+clic'
        );
    }

    /**
     * L'istruzione è nascosta nel markup e lo script la mostra: senza
     * JavaScript i mesi sono link singoli, e promettere shift+clic sarebbe
     * falso. Lo script è nello stesso partial, quindi viaggia con la barra
     * (compreso il ricaricamento via pjax).
     */
    public function testLeIstruzioniSonoNascosteSenzaJavaScript(): void
    {
        $html = $this->render();

        $this->assertMatchesRegularExpression(
            '/<p class="ip-period-hint" id="ip-period-hint" data-ip-period-hint hidden>/',
            $html,
            'Le istruzioni devono partire nascoste e comparire solo con lo script'
        );
        $this->assertStringContainsString(
            "hint.hidden = false;",
            $html,
            'Lo script della barra non mostra le istruzioni'
        );
    }
}
