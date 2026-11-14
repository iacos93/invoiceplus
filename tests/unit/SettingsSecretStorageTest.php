<?php

use App\Controllers\Settings;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Segreti delle Impostazioni cifrati a riposo.
 *
 * La pagina Impostazioni salvava cron_key in chiaro e la stampava nel campo:
 * chi leggeva ip_settings (un backup, una query, il valore mostrato a schermo)
 * aveva la chiave che protegge gli endpoint /cron. Qui si verifica che:
 *  - cron_key sia nella lista delle chiavi cifrate;
 *  - il valore scritto non contenga la chiave in chiaro e decrypt_secret()
 *    la restituisca intera (è quello che fa cron_key_valid());
 *  - il campo write-only vuoto non sovrascriva la chiave già salvata.
 *
 * Non serve il database: si provano la regola di cifratura e la funzione del
 * controller, non il salvataggio completo. Il percorso HTTP e get_setting()
 * restano coperti dai test di integrazione.
 *
 * @internal
 */
final class SettingsSecretStorageTest extends CIUnitTestCase
{
    public function testCronKeyFiguraTraLeChiaviCifrate(): void
    {
        $chiavi = (new ReflectionClassConstant(Settings::class, 'ENCRYPTED_SETTING_KEYS'))->getValue();

        $this->assertContains('cron_key', $chiavi);
    }

    public function testIlValoreSalvatoNonContieneLaChiaveInChiaro(): void
    {
        $chiave  = 'k7Qm2PzR9vLx4TbN';
        $salvato = encrypt_secret($chiave);

        $this->assertStringStartsWith('enc2:', $salvato);
        $this->assertStringNotContainsString($chiave, $salvato);
        $this->assertSame($chiave, decrypt_secret($salvato));
    }

    /**
     * Le installazioni esistenti possono avere cron_key in chiaro: il
     * decrypt_secret() lo restituisce così com'è, quindi Cron continua a
     * funzionare finché la chiave non viene rigenerata o salvata.
     */
    public function testUnaChiaveLegacyInChiaroRestaLeggibile(): void
    {
        $this->assertSame('vecchia-chiave-in-chiaro', decrypt_secret('vecchia-chiave-in-chiaro'));
    }

    public function testUnCampoVuotoNonSovrascriveLaChiaveCron(): void
    {
        $metodo = new ReflectionMethod(Settings::class, 'encryptedSettingValue');

        $this->assertNull($metodo->invoke(null, 'cron_key', ''));
    }

    /**
     * I token SdI mostrano il valore nel form: svuotarli continua a svuotare
     * il token, esattamente come prima di questa modifica.
     */
    public function testUnCampoVuotoSvuotaAncoraITokenSdi(): void
    {
        $metodo = new ReflectionMethod(Settings::class, 'encryptedSettingValue');

        $this->assertSame('', $metodo->invoke(null, 'sdi_coop_inbound_token', ''));
    }

    public function testUnaNuovaChiaveCronVieneCifrata(): void
    {
        $chiave  = 'nuova-chiave-di-prova';
        $metodo  = new ReflectionMethod(Settings::class, 'encryptedSettingValue');
        $salvato = $metodo->invoke(null, 'cron_key', $chiave);

        $this->assertIsString($salvato);
        $this->assertStringStartsWith('enc2:', $salvato);
        $this->assertStringNotContainsString($chiave, $salvato);
        $this->assertSame($chiave, decrypt_secret($salvato));
    }
}
