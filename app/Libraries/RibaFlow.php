<?php

namespace App\Libraries;

/**
 * Generatore del flusso Ri.Ba. incassi nel tracciato CBI.
 *
 * Il file è a record di lunghezza fissa (120 caratteri), uno per riga, nella
 * sequenza: IB (testata), poi per ogni ricevuta i record 14, 20, 30, 40, 50,
 * 51, 70, infine EF (coda con i totali).
 *
 * ATTENZIONE: le banche adottano varianti del tracciato (campi a disposizione
 * usati in modo proprio, codici causale, presenza del record 51). Questo
 * generatore segue il layout CBI standard, ma il primo flusso va sempre
 * verificato con la propria banca prima di usarlo in produzione: un file
 * accettato dal sistema ma con un campo interpretato male produce incassi
 * appoggiati sul conto sbagliato.
 *
 * Gli importi viaggiano in centesimi senza separatori, le date come GGMMAA.
 */
class RibaFlow
{
    private const LINE_LENGTH = 120;

    /**
     * @param array $company  dati dell'azienda creditrice (ragione sociale, fiscali)
     * @param array $account  conto di accredito (IBAN e codice SIA)
     * @param list<array> $invoices fatture da presentare, con dati cliente
     *
     * @return array{content: string, reference: string, count: int, total: float, warnings: list<string>}
     */
    public function build(array $company, array $account, array $invoices): array
    {
        $warnings = [];

        // Il SIA sta sul conto, non sull'azienda: lo assegna la banca dentro
        // il rapporto di conto, quindi presentando su due banche i codici sono
        // due. Prendendolo dall'azienda, il flusso della seconda banca usciva
        // col codice della prima.
        $sia = strtoupper(trim((string) ($account['bank_account_sia_code'] ?? '')));

        if ($sia === '') {
            $warnings[] = 'Codice SIA mancante sul conto di accredito: la banca rifiuterà il flusso.';
        }

        $creditorIban = $this->cleanIban((string) ($account['bank_account_iban'] ?? ''));

        if ($creditorIban === '') {
            $warnings[] = 'IBAN del conto di accredito mancante.';
        }

        $creditorAbi = $this->abiFrom($creditorIban);
        $creditorCab = $this->cabFrom($creditorIban);

        // Riferimento della distinta: serve a ritrovarla e a marcare le
        // fatture già presentate.
        $reference = 'RIBA' . date('YmdHis');

        $lines   = [];
        $total   = 0.0;
        $counter = 0;

        $lines[] = $this->recordIB($sia, $creditorAbi, $reference);

        foreach ($invoices as $invoice) {
            $counter++;

            $amount  = (float) ($invoice['invoice_balance'] ?? $invoice['invoice_total'] ?? 0);
            $total  += $amount;

            $debtorIban = $this->cleanIban((string) ($invoice['client_iban'] ?? ''));

            if ($debtorIban === '') {
                $warnings[] = 'Cliente "' . ($invoice['client_name'] ?? '') . '": IBAN mancante, la ricevuta non sarà domiciliata.';
            }

            $due = $this->dateOrToday((string) ($invoice['invoice_date_due'] ?? ''));

            $lines[] = $this->record14($counter, $due, $amount, $creditorAbi, $creditorCab, $debtorIban, $sia);
            $lines[] = $this->record20($counter, (string) ($company['company_name'] ?? ''));
            $lines[] = $this->record30($counter, $this->clientName($invoice), (string) ($invoice['client_tax_code'] ?? $invoice['client_vat_id'] ?? ''));
            $lines[] = $this->record40($counter, $invoice);
            $lines[] = $this->record50($counter, $company);
            $lines[] = $this->record51($counter, (string) ($invoice['invoice_number'] ?? ''));
            $lines[] = $this->record70($counter);
        }

        $lines[] = $this->recordEF($sia, $creditorAbi, $reference, $counter, $total, count($lines) + 1);

        return [
            'content'   => implode("\r\n", $lines) . "\r\n",
            'reference' => $reference,
            'count'     => $counter,
            'total'     => $total,
            'warnings'  => array_values(array_unique($warnings)),
        ];
    }

    /* ------------------------------------------------------------ record */

    private function recordIB(string $sia, string $abi, string $reference): string
    {
        return $this->line([
            [1, 2, 'IB'],
            [3, 5, $sia],
            [8, 5, $abi],
            [13, 6, date('dmy')],
            [19, 20, $reference],
            [111, 5, 'E'],
        ]);
    }

    private function record14(int $n, string $due, float $amount, string $abi, string $cab, string $debtorIban, string $sia): string
    {
        return $this->line([
            [2, 2, '14'],
            [4, 7, $this->num($n, 7)],
            [11, 6, $due],
            [17, 5, '30000'],                       // causale: incasso Ri.Ba.
            [22, 13, $this->num((int) round($amount * 100), 13)],
            [35, 1, '-'],
            [36, 5, $abi],
            [41, 5, $cab],
            [58, 5, $this->abiFrom($debtorIban)],   // banca domiciliataria
            [63, 5, $this->cabFrom($debtorIban)],
            [71, 5, $sia],
            [76, 1, '4'],
            [77, 16, $this->num($n, 16)],
        ]);
    }

