<?php

/**
 * Collaudo delle intestazioni di Apache (compressione e cache) con un server
 * usa e getta.
 *
 * Perché esiste: `public/.htaccess` è l'unico posto dove vivono la
 * compressione e la cache degli asset, e nessuno lo attraversa in sviluppo
 * (`php spark serve` non lo legge) né in CI. Per verificarlo bisognava montare
 * a mano un Apache con `DocumentRoot` sulla `public/`, ricordarsi i moduli e
 * sperare che il php-fpm fosse in ascolto. Qui è un comando:
 *
 *     php tools/apache_headers_check.php
 *
 * Avvia php-fpm su un socket temporaneo e httpd su una porta libera, punta il
 * `DocumentRoot` sulla `public/` del progetto con `AllowOverride All`, aspetta
 * che la pagina di accesso risponda, esegue tests/collaudo/AssetHeadersTest.php
 * con IP_APACHE_BASE_URL impostata, poi spegne tutto e cancella la cartella
 * temporanea — anche quando i test falliscono.
 *
 *     php tools/apache_headers_check.php --base-url=https://staging.example
 *
 * con `--base-url` (o IP_APACHE_BASE_URL già impostata) non avvia niente:
 * interroga quel server. Il `.htaccess` deve essere quello del progetto e
 * Apache deve leggere `AllowOverride All`, altrimenti i test lo diranno con
 * degli header mancanti invece che con un errore di rete.
 *
 *     php tools/apache_headers_check.php --solo-statico
 *
 * con `--solo-statico` non avvia php-fpm e serve solo i file statici: le
 * regole degli asset si verificano lo stesso, e la pagina PHP no (per quella
 * serve un database). È il modo in cui gira in CI, dove non c'è né un database
 * né il PHP dentro Apache — vedi il job `intestazioni-apache` in
 * .github/workflows/ci.yml.
 *
 * Codici di uscita: 0 test verdi, 1 test rossi, 2 collaudo impossibile
 * (manca httpd o php-fpm, o il server indicato non risponde).
 */

$radice = dirname(__DIR__);

$baseUrl     = (string) getenv('IP_APACHE_BASE_URL');
$soloStatico = getenv('IP_APACHE_STATIC_ONLY') === '1';

foreach (array_slice($argv, 1) as $argomento) {
    if (str_starts_with($argomento, '--base-url=')) {
        $baseUrl = substr($argomento, strlen('--base-url='));
    }

    if ($argomento === '--solo-statico') {
        $soloStatico = true;
    }
}

$baseUrl = rtrim(trim($baseUrl), '/');

if ($baseUrl !== '') {
    echo "Server indicato: {$baseUrl}\n";

    exit(eseguiTest($radice, $baseUrl, $soloStatico));
}

// ---------------------------------------------------------------- strumenti

$httpd   = primoEsistente(['/usr/sbin/httpd', '/opt/homebrew/bin/httpd', '/usr/bin/httpd', '/usr/sbin/apache2']);
$phpFpm  = primoEsistente([PHP_BINDIR . '/php-fpm', '/opt/homebrew/sbin/php-fpm', '/usr/sbin/php-fpm', '/usr/sbin/php-fpm8.3', '/usr/sbin/php-fpm8.2']);
$moduli  = primaCartella([
    '/usr/libexec/apache2',
    '/opt/homebrew/opt/httpd/lib/httpd/modules',
    '/usr/lib/apache2/modules',
]);
$tipiMime = primoFile([
    '/private/etc/apache2/mime.types',
    '/etc/apache2/mime.types',
    '/etc/mime.types',
]);

if ($httpd === null || (!$soloStatico && $phpFpm === null)) {
    fwrite(STDERR, "Collaudo impossibile: " . ($httpd === null ? 'httpd' : 'php-fpm') . " non trovato.\n");
    fwrite(STDERR, "Serve Apache con mod_deflate, mod_expires e mod_headers" . ($soloStatico ? ".\n" : ", e php-fpm.\n"));
    fwrite(STDERR, "In alternativa: php tools/apache_headers_check.php --base-url=https://un-server-con-questo-codice\n");

    exit(2);
}

if ($moduli === null) {
    fwrite(STDERR, "Collaudo impossibile: cartella dei moduli di Apache non trovata.\n");

    exit(2);
}

if ($tipiMime === null) {
    fwrite(STDERR, "Collaudo impossibile: mime.types non trovato.\n");

    exit(2);
}

$utente = utenteCorrente();
$porta  = portaLibera();
$tmp    = sys_get_temp_dir() . '/ip-apache-' . getmypid();
$socket = $tmp . '/php-fpm.sock';

@mkdir($tmp, 0777, true);

// ------------------------------------------------------ configurazione httpd

