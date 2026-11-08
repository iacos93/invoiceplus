<?php
// Smoke test: istanzia tutti i controller e verifica i metodi delle route
require __DIR__ . '/vendor/autoload.php';

$routesFile = file_get_contents(__DIR__ . '/app/Config/Routes.php');
preg_match_all('/\$routes->(?:get|post|put|patch|delete)\([^,]+,\s*\'([A-Za-z0-9_]+)::([A-Za-z0-9_]+)/', $routesFile, $m, PREG_SET_ORDER);

$errors = [];
$checked = [];

foreach ($m as $r) {
    $class = 'App\\Controllers\\' . $r[1];
    $method = $r[2];
    $key = $class . '::' . $method;
    if (isset($checked[$key])) {
        continue;
    }
    $checked[$key] = true;

    if (!class_exists($class)) {
        $errors[] = "CLASSE MANCANTE: $class";
        continue;
    }
    if (!method_exists($class, $method)) {
        $errors[] = "METODO MANCANTE: $key";
    }
}

// Verifica anche tutte le classi model
foreach (glob(__DIR__ . '/../app/Models/*.php') as $f) {
    $name = basename($f, '.php');
    $class = 'App\\Models\\' . $name;
    if (!class_exists($class)) {
        $errors[] = "MODEL NON CARICABILE: $class";
    }
}

echo count($checked) . " route uniche verificate\n";
if ($errors) {
    echo "ERRORI:\n" . implode("\n", $errors) . "\n";
    exit(1);
}
echo "TUTTO OK\n";