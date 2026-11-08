<?php
// Connessione al database con il bootstrap di CodeIgniter 4.7 (sola lettura).
//
// Il vecchio script caricava system/bootstrap.php, rimosso da CodeIgniter 4.5:
// quel file ora stampa l'avviso di upgrade e interrompe il boot, quindi
// ENVIRONMENT non veniva mai definita e il controllo moriva nell'exception
// handler. Stesso bootstrap di tag_check.php, che invece funziona.
define('FCPATH', __DIR__ . '/public/');
if (getcwd() . DIRECTORY_SEPARATOR !== FCPATH) {
    chdir(FCPATH);
}
require FCPATH . '../app/Config/Paths.php';
$paths = new Config\Paths();
require $paths->systemDirectory . '/Boot.php';
foreach (['appDirectory' => 'APPPATH', 'systemDirectory' => 'SYSTEMPATH', 'writableDirectory' => 'WRITEPATH', 'testsDirectory' => 'TESTPATH'] as $dir => $const) {
    if (! defined($const)) {
        define($const, realpath(rtrim($paths->{$dir}, '\\/ ')) . DIRECTORY_SEPARATOR);
    }
}
define('ROOTPATH', realpath(APPPATH . '../') . DIRECTORY_SEPARATOR);
define('ENVIRONMENT', $_SERVER['CI_ENVIRONMENT'] ?? 'production');
\CodeIgniter\Boot::bootTest($paths);
$app = service('codeigniter');
$app->initialize();
$app->setContext(is_cli() ? 'php-cli' : 'web');

$db = \Config\Database::connect();

$row = $db->query('SELECT 1 AS ok')->getRowArray();

// Il driver PostgreSQL può restituire il valore come stringa: il confronto
// stretto con l'intero faceva fallire il probe su una connessione sana.
if ((int) ($row['ok'] ?? 0) !== 1) {
    fwrite(STDERR, "Connessione al database: FALLITA\n");
    exit(1);
}

$company = $db->table('ip_companies')->select('company_name')->orderBy('company_id', 'ASC')->get(1)->getRowArray();

echo 'Connessione al database: OK'
    . ' (' . $db->getPlatform() . ', ' . $db->getDatabase() . ")\n"
    . 'Azienda predefinita: ' . ($company['company_name'] ?? '—') . "\n";
