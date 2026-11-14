<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Generare il PDF non e' inviare.
 *
 * Fino a ieri `generatePdf()` (una GET) numerava e marcava il documento come
 * inviato quando l'impostazione mark_*_sent_pdf era attiva. L'effetto non era
 * solo teorico: l'export ZIP crea i PDF chiamando lo stesso metodo, quindi
 * esportare le fatture le segnava tutte come spedite. La marcatura ora ha un
 * POST dedicato; questo test sorveglia che non rientri dalla finestra.
 */
final class PdfGenerationIsReadOnlyTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function documenti(): array
    {
        return [
            'fatture'    => ['Invoices.php', 'invoices'],
            'preventivi' => ['Quotes.php', 'quotes'],
        ];
    }

    #[DataProvider('documenti')]
    public function testGeneratePdfNonMutaLoStato(string $file, string $rotta): void
    {
        $sorgente = (string) file_get_contents(APPPATH . 'Controllers/' . $file);
        $corpo    = $this->corpoMetodo($sorgente, 'generatePdf');

        $this->assertNotSame('', $corpo, 'Metodo generatePdf non trovato in ' . $file);

        $this->assertStringNotContainsString(
            'markSent',
            $corpo,
            $file . ': generatePdf() non deve marcare il documento come inviato. Usa il POST ' . $rotta . '/mark_sent.'
        );

        $this->assertStringNotContainsString(
            'generate' . ($rotta === 'invoices' ? 'Invoice' : 'Quote') . 'NumberIfApplicable',
            $corpo,
            $file . ': generatePdf() non deve assegnare il numero definitivo. Usa il POST ' . $rotta . '/mark_sent.'
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rotte(): array
    {
        return [
            'fatture'    => ['invoices/mark_sent'],
            'preventivi' => ['quotes/mark_sent'],
        ];
    }

    #[DataProvider('rotte')]
    public function testLaMarcaturaHaUnaRottaPost(string $rotta): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        $this->assertStringContainsString(
            "\$routes->post('" . $rotta . "/(:num)'",
            $routes,
            $rotta . ' deve essere POST: e\' una mutazione di stato e va protetta dal CSRF.'
        );

        $this->assertStringNotContainsString(
            "\$routes->get('" . $rotta,
            $routes,
            $rotta . ' non deve avere una variante GET.'
        );
    }

    public function testLExportNonMarcaLeFatture(): void
    {
        $sorgente = (string) file_get_contents(APPPATH . 'Controllers/Exports.php');

        $this->assertStringNotContainsString('markSent', $sorgente);
        $this->assertStringNotContainsString('generateInvoiceNumberIfApplicable', $sorgente);
    }

    /**
     * Corpo di un metodo pubblico, dal nome alla prima graffa di chiusura
     * alla stessa indentazione. Basta per i metodi brevi che interessano qui.
     */
    private function corpoMetodo(string $sorgente, string $nome): string
    {
        if (!preg_match('/public function ' . preg_quote($nome, '/') . '\(.*?\n    \}/s', $sorgente, $m)) {
            return '';
        }

        // I commenti possono citare i metodi vietati per spiegare la scelta:
        // qui interessa solo il codice eseguito.
        return (string) preg_replace('/^\s*(\/\/|\/\*|\*).*$/m', '', $m[0]);
    }
}
