<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * La subnav del Controllo di gestione segnava sempre la prima voce.
 *
 * Le cinque pagine passano la voce corrente con
 * $this->include('management/_tabs', ['active' => ...]), ma le opzioni di
 * include() non vengono estratte fra le variabili della view: finiscono in
 * renderVars['options']. $active restava quindi indefinita e il partial
 * ripiegava sul default 'report', accendendo "Controllo di gestione" anche su
 * Flusso di cassa, Piano dei conti, Budget e Assegna voci.
 *
 * @internal
 */
final class ManagementTabsTest extends CIUnitTestCase
{
    private function render(string $active): string
    {
        helper(['ip_helpers', 'url']);

        $view = \Config\Services::renderer(null, null, false);

        return $view->include('management/_tabs', ['active' => $active]);
    }

    /**
     * @return array<string, string> voce => coda dell'href
     */
    private function tabs(): array
    {
        return [
            'report'   => 'management',
            'cashflow' => 'management/cashflow',
            'accounts' => 'management/accounts',
            'budget'   => 'management/budget',
            'assign'   => 'management/assign',
        ];
    }

    public function testOnlyTheActiveTabIsHighlighted(): void
    {
        foreach ($this->tabs() as $active => $path) {
            $html = $this->render($active);

            $this->assertSame(
                1,
                substr_count($html, 'is-active'),
                'Su ' . $active . ' deve esserci una sola voce attiva'
            );

            $this->assertMatchesRegularExpression(
                '/class="ip-subnav__item is-active"\s+href="[^"]*' . preg_quote($path, '/') . '"/',
                $html,
                'Su ' . $active . ' la voce attiva deve essere ' . $path
            );
        }
    }

    public function testEveryTabIsPresent(): void
    {
        $html = $this->render('report');

        foreach ($this->tabs() as $path) {
            $this->assertStringContainsString('/' . $path . '"', $html);
        }
    }
}
