<?php

use App\Controllers\Exports;
use App\Libraries\CsvReader;
use App\Libraries\CsvWriter;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Neutralizzazione delle formule nei CSV in uscita.
 *
 * Un CSV che contiene una cella come "=cmd|'/c calc'!A1" è un file che esegue
 * comandi quando qualcuno lo apre in Excel: il dato arriva dall'utente (nome
 * cliente, descrizione riga, note) e l'esportazione lo scriveva com'era. Qui si
 * verifica il prefisso di neutralizzazione e, soprattutto, che il giro
 * esporta → reimporta resti coerente: la correzione non deve rendere i file
 * non reimportabili, che era già successo una volta con le intestazioni.
 *
 * @internal
 */
final class CsvFormulaInjectionTest extends CIUnitTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function cellePericolose(): iterable
    {
        yield 'uguale'     => ['=cmd'];
        yield 'piu'        => ['+1'];
        yield 'chiocciola' => ['@SUM(A1:A2)'];
        yield 'meno concatenato' => ['-1+1'];
        yield 'catena cmd'       => ["-2+3+cmd|' /C calc'!A0"];
        yield 'tab'        => ["\tvalore"];
        yield 'cr'         => ["\rvalore"];
    }

    #[DataProvider('cellePericolose')]
    public function testNeutralizzaLeCelleCheUnFoglioDiCalcoloEseguirebbe(string $valore): void
    {
        $this->assertSame("\t" . $valore, CsvWriter::neutralizeFormula($valore));
    }

    public function testLasciaInvariateLeCelleNormali(): void
    {
        $normali = ['Acme S.r.l.', '2026-01-15', '1.234,56', 'Rossi, Mario', '', '0'];

        foreach ($normali as $valore) {
            $this->assertSame($valore, CsvWriter::neutralizeFormula($valore));
        }
    }

    /**
     * Gli importi negativi prodotti da Exports::number() ("-1234,56") sono
     * numeri per Excel: devono restare tali, altrimenti nei CSV esportati le
     * note di credito e i saldi negativi diventano testo e le somme non
     * tornano.
     */
    public function testGliImportiNegativiRestanoNumeri(): void
    {
        $importi = ['-1', '-1234,56', '-1234.56', '-0,01', '-1000'];

        foreach ($importi as $importo) {
            $this->assertSame($importo, CsvWriter::neutralizeFormula($importo));
        }
    }

    /**
     * Il meno è innocuo solo se il valore è un numero semplice: una catena con
     * altri caratteri può essere una formula ed è neutralizzata.
     */
    public function testLeCateneCheInizianoConMenoRestanoNeutralizzate(): void
    {
        $catene = ['-1+1', "-2+3+cmd|' /C calc'!A0", '-1 2'];

        foreach ($catene as $catena) {
            $this->assertSame("\t" . $catena, CsvWriter::neutralizeFormula($catena));
        }
    }

    /**
     * Anche le intestazioni passano dalla stessa regola: sono scelte
     * dall'utente nei modelli di import e non devono poter diventare formule.
     */
    public function testNeutralizzaAncheLeIntestazioni(): void
    {
        $csv = CsvWriter::toString(['=cmd', 'nome'], [['valore', 'Acme']]);

        // fputcsv quota i campi che iniziano con spazio bianco, quindi si
        // rilegge la riga grezza e si guarda la cella vera.
        $righe = explode("\n", trim(CsvReader::stripBom($csv)));
        $celle = str_getcsv($righe[0], ';', '"', '\\');

        $this->assertSame(["\t=cmd", 'nome'], $celle);
    }

    /**
     * Il file scritto non deve contenere formule; il meno è ammesso solo per
     * i numeri semplici (importi negativi), che Excel tratta come numeri.
     */
    public function testNessunaCellaScrittaRestaUnaFormula(): void
    {
        $csv = CsvWriter::toString(
            ['nome', 'nota', 'a', 'b', 'c'],
            [
                ['=1+1', '@SUM(A1:A2)', '+1', '-1+1', '-2+3+cmd'],
                ['-1', '-1234,56', '-1234.56', 'Acme', '0'],
            ]
        );

        foreach (explode("\n", trim(CsvReader::stripBom($csv))) as $riga) {
            if ($riga === '') {
                continue;
            }

            foreach (str_getcsv($riga, ';', '"', '\\') as $cella) {
                // Vuota o neutralizzata con il TAB: va bene.
                if ($cella === '' || $cella[0] === "\t") {
                    continue;
                }

                // Il meno è lecito solo se il valore è un numero semplice.
                if ($cella[0] === '-') {
                    $this->assertSame(
                        1,
                        preg_match('/^-?\d+(?:[.,]\d+)*$/', $cella),
                        'Valore che inizia con meno e non è un numero: ' . $cella
                    );

                    continue;
                }

                $this->assertDoesNotMatchRegularExpression('/^[=+@]/', $cella, 'Cella non neutralizzata: ' . $cella);
            }
        }

        // Gli importi negativi arrivano numerici, senza TAB.
        $righe = CsvReader::rowsFromString($csv);

        $this->assertSame('-1', $righe[2][0]);
        $this->assertSame('-1234,56', $righe[2][1]);
        $this->assertSame('-1234.56', $righe[2][2]);
    }

    /**
     * Il TAB è spazio bianco e CsvReader fa trim() di ogni cella: il valore
     * torna identico, quindi i file esportati restano reimportabili.
     */
    public function testIlGiroConCsvReaderRestituisceGliStessiValori(): void
    {
        $riga = ['=cmd', '+1', '-1', '@SUM(A1:A2)', 'Acme S.r.l.', '-1234,56'];

        $csv    = CsvWriter::toString(['a', 'b', 'c', 'd', 'e', 'f'], [$riga]);
        $righe  = CsvReader::rowsFromString($csv);

        $this->assertSame($riga, $righe[1]);
    }

    /**
     * Limite noto e preesistente: la lettura fa trim(), quindi un valore che
     * inizia davvero con un TAB non è rappresentabile nel giro export→import
     * (perdeva il TAB anche prima della neutralizzazione). La neutralizzazione
     * aggiunge un TAB in scrittura, che la lettura consuma insieme agli altri.
     */
    public function testUnTabInizialeNelDatoNonSopravviveAllaLettura(): void
    {
        $csv = CsvWriter::toString(['valore'], [["\tvalore"]]);

        $this->assertSame([['valore'], ['valore']], CsvReader::rowsFromString($csv));
    }

    /**
     * L'export rapido "XLS" non passa da CsvWriter (è SpreadsheetML), ma usa
     * la stessa neutralizzazione per le celle di testo.
     */
    public function testAncheIlFoglioXlsNeutralizzaLeStringhe(): void
    {
        $metodo = new ReflectionMethod(Exports::class, 'buildXls');
        $xml    = $metodo->invoke(new Exports(), 'Foglio', ['=cmd'], [['=1+1', 'Acme S.r.l.', '-1234,56']]);

        $this->assertStringContainsString("\t=1+1", $xml);
        $this->assertStringContainsString("\t=cmd", $xml);
        $this->assertStringContainsString('Acme S.r.l.', $xml);
        // Gli importi negativi restano numeri anche nel foglio XLS.
        $this->assertStringContainsString('<Data ss:Type="Number">-1234.56</Data>', $xml);
    }
}
