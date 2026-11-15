<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Il campo della chiave MCP: niente segreto nell'HTML, e un testo onesto.
 *
 * Il ciphertext non deve mai finire nella pagina (attributo value, data-*,
 * JSON di esempio): chiunque apra il sorgente o un backup della pagina avrebbe
 * il segreto. Il campo parte quindi sempre vuoto e si riempie solo per ajax —
 * con la chiave appena generata (mcp/ajax/get_key) o con quella configurata,
 * che arriva quando si preme Copia (mcp/ajax/reveal_key).
 *
 * Lo stato lo calcola il parziale, non glielo passa la pagina: quando arrivava
 * da fuori, il campo diceva «nessuna chiave configurata» mentre il badge in
 * cima diceva attivo.
 *
 * @internal
 */
final class McpKeyFieldTest extends CIUnitTestCase
{
    private const CIPHERTEXT = 'enc2:QUESTO-E-IL-CIFRATO-DA-NON-MOSTRARE';

    public function testIlCiphertextNonFinisceNellHtml(): void
    {
        helper('ip_helpers');

        $html = view('mcp/_key_field');

        $this->assertStringNotContainsString('enc2:', $html);
        $this->assertStringNotContainsString(self::CIPHERTEXT, $html);
    }

    public function testIlCampoParteSempreVuoto(): void
    {
        helper('ip_helpers');

        $html = view('mcp/_key_field');

        $this->assertMatchesRegularExpression('/id="mcp_key"[^>]*value=""/s', $html);
    }

    public function testIlPulsanteCopiaEDisegnatoAccantoAlCampo(): void
    {
        helper('ip_helpers');

        $html = view('mcp/_key_field');

        $this->assertStringContainsString('id="btn_copy_mcp_key"', $html);
        $this->assertStringContainsString('id="btn_generate_mcp_key"', $html);
    }

    /**
     * La chiave in .env (mcp.key) è quella che il server /mcp confronta: con il
     * database vuoto l'integrazione è comunque attiva, e il campo non deve dire
     * il contrario.
     */
    public function testLaChiaveInEnvContaComeConfigurata(): void
    {
        helper('ip_helpers');

        $_ENV['mcp.key'] = 'CHIAVE-DA-ENV';

        try {
            $html = html_entity_decode(view('mcp/_key_field'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } finally {
            unset($_ENV['mcp.key']);
        }

        $this->assertStringContainsString('premi Copia per mostrarla e copiarla', $html);
        $this->assertStringNotContainsString('Nessuna chiave configurata', $html);
    }

    public function testIlTestoDelCampoDistingueChiavePresenteEAssente(): void
    {
        helper('ip_helpers');

        // Con una chiave si copia quella che c'è: rigenerarla sconnetterebbe i
        // client già configurati, quindi il campo non manda a rigenerare.
        $this->assertStringContainsString('premi Copia per mostrarla e copiarla', mcp_key_placeholder(true));
        $this->assertStringNotContainsString('rigenerala per vederne una nuova', mcp_key_placeholder(true));

        $this->assertStringContainsString('Nessuna chiave configurata', mcp_key_placeholder(false));
        $this->assertStringNotContainsString('premi Copia', mcp_key_placeholder(false));
    }
}
