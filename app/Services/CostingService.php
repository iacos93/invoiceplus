<?php

namespace App\Services;

/**
 * CostingService — matematica pura del controllo di gestione.
 *
 * Non tocca database né impostazioni: prende serie mensili e restituisce
 * serie mensili. È la stessa scelta fatta per FinanceService, e per la stessa
 * ragione: margine ed EBITDA sono le formule che, se sbagliate, fanno
 * decidere male, quindi devono poter essere verificate senza HTTP né
 * fixtures.
 *
 * Convenzione: tutte le serie sono array con chiavi 1..12 (mese) e valori
 * float. Il mese 0 non esiste; i totali annui si chiedono con total().
 */
final class CostingService
{
    /**
     * Serie di dodici mesi a zero.
     *
     * @return array<int, float>
     */
    public static function months(): array
    {
        return array_fill(1, 12, 0.0);
    }

    /**
     * @param array<int, float> $months
     */
    public static function total(array $months): float
    {
        return round(array_sum($months), 2);
    }

    /**
     * Somma due serie mese per mese (es. più voci dello stesso tipo).
     *
     * @param array<int, float> $a
     * @param array<int, float> $b
     *
     * @return array<int, float>
     */
    public static function add(array $a, array $b): array
    {
        $out = self::months();

        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = round(($a[$m] ?? 0.0) + ($b[$m] ?? 0.0), 2);
        }

        return $out;
    }

    /**
     * A meno B, mese per mese. Usata per margine ed EBITDA.
     *
     * @param array<int, float> $a
     * @param array<int, float> $b
     *
     * @return array<int, float>
     */
    public static function subtract(array $a, array $b): array
    {
        $out = self::months();

        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = round(($a[$m] ?? 0.0) - ($b[$m] ?? 0.0), 2);
        }

        return $out;
    }

    /**
     * Margine di contribuzione: ricavi meno costi variabili.
     *
     * Dice quanto resta da ogni euro venduto per coprire la struttura. È la
     * prima cosa che si guarda nel Bilancino, prima ancora dell'EBITDA,
     * perché un margine negativo non si recupera con i volumi.
     *
     * @param array<int, float> $revenue
     * @param array<int, float> $variable
     *
     * @return array<int, float>
     */
    public static function contributionMargin(array $revenue, array $variable): array
    {
        return self::subtract($revenue, $variable);
    }

    /**
     * EBITDA: ricavi meno costi variabili e fissi.
     *
     * Qui i costi sono quelli operativi registrati nell'applicazione. Non ci
     * sono ammortamenti né interessi (non gestiti), quindi il valore
     * coincide con il risultato operativo: va letto come stima gestionale,
     * non come voce di bilancio civilistico.
     *
     * @param array<int, float> $revenue
     * @param array<int, float> $variable
     * @param array<int, float> $fixed
     *
     * @return array<int, float>
     */
    public static function ebitda(array $revenue, array $variable, array $fixed): array
    {
        return self::subtract(self::subtract($revenue, $variable), $fixed);
    }

    /**
     * Cumulato progressivo: il saldo di cassa si legge solo cumulato, perché
     * un mese positivo non compensa un saldo iniziale negativo.
     *
     * @param array<int, float> $values
     *
     * @return array<int, float>
     */
    public static function cumulative(array $values): array
    {
        $out  = self::months();
        $running = 0.0;

        for ($m = 1; $m <= 12; $m++) {
            $running += (float) ($values[$m] ?? 0.0);
            $out[$m]  = round($running, 2);
        }

        return $out;
    }

    /**
     * Scostamento consuntivo − budget.
     *
     * Il segno è volutamente quello algebrico: sui ricavi un valore positivo
     * è buono, sui costi è cattivo. Chi mostra la cifra decide come colorarla
     * in base al tipo di voce; qui non si nasconde il segno.
     *
     * @return array{amount: float, percent: float|null}
     */
    public static function variance(float $actual, float $budget): array
    {
        $amount = round($actual - $budget, 2);

        return [
            'amount'  => $amount,
            'percent' => $budget != 0.0 ? round($amount / abs($budget) * 100, 1) : null,
        ];
    }

    /**
     * Percentuale sul totale, null quando il totale è zero (una percentuale
     * su zero non esiste; mostrare 0% direbbe una cosa falsa).
     */
    public static function share(float $value, float $base): ?float
    {
        return $base == 0.0 ? null : round($value / $base * 100, 1);
    }

    /**
     * Somma le serie di più voci, ciascuna con la sua serie mensile.
     *
     * @param iterable<array<int, float>> $series
     *
     * @return array<int, float>
     */
    public static function sumSeries(iterable $series): array
    {
        $out = self::months();

        foreach ($series as $months) {
            $out = self::add($out, $months);
        }

        return $out;
    }
}
