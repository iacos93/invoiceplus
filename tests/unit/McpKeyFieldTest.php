<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * La chiave MCP è write-only: il ciphertext serve solo a sapere se
 * l'integrazione è configurata e non deve mai finire nella pagina.
 *
 * Se finisse nell'HTML (attributo value, data-*, JSON di esempio) chiunque
 * apra il sorgente o un backup della pagina avrebbe il segreto. Il campo
 * parte quindi sempre vuoto e si riempie solo con la risposta di
 * mcp/ajax/get_key, l'unica occasione in cui la chiave esiste in chiaro.
 *
 * @internal
 */
final class McpKeyFieldTest extends CIUnitTestCase
{
    private const CIPHERTEXT = 'enc2:QUESTO-E-IL-CIFRATO-DA-NON-MOSTRARE';

    public function testIlCiphertextNonFinisceNellHtml(): void
    {
        helper('ip_helpers');

        $html = view('mcp/_key_field', ['mcpKeyStored' => self::CIPHERTEXT]);

        $this->assertStringNotContainsString('enc2:', $html);
        $this->assertStringNotContainsString(self::CIPHERTEXT, $html);
    }

    public function testIlCampoParteSempreVuoto(): void
    {
        helper('ip_helpers');

        $html = view('mcp/_key_field', ['mcpKeyStored' => self::CIPHERTEXT]);

        $this->assertMatchesRegularExpression('/id="mcp_key"[^>]*value=""/s', $html);
        $this->assertStringNotContainsString('value="' . self::CIPHERTEXT . '"', $html);
    }

    public function testConChiaveIlPlaceholderDiceCheNonEVibile(): void
    {
        helper('ip_helpers');

        $html = view('mcp/_key_field', ['mcpKeyStored' => self::CIPHERTEXT]);

        // placeholder usa esc(..., 'attr'): nel sorgente gli spazi e le
        // lettere accentate sono entità, nel DOM il testo è quello atteso.
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertStringContainsString('La chiave non viene più mostrata', $decoded);
    }

    public function testSenzaChiaveIlPlaceholderInvitaAGenerarla(): void
    {
        helper('ip_helpers');

        $html    = view('mcp/_key_field', ['mcpKeyStored' => '']);
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertStringContainsString('Nessuna chiave configurata', $decoded);
        $this->assertStringNotContainsString('La chiave non viene più mostrata', $decoded);
    }

    public function testIlPulsanteCopiaEDisegnatoAccantoAlCampo(): void
    {
        helper('ip_helpers');

        $html = view('mcp/_key_field', ['mcpKeyStored' => '']);

        $this->assertStringContainsString('id="btn_copy_mcp_key"', $html);
        $this->assertStringContainsString('id="btn_generate_mcp_key"', $html);
    }
}
