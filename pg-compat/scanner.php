<?php
/**
 * PostgreSQL Compatibility Scanner (LOCKED — do not modify during the loop)
 *
 * Scans app/ for MySQL-isms that crash or misbehave on PostgreSQL.
 * Outputs findings as TSV to stdout. Exit code 0 = clean run (may have findings).
 *
 * Usage: php pg-compat/scanner.php [--json]
 */

$appDir = __DIR__ . '/../app';
$outputJson = in_array('--json', $argv ?? [], true);

$patterns = [
    // P1: empty string on date/numeric columns in builder calls
    [
        'id'    => 'P1-builder',
        'risk'  => 'HIGH',
        'desc'  => 'Empty string comparison on non-text column via builder',
        'regex' => '/->(?:where|orWhere|having|orHaving)\s*\(\s*[\'"]([^"\']*(?:_date|_amount|_total|_balance|_order|_id|_days|_count|_quantity|_price|_discount|_rate|_number|_tax|_paid)[^"\']*)[\'"],\s*[\'"][\'"]/',
    ],
    // P1-raw: empty string on date/numeric columns in raw SQL strings
    [
        'id'    => 'P1-raw',
        'risk'  => 'HIGH',
        'desc'  => 'Empty string comparison on non-text column in raw SQL',
        'regex' => '/(?:_date|_amount|_total|_balance|_order|_days|_count|_quantity|_price|_discount|_rate|_number|_tax|_paid)\s*(?:=|!=|<>)\s*\'\'/',
        'context_filter' => 'sql_string',
    ],
    // P2: double-quoted string literal in SQL passed to builder or query()
    [
        'id'    => 'P2',
        'risk'  => 'HIGH',
        'desc'  => 'Double-quoted string literal in SQL (PG reads as identifier)',
        'regex' => '/(?:CONCAT|CONCAT_WS|REPLACE|TRIM|SUBSTR|SUBSTRING|UPPER|LOWER|LPAD|RPAD)\s*\([^)]*"[^"]*"/',
        'context_filter' => 'sql_string',
    ],
    // P3: HAVING / orHaving on computed aliases
    [
        'id'    => 'P3',
        'risk'  => 'MEDIUM',
        'desc'  => 'HAVING on column alias (PG < 16 rejects)',
        'regex' => '/->(?:having|orHaving)\s*\(/',
    ],
    // P5: MySQL-only functions
    [
        'id'    => 'P5',
        'risk'  => 'HIGH',
        'desc'  => 'MySQL-only SQL function',
        'regex' => '/\b(?:IFNULL|DATE_FORMAT|CURDATE|DATEDIFF|RAND|GROUP_CONCAT|UNIX_TIMESTAMP|STR_TO_DATE)\s*\(/',
        'context_filter' => 'sql_string',
    ],
];

// Column-name suffixes that are always text type (to reduce P1 false positives)
$textSuffixes = ['_name', '_surname', '_email', '_url', '_path', '_file', '_token',
    '_key', '_label', '_note', '_memo', '_desc', '_description', '_type',
    '_method', '_address', '_city', '_state', '_country', '_zip', '_phone',
    '_fax', '_web', '_vat', '_code', '_language', '_format', '_password',
    '_hash', '_salt', '_status', '_period', '_reference', '_subject'];

$findings = [];

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') continue;
    $relPath = str_replace(dirname($appDir) . '/', '', $file->getPathname());
    $lines = file($file->getPathname());

    foreach ($patterns as $pat) {
        foreach ($lines as $lineNum => $line) {
            if (!preg_match($pat['regex'], $line, $m)) continue;

            // Context filter: skip if the match is clearly not in SQL context
            if (isset($pat['context_filter']) && $pat['context_filter'] === 'sql_string') {
                // Must be inside a ->select(), ->query(), $where .=, or similar
                $surroundingContext = implode("\n", array_slice($lines, max(0, $lineNum - 3), 7));
                if (!preg_match('/->(?:select|where|orWhere|having|orHaving|join|query|groupBy|orderBy)\s*\(|->db->query\s*\(|\$(?:where|sql|joinClient)\s*\.?=/', $surroundingContext)) {
                    continue;
                }
            }

            // P1: skip if the column name ends with a known text suffix
            if (str_starts_with($pat['id'], 'P1') && isset($m[1])) {
                $colName = $m[1];
                $isText = false;
                foreach ($textSuffixes as $suffix) {
                    if (str_ends_with(strtolower($colName), $suffix)) {
                        $isText = true;
                        break;
                    }
                }
                if ($isText) continue;
            }

            $findings[] = [
                'file'    => $relPath,
                'line'    => $lineNum + 1,
                'pattern' => $pat['id'],
                'risk'    => $pat['risk'],
                'desc'    => $pat['desc'],
                'match'   => trim($line),
            ];
        }
    }
}

// Sort: HIGH first, then MEDIUM, then LOW
$riskOrder = ['HIGH' => 0, 'MEDIUM' => 1, 'LOW' => 2];
usort($findings, function ($a, $b) use ($riskOrder) {
    return ($riskOrder[$a['risk']] ?? 3) <=> ($riskOrder[$b['risk']] ?? 3)
        ?: strcmp($a['file'], $b['file'])
        ?: $a['line'] <=> $b['line'];
});

if ($outputJson) {
    echo json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "file\tline\tpattern\trisk\tdesc\tmatch\n";
    foreach ($findings as $f) {
        echo implode("\t", [
            $f['file'], $f['line'], $f['pattern'], $f['risk'], $f['desc'],
            substr($f['match'], 0, 120),
        ]) . "\n";
    }
}

fprintf(STDERR, "Scanner complete: %d finding(s) across %d pattern(s).\n",
    count($findings), count($patterns));
