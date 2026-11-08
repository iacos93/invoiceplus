<?php

namespace App\Libraries;

use App\Models\ExpenseModel;
use App\Models\InvoiceModel;

/**
 * Abbinamento fra movimenti bancari e documenti.
 *
 * Un movimento in entrata si cerca fra le fatture emesse, uno in uscita fra le
 * spese. Non si abbina mai in automatico senza conferma: un movimento assegnato
 * al documento sbagliato produce una fattura "incassata" che non lo è, e
 * l'errore si scopre mesi dopo in fase di riconciliazione contabile. Qui si
 * propongono candidati con un punteggio, la scelta resta all'utente.
 *
 * Il punteggio combina tre segnali, dal più al meno affidabile:
 *   - numero del documento citato nella causale  → quasi certezza
 *   - importo coincidente                        → forte
 *   - vicinanza fra le date                      → debole, serve a ordinare
 */
class BankReconciler
{
    /**
     * Oltre questa distanza in giorni il documento non è più un candidato.
     *
     * Un anno, non pochi mesi: fra termini a 60/90 giorni, solleciti e ritardi,
     * una fattura di marzo saldata ad agosto è normale. A filtrare ci pensano i
     * segnali forti (numero in causale, importo), mentre la data serve solo a
     * ordinare i pari merito; una finestra stretta nascondeva in silenzio
     * abbinamenti legittimi.
     */
    private const MAX_DAYS = 365;

    /** Tolleranza sull'importo: arrotondamenti e commissioni di pochi centesimi. */
    private const AMOUNT_TOLERANCE = 0.02;

    /**
     * Cache per-istanza di fatture e spese aperte. Reconciliation::index()
     * crea un solo BankReconciler e chiama candidatesFor() una volta per
     * ogni movimento da abbinare: senza questa cache, ogni chiamata
     * rileggeva l'intera tabella fatture o spese con i relativi join --
     * con 200 movimenti e 5.000 fatture erano 200 query pesanti e circa un
     * milione di confronti PHP ad ogni caricamento della pagina.
     */
    private ?array $invoicesCache = null;

    private ?array $expensesCache = null;

    private function invoices(): array
    {
        return $this->invoicesCache ??= (new InvoiceModel())->getAllWithStatus('all', 0, 0);
    }

    private function expenses(): array
    {
        return $this->expensesCache ??= (new ExpenseModel())->getAll(0, 0, '');
    }

    /**
     * Candidati per un movimento, dal più probabile.
     *
     * Il verso del movimento dice in quale insieme cercare, e le note di
     * credito stanno dalla parte opposta del loro documento: un'uscita può
     * essere il pagamento di una spesa ma anche il rimborso di una nota di
     * credito emessa a un cliente; un'entrata può essere l'incasso di una
     * fattura ma anche il rimborso di una nota di credito ricevuta da un
     * fornitore. Prima le note di credito, negative, non trovavano mai
     * l'importo (il confronto era solo su importi positivi) e una nota emessa
     * citata in causale veniva proposta per un incasso.
     *
     * @return list<array{type: string, id: int, label: string, date: string, amount: float, score: int, reasons: list<string>}>
     */
    public function candidatesFor(array $transaction): array
    {
        $amount = (float) $transaction['transaction_amount'];

        $candidates = $amount >= 0
            ? array_merge($this->matchInvoices($transaction, $amount, 1), $this->matchExpenses($transaction, $amount, -1))
            : array_merge($this->matchExpenses($transaction, abs($amount), 1), $this->matchInvoices($transaction, abs($amount), -1));

        return $this->best($candidates);
    }

    /**
     * Il documento va nel verso cercato? $sign = 1 per i documenti ordinari,
     * -1 per le note di credito (totale negativo). Un documento a zero non è
     * candidato a nessun movimento.
     */
    private static function hasSign(float $total, int $sign): bool
    {
        return $total * $sign > 0;
    }

