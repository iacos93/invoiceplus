<?php

use App\Libraries\RestoreVerifier;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * La prova di ripristino: nome del database temporaneo e casi che devono
 * fermarsi prima di eseguire comandi.
 *
 * Il ripristino vero (che dipende dai binari installati) si prova a mano con
 * `php spark backup:verify`; qui si controlla la parte che può sbagliare in
 * silenzio, cioè il nome del database di prova e il rifiuto dei casi senza
 * senso.
 *
 * @internal
 */
final class RestoreVerifierTest extends CIUnitTestCase
{
    public function testIlNomeDelDatabaseDiProvaPulisceICaratteri(): void
    {
        $this->assertSame(
            'invoiceplane_verify_20261112103000',
            RestoreVerifier::scratchName('invoiceplane', '2026-11-12 10:30:00')
        );
    }

    /**
     * Un nome con spazi o apostrofi non deve finire in un comando senza
     * quotatura: qui viene ridotto a lettere, cifre e underscore.
     */
    public function testNomiConSpaziEApiciVengonoRidotti(): void
    {
        $this->assertSame(
            "db_dell_ufficio_verify_20261112103000",
            RestoreVerifier::scratchName("db dell'ufficio", '2026-11-12 10:30:00')
        );
    }

    /**
     * PostgreSQL taglia gli identificatori a 63 byte: un nome più lungo
     * verrebbe troncato dal server, e il DROP finale non troverebbe più il
     * database da eliminare.
     */
    public function testIlNomeNonSuperaISessantatreCaratteri(): void
    {
        $nome = RestoreVerifier::scratchName(str_repeat('a', 80), '20261112103000');

        $this->assertLessThanOrEqual(63, strlen($nome));
        $this->assertStringEndsWith('_verify_20261112103000', $nome);
    }

    /**
     * Un identificatore che inizia con una cifra non è valido senza virgolette
     * in PostgreSQL.
     */
    public function testUnNomeCheIniziaConCifraVienePrefissato(): void
    {
        $this->assertSame(
            'db_123db_verify_20261112103000',
            RestoreVerifier::scratchName('123db', '20261112103000')
        );
    }

    public function testMotoreNonSupportatoSpiegaIlMotivo(): void
    {
        $verifier = new RestoreVerifier(
            ['DBDriver' => 'SQLite3', 'database' => 'db.sqlite'],
            '/tmp/non-importa.sqlite'
        );

        $esito = $verifier->verify();

        $this->assertFalse($esito['ok']);
        $this->assertStringContainsString('PostgreSQL e MySQL/MariaDB', $esito['error']);
        $this->assertSame('', $esito['scratch'], 'Senza motore non si crea nessun database');
    }

    /**
     * Un file assente o da 0 byte va detto subito: non serve creare un
     * database per scoprirlo.
     */
    public function testDumpAssenteNonVieneProvato(): void
    {
        $verifier = new RestoreVerifier(
            [
                'DBDriver' => 'Postgre',
                'hostname' => 'localhost',
                'username' => 'app',
                'database' => 'invoiceplane',
            ],
            sys_get_temp_dir() . '/dump-che-non-esiste-' . bin2hex(random_bytes(4)) . '.dump'
        );

        $esito = $verifier->verify();

        $this->assertFalse($esito['ok']);
        $this->assertStringContainsString('assente o vuoto', $esito['error']);
        $this->assertSame(0, $esito['tables']);
    }

    public function testDumpDaZeroByteNonVieneProvato(): void
    {
        $file = sys_get_temp_dir() . '/dump-vuoto-' . bin2hex(random_bytes(4)) . '.dump';
        file_put_contents($file, '');

        try {
            $verifier = new RestoreVerifier(
                [
                    'DBDriver' => 'MySQLi',
                    'hostname' => 'localhost',
                    'username' => 'app',
                    'database' => 'invoiceplane',
                ],
                $file
            );

            $esito = $verifier->verify();

            $this->assertFalse($esito['ok']);
            $this->assertStringContainsString('assente o vuoto', $esito['error']);
        } finally {
            @unlink($file);
        }
    }
}
