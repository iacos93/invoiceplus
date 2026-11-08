<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Approvare o rifiutare un preventivo cambia lo stato di un documento.
 *
 * Erano rotte GET collegate a normali <a href>: bastava che il browser o un
 * prefetch seguisse il link — o che qualcuno lo mettesse in un <img src> su
 * una pagina di terzi — perche' il preventivo risultasse approvato dal
 * cliente senza un clic vero, e nessun token CSRF veniva controllato.
 *
 * Il test blocca il ritorno a quel modello: le quattro rotte devono esistere
 * solo su POST, e nelle viste i pulsanti devono essere form con csrf_field(),
 * non link.
 */
final class GuestQuoteActionsTest extends CIUnitTestCase
{
    /**
     * Rotte che mutano lo stato del preventivo: area pubblica (url key) e
     * area guest autenticata (id). I percorsi sono quelli scritti in
     * Config/Routes.php: dentro $routes->group('guest', ...) il prefisso non
     * compare, quindi le due rotte guest sono definite come 'quotes/approve/'
     * e 'quotes/reject/'.
     */
    private const ROUTES = [
        'guest/view/approve_quote/',
        'guest/view/reject_quote/',
        'quotes/approve/',
        'quotes/reject/',
    ];

    public function testApprovazioneERifiutoEsistonoSoloSuPost(): void
    {
        $lines = file(APPPATH . 'Config/Routes.php', FILE_IGNORE_NEW_LINES);

        $this->assertNotEmpty($lines);

        foreach (self::ROUTES as $uri) {
            $matching = array_values(array_filter(
                $lines,
                static fn (string $line): bool => str_contains($line, $uri)
            ));

            $this->assertNotEmpty($matching, 'Rotta ' . $uri . ' assente da Config/Routes.php.');

            foreach ($matching as $line) {
                $this->assertStringContainsString(
                    '->post(',
                    $line,
                    'La rotta ' . $uri . ' non e\' registrata su POST: ' . trim($line)
                    . ' — una richiesta GET non deve poter cambiare lo stato di un preventivo.'
                );
            }
        }
    }

    public function testLeVisteNonColleganoApprovazioneERifiutoConUnLink(): void
    {
        $linkVietati = [
            'guest/quotes/approve',
            'guest/quotes/reject',
        ];

        $source = (string) file_get_contents(APPPATH . 'Views/guest/quotes_index.php');

        foreach ($linkVietati as $endpoint) {
            $this->assertStringNotContainsString(
                "href=\"<?= site_url('" . $endpoint . '/',
                $source,
                'guest/quotes_index.php: ' . $endpoint . ' e\' ancora un link; deve essere un form POST con token.'
            );
        }

        $this->assertStringContainsString(
            'csrf_field()',
            $source,
            'guest/quotes_index.php: i pulsanti di approvazione/rifiuto devono portare il token CSRF.'
        );

        $detail = (string) file_get_contents(APPPATH . 'Views/guest/quotes_view.php');

        $this->assertStringContainsString(
            'csrf_field()',
            $detail,
            'guest/quotes_view.php: i pulsanti di approvazione/rifiuto devono portare il token CSRF.'
        );

        // Il dettaglio costruisce l'URL da $action, quindi la verifica utile
        // e' che non ci siano piu' <a> con le classi dei due pulsanti.
        $this->assertStringNotContainsString(
            '<a class="btn btn-success" href="\' . site_url(\'guest/view/approve_quote',
            $detail,
            'guest/quotes_view.php: l\'approvazione e\' ancora un link.'
        );
    }
}