$carica = static function (string $modulo) use ($moduli): string {
    $file = $moduli . '/mod_' . $modulo . '.so';

    return is_file($file) ? 'LoadModule ' . $modulo . "_module {$file}\n" : '';
};

// mpm_event se c'è (è quello degli Apache moderni), altrimenti prefork.
$mpm = is_file($moduli . '/mod_mpm_event.so') ? 'mpm_event' : 'mpm_prefork';

// `User`/`Group` servono solo quando httpd parte da root: senza quel permesso
// Apache non cambia identità e la direttiva non va messa (su un runner CI o sul
// Mac si parte già come utente normale).
$identita = function_exists('posix_geteuid') && posix_geteuid() === 0
    ? "User {$utente}\nGroup " . gruppoCorrente() . "\n\n"
    : '';

$conf = "# Generato da tools/apache_headers_check.php: solo per questo collaudo.\n"
    . "ServerRoot \"{$tmp}\"\n"
    . "Listen 127.0.0.1:{$porta}\n"
    . "ServerName localhost\n"
    . "PidFile {$tmp}/httpd.pid\n"
    . "ErrorLog {$tmp}/error.log\n"
    . "LogLevel warn\n"
    . "DefaultRuntimeDir {$tmp}\n\n"
    . $carica('unixd')
    . $carica($mpm)
    . $carica('authz_core') . $carica('authz_host') . $carica('authn_core')
    . $carica('dir') . $carica('mime')
    . $carica('filter') . $carica('deflate') . $carica('setenvif')
    . $carica('headers') . $carica('expires')
    . $carica('rewrite')
    . $carica('proxy') . $carica('proxy_fcgi')
    . "\nTypesConfig {$tipiMime}\n"
    . "DirectoryIndex index.php index.html\n\n"
    . $identita
    . 'DocumentRoot "' . $radice . "/public\"\n\n"
    . '<Directory "' . $radice . "/public\">\n"
    . "    Options FollowSymLinks\n"
    . "    AllowOverride All\n"
    . "    Require all granted\n\n"
    // In modalità solo statica il PHP non è collegato: i .php restano file
    // da scaricare, e i test non li chiedono.
    . ($soloStatico ? '' : "    <FilesMatch \"\\.php$\">\n"
        . "        SetHandler \"proxy:unix:{$socket}|fcgi://localhost/\"\n"
        . "    </FilesMatch>\n")
    . "</Directory>\n";

file_put_contents($tmp . '/httpd.conf', $conf);

$fpm = "[global]\n"
    . "pid = {$tmp}/php-fpm.pid\n"
    . "error_log = {$tmp}/php-fpm.log\n"
    . "daemonize = yes\n\n"
    . "[www]\n"
    . "listen = {$socket}\n"
    . "listen.mode = 0666\n"
    . "user = {$utente}\n"
    . "group = " . gruppoCorrente() . "\n"
    . "pm = static\n"
    . "pm.max_children = 2\n"
    . "catch_workers_output = yes\n"
    . "php_admin_value[error_log] = {$tmp}/php-error.log\n"
    . "php_admin_flag[log_errors] = on\n";

file_put_contents($tmp . '/php-fpm.conf', $fpm);

// ------------------------------------------------------------ avvio e stop

// Se il server non parte, la configurazione generata resta sul disco: è
// l'unico modo per capire quale direttiva non è andata giù.
$conserva = false;

$spegni = static function () use ($httpd, $tmp): void {
    if (is_file($tmp . '/httpd.pid')) {
        exec(escapeshellarg($httpd) . ' -f ' . escapeshellarg($tmp . '/httpd.conf') . ' -k stop 2>/dev/null');
    }

    $pid = is_file($tmp . '/php-fpm.pid') ? (int) trim((string) file_get_contents($tmp . '/php-fpm.pid')) : 0;

    if ($pid > 0 && function_exists('posix_kill')) {
        @posix_kill($pid, 15);
    }
};

