<?php

namespace App\Libraries;

/**
 * Lettura di un estratto conto.
 *
 * Due formati, che coprono quasi tutte le banche italiane:
 *   - CSV      quello che si scarica dall'home banking; le colonne cambiano da
 *              banca a banca, quindi si riconoscono dall'intestazione
 *   - CAMT.053 lo standard ISO 20022, uguale ovunque
 *
 * Ritorna sempre movimenti normalizzati: importo positivo in entrata, negativo
 * in uscita, così a valle non serve sapere da dove arrivano.
 *
 * @phpstan-type Movement array{date: string, amount: float, description: string}
 */
class StatementImporter
{
    /** Intestazioni riconosciute, in minuscolo e senza accenti. */
    private const DATE_HEADERS = ['data', 'data operazione', 'data contabile', 'date', 'booking date', 'data valuta'];
    private const DESC_HEADERS = ['descrizione', 'causale', 'descrizione operazione', 'description', 'dettagli', 'note'];
    private const AMOUNT_HEADERS = ['importo', 'amount', 'importo (eur)', 'valore'];
    private const DEBIT_HEADERS = ['uscite', 'dare', 'addebiti', 'debit'];
    private const CREDIT_HEADERS = ['entrate', 'avere', 'accrediti', 'credit'];

    /**
     * @return list<array{date: string, amount: float, description: string}>
     */
    public function parse(string $path, string $originalName): array
    {
        $raw = (string) @file_get_contents($path);

        if (trim($raw) === '') {
            return [];
        }

        if (stripos($raw, '<Document') !== false || stripos($raw, 'BkToCstmrStmt') !== false) {
            return $this->parseCamt($raw);
        }

        return $this->parseCsv($raw);
    }

    /* ------------------------------------------------------------ CAMT.053 */

    /**
     * @return list<array{date: string, amount: float, description: string}>
     */
    private function parseCamt(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        // I namespace cambiano con la versione dello standard: rimuoverli
        // evita di doverli registrare uno per uno.
        // LIBXML_NONET: stesso hardening di FatturaPreview per XML di
        // provenienza esterna (qui l'estratto conto importato) -- niente
        // richieste di rete innescate dal parser.
        $flat = simplexml_load_string(preg_replace('/(<\/?)[a-zA-Z0-9]+:/', '$1', $xml), \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($flat === false) {
            return [];
        }

        $movements = [];

        foreach ($flat->xpath('//Ntry') ?: [] as $entry) {
            $amount = (float) ($entry->Amt ?? 0);

            // CdtDbtInd dice il verso: DBIT è un'uscita.
            if (strtoupper((string) ($entry->CdtDbtInd ?? '')) === 'DBIT') {
                $amount = -$amount;
            }

            $date = (string) ($entry->BookgDt->Dt ?? $entry->ValDt->Dt ?? '');

            $description = trim((string) (
                $entry->NtryDtls->TxDtls->RmtInf->Ustrd
                ?? $entry->AddtlNtryInf
                ?? ''
            ));

            if ($date === '') {
                continue;
            }

            $movements[] = [
                'date'        => substr($date, 0, 10),
                'amount'      => round($amount, 2),
                'description' => $description,
            ];
        }

        return $movements;
    }

    /* ----------------------------------------------------------------- CSV */

    /**
     * @return list<array{date: string, amount: float, description: string}>
     */
    private function parseCsv(string $raw): array
    {
        $raw = $this->stripBom($raw);
        $delimiter = $this->detectDelimiter($raw);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $raw);
        rewind($handle);

        $map       = null;
        $movements = [];

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }

            if ($map === null) {
                $map = $this->mapColumns($row);

                // Molte banche mettono righe di intestazione decorative prima
                // di quella vera: si continua a cercare finché non si trova.
                if ($map === null) {
                    continue;
                }

                continue;
            }

            $movement = $this->rowToMovement($row, $map);

