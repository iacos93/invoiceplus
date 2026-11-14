<?php

use App\Libraries\Mcp\McpTools;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Un tool MCP che lancia non deve restituire al client il messaggio
 * dell'eccezione (SQLSTATE, nomi di tabelle, percorsi): quello resta nel log,
 * correlato da un identificativo breve che il client riceve.
 *
 * @internal
 */
final class McpInternalErrorTest extends CIUnitTestCase
{
    public function testIlClientRiceveSoloIlMessaggioGenericoConLIdentificativo(): void
    {
        $errore = new RuntimeException('SQLSTATE[42S02] tabella segreta');

        $result = McpTools::internalError('list_invoices', $errore);

        $this->assertTrue($result['isError']);

        $testo = $result['content'][0]['text'];

        $this->assertStringContainsString('list_invoices', $testo);
        $this->assertStringNotContainsString('SQLSTATE', $testo);
        $this->assertStringNotContainsString('tabella segreta', $testo);
        $this->assertMatchesRegularExpression('/Identificativo: [0-9a-f]{8}/', $testo);
    }

    public function testIlLogConservaIlDettaglioELoStessoIdentificativo(): void
    {
        $errore = new RuntimeException('SQLSTATE[42S02] tabella segreta');

        $result = McpTools::internalError('get_invoice', $errore);
        $testo  = $result['content'][0]['text'];

        $this->assertSame(1, preg_match('/Identificativo: ([0-9a-f]{8})/', $testo, $matches));
        $identificativo = $matches[1];

        $this->assertLogContains('error', 'SQLSTATE[42S02] tabella segreta');
        $this->assertLogContains('error', 'get_invoice');
        $this->assertLogContains('error', '[' . $identificativo . ']');
    }
}
