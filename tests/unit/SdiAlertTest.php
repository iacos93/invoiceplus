<?php

use App\Libraries\SdiAlert;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * L'avviso al titolare quando lo SdI segnala un problema.
 *
 * Deve partire per gli esiti che richiedono di fare qualcosa (scarto, mancata
 * consegna, impossibilita' di recapito), una volta sola anche se lo SdI
 * ripete la notifica, e dire che cosa fare: una mail che segnala solo "c'e'
 * un problema" fa aprire l'app senza sapere da dove cominciare.
 *
 * @internal
 */
final class SdiAlertTest extends CIUnitTestCase
{
    public function testAvvisaSoloPerGliEsitiCheRichiedonoUnIntervento(): void
    {
        foreach (['NS', 'MC', 'AT'] as $esito) {
            $this->assertTrue(SdiAlert::shouldNotify('pending', $esito), $esito);
        }

        foreach (['RC', 'NE', 'EC', 'DT', 'pending', 'IMP'] as $esito) {
            $this->assertFalse(SdiAlert::shouldNotify('pending', $esito), "{$esito} e' una buona notizia o una nota: nessuna mail");
        }
    }

    public function testNonRipeteLAvvisoSeLoSdiRimandaLaStessaNotifica(): void
    {
        $this->assertFalse(SdiAlert::shouldNotify('NS', 'NS'));
        $this->assertTrue(SdiAlert::shouldNotify(null, 'NS'));
        $this->assertTrue(SdiAlert::shouldNotify('MC', 'NS'), 'Un esito diverso e\' una notizia nuova');
    }

    public function testLoScartoDiceCosaFare(): void
    {
        $mail = SdiAlert::compose(
            'NS',
            '2026/15',
            'Cliente Uno S.p.A.',
            'IT01234567890_00007.xml',
            'Codice 00305: IdFiscaleIVA del CessionarioCommittente non valido',
            'Novatech Srl',
            'https://esempio.it/invoices/view/15'
        );

        $this->assertStringContainsString('2026/15', $mail['subject']);
        $this->assertStringContainsString('scartata', $mail['subject']);
        $this->assertStringContainsString('Novatech Srl', $mail['subject']);

        foreach ([
            'Cliente Uno S.p.A.',
            '00305',
            'sbloccata',
            'reinviala',
            '5 giorni',
            'commercialista',
            'IT01234567890_00007.xml',
            'https://esempio.it/invoices/view/15',
        ] as $atteso) {
            $this->assertStringContainsString($atteso, $mail['body'], "Manca: {$atteso}");
        }
    }

    public function testLaMancataConsegnaDiceDiAvvisareIlCliente(): void
    {
        $mail = SdiAlert::compose('MC', '2026/16', '', '', '', 'Novatech Srl', 'https://esempio.it/x');

        $this->assertStringContainsString('mancata consegna', $mail['subject']);
        $this->assertStringContainsString('area riservata', $mail['body']);
        $this->assertStringContainsString('copia', $mail['body']);
        $this->assertStringNotContainsString('Dettaglio', $mail['body'], 'Senza messaggio non si stampa una sezione vuota');
        $this->assertStringNotContainsString('()', $mail['body'], 'Senza cliente niente parentesi vuote');
    }

    public function testLImpossibilitaDiRecapitoAllaPaHaIlSuoTesto(): void
    {
        $mail = SdiAlert::compose('AT', '2026/17', 'Comune di Prova', '', 'x', 'Novatech Srl', 'https://esempio.it/y');

        $this->assertStringContainsString('pubblica amministrazione', $mail['body']);
        $this->assertStringContainsString('codice univoco ufficio', $mail['body']);
    }
}