    private function record20(int $n, string $creditorName): string
    {
        return $this->line([
            [2, 2, '20'],
            [4, 7, $this->num($n, 7)],
            [11, 24, $creditorName],
        ]);
    }

    private function record30(int $n, string $debtorName, string $fiscalCode): string
    {
        return $this->line([
            [2, 2, '30'],
            [4, 7, $this->num($n, 7)],
            [11, 30, $debtorName],
            [71, 16, strtoupper($fiscalCode)],
        ]);
    }

    private function record40(int $n, array $invoice): string
    {
        $city = trim(
            (string) ($invoice['client_city'] ?? '') . ' ' .
            (string) ($invoice['client_state'] ?? '')
        );

        return $this->line([
            [2, 2, '40'],
            [4, 7, $this->num($n, 7)],
            [11, 30, (string) ($invoice['client_address_1'] ?? '')],
            [41, 5, (string) ($invoice['client_zip'] ?? '')],
            [46, 25, $city],
        ]);
    }

    private function record50(int $n, array $company): string
    {
        return $this->line([
            [2, 2, '50'],
            [4, 7, $this->num($n, 7)],
            [11, 40, 'Pagamento fattura'],
            [81, 16, strtoupper((string) ($company['company_tax_code'] ?? $company['company_vat_id'] ?? ''))],
        ]);
    }

    /** Numero della fattura a cui la ricevuta si riferisce. */
    private function record51(int $n, string $invoiceNumber): string
    {
        return $this->line([
            [2, 2, '51'],
            [4, 7, $this->num($n, 7)],
            [11, 10, $invoiceNumber],
        ]);
    }

    private function record70(int $n): string
    {
        return $this->line([
            [2, 2, '70'],
            [4, 7, $this->num($n, 7)],
        ]);
    }

    private function recordEF(string $sia, string $abi, string $reference, int $count, float $total, int $lines): string
    {
        return $this->line([
            [1, 2, 'EF'],
            [3, 5, $sia],
            [8, 5, $abi],
            [13, 6, date('dmy')],
            [19, 20, $reference],
            [49, 7, $this->num($count, 7)],
            [56, 15, $this->num((int) round($total * 100), 15)],
            [86, 7, $this->num($lines, 7)],
        ]);
    }

    /* ------------------------------------------------------------ utility */

    /**
     * Compone un record da 120 caratteri posizionando i campi.
     * Ogni campo è [posizione 1-based, lunghezza, valore].
     *
     * @param list<array{0:int,1:int,2:string}> $fields
     */
    private function line(array $fields): string
    {
        $record = str_repeat(' ', self::LINE_LENGTH);

        foreach ($fields as [$position, $length, $value]) {
            $value = $this->ascii((string) $value);
            $value = str_pad(mb_substr($value, 0, $length), $length, ' ');

            $record = substr_replace($record, $value, $position - 1, $length);
        }

        return substr($record, 0, self::LINE_LENGTH);
    }

    /** Numerico allineato a destra con zeri: il tracciato non ammette spazi. */
    private function num(int $value, int $length): string
    {
        return str_pad((string) abs($value), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Il tracciato accetta solo caratteri ASCII maiuscoli: accenti e simboli
     * farebbero scartare il flusso, quindi si traslitterano.
     */
    private function ascii(string $value): string
    {
        $value = strtr($value, [
            'à' => 'A', 'è' => 'E', 'é' => 'E', 'ì' => 'I', 'ò' => 'O', 'ù' => 'U',
            'À' => 'A', 'È' => 'E', 'É' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
        ]);

        $value = strtoupper($value);

        return (string) preg_replace('/[^A-Z0-9 \/\-\.,]/', ' ', $value);
    }

    /**
     * Ragione sociale del debitore. Non si usa format_client(): questa
     * libreria deve poter girare anche fuori dal contesto dell'applicazione
     * (test, generazione da riga di comando).
     */
    private function clientName(array $invoice): string
    {
        $name = trim(
            (string) ($invoice['client_name'] ?? '') . ' ' .
            (string) ($invoice['client_surname'] ?? '')
        );

        return $name !== '' ? $name : 'CLIENTE';
    }

    private function cleanIban(string $iban): string
    {
        // Come BankAccountModel::cleanIban(): si toglie tutto cio' che non e'
        // alfanumerico (spazi, punti, trattini), perche' un IBAN cliente
        // incollato con punteggiatura darebbe ABI/CAB errati nel flusso RiBa.
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $iban));
    }

    /** IBAN italiano: ABI in posizione 6-10, CAB in 11-15. */
    private function abiFrom(string $iban): string
    {
        return strlen($iban) >= 15 ? substr($iban, 5, 5) : '';
    }

    private function cabFrom(string $iban): string
    {
        return strlen($iban) >= 15 ? substr($iban, 10, 5) : '';
    }

    private function dateOrToday(string $date): string
    {
        $time = strtotime(substr($date, 0, 10));

        return date('dmy', $time ?: time());
    }
}
