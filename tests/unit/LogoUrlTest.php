<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Percorso e URL del logo.
 *
 * @internal
 */
final class LogoUrlTest extends CIUnitTestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();

        helper('ip');
    }

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file(WRITEPATH . 'uploads/' . $this->file)) {
            unlink(WRITEPATH . 'uploads/' . $this->file);
        }

        parent::tearDown();
    }

    private function creaLogo(string $estensione): string
    {
        $this->file = 'logo-unit-' . uniqid('', true) . '.' . $estensione;
        file_put_contents(WRITEPATH . 'uploads/' . $this->file, 'contenuto');

        return $this->file;
    }

    public function testPercorsoNullSenzaNome(): void
    {
        $this->assertNull(logo_path(''));
        $this->assertNull(logo_path(null));
    }

    public function testPercorsoDeveRestareInUploads(): void
    {
        $this->assertNull(logo_path('../../.env'));
    }

    public function testPercorsoRifiutaEstensioniNonImmagine(): void
    {
        $this->file = 'logo-unit-' . uniqid('', true) . '.php';
        file_put_contents(WRITEPATH . 'uploads/' . $this->file, '<?php');

        $this->assertNull(logo_path($this->file));
    }

    public function testPercorsoDiUnLogoValido(): void
    {
        $file = $this->creaLogo('png');

        $this->assertSame(WRITEPATH . 'uploads/' . $file, logo_path($file));
    }

    public function testDataUriUgualeAPrima(): void
    {
        $file = $this->creaLogo('png');

        // Il PDF continua a usare il data URI, e deve restare quello di prima:
        // stessa immagine, stessa gestione degli SVG.
        $this->assertStringStartsWith('data:image/png;base64,', logo_data_uri($file));
        $this->assertSame('', logo_data_uri(logo_path('') ?? ''));
    }
}
