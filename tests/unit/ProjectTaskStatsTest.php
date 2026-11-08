<?php

use App\Models\ProjectModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Conteggi del riepilogo progetto.
 *
 * Alimentano la barra di composizione e la percentuale di avanzamento: se
 * sbagliano, il grafico disegna con precisione un dato falso. Il caso che
 * rompeva tutto è lo stato "fatturata", che finiva nel ramo delle attività
 * ancora aperte.
 *
 * @internal
 */
final class ProjectTaskStatsTest extends CIUnitTestCase
{
    /** @return array<string, mixed> */
    private function task(int $status, float $price = 100.0, bool $overdue = false): array
    {
        return [
            'task_status'     => $status,
            'task_price'      => $price,
            'is_overdue'      => $overdue ? 1 : 0,
        ];
    }

    public function testCountsEachStatusInItsOwnBucket(): void
    {
        $s = ProjectModel::taskStats([
            $this->task(1), $this->task(2), $this->task(2),
            $this->task(3), $this->task(4),
        ]);

        $this->assertSame(5, $s['total']);
        $this->assertSame(1, $s['not_started']);
        $this->assertSame(2, $s['in_progress']);
        $this->assertSame(1, $s['completed']);
        $this->assertSame(1, $s['invoiced']);
    }

    /** Fatturata = chiusa. Prima finiva fra le aperte insieme a "non iniziata". */
    public function testInvoicedIsNotOpen(): void
    {
        $s = ProjectModel::taskStats([
            $this->task(1), $this->task(2), $this->task(3), $this->task(4),
        ]);

        $this->assertSame(2, $s['open'], 'Aperte sono solo "non iniziata" e "in corso"');
    }

    /** Su un lavoro già fatturato la scadenza non vuol più dire niente. */
    public function testInvoicedIsNeverOverdue(): void
    {
        $s = ProjectModel::taskStats([
            $this->task(4, 100.0, true),
            $this->task(3, 100.0, true),
            $this->task(2, 100.0, true),
        ]);

        $this->assertSame(1, $s['overdue'], 'Solo "in corso" può essere in ritardo');
    }

    /**
     * Il difetto peggiore: l'avanzamento contava le sole completate, quindi
     * emettere la fattura per un'attività finita faceva *scendere* la
     * percentuale del progetto.
     */
    public function testInvoicingDoesNotLowerProgress(): void
    {
        $beforeInvoicing = ProjectModel::taskStats([$this->task(3), $this->task(3), $this->task(2)]);
        $afterInvoicing  = ProjectModel::taskStats([$this->task(4), $this->task(3), $this->task(2)]);

        $this->assertSame(67, $beforeInvoicing['progress']);
        $this->assertSame(
            $beforeInvoicing['progress'],
            $afterInvoicing['progress'],
            'Fatturare un lavoro concluso non può far arretrare l\'avanzamento'
        );
    }

    public function testFullyInvoicedProjectIsComplete(): void
    {
        $s = ProjectModel::taskStats([$this->task(4), $this->task(4)]);

        $this->assertSame(100, $s['progress']);
        $this->assertSame(0, $s['open']);
    }

    public function testAmountSumsEveryTask(): void
    {
        $s = ProjectModel::taskStats([
            $this->task(1, 800.0), $this->task(4, 1200.5), $this->task(3, 449.5),
        ]);

        $this->assertEqualsWithDelta(2450.0, $s['amount'], 0.001);
    }

    /** Un progetto senza attività non deve dividere per zero. */
    public function testEmptyProjectIsAllZeroes(): void
    {
        $s = ProjectModel::taskStats([]);

        $this->assertSame(0, $s['total']);
        $this->assertSame(0, $s['progress']);
        $this->assertSame(0.0, $s['amount']);
    }

    /** Le quote della barra devono coprire esattamente il totale. */
    public function testBucketsAlwaysSumToTotal(): void
    {
        $s = ProjectModel::taskStats([
            $this->task(1), $this->task(2), $this->task(3), $this->task(4),
            $this->task(2), $this->task(4), $this->task(99),
        ]);

        $this->assertSame(
            $s['total'],
            $s['not_started'] + $s['in_progress'] + $s['completed'] + $s['invoiced'],
            'Ogni attività deve finire in un segmento e uno solo'
        );
    }
}
