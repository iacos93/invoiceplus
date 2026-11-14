<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\ContentSecurityPolicy;

/**
 * La policy CSP si sceglie con app.cspMode e non deve mai peggiorare per un
 * errore di battitura.
 *
 * Il caso che si vuole evitare: scrivere "enforced" invece di "enforce" e
 * ritrovarsi con la CSP spenta in produzione senza che nessuno se ne accorga,
 * oppure, al contrario, con una policy applicata che non e' mai stata provata.
 * Un valore sconosciuto vale "report": la policy c'e' e segnala, ma non blocca.
 *
 * @internal
 */
final class ContentSecurityPolicyConfigTest extends CIUnitTestCase
{
    private const KEY = 'app.cspMode';

    protected function tearDown(): void
    {
        unset($_SERVER[self::KEY], $_ENV[self::KEY]);
        putenv(self::KEY);

        parent::tearDown();
    }

    private function conModalita(?string $mode): ContentSecurityPolicy
    {
        unset($_SERVER[self::KEY], $_ENV[self::KEY]);

        if ($mode !== null) {
            $_SERVER[self::KEY] = $mode;
        }

        return new ContentSecurityPolicy();
    }

    public function testSenzaConfigurazioneVinceLAmbiente(): void
    {
        // La suite gira in "testing": li' si segnala soltanto. In produzione
        // il predefinito e' enforce (vedi mode()).
        $atteso = (string) env('CI_ENVIRONMENT', 'production') === 'production' ? 'enforce' : 'report';

        $this->assertSame($atteso, $this->modalita(null));
        $this->assertSame($atteso !== 'enforce', $this->conModalita(null)->reportOnly);
    }

    public function testInProduzioneSenzaSceltaSiApplica(): void
    {
        // Il caso che conta: un'installazione che non scrive niente in .env.
        // env() legge $_ENV prima di $_SERVER, e il .env locale riempie $_ENV:
        // per simulare un ambiente vanno impostati entrambi.
        $envPrima    = $_ENV['CI_ENVIRONMENT'] ?? null;
        $serverPrima = $_SERVER['CI_ENVIRONMENT'] ?? null;

        $imposta = static function (string $ambiente): void {
            $_ENV['CI_ENVIRONMENT']    = $ambiente;
            $_SERVER['CI_ENVIRONMENT'] = $ambiente;
        };

        try {
            $imposta('production');
            $this->assertSame('enforce', $this->modalita(null));
            $this->assertFalse($this->conModalita(null)->reportOnly);

            $imposta('development');
            $this->assertSame('report', $this->modalita(null), 'In sviluppo la porta puo\' cambiare: si segnala soltanto');

            $imposta('production');
            $this->assertSame('report', $this->modalita('enforced'), 'Un errore di battitura non applica una policy mai provata');
            $this->assertSame('off', $this->modalita('off'), 'Spegnere resta possibile, ma solo di proposito');
        } finally {
            foreach ([['_ENV', $envPrima], ['_SERVER', $serverPrima]] as [$nome, $valore]) {
                if ($valore === null) {
                    unset($GLOBALS[$nome]['CI_ENVIRONMENT']);
                } else {
                    $GLOBALS[$nome]['CI_ENVIRONMENT'] = $valore;
                }
            }
        }
    }

    public function testLIndirizzoDelleSegnalazioniEFacoltativo(): void
    {
        $this->assertNull($this->conModalita('enforce')->reportURI);

        $_SERVER['app.cspReportUri'] = 'https://esempio.it/csp-report';
        $csp = $this->conModalita('enforce');
        unset($_SERVER['app.cspReportUri']);

        $this->assertSame('https://esempio.it/csp-report', $csp->reportURI);
    }

    public function testIlServizioNonEmetteMaiUnNonce(): void
    {
        // Con CI_DEBUG il framework chiede un nonce a ogni richiesta (Kint), e
        // gli helper csp_script_nonce()/safe_mailto() lo chiedono a loro volta.
        // Un solo nonce nell'header fa ignorare 'unsafe-inline' ai browser.
        $csp = service('csp');

        $this->assertInstanceOf(\App\Libraries\CspWithoutNonce::class, $csp);
        $this->assertSame('', $csp->getScriptNonce());
        $this->assertSame('', $csp->getStyleNonce());
    }

    public function testEnforceApplicaLaPolicy(): void
    {
        $this->assertSame('enforce', $this->modalita('enforce'));
        $this->assertFalse($this->conModalita('enforce')->reportOnly);
    }

    public function testUnValoreSconosciutoNonSpegneNeApplica(): void
    {
        $this->assertSame('report', $this->modalita('enforced'));
        $this->assertTrue($this->conModalita('enforced')->reportOnly);
        $this->assertSame('report', $this->modalita(''));
    }

    public function testOffSpegneLaPolicy(): void
    {
        $this->assertSame('off', $this->modalita('OFF'), 'Le maiuscole non contano');
    }

    public function testLaPolicyChiudeCioCheNessunaPaginaUsa(): void
    {
        $csp = $this->conModalita('enforce');

        $this->assertSame('none', $csp->objectSrc, 'Nessun plugin');
        $this->assertSame('self', $csp->frameAncestors, 'Nessun incorporamento in siti esterni');
        $this->assertSame('self', $csp->formAction, 'I form scrivono solo verso questo sito');
        $this->assertSame('self', $csp->connectSrc, 'Nessun invio di dati verso altri host');
        $this->assertSame('self', $csp->baseURI);

        // L'unica origine esterna ammessa per gli script e' nessuna; per
        // stili e font sono i due host di Google Fonts, e basta.
        foreach (['scriptSrc', 'scriptSrcElem'] as $direttiva) {
            $esterne = array_filter((array) $csp->{$direttiva}, static fn ($v) => str_starts_with($v, 'http'));
            $this->assertSame([], array_values($esterne), "$direttiva non deve ammettere origini esterne");
        }

        $this->assertSame(['https://fonts.googleapis.com'], array_values(array_filter((array) $csp->styleSrc, static fn ($v) => str_starts_with($v, 'http'))));
        $this->assertSame(['https://fonts.gstatic.com'], array_values(array_filter((array) $csp->fontSrc, static fn ($v) => str_starts_with($v, 'http'))));
    }

    public function testIlNonceAutomaticoNonCancellaGliScriptInLinea(): void
    {
        // Con un nonce nell'header i browser ignorano 'unsafe-inline': le 53
        // viste con <script> in linea smetterebbero di girare.
        $csp = $this->conModalita('enforce');

        $this->assertFalse($csp->autoNonce);
        $this->assertContains('unsafe-inline', (array) $csp->scriptSrc);
    }

    private function modalita(?string $mode): string
    {
        $this->conModalita($mode);

        return ContentSecurityPolicy::mode();
    }
}