    private function matchInvoices(array $transaction, float $amount, int $sign = 1): array
    {
        $candidates = [];

        foreach ($this->invoices() as $invoice) {
            // Le bozze non sono ancora crediti: un incasso non può riferirsi a
            // un documento mai emesso.
            if ((int) $invoice['invoice_status_id'] === 1) {
                continue;
            }

            $balance = (float) ($invoice['invoice_balance'] ?? 0);
            $total   = (float) ($invoice['invoice_total'] ?? 0);
            $number  = (string) ($invoice['invoice_number'] ?? '');

            if (!self::hasSign($total, $sign)) {
                continue;
            }

            // Importi portati nel verso del movimento: il residuo di una nota
            // di credito (-1.049) è un rimborso di 1.049.
            $score = $this->score(
                $transaction,
                $amount,
                [$balance * $sign, $total * $sign],
                $number,
                (string) $invoice['invoice_date_created'],
                $reasons
            );

            if ($score <= 0) {
                continue;
            }

            $candidates[] = [
                'type'    => 'invoice',
                'id'      => (int) $invoice['invoice_id'],
                'label'   => ($sign < 0 ? 'Nota di credito ' : '')
                    . trim(($number ?: '#' . $invoice['invoice_id']) . ' — ' . format_client($invoice)),
                'date'    => (string) $invoice['invoice_date_created'],
                'amount'  => $total,
                'score'   => $score,
                'reasons' => $reasons,
            ];
        }

        return $candidates;
    }

    private function matchExpenses(array $transaction, float $amount, int $sign = 1): array
    {
        $candidates = [];

        foreach ($this->expenses() as $expense) {
            $open  = (float) $expense['expense_total'] - (float) $expense['expense_paid'];
            $total = (float) $expense['expense_total'];

            if (!self::hasSign($total, $sign)) {
                continue;
            }

            $score = $this->score(
                $transaction,
                $amount,
                [$open * $sign, $total * $sign],
                (string) ($expense['expense_number'] ?? ''),
                (string) $expense['expense_date'],
                $reasons
            );

            if ($score <= 0) {
                continue;
            }

            $candidates[] = [
                'type'    => 'expense',
                'id'      => (int) $expense['expense_id'],
                'label'   => ($sign < 0 ? 'Nota di credito ' : '')
                    . trim(($expense['expense_number'] ?: '—') . ' — ' . $expense['supplier_name']),
                'date'    => (string) $expense['expense_date'],
                'amount'  => $total,
                'score'   => $score,
                'reasons' => $reasons,
            ];
        }

        return $candidates;
    }

    /**
     * Punteggio di un candidato. $amounts contiene gli importi accettabili
     * (residuo e totale): un bonifico può saldare l'intera fattura o solo il
     * residuo dopo un acconto.
     *
     * @param list<float> $amounts
     * @param list<string>|null $reasons popolato con le motivazioni
     */
    private function score(array $transaction, float $amount, array $amounts, string $number, string $date, ?array &$reasons): int
    {
        $reasons = [];
        $score   = 0;

        $days = $this->daysBetween((string) $transaction['transaction_date'], $date);

        if ($days === null || $days > self::MAX_DAYS) {
            return 0;
        }

        $description = (string) ($transaction['transaction_description'] ?? '');

        if ($number !== '' && $this->mentions($description, $number)) {
            $score   += 60;
            $reasons[] = 'numero documento nella causale';
        }

        foreach ($amounts as $candidateAmount) {
            if ($candidateAmount > 0 && abs($candidateAmount - $amount) <= self::AMOUNT_TOLERANCE) {
                $score   += 30;
                $reasons[] = 'importo corrispondente';
                break;
            }
        }

        // Nessuno dei due segnali forti: non è un candidato, la sola vicinanza
        // di data non significa niente.
        if ($score === 0) {
            return 0;
        }

        // La data avvicina i pari merito senza mai determinare da sola: il
        // bonus si esaurisce nei primi mesi, oltre non distingue più.
        $score += max(0, 10 - (int) floor($days / 30));

        if ($days <= 15) {
            $reasons[] = 'data ravvicinata';
        }

        return $score;
    }

    /**
     * Il numero compare nella causale?
     *
     * Il confronto ignora separatori e zeri iniziali: "2026/A/447", "2026-A-447"
     * e "447" nella causale indicano lo stesso documento, e le banche
     * normalizzano le causali a modo loro.
     */
    private function mentions(string $description, string $number): bool
    {
        $clean = static fn(string $v): string => strtoupper(preg_replace('/[^A-Z0-9]/i', '', $v));

        $haystack = $clean($description);
        $needle   = $clean($number);

        if ($needle === '' || strlen($needle) < 3) {
            return false;
        }

        return str_contains($haystack, $needle);
    }

    private function daysBetween(string $a, string $b): ?int
    {
        $timeA = strtotime(substr($a, 0, 10));
        $timeB = strtotime(substr($b, 0, 10));

        if (!$timeA || !$timeB) {
            return null;
        }

        return (int) floor(abs($timeA - $timeB) / 86400);
    }

    /**
     * I migliori candidati, al massimo cinque: un elenco più lungo non aiuta a
     * decidere, lo rende solo più faticoso.
     */
    private function best(array $candidates): array
    {
        usort($candidates, static fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($candidates, 0, 5);
    }
}