register_shutdown_function(static function () use ($spegni, $tmp, &$conserva): void {
    $spegni();

    // Un attimo per far chiudere i processi, poi si toglie tutto.
    usleep(300000);

    if ($conserva) {
        return;
    }

    foreach (glob($tmp . '/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($tmp);
});

if (!$soloStatico) {
    exec(escapeshellarg((string) $phpFpm) . ' -y ' . escapeshellarg($tmp . '/php-fpm.conf') . ' 2>&1', $uscitaFpm, $statoFpm);

    if ($statoFpm !== 0) {
        fwrite(STDERR, "php-fpm non è partito:\n" . implode("\n", $uscitaFpm) . "\n");

        exit(2);
    }

    // Il socket deve esistere prima che httpd provi a usarlo.
    for ($i = 0; $i < 50 && !file_exists($socket); $i++) {
        usleep(100000);
    }

    if (!file_exists($socket)) {
        fwrite(STDERR, "php-fpm non ha creato il socket {$socket}\n");

        exit(2);
    }
}

exec(escapeshellarg($httpd) . ' -f ' . escapeshellarg($tmp . '/httpd.conf') . ' -k start 2>&1', $uscitaHttpd, $statoHttpd);

if ($statoHttpd !== 0) {
    fwrite(STDERR, "httpd non è partito:\n" . implode("\n", $uscitaHttpd) . "\n");

    exit(2);
}

$baseUrl = "http://127.0.0.1:{$porta}";

echo "Apache su {$baseUrl} (DocumentRoot public/, AllowOverride All" . ($soloStatico ? ', solo file statici' : '') . ")\n";

$prova = $soloStatico ? '/assets/core/css/custom.min.css' : '/sessions/login';

if (!attendiServer($baseUrl . $prova, 20)) {
    fwrite(STDERR, "Il server non risponde su {$baseUrl}.\n\n");
    fwrite(STDERR, "error.log:\n" . codaLog($tmp . '/error.log') . "\n\n");
    fwrite(STDERR, "php-error.log:\n" . codaLog($tmp . '/php-error.log') . "\n\n");
    $conserva = true;

    fwrite(STDERR, "La configurazione generata è in {$tmp} e resta per capire cosa non va.\n");

    exit(2);
}

echo "Server pronto, avvio i test.\n\n";

$codice = eseguiTest($radice, $baseUrl, $soloStatico);

exit($codice);

// --------------------------------------------------------------- funzioni

function eseguiTest(string $radice, string $baseUrl, bool $soloStatico = false): int
{
    $comando = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg($radice . '/vendor/bin/phpunit') . ' --no-coverage '
        . escapeshellarg($radice . '/tests/collaudo/AssetHeadersTest.php');

    // putenv() prima di proc_open: il figlio eredita così anche tutto il resto
    // dell'ambiente (è il PATH che serve a PHPUnit per npx e simili).
    putenv('IP_APACHE_BASE_URL=' . $baseUrl);
    putenv('IP_APACHE_STATIC_ONLY=' . ($soloStatico ? '1' : '0'));

    $processo = proc_open($comando, [1 => STDOUT, 2 => STDERR], $condotti, $radice);

    if (!is_resource($processo)) {
        fwrite(STDERR, "Non riesco a lanciare PHPUnit.\n");

        return 2;
    }

    return proc_close($processo);
}

function attendiServer(string $url, int $secondi): bool
{
    for ($i = 0; $i < $secondi * 4; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        curl_exec($ch);
        $stato = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($stato >= 200 && $stato < 400) {
            return true;
        }

        usleep(250000);
    }

    return false;
}

/**
 * Le ultime righe di un log, per capire perché il server non risponde.
 */
function codaLog(string $file, int $righe = 15): string
{
    if (!is_file($file)) {
        return '(assente) ' . $file;
    }

    $contenuto = (string) file_get_contents($file);
    $elenco    = array_slice(explode("\n", trim($contenuto)), -$righe);

    return implode("\n", $elenco);
}

function portaLibera(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errore, $messaggio);

    if ($socket === false) {
        throw new RuntimeException('Nessuna porta libera: ' . $messaggio);
    }

    $nome = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr((string) strrchr((string) $nome, ':'), 1);
}

/** @param list<string> $candidati */
function primoEsistente(array $candidati): ?string
{
    foreach ($candidati as $candidato) {
        if (is_file($candidato) && is_executable($candidato)) {
            return $candidato;
        }
    }

    return null;
}

/** @param list<string> $candidati */
function primoFile(array $candidati): ?string
{
    foreach ($candidati as $candidato) {
        if (is_file($candidato)) {
            return $candidato;
        }
    }

    return null;
}

/** @param list<string> $candidati */
function primaCartella(array $candidati): ?string
{
    foreach ($candidati as $candidato) {
        if (is_dir($candidato)) {
            return $candidato;
        }
    }

    return null;
}

function utenteCorrente(): string
{
    if (function_exists('posix_getpwuid')) {
        $info = posix_getpwuid(posix_geteuid());

        if (is_array($info) && isset($info['name'])) {
            return (string) $info['name'];
        }
    }

    return (string) (getenv('USER') ?: 'www-data');
}

function gruppoCorrente(): string
{
    if (function_exists('posix_getgrgid')) {
        $info = posix_getgrgid(posix_getegid());

        if (is_array($info) && isset($info['name'])) {
            return (string) $info['name'];
        }
    }

    return (string) (getenv('USER') ?: 'www-data');
}
