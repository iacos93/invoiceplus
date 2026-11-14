<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Stores the default settings for the ContentSecurityPolicy, if you
 * choose to use it. The values here will be read in and set as defaults
 * for the site. If needed, they can be overridden on a page-by-page basis.
 *
 * Suggested reference for explanations:
 *
 * @see https://www.html5rocks.com/en/tutorials/security/content-security-policy/
 */
class ContentSecurityPolicy extends BaseConfig
{
    /**
     * Modalita' scelta in .env con app.cspMode: off | report | enforce.
     *
     * - "enforce" applica la policy: il browser blocca cio' che non ammette.
     * - "report" manda Content-Security-Policy-Report-Only: segnala senza
     *   bloccare, nella console del browser e, se c'e', a app.cspReportUri.
     * - "off" non manda niente.
     *
     * Senza scelta esplicita: "enforce" in produzione, "report" altrove. In
     * sviluppo il server integrato parte su un'altra porta quando la 8080 e'
     * occupata, ma app.baseURL resta quello del .env: gli asset diventerebbero
     * di un altro sito e la policy li bloccherebbe, lasciando l'app senza CSS
     * ne' JavaScript. In produzione indirizzo e baseURL coincidono.
     *
     * Un valore sconosciuto vale "report", mai "enforce" e mai "off": un errore
     * di battitura non deve spegnere la protezione ne' bloccare l'app.
     *
     * Percorrendo ~48 schermate (liste, schede, modifiche, anteprime in iframe,
     * impostazioni, portale cliente, login) con la policy applicata non e'
     * emersa nessuna violazione; vedi docs/installazione-e-aggiornamento.md.
     */
    public static function mode(): string
    {
        // CI_ENVIRONMENT e' la stessa fonte da cui il framework ricava la
        // costante ENVIRONMENT; letta qui perche' la costante, a un'analisi
        // statica, ha un valore solo e il confronto risulterebbe fisso.
        $default = (string) env('CI_ENVIRONMENT', 'production') === 'production' ? 'enforce' : 'report';
        $mode    = strtolower(trim((string) env('app.cspMode', $default)));

        return in_array($mode, ['off', 'report', 'enforce'], true) ? $mode : 'report';
    }

    // -------------------------------------------------------------------------
    // Broadbrush CSP management
    // -------------------------------------------------------------------------

    public bool $reportOnly = true;

    public ?string $reportURI = null;

    public ?string $reportTo = null;

    public bool $upgradeInsecureRequests = false;

    // -------------------------------------------------------------------------
    // Direttive
    //
    // La policy chiude cio' che nessuna pagina usa: plugin, base URI diversi,
    // form verso altri siti, incorporazione in siti esterni, script, font e
    // immagini da origini non elencate. Le sole origini esterne sono i due
    // host di Google Fonts (layout/fonts.php).
    //
    // 'unsafe-inline' resta su script e stili, e va detto: 53 viste hanno
    // <script> inline e la navigazione parziale (ip-pjax.js) riesegue gli
    // script presi da un'altra risposta, il cui nonce non sarebbe quello della
    // pagina aperta. Una policy a nonce richiede di spostare quegli script in
    // file e di rifare il pjax: finche' non e' fatto, questa policy non ferma
    // un XSS riflesso che inietta uno <script> in linea, ma impedisce che
    // porti codice da fuori o spedisca dati altrove (connect-src, form-action).
    // -------------------------------------------------------------------------

    public $defaultSrc = 'self';

    public $scriptSrc = ['self', 'unsafe-inline'];

    public array|string $scriptSrcElem = ['self', 'unsafe-inline'];

    public array|string $scriptSrcAttr = ['unsafe-inline'];

    public $styleSrc = ['self', 'unsafe-inline', 'https://fonts.googleapis.com'];

    public array|string $styleSrcElem = ['self', 'unsafe-inline', 'https://fonts.googleapis.com'];

    public array|string $styleSrcAttr = ['unsafe-inline'];

    // data: per i QR code (F24, avvisi di pagamento) e le icone in linea.
    public $imageSrc = ['self', 'data:'];

    public $baseURI = 'self';

    public $childSrc = 'self';

    public $connectSrc = 'self';

    public $fontSrc = ['self', 'https://fonts.gstatic.com'];

    public $formAction = 'self';

    public $frameAncestors = 'self';

    public $frameSrc = 'self';

    public $mediaSrc;

    public $objectSrc = 'none';

    public $manifestSrc;

    public array|string $workerSrc = [];

    public $pluginTypes;

    public $sandbox;

    public string $styleNonceTag = '{csp-style-nonce}';

    public string $scriptNonceTag = '{csp-script-nonce}';

    // Spento di proposito: con un nonce nell'header 'unsafe-inline' verrebbe
    // ignorato dai browser, e gli script in linea smetterebbero di girare.
    public bool $autoNonce = false;

    public function __construct()
    {
        parent::__construct();

        $this->reportOnly = self::mode() !== 'enforce';

        // Indirizzo a cui il browser spedisce una segnalazione per ogni
        // violazione (app.cspReportUri in .env). Facoltativo: serve a passare
        // da "report" a "enforce" senza dover aprire a mano ogni pagina con la
        // console del browser, e in "enforce" dice cosa sta venendo bloccato
        // davvero. Vuoto = nessuna segnalazione.
        $uri             = trim((string) env('app.cspReportUri', ''));
        $this->reportURI = $uri !== '' ? $uri : null;
    }
}
