<?php

use App\Libraries\CsvReader;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Copertura della lettura CSV in ingresso.
 *
 * È l'altra parte dell'applicazione che sbaglia in silenzio: un CSV letto male
 * non solleva niente, scrive righe plausibili e sbagliate. I casi qui dentro
 * sono esattamente quelli che si erano rotti — un file esportato da questa
 * stessa applicazione che non rientrava (BOM + punto e virgola), una data
 * italiana finita grezza in una colonna DATE, un importo con separatore delle
 * migliaia diventato mille volte più piccolo.
 *
 * @internal
 */
final class CsvReaderTest extends CIUnitTestCase
{
    /* ------------------------------------------------------- delimitatore */

    public function testRilevaIlPuntoEVirgolaDiExcelItaliano(): void
    {
        $this->assertSame(';', CsvReader::detectDelimiter('a;b;c'));
    }

    public function testRilevaLaVirgolaQuandoPrevale(): void
    {
        $this->assertSame(',', CsvReader::detectDelimiter('a,b,c,d'));
    }

    public function testRilevaLaTabulazione(): void
    {
        $this->assertSame("\t", CsvReader::detectDelimiter("a\tb\tc"));
    }

    public function testColonnaSingolaRipiegaSulPuntoEVirgola(): void
    {
        $this->assertSame(';', CsvReader::detectDelimiter('client_name'));
    }

    /* -------------------------------------------------------------- righe */

    /**
     * Il caso che rendeva impossibile il giro completo: il file scritto dalle
     * esportazioni (BOM + punto e virgola) veniva letto a virgola e con il BOM
     * incollato alla prima intestazione, quindi nessuna colonna coincideva.
     */
    public function testLeggeIlFormatoScrittoDalleEsportazioni(): void
    {
        $csv  = "\xEF\xBB\xBFclient_name;client_city\n";
        $csv .= "Acme S.r.l.;Milano\n";

        $rows = CsvReader::rowsFromString($csv);

        $this->assertSame([['client_name', 'client_city'], ['Acme S.r.l.', 'Milano']], $rows);
    }

    public function testScartaLeRigheVuoteInFondoAlFile(): void
    {
        $rows = CsvReader::rowsFromString("a;b\n1;2\n\n;\n");

        $this->assertCount(2, $rows);
    }

    public function testNonTroncaLeRigheLunghe(): void
    {
        $long = str_repeat('x', 4000);
        $rows = CsvReader::rowsFromString("item_description\n{$long}\n");

        $this->assertSame(4000, strlen($rows[1][0]));
    }

    public function testRispettaLeVirgoleDentroIValoriQuotati(): void
    {
        $rows = CsvReader::rowsFromString("a,b\n\"Rossi, Mario\",2\n");

        $this->assertSame(['Rossi, Mario', '2'], $rows[1]);
    }

    public function testConverteUnFileNonUtf8(): void
    {
        $csv  = mb_convert_encoding("client_city\nCittà di Castello\n", 'Windows-1252', 'UTF-8');
        $rows = CsvReader::rowsFromString($csv);

        $this->assertSame('Città di Castello', $rows[1][0]);
    }

    /* --------------------------------------------------------------- date */

    public function testDataItaliana(): void
    {
        $this->assertSame('2025-12-31', CsvReader::toDate('31/12/2025'));
    }

    public function testDataIso(): void
    {
        $this->assertSame('2025-12-31', CsvReader::toDate('2025-12-31'));
    }

    public function testDataIsoConOrario(): void
    {
        $this->assertSame('2025-12-31', CsvReader::toDate('2025-12-31 14:30:00'));
    }

    public function testDataConPuntiOTrattini(): void
    {
        $this->assertSame('2025-01-05', CsvReader::toDate('05.01.2025'));
        $this->assertSame('2025-01-05', CsvReader::toDate('05-01-2025'));
    }

    /**
     * 03/04 è ambiguo: si sceglie il giorno prima del mese, che è la
     * convenzione dell'applicazione.
     */
    public function testDataAmbiguaSiLeggeAllItaliana(): void
    {
        $this->assertSame('2025-04-03', CsvReader::toDate('03/04/2025'));
    }

    /** Con il primo numero sopra 12 non c'è ambiguità da risolvere. */
    public function testDataAmericanaRiconosciutaQuandoNonAmbigua(): void
    {
        $this->assertSame('2025-12-31', CsvReader::toDate('12/31/2025'));
    }

    public function testDataInesistenteRifiutata(): void
    {
        $this->assertNull(CsvReader::toDate('31/02/2025'));
    }

    public function testDataVuotaONull(): void
    {
        $this->assertNull(CsvReader::toDate(''));
        $this->assertNull(CsvReader::toDate('null'));
        $this->assertNull(CsvReader::toDate('non una data'));
    }

    /* ------------------------------------------------------------ importi */

    public function testImportoItalianoConSeparatoreMigliaia(): void
    {
        $this->assertSame('1234.56', CsvReader::toDecimal('1.234,56'));
    }

    public function testImportoAngloConSeparatoreMigliaia(): void
    {
        $this->assertSame('1234.56', CsvReader::toDecimal('1,234.56'));
    }

    public function testImportoConSolaVirgolaDecimale(): void
    {
        $this->assertSame('80.5', CsvReader::toDecimal('80,50'));
    }

    public function testImportoGiaInFormatoDatabase(): void
    {
        $this->assertSame('80.5', CsvReader::toDecimal('80.50'));
    }

    public function testImportoConSimboloDiValutaESpazi(): void
    {
        $this->assertSame('1234.56', CsvReader::toDecimal('€ 1.234,56'));
    }

    public function testImportoNegativo(): void
    {
        $this->assertSame('-42.5', CsvReader::toDecimal('-42,50'));
    }

    public function testImportoNonNumerico(): void
    {
        $this->assertNull(CsvReader::toDecimal('n/d'));
        $this->assertNull(CsvReader::toDecimal(''));
        $this->assertNull(CsvReader::toDecimal('null'));
    }

    /* ------------------------------------------------------ intestazioni */

    /**
     * Maiuscole, spazi e underscore non devono contare: lo stesso campo arriva
     * scritto in troppi modi perché il confronto possa essere letterale.
     */
    public function testIntestazioniEquivalentiSiNormalizzanoUguali(): void
    {
        $atteso = CsvReader::normalizeHeader('client_name');

        $this->assertSame($atteso, CsvReader::normalizeHeader('Client Name'));
        $this->assertSame($atteso, CsvReader::normalizeHeader('  CLIENT-NAME '));
        $this->assertSame($atteso, CsvReader::normalizeHeader("\xEF\xBB\xBFclient_name"));
    }

    public function testIntestazioniDiverseRestanoDiverse(): void
    {
        $this->assertNotSame(
            CsvReader::normalizeHeader('client_name'),
            CsvReader::normalizeHeader('client_names')
        );
    }
}
