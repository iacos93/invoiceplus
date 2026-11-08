<?php

namespace App\Libraries;

/**
 * Anteprima leggibile di una fattura elettronica.
 *
 * Il tracciato FatturaPA e' XML: per leggerlo si applica un foglio di stile
 * XSLT, esattamente come fanno i portali di consultazione. Ne esistono due
 * diffusi e questa classe li tratta allo stesso modo:
 *
 *   - 'ade'  — foglio di stile dell'Agenzia delle Entrate (versione ministeriale)
 *   - 'asso' — foglio di stile AssoSoftware (layout piu' compatto, molto usato
 *              dai gestionali italiani)
 *
 * I file .xsl NON sono inclusi nel repository: sono documenti di terze parti e
 * vanno scaricati dalle rispettive fonti e messi in app/Libraries/fatturapa/xsl/
 * con i nomi indicati in STYLES. Finche' mancano, l'anteprima lo dice invece di
 * fallire in modo oscuro.
 */
class FatturaPreview
{
    /**
     * Fogli di stile riconosciuti.
     *
     * 'file'  e' il nome canonico, quello con cui il foglio viene pubblicato.
     * 'match' e' una regex sul nome del file: lo stesso foglio circola con nomi
     *         diversi a seconda di dove lo si preleva (AssoSoftware non lo
     *         distribuisce come file a se', quindi chi lo estrae da AssoInvoice
     *         o lo salva da un gestionale se lo ritrova chiamato in altri modi).
     *         Basta copiare il .xsl nella cartella: se il nome combacia con il
     *         pattern viene riconosciuto lo stesso, senza doverlo rinominare.
     *
     * @var array<string, array{label: string, file: string, match: string, source: string}>
     */
    public const STYLES = [
        'ade' => [
            'label'  => 'Agenzia delle Entrate',
            'badge'  => 'Ministeriale',
            'desc'   => 'Tracciato ufficiale ordinario',
            // Nome del file come pubblicato da fatturapa.gov.it: e' quello della
            // Fattura Ordinaria 1.2.3, in vigore dal 1 aprile 2025. Rende anche
            // i documenti 1.2.x precedenti (stesso namespace .../fatture/v1.2).
            'file'   => 'Foglio_di_stile_fattura_ordinaria_ver1.2.3.xsl',
            'match'  => '/(ordinaria|fatturapa).*\.xsl$/i',
            'source' => 'https://www.fatturapa.gov.it/it/norme-e-regole/documentazione-fattura-elettronica/formato-fatturapa/',
        ],
        'asso' => [
            'label'  => 'AssoSoftware',
            'badge'  => 'Gestionale',
            'desc'   => 'Layout compatto per aziende',
            // AssoSoftware non pubblica il foglio come file a se': viene
            // distribuito dentro l'applicazione AssoInvoice, da cui va estratto
            // e copiato qui con questo nome.
            'file'   => 'FoglioStileAssoSoftware.xsl',
            'match'  => '/asso.*\.xsl$/i',
            'source' => 'https://www.assosoftware.it/servizi-offerti/assoinvoice/',
        ],
    ];

    // Predefinito: quello dell'Agenzia delle Entrate, l'unico che si possa
    // installare scaricando un singolo file dalla fonte ufficiale.
    public const DEFAULT_STYLE = 'ade';

    public function stylesheetDir(): string
    {
        return APPPATH . 'Libraries/fatturapa/xsl/';
    }

    /**
     * Stili disponibili, con il flag di presenza del file .xsl.
     *
     * @return array<string, array{label: string, file: string, source: string, installed: bool}>
     */
    public function styles(): array
    {
        $out = [];

        foreach (self::STYLES as $key => $style) {
            $style['installed'] = is_file($this->stylesheetDir() . $style['file']);
            $out[$key]          = $style;
        }

        return $out;
    }

    /**
     * Percorso del foglio di stile installato per questo stile, o null.
     * Prima il nome canonico, poi qualunque .xsl nella cartella il cui nome
     * combaci con il pattern dello stile.
     */
    public function resolve(string $style): ?string
    {
        if (!isset(self::STYLES[$style])) {
            return null;
        }

        $exact = $this->stylesheetDir() . self::STYLES[$style]['file'];

        if (is_file($exact)) {
            return $exact;
        }

        foreach ((array) glob($this->stylesheetDir() . '*.[xX][sS][lL]') as $candidate) {
            if (preg_match(self::STYLES[$style]['match'], basename((string) $candidate))) {
                return (string) $candidate;
            }
        }

        return null;
    }

