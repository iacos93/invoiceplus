<?php
// Verifica i tag {{...}} del Mailer contro dati reali (sola lettura).
define('FCPATH', dirname(__DIR__) . '/public/');
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
service('session')->start();
$app->initialize();

use App\Controllers\Mailer;
use App\Models\InvoiceModel;
use App\Models\QuoteModel;

$mailer = new Mailer();
$get = function (string $method, array $arg) use ($mailer) {
    $ref = new ReflectionMethod($mailer, $method);
    return $ref->invoke($mailer, $arg);
};

$allTags = implode(' ', array_map(
    fn($t) => '{{' . $t . '}}',
    ['client_name', 'client_surname', 'client_fullname', 'client_address_1', 'client_address_2',
     'client_city', 'client_state', 'client_zip', 'client_country', 'client_phone', 'client_fax',
     'client_mobile', 'client_email', 'client_web', 'client_vat_id', 'client_tax_code',
     'user_name', 'user_company',
     'user_address_1', 'user_address_2', 'user_city', 'user_state', 'user_zip', 'user_country',
     'user_phone', 'user_fax', 'user_mobile', 'user_email', 'user_web',
     'user_iban', 'invoice_number', 'invoice_date_created',
     'invoice_date_due', 'invoice_status', 'invoice_item_subtotal', 'invoice_item_tax_total',
     'invoice_tax_total', 'invoice_total', 'invoice_paid', 'invoice_balance', 'invoice_terms',
     'invoice_guest_url',
     'ip_cf_1', 'ip_cf_999', 'tag_inesistente']
));

$problems = 0;

$db = \Config\Database::connect();
$row = $db->table('ip_invoices')->select('invoice_id')->limit(1)->get()->getRowArray();
$invoiceId = $row ? (int) $row['invoice_id'] : 0;

$company = $db->table('ip_companies')->select('company_id')->limit(1)->get()->getRowArray();
if ($company) {
    session()->set('active_company_id', (int) $company['company_id']);
}

try {
    $invoice = (new InvoiceModel())->getById($invoiceId);
} catch (\Throwable $e) {
    echo "EXC fattura: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $invoice = null;
}
if ($invoice) {
    $data = $get('invoiceTemplateData', [$invoice]);
    $out  = $mailer->parseTemplate($allTags, $data);
    foreach (['invoice_guest_url', 'invoice_status', 'invoice_item_subtotal', 'invoice_terms', 'ip_cf_1'] as $tag) {
        $unresolved = preg_match('/\{\{' . $tag . '\}\}/', $out, $m) === 1;
        if (!$unresolved) {
            echo "invoice  $tag: ok\n";
            continue;
        }
        $table = ['ip_cf_1' => 'ip_invoice_custom'][$tag] ?? null;
        if ($table !== null && $db->table($table)->countAllResults() === 0) {
            echo "invoice  $tag: NON RISOLTO (nessun dato nel DB, atteso)\n";
        } else {
            echo "invoice  $tag: NON RISOLTO\n";
            $problems++;
        }
    }
    if (!str_contains($out, '{{tag_inesistente}}')) { echo "invoice  tag_inesistente: SOSTITUITO (bug)\n"; $problems++; }
    else { echo "invoice  tag_inesistente: ok (resta visibile)\n"; }
} else {
    echo "nessuna fattura id=$invoiceId\n";
}

$quote = (new QuoteModel())->getByIdWithJoins(1);
if ($quote) {
    $data = $get('quoteTemplateData', [$quote]);
    $out  = $mailer->parseTemplate($allTags, $data);
    foreach (['quote_guest_url', 'quote_item_subtotal', 'quote_tax_total'] as $tag) {
        $unresolved = preg_match('/\{\{' . $tag . '\}\}/', $out) === 1;
        echo "quote    $tag: " . ($unresolved ? 'NON RISOLTO' : 'ok') . "\n";
        if ($unresolved) { $problems++; }
    }
    if (preg_match('/\{\{ip_cf_1\}\}/', $out)) {
        echo "quote    ip_cf_1: NON RISOLTO (nessun valore custom nel DB, atteso)\n";
    } else {
        echo "quote    ip_cf_1: ok\n";
    }
} else {
    echo "nessun preventivo id=1\n";
}

echo $problems === 0 ? "TUTTO OK\n" : "PROBLEMI: $problems\n";

// Verifica logica di mappatura con dati sintetici (senza DB)
$refCustom = new ReflectionMethod($mailer, 'customTags');
$tags = $refCustom->invoke($mailer, [
    ['invoice_custom_fieldid' => 7, 'invoice_custom_fieldvalue' => 'ABC'],
    ['invoice_custom_fieldid' => 12, 'invoice_custom_fieldvalue' => null],
], 'invoice_custom_fieldid', 'invoice_custom_fieldvalue');
$ok = ($tags['ip_cf_7'] ?? '') === 'ABC' && isset($tags['ip_cf_12']) && $tags['ip_cf_12'] === '';
echo $ok ? "customTags: ok\n" : "customTags: ERRORE\n";

$refCut = new ReflectionMethod($mailer, 'clientUserTags');
$cut = $refCut->invoke($mailer, [
    'client_name' => 'Mario', 'client_surname' => 'Rossi', 'client_city' => 'Roma',
    'user_name' => 'admin', 'client_id' => 0, 'user_id' => 0,
]);
$ok = ($cut['client_fullname'] ?? '') === 'Mario Rossi'
    && ($cut['client_city'] ?? '') === 'Roma'
    && ($cut['user_name'] ?? '') === 'admin';
echo $ok ? "clientUserTags: ok\n" : "clientUserTags: ERRORE\n";

exit($problems === 0 && $ok ? 0 : 1);
