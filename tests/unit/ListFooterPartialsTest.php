<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Coda delle liste (piede totali e barra esportazioni) con l'elenco vuoto.
 *
 * Con zero righe la pagina ripeteva gli zeri in tre punti — riepilogo in
 * testa, piede della tabella, importi sotto ogni mese — mentre l'empty-state
 * diceva già che non c'era nulla. Il piede e la barra di esportazione ora
 * spariscono; i totali tornano appena la lista ha una riga.
 *
 * @internal
 */
final class ListFooterPartialsTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $options
     */
    private function render(string $view, array $options): string
    {
        helper(['ip_helpers', 'url']);

        $renderer = \Config\Services::renderer(null, null, false);
        $html     = $renderer->render($view, $options);

        // In ambiente di test CI4 avvolge ogni view nei commenti DEBUG-VIEW:
        // qui interessa solo il markup prodotto (o il fatto che non ce ne sia).
        return (string) preg_replace('/<!--\s*DEBUG-VIEW.*?-->/s', '', $html);
    }

    public function testTotalsFooterIsNotRenderedForAnEmptyList(): void
    {
        $html = $this->render('layout/list_totals', [
            'totals' => ['count' => 0, 'net' => 0.0, 'tax' => 0.0, 'total' => 0.0, 'balance' => 0.0],
        ]);

        $this->assertSame('', trim($html));
    }

    public function testExportBarIsNotRenderedForAnEmptyList(): void
    {
        $html = $this->render('layout/list_export_bar', [
            'empty' => true,
            'links' => [
                ['url' => '/exports/invoices', 'label' => 'Fatture in XLS', 'icon' => 'fa-solid fa-file-excel'],
            ],
        ]);

        $this->assertSame('', trim($html));
    }

    public function testExportBarIsRenderedWhenTheListHasRows(): void
    {
        $html = $this->render('layout/list_export_bar', [
            'empty' => false,
            'links' => [
                ['url' => '/exports/invoices', 'label' => 'Fatture in XLS', 'icon' => 'fa-solid fa-file-excel'],
            ],
        ]);

        $this->assertStringContainsString('ip-list-export', $html);
        $this->assertStringContainsString('Fatture in XLS', $html);
        $this->assertStringContainsString('exports', $html);
    }
}