    public function isInstalled(string $style): bool
    {
        return $this->resolve($style) !== null;
    }

    /**
     * Trasforma l'XML in HTML con il foglio di stile scelto.
     *
     * @return array{ok: bool, html?: string, error?: string, style: string}
     */
    public function render(string $xml, string $style = self::DEFAULT_STYLE): array
    {
        $style = isset(self::STYLES[$style]) ? $style : self::DEFAULT_STYLE;

        if (!extension_loaded('xsl')) {
            return ['ok' => false, 'style' => $style, 'error' => 'Estensione PHP ext-xsl non attiva: senza non e\' possibile applicare il foglio di stile.'];
        }

        if (!$this->isInstalled($style)) {
            return [
                'ok'    => false,
                'style' => $style,
                // Percorso relativo: quello assoluto esponeva l'albero del
                // server in pagina e non stava su una riga.
                'error' => 'Foglio di stile "' . self::STYLES[$style]['label'] . '" non installato: manca '
                    . 'app/Libraries/fatturapa/xsl/' . self::STYLES[$style]['file'],
            ];
        }

        if (trim($xml) === '') {
            return ['ok' => false, 'style' => $style, 'error' => 'Nessun XML da mostrare.'];
        }

        // L'XML puo' arrivare da terzi (fattura di acquisto ricevuta): niente
        // entita' esterne, e al foglio di stile si vieta l'accesso a rete e
        // filesystem. Senza questi vincoli un documento ostile potrebbe farsi
        // leggere file locali dal processore XSLT.
        $previous = libxml_use_internal_errors(true);

        $doc = new \DOMDocument();
        $ok  = $doc->loadXML($xml, LIBXML_NONET);

        if (!$ok) {
            $msg = $this->firstLibxmlError() ?: 'XML non leggibile.';
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return ['ok' => false, 'style' => $style, 'error' => 'XML non valido: ' . $msg];
        }

        $xsl = new \DOMDocument();
        if (!$xsl->load((string) $this->resolve($style), LIBXML_NONET)) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return ['ok' => false, 'style' => $style, 'error' => 'Foglio di stile non leggibile.'];
        }

        $proc = new \XSLTProcessor();

        if (defined('XSL_SECPREF_WRITE_FILE')) {
            $proc->setSecurityPrefs(
                XSL_SECPREF_WRITE_FILE | XSL_SECPREF_CREATE_DIRECTORY | XSL_SECPREF_WRITE_NETWORK | XSL_SECPREF_READ_NETWORK
            );
        }

        $proc->importStylesheet($xsl);
        $html = @$proc->transformToXml($doc);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($html === false || $html === null || trim((string) $html) === '') {
            return ['ok' => false, 'style' => $style, 'error' => 'Il foglio di stile non ha prodotto output per questo documento.'];
        }

