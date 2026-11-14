<?php

use App\Libraries\StatementImporter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * L'estratto conto entra nella riconciliazione: un importo con il segno
 * sbagliato o una data letta al contrario abbina il pagamento al documento
 * sbagliato, e l'errore non si vede finché qualcuno non controlla il saldo.
 * Questi test fissano le forme che le banche italiane producono davvero.
 *
 * @internal
 */
final class StatementImporterTest extends CIUnitTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    /** @return list<array{date: string, amount: float, description: string}> */
    private function leggi(string $contenuto, string $nome = 'estratto.csv'): array
    {
        $path = tempnam(sys_get_temp_dir(), 'stmt');
        file_put_contents($path, $contenuto);
        $this->files[] = $path;

        return (new StatementImporter())->parse($path, $nome);
    }

    public function testCsvConPuntoEVirgolaEImportiItaliani(): void
    {
        $movimenti = $this->leggi("Data;Descrizione;Importo\n15/01/2026;Bonifico da Rossi;1.234,56\n16/01/2026;Commissioni;-2,50\n");

        $this->assertSame([
            ['date' => '2026-01-15', 'amount' => 1234.56, 'description' => 'Bonifico da Rossi'],
            ['date' => '2026-01-16', 'amount' => -2.5, 'description' => 'Commissioni'],
        ], $movimenti);
    }

    public function testCsvConColonneEntrateEUsciteSeparate(): void
    {
        $movimenti = $this->leggi("Data;Causale;Uscite;Entrate\n10/02/2026;Pagamento fornitore;100,00;\n11/02/2026;Incasso fattura;;50,00\n12/02/2026;Gia negativa;-30,00;\n");

        $this->assertSame(-100.0, $movimenti[0]['amount'], 'Una colonna Uscite vale sempre negativo');
        $this->assertSame(50.0, $movimenti[1]['amount']);
        $this->assertSame(-30.0, $movimenti[2]['amount'], 'Un\'uscita gia\' negativa non si ribalta');
    }

    public function testLeRigheDecorativeInTestaVengonoSaltate(): void
    {
        $movimenti = $this->leggi("Estratto conto al 31/01/2026\n\nConto IT60...\nData;Descrizione;Importo\n05/01/2026;Prelievo;-40,00\n");

        $this->assertCount(1, $movimenti);
        $this->assertSame('Prelievo', $movimenti[0]['description']);
    }

    public function testIlBomEIlSeparatoreVirgolaConImportoTraVirgolette(): void
    {
        $movimenti = $this->leggi("\xEF\xBB\xBFDate,Description,Amount\n2026-03-04,\"Wire, customer\",\"1,234.56\"\n");

        $this->assertSame([['date' => '2026-03-04', 'amount' => 1234.56, 'description' => 'Wire, customer']], $movimenti);
    }

    public function testAnnoACifreDueEDateImpossibili(): void
    {
        $movimenti = $this->leggi("Data;Descrizione;Importo\n05/03/26;Anno corto;10,00\n31/02/2026;Data impossibile;10,00\n30.04.2026;Con i punti;5,00\n");

        $date = array_column($movimenti, 'date');

        $this->assertSame(['2026-03-05', '2026-04-30'], $date, 'Il 31/02 va saltato, non scritto cosi\' com\'e\'');
    }

    public function testIMovimentiAZeroEleRigheSenzaDataSiSaltano(): void
    {
        $movimenti = $this->leggi("Data;Descrizione;Importo\n05/03/2026;Zero;0,00\n;Senza data;10,00\n06/03/2026;Valido;10,00\n");

        $this->assertCount(1, $movimenti);
        $this->assertSame('Valido', $movimenti[0]['description']);
    }

    public function testImportoConSimboloEuroESpaziInsecabili(): void
    {
        $movimenti = $this->leggi("Data;Descrizione;Importo\n05/03/2026;Con simbolo;€\u{00A0}1.000,00\n");

        $this->assertSame(1000.0, $movimenti[0]['amount']);
    }

    public function testCamt053ConVersoDelMovimento(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">
  <BkToCstmrStmt>
    <Stmt>
      <Ntry>
        <Amt Ccy="EUR">100.00</Amt>
        <CdtDbtInd>DBIT</CdtDbtInd>
        <BookgDt><Dt>2026-01-20</Dt></BookgDt>
        <NtryDtls><TxDtls><RmtInf><Ustrd>Pagamento fornitore</Ustrd></RmtInf></TxDtls></NtryDtls>
      </Ntry>
      <Ntry>
        <Amt Ccy="EUR">250.50</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
        <BookgDt><Dt>2026-01-21T10:00:00</Dt></BookgDt>
        <AddtlNtryInf>Incasso fattura 15</AddtlNtryInf>
      </Ntry>
      <Ntry>
        <Amt Ccy="EUR">9.00</Amt>
        <CdtDbtInd>CRDT</CdtDbtInd>
      </Ntry>
    </Stmt>
  </BkToCstmrStmt>
</Document>
XML;

        $movimenti = $this->leggi($xml, 'camt.xml');

        $this->assertSame([
            ['date' => '2026-01-20', 'amount' => -100.0, 'description' => 'Pagamento fornitore'],
            ['date' => '2026-01-21', 'amount' => 250.5, 'description' => 'Incasso fattura 15'],
        ], $movimenti, 'DBIT e\' un\'uscita; la voce senza data si salta; la data si tronca a 10 caratteri');
    }

    public function testCamtConPrefissiDiNamespace(): void
    {
        $xml = '<?xml version="1.0"?><ns2:Document xmlns:ns2="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08"><ns2:BkToCstmrStmt><ns2:Stmt>'
             . '<ns2:Ntry><ns2:Amt Ccy="EUR">12.34</ns2:Amt><ns2:CdtDbtInd>CRDT</ns2:CdtDbtInd><ns2:BookgDt><ns2:Dt>2026-05-02</ns2:Dt></ns2:BookgDt></ns2:Ntry>'
             . '</ns2:Stmt></ns2:BkToCstmrStmt></ns2:Document>';

        $movimenti = $this->leggi($xml, 'camt.xml');

        $this->assertCount(1, $movimenti);
        $this->assertSame(12.34, $movimenti[0]['amount']);
    }

    public function testFileOltreIlLimiteDiLetturaVieneIgnorato(): void
    {
        // Un CSV valido in testa, poi il file viene portato oltre i 10 MB:
        // senza il tetto interno il parser leggerebbe il primo movimento.
        $path = tempnam(sys_get_temp_dir(), 'stmt');
        file_put_contents($path, "Data;Descrizione;Importo\n15/01/2026;Bonifico;10,00\n");

        $handle = fopen($path, 'r+');
        ftruncate($handle, 10 * 1024 * 1024 + 1);
        fclose($handle);

        $this->files[] = $path;

        $this->assertSame([], (new StatementImporter())->parse($path, 'estratto.csv'));
    }

    public function testFileVuotoOIllegibileDaListaVuota(): void
    {
        $this->assertSame([], $this->leggi(''));
        $this->assertSame([], $this->leggi("   \n  "));
        $this->assertSame([], $this->leggi('<Document><non chiuso'));
        $this->assertSame([], $this->leggi("Colonna uno;Colonna due\n1;2\n"), 'Senza intestazione riconoscibile non si inventa nulla');
        $this->assertSame([], (new StatementImporter())->parse('/percorso/che/non/esiste.csv', 'x.csv'));
    }
}
