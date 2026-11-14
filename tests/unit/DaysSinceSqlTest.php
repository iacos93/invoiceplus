<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * days_since_sql() deve restituire giorni, non una differenza fra numeri.
 *
 * Il caso che lo ha motivato: `CURRENT_DATE - colonna` dà i giorni su
 * PostgreSQL, ma su MariaDB sottrae le date come interi AAAAMMGG. Fra due mesi
 * diversi il risultato salta (dal 28 gennaio al 3 febbraio: 75 invece di 6) e
 * le fasce 1-30 / 31-60 del report di anzianità dei crediti si spostano.
 *
 * Il test gira sul database della suite: verifica il ramo di quel motore con
 * date a cavallo di un cambio di mese, che è proprio dove la vecchia
 * espressione sbagliava.
 *
 * @internal
 */
final class DaysSinceSqlTest extends CIUnitTestCase
{
    public function testCountsCalendarDaysAcrossAMonthBoundary(): void
    {
        helper('ip_helpers');

        foreach ([0, 6, 45, 400] as $days) {
            $date = date('Y-m-d', strtotime("-{$days} days"));
            $row  = db_connect()
                ->query('SELECT ' . days_since_sql($this->dateLiteral($date)) . ' AS d')
                ->getRowArray();

            $this->assertSame($days, (int) $row['d'], "Giorni trascorsi dal {$date}");
        }
    }

    public function testAFutureDateIsNegative(): void
    {
        helper('ip_helpers');

        $date = date('Y-m-d', strtotime('+10 days'));
        $row  = db_connect()
            ->query('SELECT ' . days_since_sql($this->dateLiteral($date)) . ' AS d')
            ->getRowArray();

        $this->assertSame(-10, (int) $row['d']);
    }

    /** Una data letterale che ogni motore tratta come colonna DATE. */
    private function dateLiteral(string $date): string
    {
        return db_connect()->DBDriver === 'SQLite3'
            ? "'{$date}'"
            : "CAST('{$date}' AS DATE)";
    }
}
