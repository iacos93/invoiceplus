<?php
/**
 * Soglia minima di copertura, letta dal clover prodotto da PHPUnit.
 *
 * La copertura senza una soglia è un numero che si guarda una volta: qui
 * diventa un controllo che fallisce se una modifica importante smette di
 * essere esercitata dai test. La soglia sta sotto il valore attuale di
 * proposito: deve cogliere un crollo, non impedire ogni refactoring.
 *
 * Uso: php tools/coverage_gate.php [soglia_percentuale]
 */

$soglia = (float) ($argv[1] ?? 45.0);
$file   = __DIR__ . '/../build/logs/clover.xml';

if (! is_file($file)) {
    fwrite(STDERR, "Copertura non trovata in build/logs/clover.xml: esegui prima PHPUnit con --coverage-clover.\n");
    exit(2);
}

$xml = simplexml_load_file($file);

if ($xml === false) {
    fwrite(STDERR, "clover.xml non leggibile.\n");
    exit(2);
}

$totale   = 0;
$coperte  = 0;

// Il conteggio guarda le righe di codice (type="stmt"), non i metodi: è la
// misura che si confronta con il report --coverage-text di PHPUnit.
foreach ($xml->xpath('//line[@type="stmt"]') ?: [] as $line) {
    $totale++;

    if ((int) $line['count'] > 0) {
        $coperte++;
    }
}

if ($totale === 0) {
    fwrite(STDERR, "Nessuna riga misurabile nel clover: il file è vuoto o incompleto.\n");
    exit(2);
}

$percentuale = 100 * $coperte / $totale;

printf("Copertura righe: %.2f%% (%d/%d), soglia %.1f%%\n", $percentuale, $coperte, $totale, $soglia);

if ($percentuale < $soglia) {
    fwrite(STDERR, "Copertura sotto la soglia: aggiungi test o abbassa la soglia con una motivazione.\n");
    exit(1);
}

exit(0);
