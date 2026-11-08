<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * La barra filtri delle liste (fatture, spese, preventivi) nasce da un elenco
 * di gruppi.
 *
 * Prima gli stessi comandi erano scritti due volte — una nell'headerbar, una
 * nel sottomenu mobile — e le due copie divergevano: il filtro "Preferiti"
 * esisteva solo su desktop. Il partial è ora l'unico posto in cui quel markup
 * viene composto, quindi queste asserzioni valgono per entrambe le viste.
 *
 * @internal
 */
final class ListFiltersPartialTest extends CIUnitTestCase
{
    /**
     * @param list<array<string, mixed>> $groups
     */
    private function render(array $groups): string
    {
        helper(['ip_helpers', 'url']);

        $view = \Config\Services::renderer(null, null, false);

        return $view->render('layout/list_filters', ['groups' => $groups]);
    }

    public function testEveryButtonIsRenderedWithItsActiveState(): void
    {
        $html = $this->render([
            [
                'label'   => 'Stato',
                'buttons' => [
                    ['url' => '/invoices/status/all', 'label' => 'Tutte', 'active' => true],
                    ['url' => '/invoices/status/paid', 'label' => 'Pagate', 'active' => false],
                ],
            ],
            [
                'label'   => 'Preferite',
                'buttons' => [
                    ['url' => '/invoices/status/all&fav=1', 'label' => 'Sì', 'icon' => 'fa-solid fa-star', 'active' => false],
                ],
            ],
        ]);

        $this->assertStringContainsString('ip-filter-bar', $html);

        // Due gruppi, due etichette, tre pulsanti: nessuna voce duplicata.
        $this->assertSame(2, substr_count($html, 'class="ip-filter-group"'));
        $this->assertSame(3, substr_count($html, '<a href='));
        $this->assertSame(1, substr_count($html, 'aria-pressed="true"'));
        $this->assertSame(2, substr_count($html, 'aria-pressed="false"'));

        // L'etichetta del gruppo serve anche a chi usa uno screen reader: senza
        // aria-label i due segmenti "Tutte" sarebbero indistinguibili.
        $this->assertStringContainsString('aria-label="Stato"', $html);
        $this->assertStringContainsString('aria-label="Preferite"', $html);

        // L'icona è opzionale e, quando c'è, resta decorativa.
        $this->assertStringContainsString('fa-solid fa-star', $html);
    }

    public function testGroupsWithoutButtonsAreSkipped(): void
    {
        $html = $this->render([
            ['label' => 'Vuoto', 'buttons' => []],
            ['label' => 'Tipo', 'buttons' => [
                ['url' => '/x', 'label' => 'Fatture', 'active' => true],
            ]],
        ]);

        $this->assertStringNotContainsString('Vuoto', $html);
        $this->assertSame(1, substr_count($html, 'class="ip-filter-group"'));
    }

    public function testButtonWithoutIconDoesNotLeaveAnEmptyIconNode(): void
    {
        $html = $this->render([
            ['label' => null, 'buttons' => [
                ['url' => '/x', 'label' => 'Fatture', 'active' => true],
            ]],
        ]);

        $this->assertStringNotContainsString('<i class="fa "', $html);
        // Senza etichetta il gruppo non stampa né il titolo né l'aria-label.
        $this->assertStringNotContainsString('ip-filter-group__label', $html);
        $this->assertStringNotContainsString('aria-label=', $html);
    }
}