            if ($movement !== null) {
                $movements[] = $movement;
            }
        }

        fclose($handle);

        return $movements;
    }

    /**
     * @return array{date: int, description: int, amount: int|null, debit: int|null, credit: int|null}|null
     */
    private function mapColumns(array $header): ?array
    {
        $normalized = array_map(
            static fn($cell) => strtolower(trim((string) $cell)),
            $header
        );

        $find = static function (array $candidates) use ($normalized): ?int {
            foreach ($normalized as $index => $value) {
                if (in_array($value, $candidates, true)) {
                    return $index;
                }
            }

            return null;
        };

        $date = $find(self::DATE_HEADERS);

        if ($date === null) {
            return null;
        }

        $amount = $find(self::AMOUNT_HEADERS);
        $debit  = $find(self::DEBIT_HEADERS);
        $credit = $find(self::CREDIT_HEADERS);

        // Serve almeno una colonna importo, in una delle due forme: unica con
        // segno, oppure entrate/uscite separate.
        if ($amount === null && $debit === null && $credit === null) {
            return null;
        }

        return [
            'date'        => $date,
            'description' => $find(self::DESC_HEADERS) ?? $date,
            'amount'      => $amount,
            'debit'       => $debit,
            'credit'      => $credit,
        ];
    }

    /**
     * @return array{date: string, amount: float, description: string}|null
     */
    private function rowToMovement(array $row, array $map): ?array
    {
        $date = $this->normalizeDate((string) ($row[$map['date']] ?? ''));

        if ($date === null) {
            return null;
        }

        if ($map['amount'] !== null) {
            $amount = $this->normalizeAmount((string) ($row[$map['amount']] ?? ''));
        } else {
            // Colonne separate: le uscite sono spesso già negative, ma non
            // sempre — si forza il segno in base alla colonna.
            $debit  = $map['debit'] !== null ? abs($this->normalizeAmount((string) ($row[$map['debit']] ?? ''))) : 0.0;
            $credit = $map['credit'] !== null ? abs($this->normalizeAmount((string) ($row[$map['credit']] ?? ''))) : 0.0;
            $amount = $credit - $debit;
        }

        if ($amount === 0.0) {
            return null;
        }

        return [
            'date'        => $date,
            'amount'      => round($amount, 2),
            'description' => trim((string) ($row[$map['description']] ?? '')),
        ];
    }

    /** Accetta dd/mm/yyyy, dd-mm-yyyy e yyyy-mm-dd. */
    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                ? $m[1] . '-' . $m[2] . '-' . $m[3]
                : null;
        }

        if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})#', $value, $m)) {
            $year = (int) $m[3];

            if ($year < 100) {
                $year += 2000;
            }

            // checkdate() e non il solo formato: una riga con "31/02" veniva
            // scritta cosi' com'era nella colonna date e faceva fallire
            // l'intero estratto conto su PostgreSQL, invece di essere saltata
            // come le righe senza data riconoscibile.
            if (!checkdate((int) $m[2], (int) $m[1], $year)) {
                return null;
            }

            return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    /**
     * "1.234,56", "1,234.56", "-1234.56" → float.
     * L'ultimo separatore presente è quello decimale.
     */
    private function normalizeAmount(string $value): float
    {
        $value = trim($value);
        $value = str_replace(["\u{00A0}", ' ', '€', 'EUR'], '', $value);

        if ($value === '') {
            return 0.0;
        }

        $lastComma = strrpos($value, ',');
        $lastDot   = strrpos($value, '.');

        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }

        return (float) $value;
    }

    private function detectDelimiter(string $raw): string
    {
        $line = strtok($raw, "\r\n") ?: '';

        $counts = [
            ';'  => substr_count($line, ';'),
            ','  => substr_count($line, ','),
            "\t" => substr_count($line, "\t"),
        ];

        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function stripBom(string $raw): string
    {
        return str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;
    }
}