        return ['ok' => true, 'style' => $style, 'html' => self::injectPreviewChrome((string) $html)];
    }

    /**
     * Antepone un <style> minimo all'HTML del foglio di stile prima di
     * incorporarlo nell'iframe sandbox.
     *
     * I fogli AdE/AssoSoftware sono documenti di terzi (non nel repository,
     * vedi commento di classe): non emettono ne' sfondo ne' centratura,
     * quindi il documento veniva incollato in alto a sinistra dell'iframe —
     * su un monitor largo, meta' schermo restava vuota a destra. Qui non si
     * conosce la struttura interna del foglio (tabella o div, larghezza
     * fissa o 100%), quindi niente selettori di classe: si centra con
     * flexbox sul <body> (funziona qualunque sia il markup del figlio, se ha
     * una larghezza intrinseca) e si tratta il figlio diretto come un foglio
     * A4 bianco appoggiato su un canvas neutro, per l'effetto "pagina" —
     * coerente col timbro "ANTEPRIMA" ruotato gia' presente nella barra
     * sopra l'iframe.
     *
     * Il canvas usa lo stesso grigio neutro delle superfici dell'app
     * (#e7e7ec in chiaro, #16161a in scuro via prefers-color-scheme) cosi'
     * il foglio si stacca senza introdurre colori nuovi. Il foglio stesso e'
     * bianco con ombra morbida, come il PDF che si scarica da /invoices/preview.
     *
     * Il tag viene anteposto senza cercare <head>/<body>: un <style> in testa
     * a un frammento HTML e' comunque valido e applicato dal parser del
     * browser, quindi funziona a prescindere da come il foglio strutturi il
     * resto del documento.
     */
    private static function injectPreviewChrome(string $html): string
    {
        // Rispetta fedelmente il modello ministeriale dell'Agenzia delle Entrate
        // e lo standard AssoSoftware, posizionandoli su una pagina A4 chiara e pulita.
        $style = '<style>'
            . 'html{background:#f0f2f5;color-scheme:light;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            . 'body{margin:0;padding:24px 16px;box-sizing:border-box;'
            . 'font-family:Arial,Helvetica,sans-serif;background:#f0f2f5;color:#000;}'
            . '/* Agenzia delle Entrate */'
            . '#fattura-container{width:100%;max-width:1100px;margin:0 auto;box-sizing:border-box;}'
            . '#fattura-elettronica{width:100% !important;max-width:1100px !important;margin:0 auto !important;box-sizing:border-box !important;}'
            . '#fattura-elettronica h1{color:#1a1a1a;font-family:Arial,Helvetica,sans-serif;text-align:left;padding:15px 0 10px 0;margin:0;font-size:26px;font-weight:bold;}'
            . '#fattura-elettronica div.page{background-color:#ffffff !important;border:1px solid #cccccc !important;border-radius:3px !important;box-shadow:0 2px 12px rgba(0,0,0,0.12) !important;margin:16px 0 32px 0 !important;padding:36px 40px !important;box-sizing:border-box !important;}'
            . '#fattura-elettronica table{max-width:100%;border-collapse:collapse;}'
            . '/* AssoSoftware */'
            . 'body:not(:has(#fattura-container)) #fattura-elettronica div.page,'
            . 'body:not(:has(#fattura-container)) > #fattura-elettronica{background-color:#ffffff !important;border:1px solid #cccccc !important;border-radius:3px !important;box-shadow:0 2px 12px rgba(0,0,0,0.12) !important;margin:16px auto 32px auto !important;padding:32px 36px !important;box-sizing:border-box !important;max-width:1000px !important;width:100% !important;}'
            . 'body:not(:has(#fattura-container)) .tbHeader,'
            . 'body:not(:has(#fattura-container)) .tableHead,'
            . 'body:not(:has(#fattura-container)) table{max-width:100% !important;width:100% !important;}'
            . '@media (max-width:768px){body{padding:10px 6px;}#fattura-elettronica div.page{padding:20px 14px !important;margin:10px 0 20px 0 !important;}#fattura-elettronica h1{font-size:20px;}}'
            . '@media print{body{background:#fff;padding:0;}#fattura-elettronica div.page{box-shadow:none !important;border:none !important;margin:0 !important;padding:20px 0 !important;}}'
            . '</style>';

        if (preg_match('/<\\/head\\s*>/i', $html)) {
            return (string) preg_replace('/<\\/head\\s*>/i', $style . '</head>', $html, 1);
        }

        return $style . $html;
    }

    /**
     * Estrae i campi chiave della fattura dall'XML, per mostrarli come
     * "cifre da registro" sopra l'anteprima senza dover aprire il foglio di
     * stile. La lettura e' indipendente dal namespace (usa local-name()), cosi'
     * funziona sia su FatturaPA 1.2 che su varianti di prefisso.
     *
     * @return array{tipo: string, numero: string, data: string, totale: string, imponibile: string, imposta: string, cedente: string, cessionario: string, progressivo: string, formato: string}
     */
    public function summary(string $xml): array
    {
        $empty = [
            'tipo'        => '',
            'numero'      => '',
            'data'        => '',
            'totale'      => '',
            'imponibile'  => '',
            'imposta'     => '',
            'cedente'     => '',
            'cessionario' => '',
            'progressivo' => '',
            'formato'     => '',
        ];

        if (trim($xml) === '') {
            return $empty;
        }

        $previous = libxml_use_internal_errors(true);
        $doc      = new \DOMDocument();

        if (!$doc->loadXML($xml, LIBXML_NONET)) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return $empty;
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($doc);
        $text  = static function (string $path) use ($xpath): string {
            $parts = array_map(
                static fn(string $n): string => "*[local-name()='" . str_replace("'", '', $n) . "']",
                explode('/', trim($path, '/'))
            );
            $nodes = $xpath->query('//' . implode('/', $parts));

            return ($nodes && $nodes->length) ? trim((string) $nodes->item(0)->textContent) : '';
        };

        // DatiRiepilogo puo' essere multiplo: si sommano imponibile e imposta.
        $sum = static function (string $path) use ($xpath): float {
            $parts = array_map(
                static fn(string $n): string => "*[local-name()='" . str_replace("'", '', $n) . "']",
                explode('/', trim($path, '/'))
            );
            $nodes = $xpath->query('//' . implode('/', $parts));
            $tot   = 0.0;
            if ($nodes) {
                foreach ($nodes as $n) {
                    $tot += (float) str_replace(',', '.', (string) $n->textContent);
                }
            }

            return $tot;
        };

        $tipoCode = $text('FatturaElettronicaBody/DatiGenerali/DatiGeneraliDocumento/TipoDocumento');

        // Le somme su DatiRiepilogo tornano come float: le si riformatta a 2
        // decimali come stringa, coerentemente con i campi testuali grezzi.
        $imponibile = $sum('FatturaElettronicaBody/DatiBeniServizi/DatiRiepilogo/ImponibileImporto');
        $imposta    = $sum('FatturaElettronicaBody/DatiBeniServizi/DatiRiepilogo/Imposta');

        return [
            'tipo'        => self::documentTypeLabel($tipoCode),
            'numero'      => $text('FatturaElettronicaBody/DatiGenerali/DatiGeneraliDocumento/Numero'),
            'data'        => $text('FatturaElettronicaBody/DatiGenerali/DatiGeneraliDocumento/Data'),
            'totale'      => $text('FatturaElettronicaBody/DatiGenerali/DatiGeneraliDocumento/ImportoTotaleDocumento'),
            'imponibile'  => $imponibile > 0
                ? sprintf('%.2f', $imponibile)
                : $text('FatturaElettronicaBody/DatiGenerali/DatiGeneraliDocumento/ImportoTotaleDocumento'),
            'imposta'     => $imposta > 0 ? sprintf('%.2f', $imposta) : '',
            'cedente'     => $text('FatturaElettronicaHeader/CedentePrestatore/DatiAnagrafici/Anagrafica/Denominazione')
                ?: $text('FatturaElettronicaHeader/CedentePrestatore/DatiAnagrafici/Anagrafica/Nome') . ' ' . $text('FatturaElettronicaHeader/CedentePrestatore/DatiAnagrafici/Anagrafica/Cognome'),
            'cessionario' => $text('FatturaElettronicaHeader/CessionarioCommittente/DatiAnagrafici/Anagrafica/Denominazione')
                ?: $text('FatturaElettronicaHeader/CessionarioCommittente/DatiAnagrafici/Anagrafica/Nome') . ' ' . $text('FatturaElettronicaHeader/CessionarioCommittente/DatiAnagrafici/Anagrafica/Cognome'),
            'progressivo' => $text('FatturaElettronicaHeader/DatiTrasmissione/ProgressivoInvio'),
            'formato'     => $text('FatturaElettronicaHeader/DatiTrasmissione/FormatoTrasmissione'),
        ];
    }

    /**
     * Etichetta italiana del TipoDocumento FatturaPA (TD01= Fattura, …).
     */
    public static function documentTypeLabel(string $code): string
    {
        $map = [
            'TD01' => 'Fattura',
            // Codici delle specifiche tecniche SdI: le etichette erano
            // sfasate di una riga (TD03 "nota di credito", TD04 "nota di
            // debito"), e l'anteprima di una nota di credito la chiamava
            // nota di debito.
            'TD02' => 'Acconto / anticipo su fattura',
            'TD03' => 'Acconto / anticipo su parcella',
            'TD04' => 'Nota di credito',
            'TD05' => 'Nota di debito',
            'TD06' => 'Parcella',
            'TD16' => 'Integrazione/ Reverse charge',
            'TD17' => 'Integrazione acquisto servizi',
            'TD18' => 'Integrazione acquisto beni',
            'TD19' => 'Integrazione autofattura',
            'TD20' => 'Autofattura per regolarizzazione',
            'TD21' => 'Autofattura per splafonamento',
            'TD22' => 'Estrazione da deposito IVA',
            'TD23' => 'Estrazione da deposito IVA (accisa)',
            'TD24' => 'Termine di cessazione sospensione',
            'TD25' => 'Trasformazione del fallimento',
            'TD26' => 'Fattura per acquisto da San Marino',
            'TD27' => 'Trasferimento beni terzo',
            'TD28' => 'Acquisto da PA (reverse charge)',
        ];

        return $map[$code] ?? ($code !== '' ? $code : '');
    }

    private function firstLibxmlError(): string
    {
        foreach (libxml_get_errors() as $e) {
            return trim($e->message) . ' (riga ' . $e->line . ')';
        }

        return '';
    }
}
