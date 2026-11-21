<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il motore di stampa si sceglie dall'ambiente prima che dalle impostazioni:
 * è la via con cui un'installazione (e questa suite) resta su dompdf senza
 * toccare il database.
 *
 * @internal
 */
final class GotenbergPdfTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('ip_helpers');
    }

    public function testIlMotoreSiPuoForzareDaAmbiente(): void
    {
        $originale = $_ENV['pdf.engine'] ?? null;

        $_ENV['pdf.engine'] = 'dompdf';
        $this->assertSame('dompdf', pdf_engine());

        $_ENV['pdf.engine'] = 'gotenberg';
        $this->assertSame('gotenberg', pdf_engine());

        if ($originale === null) {
            unset($_ENV['pdf.engine']);
        } else {
            $_ENV['pdf.engine'] = $originale;
        }
    }
}
