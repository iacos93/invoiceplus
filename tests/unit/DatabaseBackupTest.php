<?php

use App\Libraries\DatabaseBackup;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il backup del database: motore giusto, comando giusto, rotazione.
 *
 * L'endpoint lanciava sempre pg_dump: su un'installazione MariaDB falliva
 * parlando di PostgreSQL. Il dump vero (che dipende dai binari installati) si
 * prova nel collaudo; qui si controlla la parte che può sbagliare in silenzio,
 * cioè gli argomenti e la scelta del motore.
 *
 * @internal
 */
final class DatabaseBackupTest extends CIUnitTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/ip-backup-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);

        parent::tearDown();
    }

    public function testRiconosceIlMotoreDalDriver(): void
    {
        $this->assertSame(DatabaseBackup::ENGINE_POSTGRES, DatabaseBackup::engineFor('Postgre'));
        $this->assertSame(DatabaseBackup::ENGINE_POSTGRES, DatabaseBackup::engineFor('PostgreSQL'));
        $this->assertSame(DatabaseBackup::ENGINE_MYSQL, DatabaseBackup::engineFor('MySQLi'));
        $this->assertSame(DatabaseBackup::ENGINE_MYSQL, DatabaseBackup::engineFor('MariaDB'));
        $this->assertNull(DatabaseBackup::engineFor('SQLite3'));
        $this->assertNull(DatabaseBackup::engineFor(''));
    }

    /**
     * SQLite non ha un binario di dump: meglio un errore che dice perché,
     * invece di un pg_dump lanciato contro un file.
     */
    public function testMotoreNonSupportatoSpiegaIlMotivo(): void
    {
        $backup = new DatabaseBackup(['DBDriver' => 'SQLite3', 'database' => 'db.sqlite'], $this->dir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PostgreSQL e MySQL/MariaDB');

        $backup->dump();
    }

    public function testComandoPostgresUsaFormatoCustomEEscludeLaPassword(): void
    {
        $backup = new DatabaseBackup([
            'DBDriver' => 'Postgre',
            'hostname' => 'db.interno',
            'port'     => 5433,
            'username' => 'app',
            'password' => 'password-segreta',
            'database' => 'invoiceplane',
        ], $this->dir);

        $comando = $backup->command(DatabaseBackup::ENGINE_POSTGRES, '/usr/bin/pg_dump', '/tmp/backup.dump');

        $this->assertSame('PGPASSWORD', $comando['name'], 'La password passa dall\'ambiente, non dalla riga di comando');
        $this->assertStringContainsString("'-Fc'", $comando['command'], 'Formato custom per pg_restore');
        $this->assertStringContainsString("'--no-owner'", $comando['command']);
        $this->assertStringContainsString("'--no-acl'", $comando['command']);
        $this->assertStringContainsString("'-h' 'db.interno'", $comando['command']);
        $this->assertStringContainsString("'-p' '5433'", $comando['command']);
        $this->assertStringContainsString("'-U' 'app'", $comando['command']);
        $this->assertStringContainsString("'-f' '/tmp/backup.dump'", $comando['command']);
        $this->assertStringContainsString("'invoiceplane'", $comando['command']);
        $this->assertStringNotContainsString('password-segreta', $comando['command']);
    }

    public function testComandoMysqldumpECoerenteESenzaPassword(): void
    {
        $backup = new DatabaseBackup([
            'DBDriver' => 'MySQLi',
            'hostname' => '127.0.0.1',
            'port'     => 3307,
            'username' => 'app',
            'password' => 'password-segreta',
            'database' => 'invoiceplane',
        ], $this->dir);

        $comando = $backup->command(DatabaseBackup::ENGINE_MYSQL, '/usr/bin/mysqldump', '/tmp/backup.sql');

        $this->assertSame('MYSQL_PWD', $comando['name']);
        $this->assertStringContainsString("'--single-transaction'", $comando['command'], 'Dump coerente senza bloccare le scritture');
        $this->assertStringContainsString("'--quick'", $comando['command']);
        $this->assertStringContainsString("'--default-character-set=utf8mb4'", $comando['command']);
        $this->assertStringContainsString("'--host=127.0.0.1'", $comando['command']);
        $this->assertStringContainsString("'--port=3307'", $comando['command']);
        $this->assertStringContainsString("'--user=app'", $comando['command']);
        $this->assertStringContainsString("'--result-file=/tmp/backup.sql'", $comando['command']);
        $this->assertStringContainsString("'invoiceplane'", $comando['command']);
        $this->assertStringNotContainsString('password-segreta', $comando['command']);
    }

    /**
     * Un nome con spazi o apici non deve spezzare il comando: è il caso di un
     * database creato con un nome leggibile.
     */
    public function testNomiConSpaziVengonoQuotati(): void
    {
        $backup = new DatabaseBackup([
            'DBDriver' => 'Postgre',
            'hostname' => 'localhost',
            'username' => 'app',
            'database' => "db dell'ufficio",
        ], $this->dir);

        $comando = $backup->command(DatabaseBackup::ENGINE_POSTGRES, '/usr/bin/pg_dump', '/tmp/backup.dump');

        $this->assertStringContainsString(escapeshellarg("db dell'ufficio"), $comando['command']);
    }

    /**
     * Con username vuoto la connessione usa l'utente di sistema: passare
     * `-U ''` è una cosa diversa, e su alcune configurazioni non funziona.
     */
    public function testUtenteVuotoNonVienePassatoAllaRigaDiComando(): void
    {
        $pg = new DatabaseBackup([
            'DBDriver' => 'Postgre',
            'hostname' => 'localhost',
            'username' => '',
            'database' => 'invoiceplane',
        ], $this->dir);

        $this->assertStringNotContainsString(
            "'-U'",
            $pg->command(DatabaseBackup::ENGINE_POSTGRES, '/usr/bin/pg_dump', '/tmp/b.dump')['command']
        );

        $my = new DatabaseBackup([
            'DBDriver' => 'MySQLi',
            'hostname' => 'localhost',
            'username' => '',
            'database' => 'invoiceplane',
        ], $this->dir);

        $this->assertStringNotContainsString(
            "'--user='",
            $my->command(DatabaseBackup::ENGINE_MYSQL, '/usr/bin/mysqldump', '/tmp/b.sql')['command']
        );
    }

    /**
     * I dump contengono dati fiscali e personali: devono nascere chiusi
     * (0600), non 0644 come li lascia l'umask del processo. Il permesso si
     * imposta dopo che il binario ha scritto il file e prima della rotazione.
     */
    public function testIlDumpVieneScrittoConPermessiRistretti(): void
    {
        $bin = $this->dir . '/finto-mysqldump';
        file_put_contents($bin, "#!/bin/sh\n"
            . "for arg in \"\$@\"; do\n"
            . "  case \"\$arg\" in\n"
            . "    --result-file=*) printf 'dump di prova' > \"\${arg#--result-file=}\" ;;\n"
            . "  esac\n"
            . "done\n"
            . "exit 0\n");
        chmod($bin, 0755);

        putenv('backup.mysqldumpPath=' . $bin);

        try {
            $backup = new DatabaseBackup([
                'DBDriver' => 'MySQLi',
                'hostname' => 'localhost',
                'username' => 'app',
                'database' => 'invoiceplane',
            ], $this->dir);

            $esito = $backup->dump();
        } finally {
            putenv('backup.mysqldumpPath');
        }

        $this->assertFileExists($esito['file']);
        $this->assertSame(0600, fileperms($esito['file']) & 0777, 'Il dump deve essere leggibile solo dall\'utente del processo PHP');
    }

    public function testRotazioneTieneGliUltimiDump(): void
    {
        foreach (range(1, 16) as $n) {
            file_put_contents(sprintf('%s/backup_202611%02d_030000.dump', $this->dir, $n), 'x');
        }

        $rimossi = DatabaseBackup::rotate($this->dir, 'backup_', '.dump', 14);

        $this->assertSame(2, $rimossi, 'Due dump oltre i 14 devono sparire');

        $rimasti = glob($this->dir . '/backup_*.dump') ?: [];
        sort($rimasti, SORT_STRING);

        $this->assertCount(14, $rimasti);
        $this->assertSame($this->dir . '/backup_20261103_030000.dump', $rimasti[0], 'Devono restare i più recenti');
        $this->assertSame($this->dir . '/backup_20261116_030000.dump', $rimasti[13]);
    }

    /**
     * Cambiare motore non deve far contare i .dump di PostgreSQL fra i .sql di
     * MySQL, altrimenti i backup nuovi spingerebbero fuori quelli vecchi
     * dell'altro motore (o viceversa) senza che siano confrontabili.
     */
    public function testRotazioneIgnoraIFormatiDellAltroMotore(): void
    {
        foreach (range(1, 15) as $n) {
            file_put_contents(sprintf('%s/backup_202611%02d_030000.dump', $this->dir, $n), 'x');
        }

        file_put_contents($this->dir . '/backup_20261120_030000.sql', 'x');

        $this->assertSame(0, DatabaseBackup::rotate($this->dir, 'backup_', '.sql', 14), 'Il .sql è l\'unico del suo formato');
        $this->assertCount(15, glob($this->dir . '/backup_*.dump') ?: [], 'I dump dell\'altro motore non si toccano');
    }
}
