<?php

use App\Models\SdiTransmissionModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il nome file SdI dice a quale azienda appartiene la trasmissione
 * (`IT<partitaIVA>_<progressivo>.xml`).
 *
 * In multi-azienda lo stesso progressivo puo' ripetersi. La notifica va
 * applicata solo quando la corrispondenza e' una sola: meglio non aggiornare
 * nulla che sbloccare o bloccare la fattura di un'altra azienda. Qui si
 * provano le due parti pure, senza PostgreSQL.
 *
 * @internal
 */
final class SdiFilenameCompanyTest extends CIUnitTestCase
{
    public function testEstraeLaPartitaIvaDalNomeFileUfficiale(): void
    {
        $this->assertSame(
            '01234567890',
            SdiTransmissionModel::vatFromFilename('IT01234567890_00001.xml')
        );
    }

    public function testAccettaAncheIlPrefissoInMinuscolo(): void
    {
        $this->assertSame(
            '01234567890',
            SdiTransmissionModel::vatFromFilename('it01234567890_ABC12.xml')
        );
    }

    public function testNomiFileNonUfficialiNonDannoUnaPartitaIva(): void
    {
        foreach ([
            '',
            'IT01234567890.xml',          // manca il progressivo dopo l'underscore
            'IT1234567890_00001.xml',     // dieci cifre, non undici
            'IT012345678901_00001.xml',   // dodici cifre
            'FR01234567890_00001.xml',    // paese diverso dall'Italia
            '01234567890_00001.xml',      // manca il paese
            'ITABCDEFGHIJK_00001.xml',
        ] as $nome) {
            $this->assertNull(SdiTransmissionModel::vatFromFilename($nome), 'Non valido: ' . $nome);
        }
    }

    public function testNessunaCandidataNonSiApplica(): void
    {
        $this->assertNull(SdiTransmissionModel::pickCandidate([]));
    }

    public function testUnaSolaCandidataSiApplica(): void
    {
        $riga = ['sdi_transmission_id' => 42, 'filename' => 'IT01234567890_00001.xml'];

        $this->assertSame($riga, SdiTransmissionModel::pickCandidate([$riga]));
    }

    public function testPiuCandidatesSonoAmbiguE_NonSiApplicano(): void
    {
        $righe = [
            ['sdi_transmission_id' => 43, 'filename' => 'IT01234567890_00001.xml'],
            ['sdi_transmission_id' => 42, 'filename' => 'IT01234567890_00001.xml'],
        ];

        $this->assertNull(SdiTransmissionModel::pickCandidate($righe), 'Due trasmissioni con lo stesso nome file: nessuna scelta certa');
    }
}
