<?php

use App\Models\SdiTransmissionModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Cosa fa ogni esito dello SdI al blocco della fattura.
 *
 * Una fattura che lo SdI ha in mano non deve cambiare, tranne dopo uno scarto:
 * li' va corretta e rinviata. Il canale manuale e' l'eccezione, perche' per
 * l'app "XML generato" non vuol dire "consegnato".
 *
 * @internal
 */
final class SdiLockEffectTest extends CIUnitTestCase
{
    public function testGliEsitiCheDiconoCheLoSdiHaLaFatturaLaBloccano(): void
    {
        foreach (['RC', 'NE', 'EC', 'MC', 'DT', 'AT', 'IMP'] as $esito) {
            $this->assertTrue(SdiTransmissionModel::lockEffect($esito, 'sdicoop'), "{$esito} blocca");
        }
    }

    public function testLoScartoSblocca(): void
    {
        foreach (['sdicoop', 'pec', 'api', 'manual'] as $canale) {
            $this->assertFalse(SdiTransmissionModel::lockEffect('NS', $canale), "NS sblocca ({$canale})");
        }
    }

    public function testInAttesaBloccaSoloSuiCanaliAutomatici(): void
    {
        $this->assertTrue(SdiTransmissionModel::lockEffect('pending', 'sdicoop'));
        $this->assertTrue(SdiTransmissionModel::lockEffect('pending', 'pec'));
        $this->assertTrue(SdiTransmissionModel::lockEffect('pending', 'api'));
        $this->assertNull(SdiTransmissionModel::lockEffect('pending', 'manual'), 'Con il canale manuale l\'app non sa se e\' stata consegnata');
    }

    public function testUnInvioFallitoNonCambiaNiente(): void
    {
        $this->assertNull(SdiTransmissionModel::lockEffect(null, 'sdicoop'));
        $this->assertNull(SdiTransmissionModel::lockEffect('', 'pec'));
    }

    public function testUnEsitoManualeDopoIlCanaleManualeBlocca(): void
    {
        $this->assertTrue(SdiTransmissionModel::lockEffect('RC', 'manual'), 'Registrare a mano una ricevuta di consegna conferma l\'invio');
    }
}
