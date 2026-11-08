<?php

use App\Libraries\fatturapa\FatturaPA;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Lo schema FatturaPA ammette solo Basic Latin + Latin-1: trattini lunghi e
 * apici curvi presi da Word facevano scartare la fattura in validazione
 * (fatture 91, 95, 96, 97 dell'archivio).
 */
final class FatturaPALatin1Test extends CIUnitTestCase
{
    public function testTypographicCharactersBecomeAllowedOnes(): void
    {
        $this->assertSame(
            "Tende - N. 2 mod. 'Itala' \"bianca\" 5,4 mq...",
            FatturaPA::latin1("Tende \u{2013} N. 2 mod. \u{2018}Itala\u{2019} \u{201C}bianca\u{201D} 5,4 mq\u{2026}")
        );
        $this->assertSame('Piano Standard - 1 anno 100 EUR', FatturaPA::latin1("Piano Standard \u{2014} 1 anno 100 \u{20AC}"));
    }

    public function testLatin1IsKeptAndTheRestIsDropped(): void
    {
        $this->assertSame('Attività 1° anno ½ già', FatturaPA::latin1('Attività 1° anno ½ già'));
        $this->assertSame('Ok ', FatturaPA::latin1("Ok \u{1F600}"));
    }

    public function testInvalidUtf8IsLeftUntouched(): void
    {
        $this->assertSame("rotto\xC3", FatturaPA::latin1("rotto\xC3"));
    }
}
