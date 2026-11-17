<?php

use App\Models\SettingModel;

if (!function_exists('get_setting')) {
    /**
     * Legge un'impostazione di sistema (equivalente di get_setting() CI3).
     */
    function get_setting(string $setting_key, $default = '', bool $escape = false)
    {
        $value = (new SettingModel())->setting($setting_key, $default);
        return $escape ? htmlsc($value) : $value;
    }
}

if (!function_exists('mcp_key_configured')) {
    /**
     * La chiave del server MCP esiste, ovunque sia stata messa.
     *
     * Può stare nelle impostazioni (cifrata) oppure in `mcp.key` dentro `.env`,
     * che Mcp::authenticated() preferisce. Il pannello guardava solo il
     * database e nell'altro caso scriveva "nessuna chiave configurata" mentre
     * l'integrazione era attiva: due risposte diverse nella stessa schermata.
     *
     * Un solo posto decide, e non serve decifrare: basta sapere che c'è.
     */
    function mcp_key_configured(): bool
    {
        return trim((string) env('mcp.key', '')) !== ''
            || (string) get_setting('mcp_key', '') !== '';
    }
}

if (!function_exists('mcp_key_placeholder')) {
    /**
     * Il testo dentro il campo della chiave MCP.
     *
     * Con una chiave configurata manda a copiarla, non a rigenerarla: chi
     * rigenera per riavere la chiave sconnette tutti i client già configurati.
     * Senza chiave, invita a generarne una.
     */
    function mcp_key_placeholder(bool $configured): string
    {
        return $configured
            ? trans('mcp_key_placeholder', '', 'Chiave configurata: premi Copia per mostrarla e copiarla')
            : trans('mcp_key_placeholder_empty', '', 'Nessuna chiave configurata: generala per attivare l\'integrazione');
    }
}

if (!function_exists('selected_years_map')) {
    /**
     * L'anno scelto in ogni azienda: `[company_id => anno]`, 0 = tutti gli anni.
     *
     * Una scelta sola per tutte le aziende non regge: chi lavora su due soggetti
     * tiene periodi diversi — su una sta chiudendo il 2025, sull'altra emette il
     * 2026 — e cambiare azienda gli cambiava il periodo sotto le mani. Così ogni
     * azienda ricorda il suo, e il cambio azienda riporta dove si era.
     *
     * Sta in sessione e, per sopravvivere a logout e chiusura del browser, in un
     * cookie di un anno con la stessa mappa in JSON. Il cookie è piccolo e non
     * contiene altro che gli anni: sono numeri, una voce per azienda.
     *
     * @return array<int, int>
     */
    function selected_years_map(): array
    {
        $fromSession = session('selected_years');

        if (is_array($fromSession)) {
            return $fromSession;
        }

        $map = selected_years_from_cookies(
            (string) (service('request')->getCookie('ip_selected_years') ?? ''),
            (string) (service('request')->getCookie('ip_selected_year') ?? ''),
            active_company_id()
        );

        // Rimesso in sessione: le altre chiamate della stessa pagina (layout
        // compreso) non rileggono il cookie né rifanno query.
        session()->set('selected_years', $map);

        return $map;
    }
}

if (!function_exists('selected_years_from_cookies')) {
    /**
     * La mappa azienda => anno contenuta nei cookie.
     *
     * Separata dalla richiesta per poterla provare senza HTTP. Se c'è solo il
     * cookie vecchio — un anno per tutte le aziende — lo si attribuisce
     * all'azienda in cui si sta entrando, che è l'unica lettura sensata di quel
     * valore; alla prima scelta nuova sparisce da sé, perché da lì in poi si
     * scrive solo la mappa.
     *
     * @return array<int, int>
     */
    function selected_years_from_cookies(string $json, string $legacy, int $companyId): array
    {
        $map = [];

        if ($json !== '') {
            $decoded = json_decode($json, true);

            if (is_array($decoded)) {
                foreach ($decoded as $company => $year) {
                    $map[(int) $company] = (int) $year;
                }
            }
        }

        if ($map === [] && (int) $legacy !== 0) {
            $map[$companyId] = (int) $legacy;
        }

        return $map;
    }
}

if (!function_exists('selected_year')) {
    /**
     * Anno scelto nell'azienda attiva (selettore in sidebar), 0 = tutti gli anni.
     *
     * Persiste in sessione, non nella query string: a differenza del filtro
     * anno/mese locale di fatture e preventivi (che resta nell'URL così è
     * linkabile/condivisibile), questo è un contesto di navigazione — deve
     * seguire l'utente da una pagina all'altra senza che ogni link dell'app
     * debba propagarlo esplicitamente.
     */
    function selected_year(): int
    {
        $companyId = active_company_id();
        $map       = selected_years_map();

        if (!array_key_exists($companyId, $map)) {
            return 0;
        }

        $candidate = (int) $map[$companyId];

        // L'anno vale finché ha documenti in questa azienda: uno ormai vuoto
        // farebbe apparire un archivio deserto e sembrerebbe che il filtro non
        // funzioni. Il controllo si fa qui e non solo sul cookie perché il
        // periodo di un'azienda può essere rimasto indietro (documenti archiviati
        // o mai importati) mentre l'azienda attiva era un'altra.
        if ($candidate !== 0 && !in_array($candidate, available_years(), true)) {
            set_selected_year(0);

            return 0;
        }

        return $candidate;
    }
}

if (!function_exists('set_selected_year')) {
    /** Sceglie l'anno per l'azienda attiva, in sessione. */
    function set_selected_year(int $year): void
    {
        $map                      = selected_years_map();
        $map[active_company_id()] = $year;

        session()->set('selected_years', $map);
    }
}

if (!function_exists('remember_selected_year')) {
    /**
     * Copia l'anno scelto in un cookie che dura un anno, oltre che in sessione.
     *
     * Restituisce la Response su cui impostarlo: va chiamata sull'oggetto che
     * viene davvero inviato (di norma quello di redirect()), perché redirect()
     * costruisce una Response nuova e i cookie messi sul servizio globale non
     * la seguono.
     *
     * Nel cookie c'è solo la mappa degli anni per azienda — nessun dato del
     * documento. Il vecchio cookie con un anno solo (ip_selected_year) resta
     * letto da selected_years_map() per chi ce l'ha già, ma non viene più
     * scritto.
     */
    function remember_selected_year(
        \CodeIgniter\HTTP\ResponseInterface $response,
        int $year
    ): \CodeIgniter\HTTP\ResponseInterface {
        $map                      = selected_years_map();
        $map[active_company_id()] = $year;

        session()->set('selected_years', $map);

        return $response->setCookie('ip_selected_years', json_encode($map), YEAR, '', '/');
    }
}

if (!function_exists('request_months')) {
    /**
     * Mesi selezionati nella query string, come lista ordinata 1-12.
     *
     * La barra periodo delle liste accetta sia il vecchio "month=3" sia la
     * selezione multipla "month[]=1&month[]=2" (shift+clic sui mesi). Una
     * lista vuota significa "tutto l'anno", che è anche il valore storico di
     * month=0: così i link già in giro continuano a funzionare.
     *
     * @return list<int>
     */
    function request_months(string $param = 'month'): array
    {
        $raw = service('request')->getGet($param);

        if ($raw === null || $raw === '') {
            return [];
        }

        $values = is_array($raw) ? $raw : preg_split('/[,\s]+/', (string) $raw);
        $months = [];

        foreach ($values as $value) {
            // Intervallo compatto ("3-5"), comodo nei link condivisi a mano.
            if (preg_match('/^\s*(\d{1,2})\s*-\s*(\d{1,2})\s*$/', (string) $value, $range)) {
                $from = max(1, min(12, (int) $range[1]));
                $to   = max(1, min(12, (int) $range[2]));

                for ($m = min($from, $to); $m <= max($from, $to); $m++) {
                    $months[$m] = $m;
                }

                continue;
            }

            $m = (int) $value;

            if ($m >= 1 && $m <= 12) {
                $months[$m] = $m;
            }
        }

        ksort($months);

        return array_values($months);
    }
}

if (!function_exists('year_sql')) {
    /**
     * Espressione SQL dell'anno di una colonna data.
     *
     * Il filtro per anno era scritto a mano in una quarantina di punti, in due
     * forme (where del builder e concatenazione dentro l'SQL). Con l'espressione
     * in un posto solo, un cambio di dialetto o di formato della data non va
     * rincorso in ogni model.
     */
    function year_sql(string $column): string
    {
        return 'EXTRACT(YEAR FROM ' . $column . ')';
    }
}

if (!function_exists('days_since_sql')) {
    /**
     * Espressione SQL dei giorni trascorsi da una colonna data a oggi
     * (positiva se la data e' passata).
     *
     * Prima era scritto `CURRENT_DATE - colonna` in ogni model. Su PostgreSQL
     * la sottrazione fra date da' i giorni; su MariaDB le due date diventano
     * numeri AAAAMMGG e il risultato e' la loro differenza aritmetica: il
     * segno resta giusto (quindi "scaduta si'/no" funzionava), ma i giorni no
     * — dal 28 gennaio al 3 febbraio risultavano 75 giorni invece di 6, e le
     * fasce del report di anzianita' dei crediti finivano nella colonna
     * sbagliata. Ogni motore ha la sua funzione, quindi la scelta sta qui.
     */
    function days_since_sql(string $column): string
    {
        return match (db_connect()->DBDriver) {
            'MySQLi'  => 'DATEDIFF(CURRENT_DATE, ' . $column . ')',
            'SQLite3' => "CAST(julianday('now', 'localtime', 'start of day') - julianday(" . $column . ') AS INTEGER)',
            default   => '(CURRENT_DATE - ' . $column . ')',
        };
    }
}

if (!function_exists('year_filter_sql')) {
    /**
     * Condizione SQL per un anno, stringa vuota quando l'anno è 0 ("tutti gli
     * anni"). Evita di ripetere in ogni query il ternario $year > 0.
     */
    function year_filter_sql(string $column, int $year): string
    {
        return $year > 0 ? year_sql($column) . ' = ' . $year : '';
    }
}
if (!function_exists('ip_month_query')) {
    /**
     * Parametri query per il filtro mesi: scalare con un mese solo (URL
     * leggibile e compatibile con i link storici), intervallo compatto
     * "1-12" quando sono tutti, array quando sono più di uno ma non tutti.
     *
     * L'intervallo evita che "Tutti i mesi" produca dodici parametri
     * month[]=... in ogni link di filtro ed esportazione; request_months()
     * sa già leggere la forma "3-5", quindi il giro è simmetrico.
     *
     * @param list<int> $months
     *
     * @return array<string, int|string|list<int>>
     */
    function ip_month_query(array $months): array
    {
        $months = array_values(array_unique(array_filter(
            array_map('intval', $months),
            static fn (int $m): bool => $m >= 1 && $m <= 12
        )));
        sort($months);

        if ($months === []) {
            return [];
        }

        if (count($months) === 12) {
            return ['month' => '1-12'];
        }

        return count($months) === 1 ? ['month' => $months[0]] : ['month' => $months];
    }
}

if (!function_exists('available_years')) {
    /**
     * Anni disponibili per il selettore globale: unione degli anni con
     * almeno una fattura o un preventivo, dal più recente. Se non c'è
     * ancora nulla in archivio, l'anno corrente è comunque incluso così
     * il selettore non appare vuoto.
     */
    function available_years(): array
    {
        // Il selettore sta nel layout e selected_year() lo interroga per
        // validare l'anno: senza memoria le due SELECT DISTINCT partivano piu'
        // volte nella stessa pagina. La chiave e' l'azienda attiva, perche'
        // gli anni sono dentro il suo perimetro (e nei cron l'azienda cambia).
        static $cache = [];

        $companyId = active_company_id();
        if (isset($cache[$companyId])) {
            return $cache[$companyId];
        }

        $years = array_unique(array_merge(
            (new \App\Models\InvoiceModel())->getAvailableYears(),
            (new \App\Models\QuoteModel())->getAvailableYears(),
            [(int) date('Y')]
        ));
        rsort($years);

        return $cache[$companyId] = $years;
    }
}

if (!function_exists('import_result_message')) {
    /**
     * Esito di un import contatti: "N inseriti, N aggiornati, N ignorati".
     *
     * Clienti e fornitori avevano la stessa sprintf copiata: una modifica al
     * testo andava fatta due volte e le due frasi potevano divergere.
     *
     * @param array{imported?: int, updated?: int, skipped?: int} $result
     */
    function import_result_message(array $result): string
    {
        return sprintf(
            'Importazione completata: %d inseriti, %d aggiornati, %d ignorati.',
            (int) ($result['imported'] ?? 0),
            (int) ($result['updated'] ?? 0),
            (int) ($result['skipped'] ?? 0)
        );
    }
}
if (!function_exists('trans')) {
    /**
     * Traduce una chiave della lingua corrente (equivalente di trans() CI3).
     * I file lingua CI3 sono in app/Language/{locale}/ip_lang.php.
     */
    function trans(string $line, string $id = '', $default = null)
    {
        $value = lang('ip_lang.' . $line);

        if ($value === 'ip_lang.' . $line) {
            return $default ?? $line;
        }

        if ($id !== '') {
            return str_replace('%s', $id, $value);
        }

        return $value;
    }
}

if (!function_exists('htmlsc')) {
    /**
     * Escapa una stringa per l'output HTML (equivalente di htmlsc() CI3).
     */
    function htmlsc($output)
    {
        return htmlspecialchars((string) $output, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('flash_html')) {
    /**
     * Converte il testo di un messaggio flash (toast) in HTML sicuro.
     *
     * I flashdata contengono anche dati che arrivano da fuori: nome del file
     * importato, errori di validazione, P.IVA letta da un XML. Senza escaping
     * un file artigianale farebbe eseguire JavaScript nella sessione
     * dell'amministratore che lo importa. Si scappa tutto e si trasformano
     * soltanto i fine riga in <br>, l'unico markup che i toast usano.
     *
     * Va usata solo per i flashdata: il markup delle viste è fidato e non
     * deve passare di qui.
     */
    function flash_html(mixed $message): string
    {
        return nl2br(esc((string) $message), false);
    }
}

if (!function_exists('asset_version')) {
    /**
     * Marcatore di versione di un asset, ricavato dalla data di modifica del
     * file.
     *
     * Gli URL degli asset uscivano nudi (assets/core/css/custom.css), quindi
     * per il browser restavano lo stesso identico URL anche dopo aver
     * riscritto il CSS: la copia in cache continuava a valere e le correzioni
     * di stile non arrivavano a schermo finche' non si faceva un ricarico
     * forzato. E' un errore che si ripete a ogni modifica, e per giunta
     * silenzioso: in locale sembra tutto a posto perche' si lavora con i
     * DevTools aperti, mentre in produzione gli utenti vedono ancora il
     * foglio vecchio.
     *
     * filemtime cambia da solo a ogni salvataggio, quindi non c'e' un numero
     * di versione da ricordarsi di alzare a mano — che sarebbe lo stesso
     * errore, spostato di un passo.
     */
    function asset_version(string $relative): string
    {
        $path = FCPATH . $relative;

        return is_file($path) ? '?v=' . filemtime($path) : '';
    }
}

if (!function_exists('_theme_asset')) {
    /**
     * URL asset del tema attivo (equivalente di _theme_asset() CI3).
     */
    function _theme_asset(string $asset)
    {
        $relative = 'assets/invoiceplane/' . $asset;

        echo base_url($relative) . asset_version($relative);
    }
}

if (!function_exists('_core_asset')) {
    /**
     * URL asset core (equivalente di _core_asset() CI3).
     */
    function _core_asset(string $asset)
    {
        $relative = 'assets/core/' . $asset;

        echo base_url($relative) . asset_version($relative);
    }
}

if (!function_exists('is_nav_active')) {
    /**
     * Determina se una voce di menu e' attiva confrontando il primo
     * segmento dell'URL corrente con quello passato. Comoda per marcare
     * la sezione corrente nella navbar con aria-current="page".
     *
     * Esempio:
     *   <a class="nav-link <?= is_nav_active('invoices') ? 'active' : '' ?>"
     *      aria-current="<?= is_nav_active('invoices') ? 'page' : 'false' ?>">
     *
     * Passando un percorso piu' lungo (es. 'invoices/recurring') confronta
     * i segmenti corrispondenti, cosi' "invoices" non marca "invoices/recurring"
     * e viceversa.
     *
     * Con $exact = true si richiede la corrispondenza completa dell'intero
     * percorso (utile per il link "Home" dell'area guest: solo 'guest' e non
     * anche 'guest/invoices'). In tal caso 'invoices' non marca 'invoices/recurring'.
     */
    function is_nav_active(string $path, bool $exact = false): bool
    {
        $uri = service('request')->getUri();

        // getRoutePath() e' il percorso al netto di base path e index page: e'
        // il dato da confrontare con le rotte. getPath() invece li include —
        // con la configurazione predefinita restituisce "/index.php/clients" —
        // e confrontandolo direttamente nessuna voce risultava mai attiva.
        if (method_exists($uri, 'getRoutePath')) {
            $current = trim($uri->getRoutePath(), '/');
        } else {
            $current = trim($uri->getPath(), '/');

            // Ripiego per le implementazioni di URI che non espongono il
            // percorso di rotta: si toglie a mano l'index page. Vale solo se si
            // chiama "index.php"; e' il motivo per cui sopra si preferisce
            // getRoutePath(), che non dipende da come e' configurata.
            $indexPage = trim((string) (config('App')->indexPage ?? 'index.php'), '/');

            if ($indexPage !== '') {
                if (str_starts_with($current, $indexPage . '/')) {
                    $current = substr($current, strlen($indexPage) + 1);
                } elseif ($current === $indexPage) {
                    $current = '';
                }
            }
        }

        $expected = trim($path, '/');
        if ($current === '' || $expected === '') {
            return $current === $expected;
        }
        if ($exact && $current !== $expected) {
            return false;
        }
        $cParts = explode('/', $current);
        $eParts = explode('/', $expected);
        foreach ($eParts as $i => $seg) {
            if (!isset($cParts[$i]) || $cParts[$i] !== $seg) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('nav_active_class')) {
    /**
     * Scorciatoia per la classe attiva Bootstrap: restituisce 'active'
     * oppure stringa vuota. Da usarsi negli attributi class.
     */
    function nav_active_class(string $path, bool $exact = false): string
    {
        return is_nav_active($path, $exact) ? 'active' : '';
    }
}

if (!function_exists('nav_item_state')) {
    /**
     * Stato di una voce di primo livello della sidebar.
     *
     * L'evidenziazione piena va a chi rappresenta meglio "sei qui", e non e'
     * sempre la corrispondenza esatta:
     *
     *  - su /clients nessun dubbio: e' la voce Clienti;
     *  - su /clients/status/active o /clients/view/5 si e' dentro Clienti: la
     *    sotto-voce "Clienti" resta accesa e la madre si limita a scurirsi;
     *  - su /clients/form/5 comanda la sotto-voce piu' specifica ("Aggiungi
     *    cliente"), altrimenti si accenderebbero insieme lei e "Clienti";
     *  - su /invoices/view/5 nessuna sotto-voce contiene la pagina: il pieno
     *    torna alla voce madre.
     *
     * @param list<array{0: string, 1: string}> $children sotto-voci [rotta, etichetta]
     *
     * @return array{open: bool, here: bool, aria: string, child: ?string, child_aria: string}
     *         open = sezione da mostrare aperta
     *         here = evidenziazione piena della voce madre
     *         aria = valore di aria-current della voce madre
     *         child = rotta della sotto-voce attiva, null se nessuna
     *         child_aria = valore di aria-current della sotto-voce attiva
     */
    function nav_item_state(string $route, array $children = []): array
    {
        $isCurrent   = is_nav_active($route, true);
        $isOpen      = is_nav_active($route);
        $activeChild = null;
        $activeDepth = 0;

        foreach ($children as $child) {
            $childRoute = is_array($child) ? ($child[0] ?? '') : (string) $child;

            // is_nav_active() senza $exact contiene anche le pagine figlie:
            // clients/status/active e clients/view/5 stanno dentro "clients".
            if ($childRoute === '' || !is_nav_active($childRoute)) {
                continue;
            }

            $isOpen = true;

            // Fra più sotto-voci che contengono l'indirizzo vince la più
            // specifica: su /clients/form/5 "clients/form" batte "clients".
            $depth = substr_count(trim($childRoute, '/'), '/') + 1;

            if ($depth > $activeDepth) {
                $activeDepth = $depth;
                $activeChild = $childRoute;
            }
        }

        $here = $isOpen && $activeChild === null;

        return [
            'open' => $isOpen,
            'here' => $here,
            // "page" solo dove l'indirizzo combacia davvero; "true" per la
            // sezione in cui ci si trova. Quando una sotto-voce rappresenta la
            // pagina, il "page" è suo: la madre non lo duplica.
            'aria' => $here ? ($isCurrent ? 'page' : 'true') : '',
            'child' => $activeChild,
            'child_aria' => $activeChild === null
                ? ''
                : (is_nav_active($activeChild, true) ? 'page' : 'true'),
        ];
    }
}

if (!function_exists('header_actions')) {
    /**
     * Restituisce il markup HTML del gruppo bottoni Salva/Annulla per le
     * headerbar dei form. Sostituisce $this->include('layout/header_buttons')
     * nei casi in cui serve una stringa (es. da passare al partial
     * layout/headerbar come slot 'actions').
     *
     * @param array $opts hide_submit_button, hide_cancel_button (bool)
     */
    function header_actions(array $opts = []): string
    {
        $hide_submit = !empty($opts['hide_submit_button']);
        $hide_cancel = !empty($opts['hide_cancel_button']);

        // 'form' => id: il pulsante invia quel form anche se sta fuori dal
        // suo <form> (attributo HTML form=). Serve alle pagine che sotto il
        // form hanno altri form propri, che non possono stargli dentro.
        $formAttr = !empty($opts['form']) ? ' form="' . esc((string) $opts['form'], 'attr') . '"' : '';

        $html = '<div class="btn-group btn-group-sm">';
        if (!$hide_submit) {
            $html .= '<button type="submit" id="btn-submit" name="btn_submit" class="btn btn-success ajax-loader" value="1"' . $formAttr . '>'
                  . '<i class="fa-solid fa-check"></i> ' . trans('save') . '</button>';
        }
        if (!$hide_cancel) {
            $html .= '<button type="button" onclick="window.history.back()" id="btn-cancel" name="btn_cancel" class="btn btn-secondary" value="1">'
                  . '<i class="fa-solid fa-times"></i> ' . trans('cancel') . '</button>';
        }
        $html .= '</div>';

        return $html;
    }
}

if (!function_exists('check_select')) {
    /**
     * Emette selected="selected" / checked="checked" (equivalente di check_select() CI3).
     */
    function check_select($value1, $value2 = null, string $operator = '==', bool $checked = false)
    {
        $select = $checked ? 'checked="checked"' : 'selected="selected"';

        if (is_bool($value1) && $value2 === null) {
            echo $value1 ? $select : '';
            return;
        }

        switch ($operator) {
            case '==':
                $echo_selected = $value1 == $value2;
                break;
            case '!=':
                $echo_selected = $value1 != $value2;
                break;
            case 'e':
                $echo_selected = empty($value1);
                break;
            default:
                $echo_selected = (bool) $value1;
                break;
        }

        echo $echo_selected ? $select : '';
    }
}

if (!function_exists('format_currency')) {
    /**
     * Formatta un importo come valuta con il simbolo configurato
     * (equivalente di format_currency() CI3).
     *
     * $decimals permette le forme compatte senza centesimi (barra periodo,
     * etichette di riepilogo), dove la cifra serve a colpo d'occhio e i
     * centesimi allargano inutilmente il controllo.
     */
    function format_currency($amount, int $decimals = 2)
    {
        $settings = new SettingModel();
        $currency_symbol           = currency_symbol();
        $currency_symbol_placement = $settings->setting('currency_symbol_placement');
        $thousands_separator       = $settings->setting('thousands_separator', '.');
        $decimal_point             = $settings->setting('decimal_point', ',');

        // Se il separatore decimale non è configurato, la valuta non usa
        // decimali: il parametro non deve reintrodurli.
        $decimals = $decimal_point ? max(0, $decimals) : 0;

        $formatted = number_format((float) $amount, $decimals, $decimal_point, $thousands_separator);

        if ($currency_symbol_placement == 'before' || $currency_symbol_placement == 'beforespace') {
            $sep = $currency_symbol_placement == 'beforespace' ? "\u{00A0}" : '';
            return $currency_symbol . $sep . $formatted;
        }
        if ($currency_symbol_placement == 'afterspace') {
            return $formatted . "\u{00A0}" . $currency_symbol;
        }

        return $formatted . $currency_symbol;
    }
}

if (!function_exists('currency_symbol')) {
    /**
     * Restituisce il simbolo di valuta configurato, oppure lo deriva dal codice
     * valuta impostato quando il simbolo esplicito e' vuoto. Cosi' la dashboard
     * e le altre viste mostrano sempre un simbolo coerente con la valuta scelta,
     * anche prima che l'utente salvi il campo "Simbolo Valuta".
     */
    function currency_symbol(): string
    {
        $settings = new SettingModel();
        $symbol   = trim((string) $settings->setting('currency_symbol'));
        if ($symbol !== '') {
            return $symbol;
        }

        $map = [
            'EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF', 'JPY' => '¥',
            'CAD' => 'CA$', 'AUD' => 'A$', 'NZD' => 'NZ$', 'SEK' => 'kr', 'NOK' => 'kr',
            'DKK' => 'kr', 'PLN' => 'zł', 'CZK' => 'Kč', 'HUF' => 'Ft', 'RON' => 'lei',
            'BGN' => 'лв', 'RUB' => '₽', 'TRY' => '₺', 'INR' => '₹', 'CNY' => '¥',
            'BRL' => 'R$', 'MXN' => 'MX$', 'ZAR' => 'R', 'ILS' => '₪', 'SGD' => 'S$',
            'HKD' => 'HK$', 'KRW' => '₩', 'THB' => '฿', 'MYR' => 'RM', 'IDR' => 'Rp',
            'PHP' => '₱', 'VND' => '₫', 'AED' => 'د.إ', 'SAR' => '﷼', 'UAH' => '₴',
        ];

        $code = trim((string) $settings->setting('currency_code'));

        return $map[$code] ?? '€';
    }
}

if (!function_exists('currency_wrap')) {
    /**
     * Avvolge un numero gia' formattato con il simbolo di valuta, rispettando
     * la posizione (e lo spazio) configurata. Usato anche dalle etichette
     * compatte della dashboard, che non passano da format_currency().
     */
    function currency_wrap(string $value): string
    {
        $settings = new SettingModel();
        $sym      = currency_symbol();
        $placement = $settings->setting('currency_symbol_placement');

        if ($placement === 'before' || $placement === 'beforespace') {
            $sep = $placement === 'beforespace' ? "\u{00A0}" : '';
            return $sym . $sep . $value;
        }
        if ($placement === 'afterspace') {
            return $value . "\u{00A0}" . $sym;
        }

        return $value . $sym;
    }
}

if (!function_exists('default_bank_account_for_payment')) {
    /**
     * Conto di saldo da proporre per un incasso: quello del metodo di
     * pagamento, altrimenti il predefinito dell'azienda, altrimenti nessuno (0).
     *
     * Il conto del metodo vale solo se appartiene all'azienda attiva: i conti
     * sono per azienda, e un metodo che punta al conto di un'altra farebbe
     * finire l'incasso nel saldo di un soggetto che non c'entra. Un metodo
     * senza conto non eredita il primo conto che capita, ma il predefinito
     * dichiarato in Impostazioni: altrimenti il saldo si riempirebbe su un
     * conto scelto dall'ordine alfabetico.
     */
    function default_bank_account_for_payment(int $paymentMethodId): int
    {
        $bankAccounts = new \App\Models\BankAccountModel();

        if ($paymentMethodId > 0) {
            $method    = (new \App\Models\PaymentMethodModel())->find($paymentMethodId);
            $accountId = (int) ($method['payment_method_bank_account_id'] ?? 0);

            if ($accountId > 0 && $bankAccounts->findScoped($accountId) !== null) {
                return $accountId;
            }
        }

        $default = $bankAccounts->getDefault();

        return (int) ($default['bank_account_id'] ?? 0);
    }
}

if (!function_exists('format_amount')) {
    /**
     * Formatta un importo con i separatori configurati
     * (equivalente di format_amount() CI3).
     */
    function format_amount($amount = null)
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $settings           = new SettingModel();
        $thousands_separator = $settings->setting('thousands_separator', '.');
        $decimal_point       = $settings->setting('decimal_point', ',');

        return number_format((float) $amount, $decimal_point ? 2 : 0, $decimal_point, $thousands_separator);
    }
}

if (!function_exists('standardize_amount')) {
    /**
     * Normalizza un importo secondo i separatori di sistema
     * (equivalente di standardize_amount() CI3).
     */
    function standardize_amount($amount)
    {
        $settings           = new SettingModel();
        $thousands_separator = $settings->setting('thousands_separator', '.');
        $decimal_point       = $settings->setting('decimal_point', ',');

        $amount = str_replace($thousands_separator, '', (string) $amount);
        $amount = str_replace($decimal_point, '.', $amount);

        return $amount;
    }
}

if (!function_exists('date_formats')) {
    function date_formats(): array
    {
        return [
            'd/m/Y' => ['setting' => 'd/m/Y', 'datepicker' => 'dd/mm/yyyy'],
            'd-m-Y' => ['setting' => 'd-m-Y', 'datepicker' => 'dd-mm-yyyy'],
            'd-M-Y' => ['setting' => 'd-M-Y', 'datepicker' => 'dd-M-yyyy'],
            'd.m.Y' => ['setting' => 'd.m.Y', 'datepicker' => 'dd.mm.yyyy'],
            'j.n.Y' => ['setting' => 'j.n.Y', 'datepicker' => 'd.m.yyyy'],
            'd M,Y' => ['setting' => 'd M,Y', 'datepicker' => 'dd M,yyyy'],
            'm/d/Y' => ['setting' => 'm/d/Y', 'datepicker' => 'mm/dd/yyyy'],
            'm-d-Y' => ['setting' => 'm-d-Y', 'datepicker' => 'mm-dd-yyyy'],
            'm.d.Y' => ['setting' => 'm.d.Y', 'datepicker' => 'mm.dd.yyyy'],
            'Y/m/d' => ['setting' => 'Y/m/d', 'datepicker' => 'yyyy/mm/dd'],
            'Y-m-d' => ['setting' => 'Y-m-d', 'datepicker' => 'yyyy-mm-dd'],
            'Y.m.d' => ['setting' => 'Y.m.d', 'datepicker' => 'yyyy.mm.dd'],
        ];
    }
}

if (!function_exists('month_label')) {
    /**
     * Nome breve del mese nella lingua dell'interfaccia ("gen", "feb", ...).
     *
     * Era ricostruito con IntlDateFormatter in quattro punti (serie mensili di
     * fatture, preventivi e spese): le etichette dei grafici potevano divergere
     * per dettagli diversi da un punto all'altro.
     */
    function month_label(int $timestamp): string
    {
        $locale    = service('request')->getLocale() ?? 'it';
        $formatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            null,
            null,
            'LLL'
        );

        $formatted = $formatter->format($timestamp);

        return ucfirst($formatted !== false ? $formatted : date('M', $timestamp));
    }
}
if (!function_exists('date_from_mysql')) {
    /**
     * Converte una data yyyy-mm-dd nel formato utente configurato
     * (equivalente di date_from_mysql() CI3).
     */
    function date_from_mysql($date, bool $ignore_post_check = false)
    {
        if ($date === null || $date === '' || $date === '0000-00-00') {
            return '';
        }

        $format = get_setting('date_format', 'd/m/Y');
        $dt     = DateTime::createFromFormat('Y-m-d', $date);

        return $dt ? $dt->format($format) : $date;
    }
}

if (!function_exists('datetime_from_mysql')) {
    /**
     * Converte un timestamp MySQL (Y-m-d H:i:s) nel formato utente.
     *
     * date_from_mysql() accetta solo Y-m-d: con un datetime createFromFormat()
     * falliva e restituiva la stringa grezza, così la scheda cliente mostrava
     * "2026-10-13 14:28:27" al posto della data.
     */
    function datetime_from_mysql($datetime, bool $withTime = true)
    {
        if ($datetime === null || $datetime === '' || str_starts_with((string) $datetime, '0000-00-00')) {
            return '';
        }

        $format = get_setting('date_format', 'd/m/Y');
        if ($withTime) {
            $format .= ' H:i';
        }

        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
        if (!$dt) {
            $dt = DateTime::createFromFormat('Y-m-d', $datetime);
        }

        return $dt ? $dt->format($format) : $datetime;
    }
}

if (!function_exists('date_to_mysql')) {
    /**
     * Converte una data nel formato utente in yyyy-mm-dd
     * (equivalente di date_to_mysql() CI3).
     */
    function date_to_mysql($date)
    {
        if (empty($date)) {
            return null;
        }

        $format = get_setting('date_format', 'd/m/Y');
        $dt     = DateTime::createFromFormat($format, $date);

        return $dt ? $dt->format('Y-m-d') : $date;
    }
}

if (!function_exists('is_date')) {
    function is_date($date): bool
    {
        $format = get_setting('date_format', 'd/m/Y');
        $d      = DateTime::createFromFormat($format, $date);

        return $d && $d->format($format) === $date;
    }
}

if (!function_exists('date_format_setting')) {
    function date_format_setting(): string
    {
        $format = get_setting('date_format', 'd/m/Y');
        return date_formats()[$format]['setting'] ?? 'd/m/Y';
    }
}

if (!function_exists('increment_date')) {
    function increment_date($date, $increment)
    {
        $start    = new DateTime($date);
        $new_date = (clone $start)->add(new DateInterval('P' . $increment));

        // Solo mesi e anni possono "traboccare": DateInterval su un giorno che
        // nel mese di destinazione non esiste non si ferma all'ultimo giorno
        // utile, scivola nel mese successivo. Cosi' una ricorrente del 31
        // gennaio +1 mese finiva al 3 marzo: febbraio saltato del tutto, e da
        // li' in poi la ricorrenza restava ancorata al giorno sbagliato,
        // scivolando un po' di piu' a ogni giro. Il controllo si applica solo
        // agli incrementi in mesi o anni: su "+15D" il giorno del mese cambia
        // per definizione e saturare sarebbe sbagliato.
        if (preg_match('/[MY]/i', (string) $increment)
            && (int) $new_date->format('j') !== (int) $start->format('j')) {
            $new_date->modify('last day of previous month');
        }

        return $new_date->format('Y-m-d');
    }
}

if (!function_exists('generate_key')) {
    /**
     * Genera una chiave alfanumerica (equivalente di random_string('alnum', $len) CI3).
     */
    function generate_key(int $length = 32): string
    {
        // random_int() e non str_shuffle(): quest'ultimo mescola con il
        // Mersenne Twister, che non e' un generatore crittografico ed e'
        // ricostruibile osservando abbastanza uscite. Non e' un dettaglio
        // teorico, perche' questa funzione non produce solo le url key
        // pubbliche dei documenti: genera anche cron_key e mcp_key, cioe' i
        // due bearer che proteggono gli endpoint pianificati e il server MCP.
        // Totp::generateSecret(), nello stesso albero, usa gia' random_bytes().
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $max      = strlen($alphabet) - 1;
        $key      = '';

        for ($i = 0; $i < $length; $i++) {
            $key .= $alphabet[random_int(0, $max)];
        }

        return $key;
    }
}

if (!function_exists('encryption_secret_key')) {
    /**
     * Chiave grezza per Cryptor, derivata da encryption.key (.env). Il prefisso
     * "base64:" è la stessa convenzione usata dal servizio Encryption nativo
     * di CI4 — qui la decodifichiamo a mano perché Cryptor è una libreria
     * indipendente e non passa dal servizio 'encrypter'.
     */
    function encryption_secret_key(): string
    {
        $key = (string) (config('Encryption')->key ?? '');

        if ($key === '') {
            $key = (string) env('encryption.key', '');
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            $key = $decoded !== false ? $decoded : $key;
        } elseif (str_starts_with($key, 'hex2bin:')) {
            $key = hex2bin(substr($key, 8)) ?: $key;
        }

        if ($key === '') {
            throw new \RuntimeException('encryption.key non configurata in .env: impossibile cifrare/decifrare i segreti.');
        }

        return $key;
    }
}

if (!function_exists('encrypt_secret')) {
    /**
     * Cifra un segreto applicativo (token, chiavi di integrazione) prima di
     * salvarlo in ip_settings. Prefisso "enc2:" e AES-256-GCM (autenticato):
     * la vecchia modalità CTR (prefisso "enc:") non porta alcun tag di
     * integrità, quindi chi ha accesso in scrittura a ip_settings poteva
     * alterare bit del ciphertext e ottenere in lettura un segreto diverso
     * senza alcun segnale di manomissione. I valori "enc:" già salvati
     * restano leggibili (vedi decrypt_secret()); migrano a "enc2:" da soli
     * al prossimo salvataggio, stesso meccanismo già usato per la migrazione
     * dal chiaro a "enc:".
     */
    function encrypt_secret(string $plain): string
    {
        if ($plain === '') {
            return '';
        }

        $gcm = new \App\Libraries\Cryptor('aes-256-gcm');

        return 'enc2:' . $gcm->encryptString($plain, encryption_secret_key());
    }
}

if (!function_exists('decrypt_secret')) {
    /**
     * Decifra un segreto salvato con encrypt_secret(). "enc2:" è il formato
     * corrente (GCM autenticato); "enc:" è il formato legacy (CTR, ancora
     * supportato in lettura per i valori salvati prima del passaggio a GCM).
     * Un valore senza prefisso è considerato legacy in chiaro (scritto prima
     * che il campo venisse cifrato) e viene restituito così com'è.
     */
    function decrypt_secret(string $stored): string
    {
        if ($stored === '') {
            return $stored;
        }

        try {
            if (strpos($stored, 'enc2:') === 0) {
                $gcm = new \App\Libraries\Cryptor('aes-256-gcm');

                return $gcm->decryptString(substr($stored, 5), encryption_secret_key());
            }

            if (strpos($stored, 'enc:') === 0) {
                return \App\Libraries\Cryptor::Decrypt(substr($stored, 4), encryption_secret_key());
            }

            return $stored;
        } catch (\Throwable $e) {
            log_message('error', 'decrypt_secret: ' . $e->getMessage());

            return '';
        }
    }
}

if (!function_exists('decrypt_setting_password')) {
    /**
     * Decifra un'impostazione salvata dal meccanismo "campo password" del
     * form Impostazioni (Settings::saveSettings(): encrypter nativo CI4 +
     * base64, non Cryptor). Copre invoice_pre_password, quote_pre_password,
     * smtp_password, sdi_api_password, sdi_coop_key_passphrase,
     * sdi_imap_password — tutti cifrati al salvataggio ma fino ad ora mai
     * decifrati in lettura, il che rendeva quei campi di fatto inutilizzabili.
     */
    function decrypt_setting_password(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        try {
            $raw = base64_decode($stored, true);

            if ($raw === false) {
                return '';
            }

            return \Config\Services::encrypter()->decrypt($raw);
        } catch (\Throwable $e) {
            log_message('error', 'decrypt_setting_password: ' . $e->getMessage());

            return '';
        }
    }
}

if (!function_exists('pdf_page_total')) {
    /**
     * Risolve il segnaposto %%PAGINE%% con il numero di fogli del documento.
     *
     * dompdf non conosce counter(pages): in un piede fisso esce sempre 0, così
     * il documento finiva con "1 / 0" stampato in calce. Il totale si sa solo
     * dopo aver impaginato, quindi si fa un passaggio usa-e-getta per contare
     * le pagine e si sostituisce il segnaposto prima del render vero. Il
     * doppio passaggio avviene solo se il segnaposto c'è (fatture, preventivi,
     * DDT): gli altri PDF non pagano nulla.
     */
    function pdf_page_total(string $html): string
    {
        if (strpos($html, '%%PAGINE%%') === false) {
            return $html;
        }

        $probe = new \Dompdf\Dompdf();
        $probe->loadHtml($html);
        $probe->setPaper('A4');
        $probe->getOptions()->setDefaultMediaType('print');
        $probe->render();

        return str_replace('%%PAGINE%%', (string) $probe->getCanvas()->get_page_count(), $html);
    }
}

if (!function_exists('pdf_create')) {
    /**
     * Genera un PDF con Dompdf e lo invia allo stream o lo salva
     * (equivalente semplificato di pdf_create() CI3 con dompdf).
     *
     * Lo stesso HTML non viene stampato due volte: il PDF esce dalla cache
     * (App\Libraries\PdfCache), la cui chiave è l'HTML stesso. Un documento con
     * il segnaposto delle pagine costava due impaginazioni a ogni richiesta —
     * il conto dei fogli e il render vero — e lo stesso documento veniva
     * richiesto più volte fra download, allegato email ed export.
     */
    function pdf_create(string $html, string $filename = 'document.pdf', bool $stream = true, ?string $path = null)
    {
        $pdf = (new \App\Libraries\PdfCache())->remember($html, static function (string $html): string {
            $html   = pdf_page_total($html);
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4');
            // Media "print": le regole @media screen (es. il padding d'anteprima)
            // non si applicano, così il PDF resta governato dai soli margini @page.
            $dompdf->getOptions()->setDefaultMediaType('print');
            $dompdf->render();

            return $dompdf->output();
        });

        if ($path !== null) {
            file_put_contents($path, $pdf);
            return $path;
        }

        if ($stream) {
            pdf_send($pdf, $filename);
            exit;
        }

        return $pdf;
    }
}

if (!function_exists('pdf_send')) {
    /**
     * Manda al browser i byte di un PDF già stampato.
     *
     * Riproduce le intestazioni che dompdf scriveva da sé quando il PDF lo
     * produceva lui (Content-Disposition compreso il nome alternativo per i
     * caratteri fuori da ISO-8859-1, che ora possono arrivare da un documento
     * con accenti nel numero): un download deve restare identico.
     */
    function pdf_send(string $pdf, string $filename): void
    {
        if (headers_sent()) {
            echo $pdf;
            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Length: ' . mb_strlen($pdf, '8bit'));

        $filename = str_replace(["\n", "'"], '', basename($filename, '.pdf')) . '.pdf';
        header(\Dompdf\Helpers::buildContentDispositionHeader('attachment', $filename));

        echo $pdf;
        flush();
    }
}

if (!function_exists('document_logo')) {
    /**
     * Nome del file del logo da stampare su un documento.
     *
     * $key è 'invoice_logo' (intestazione, la versione estesa) oppure
     * 'invoice_logo_footer' (piè di pagina, di solito il solo segno).
     *
     * Vince il logo dell'azienda che emette il documento; se non ne ha uno si
     * ricade su quello delle Impostazioni, che resta il logo comune a tutte.
     * L'azienda è quella del documento ($document, riga o oggetto con
     * company_id) e non quella attiva in sessione: il portale clienti, i link
     * pubblici e i cron non hanno una sessione con l'azienda giusta, e una
     * fattura di un'azienda stampata col logo di un'altra è esattamente
     * l'errore che i loghi per azienda devono evitare. Senza documento (o con
     * un documento d'archivio senza company_id) si usa l'azienda attiva.
     *
     * @param array|object|null $document
     */
    function document_logo(string $key, $document = null): string
    {
        if (!in_array($key, ['invoice_logo', 'invoice_logo_footer'], true)) {
            return '';
        }

        return \App\Libraries\LogoUpload::pick(document_company($document), $key, (string) get_setting($key, ''));
    }
}

if (!function_exists('document_company')) {
    /**
     * Riga dell'azienda che emette un documento: quella del suo company_id,
     * oppure l'azienda attiva se il documento manca o non ne porta uno.
     *
     * Serve a loghi e piè di pagina, che devono seguire il documento e non la
     * sessione (vedi document_logo()). Un dato dell'azienda che non si riesce
     * a leggere non deve impedire di stampare: in quel caso torna una riga
     * vuota e chi chiama ricade sul valore globale, come per un'azienda che
     * non ha impostato nulla di suo.
     *
     * @param array|object|null $document
     *
     * @return array<string, mixed>
     */
    function document_company($document = null): array
    {
        static $companies = [];

        $document  = is_object($document) ? get_object_vars($document) : (array) $document;
        $companyId = (int) ($document['company_id'] ?? 0);

        try {
            if ($companyId > 0) {
                return $companies[$companyId] ??= ((new \App\Models\CompanyModel())->find($companyId) ?: []);
            }

            return active_company();
        } catch (\Throwable) {
            return [];
        }
    }
}

if (!function_exists('document_footer')) {
    /**
     * Testo (HTML) del piè di pagina da stampare su un documento.
     *
     * $key è 'pdf_invoice_footer' (fatture e DDT) o 'pdf_quote_footer'
     * (preventivi). Come per i loghi: vince il testo dell'azienda che emette
     * il documento, altrimenti quello delle Impostazioni. I contatti e le
     * diciture di legge di una società non devono comparire sui documenti di
     * un'altra.
     *
     * @param array|object|null $document
     */
    function document_footer(string $key, $document = null): string
    {
        if (!in_array($key, ['pdf_invoice_footer', 'pdf_quote_footer'], true)) {
            return '';
        }

        return \App\Libraries\LogoUpload::pick(document_company($document), $key, (string) get_setting($key, ''));
    }
}

if (!function_exists('invoice_logo_pdf')) {
    /**
     * Logo d'intestazione per i PDF: la versione estesa del marchio
     * dell'azienda che emette (vedi document_logo() per la scelta).
     *
     * @param array|object|null $document il documento che si sta stampando
     */
    function invoice_logo_pdf($document = null): string
    {
        return invoice_logo_tag(document_logo('invoice_logo', $document), 'invoice-logo');
    }
}

if (!function_exists('invoice_logo_footer_pdf')) {
    /**
     * Secondo logo, per il piè di pagina del documento: spesso serve una
     * versione diversa da quella dell'intestazione (il solo segno, o una
     * monocromatica). Se non è caricato non si stampa nulla.
     *
     * @param array|object|null $document il documento che si sta stampando
     */
    function invoice_logo_footer_pdf($document = null): string
    {
        return invoice_logo_tag(document_logo('invoice_logo_footer', $document), 'invoice-logo-footer');
    }
}

if (!function_exists('report_pdf_brand')) {
    /**
     * Intestazione dei PDF dei report: logo esteso dell'azienda a sinistra e,
     * a destra, il testo di piè di pagina (contatti) in piccolo.
     *
     * I report non hanno un "documento" da cui ricavare l'azienda: valgono
     * quella attiva e il logo/testo che la fattura userebbe, così la stessa
     * azienda si presenta allo stesso modo su ogni foglio che esce. Niente
     * logo e niente testo: nessun blocco, il report resta com'era. Tabella
     * di impaginazione perché dompdf non ha flex.
     */
    function report_pdf_brand(): string
    {
        $logo = invoice_logo_pdf(null);
        $note = trim(document_footer('pdf_invoice_footer', null));

        if ($logo === '' && $note === '') {
            return '';
        }

        return '<table style="width:100%;border-collapse:collapse;margin:0 0 12px;border-bottom:1px solid #ddd">'
            . '<tr><td style="border:0;padding:0 0 6px;vertical-align:middle;width:1%;white-space:nowrap">'
            . ($logo !== '' ? str_replace('<img ', '<img style="width:150px;height:auto" ', $logo) : '')
            . '</td><td style="border:0;padding:0 0 6px;vertical-align:middle;text-align:right;font-size:8px;color:#777">'
            . $note . '</td></tr></table>';
    }
}

if (!function_exists('logo_mimes')) {
    /**
     * Estensioni ammesse per un logo e il loro content-type.
     *
     * @return array<string, string>
     */
    function logo_mimes(): array
    {
        return [
            'gif'  => 'image/gif',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'svg'  => 'image/svg+xml',
        ];
    }
}

if (!function_exists('logo_path')) {
    /**
     * Percorso assoluto di un logo caricato, o null se non c'è o non è
     * un'immagine ammessa.
     *
     * `basename()` non è un dettaglio: il valore arriva dalle impostazioni, ma
     * un `../../` nel nome porterebbe fuori da uploads. Lo usano il data URI
     * (per i PDF) e la rotta che serve il logo alle pagine.
     */
    function logo_path(?string $logo): ?string
    {
        if (!$logo) {
            return null;
        }

        $path = WRITEPATH . 'uploads/' . basename($logo);

        if (!is_file($path)) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return isset(logo_mimes()[$extension]) ? $path : null;
    }
}

if (!function_exists('logo_url')) {
    /**
     * URL del logo servito come file (rotta logo/..., controller Media).
     *
     * Le pagine usano questo; il data URI resta per il PDF, dove dompdf non fa
     * richieste HTTP. Stringa vuota se non c'è: chi chiama decide il fallback.
     * Il `?v=` è la data del file, così la cache lunga non serve un logo vecchio
     * dopo una sostituzione.
     */
    function logo_url(string $variant = 'login'): string
    {
        $setting = $variant === 'login-dark' ? 'login_logo_dark' : 'login_logo';
        $path    = logo_path((string) get_setting($setting, ''));

        if ($path === null) {
            return '';
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return site_url('logo/' . $variant . '.' . $extension) . '?v=' . filemtime($path);
    }
}

if (!function_exists('logo_data_uri')) {
    /**
     * data URI di un logo caricato in writable/uploads.
     *
     * I loghi vengono caricati in writable/uploads, che non è servito dal web:
     * i vecchi percorsi (base_url().'uploads/' e getcwd().'/uploads/')
     * puntavano a public/uploads, cartella che non esiste — il logo dava 404 e
     * restava un'immagine rotta. Incorporarlo come data URI funziona sia a
     * schermo sia in dompdf (che legge dal filesystem) e non espone la cartella
     * scrivibile; in più regge i nomi con spazi, che via URL andavano
     * codificati. Stringa vuota se il file manca o non è un'immagine ammessa:
     * chi chiama decide se mostrare un fallback.
     */
    function logo_data_uri(?string $logo): string
    {
        $path = logo_path($logo);

        if ($path === null) {
            return '';
        }

        $mimes     = logo_mimes();
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $data      = @file_get_contents($path);

        if ($data === false) {
            return '';
        }

        if ($extension === 'svg') {
            $data = svg_size_from_viewbox($data);
        }

        return 'data:' . $mimes[$extension] . ';base64,' . base64_encode($data);
    }
}

if (!function_exists('svg_size_from_viewbox')) {
    /**
     * Allinea width/height dell'<svg> alla sua viewBox.
     *
     * Gli SVG esportati dai programmi di grafica hanno spesso width/height in
     * pixel arbitrari (qui 1000x250) con una viewBox in altre unità (264x66).
     * I browser scalano la viewBox sulla scatola e il logo si vede intero;
     * dompdf invece disegna il contenuto nelle unità della viewBox dentro la
     * scatola di width/height, e sui PDF il logo usciva a un quarto della
     * grandezza richiesta, qualunque fosse il CSS. Con width/height uguali
     * alla viewBox la scatola e il disegno coincidono e le dimensioni le
     * decide il CSS del documento, come per un PNG.
     */
    function svg_size_from_viewbox(string $svg): string
    {
        if (!preg_match('/<svg\b[^>]*>/i', $svg, $tag)) {
            return $svg;
        }

        if (!preg_match('/\bviewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)\s*["\']/i', $tag[0], $vb)) {
            return $svg;
        }

        $new = preg_replace('/\s(width|height)\s*=\s*["\'][^"\']*["\']/i', '', $tag[0]);
        $new = preg_replace('/<svg\b/i', '<svg width="' . $vb[1] . '" height="' . $vb[2] . '"', $new, 1);

        return str_replace($tag[0], $new, $svg);
    }
}

if (!function_exists('invoice_logo_tag')) {
    /**
     * <img> del logo del documento, incorporato come data URI
     * (vedi logo_data_uri per il perché).
     */
    function invoice_logo_tag(?string $logo, string $id): string
    {
        $src = logo_data_uri($logo);

        if ($src === '') {
            return '';
        }

        return '<img src="' . $src . '" id="' . htmlsc($id) . '" alt="">';
    }
}

if (!function_exists('item_vat_label')) {
    /**
     * Aliquota IVA di una riga, per la colonna "IVA" dei documenti: "22%",
     * "8,5%". Senza aliquota (riga fuori campo o senza imposta) un trattino:
     * "0%" direbbe un'aliquota zero che non è la stessa cosa.
     *
     * @param array|object $item
     */
    function item_vat_label($item): string
    {
        $rate = is_array($item) ? ($item['item_tax_rate_percent'] ?? null) : ($item->item_tax_rate_percent ?? null);

        if ($rate === null || $rate === '') {
            return '–';
        }

        // Niente decimali inutili: 22.00 si legge "22%", 8.50 "8,5%".
        $text = rtrim(rtrim(number_format((float) $rate, 2, ',', ''), '0'), ',');

        return $text . '%';
    }
}

if (!function_exists('name_monogram')) {
    /**
     * Monogramma di un nome o di una ragione sociale: le iniziali delle prime
     * parole che contano, due o tre secondo quante ne ha ($max).
     *
     * Prendere le prime due parole qualunque dava "CD" per «Comune di
     * Monte Alto»: la preposizione finiva nel monogramma al posto del
     * nome vero. Articoli, preposizioni e congiunzioni si saltano, e di ogni
     * parola conta la prima lettera o cifra, così virgolette e parentesi
     * («Scuola "Verdi"») non diventano un'iniziale. Se dopo il filtro non
     * resta nulla (un nome fatto solo di particelle) si torna alle parole
     * così come sono.
     */
    function name_monogram(string $name, int $max = 3): string
    {
        $skip = ['di', 'de', 'del', 'dello', 'della', 'dei', 'degli', 'delle', 'da', 'dal', 'dalla',
            'e', 'ed', 'il', 'lo', 'la', 'i', 'gli', 'le', 'a', 'al', 'alla', 'in', 'per', 'con', 'su',
            'and', 'of', 'the'];

        $all = [];
        $significant = [];

        foreach (preg_split('/[\s\-\/]+/u', trim($name)) ?: [] as $word) {
            // L'articolo eliso resta attaccato alla parola ("d'Aosta"): la sua
            // lettera non è un'iniziale, e prenderla dava "IDD" per «ICS Duca
            // d'Aosta». Si toglie prima, così conta la lettera che si legge.
            $word  = preg_replace("/^[\p{L}]'/u", '', $word) ?? $word;
            // Solo lettere e cifre: "d'Italia" resta "Italia", «"Verdi"» "Verdi".
            $clean = preg_replace('/[^\p{L}\p{N}]+/u', '', $word) ?? '';

            if ($clean === '') {
                continue;
            }

            $all[] = $clean;

            if (!in_array(mb_strtolower($clean), $skip, true)) {
                $significant[] = $clean;
            }
        }

        // Fino a tre iniziali, non due: le ragioni sociali lunghe si mangiano le
        // prime due ("ICS Duca d'Aosta", "ICS Giovanni XXIII", "ICS Filippo
        // Maria Beltrami" diventavano tutte e tre "IC") e nella scheda il
        // monogramma smetteva di distinguere un cliente dall'altro. Con due
        // parole significative il risultato resta quello di prima, quindi chi
        // ha un nome corto non vede cambiare nulla.
        $monogram = '';

        foreach (array_slice($significant ?: $all, 0, $max) as $word) {
            $monogram .= mb_substr($word, 0, 1);
        }

        return $monogram === '' ? '?' : mb_strtoupper($monogram);
    }
}

if (!function_exists('format_client')) {
    /**
     * Nome completo del cliente (nome + cognome se presente),
     * equivalente di format_client() CI3. Accetta array o oggetto.
     *
     * @param array|object $client
     */
    function format_client($client): string
    {
        $name    = is_array($client) ? ($client['client_name'] ?? '') : ($client->client_name ?? '');
        $surname = is_array($client) ? ($client['client_surname'] ?? '') : ($client->client_surname ?? '');

        if ($surname != '') {
            return $name . ' ' . $surname;
        }

        return $name;
    }
}

if (!function_exists('email_track_note')) {
    /**
     * Nota compatta sotto il numero del documento: dice se l'ultima email
     * inviata è stata aperta.
     *
     * Sta nelle liste di fatture e preventivi, dove una riga per documento
     * deve restare leggibile: il testo visibile è una parola, il dettaglio
     * (prima apertura, destinatario, numero di aperture) sta nel title e in
     * una porzione nascosta per gli screen reader. Restituisce stringa vuota
     * se il documento non ha invii: nessuna icona senza informazione.
     */
    function email_track_note($track): string
    {
        if (!is_array($track) || empty($track['track_sent_at'])) {
            return '';
        }

        $destinatario = trim((string) ($track['track_recipient'] ?? ''));

        if (!empty($track['track_opened_at'])) {
            $dettaglio = sprintf(
                trans('email_tracking_opened_on'),
                datetime_from_mysql($track['track_opened_at'])
            );

            if ((int) ($track['track_open_count'] ?? 0) > 1) {
                $dettaglio .= ' · ' . sprintf(trans('email_tracking_opens'), (int) $track['track_open_count']);
            }

            if ($destinatario !== '') {
                $dettaglio .= ' · ' . $destinatario;
            }

            return '<span class="ip-email-note ip-email-note--opened" title="' . esc($dettaglio, 'attr') . '">'
                . '<i class="fa fa-solid fa-envelope-open" aria-hidden="true"></i> '
                . esc(trans('email_tracking_list_opened'))
                . '<span class="visually-hidden"> — ' . esc($dettaglio) . '</span>'
                . '</span>';
        }

        $dettaglio = trans('email_tracking_list_sent') . ' — ' . trans('email_tracking_list_sent_help');

        if ($destinatario !== '') {
            $dettaglio .= ' ' . $destinatario . '.';
        }

        return '<span class="ip-email-note" title="' . esc($dettaglio, 'attr') . '">'
            . '<i class="fa fa-solid fa-envelope" aria-hidden="true"></i> '
            . esc(trans('email_tracking_list_sent'))
            . '<span class="visually-hidden"> — ' . esc(trans('email_tracking_list_sent_help')) . '</span>'
            . '</span>';
    }
}

if (!function_exists('expense_status_badge')) {
    /**
     * Stato di una spesa passiva, per la colonna "Stato" della lista.
     *
     * ip_expenses non ha una colonna di stato: lo stato si legge dal rapporto
     * fra totale e pagato, con la stessa regola del filtro "Da pagare /
     * Pagate" del model — nel verso del documento, perché una nota di credito
     * ricevuta ha totale negativo e si chiude quando il rimborso lo copre.
     *
     * @param array<string, mixed> $expense
     *
     * @return array{label: string, class: string} label e classe Bootstrap del badge
     */
    function expense_status_badge(array $expense): array
    {
        $total   = (float) ($expense['expense_total'] ?? 0);
        $paid    = (float) ($expense['expense_paid'] ?? 0);
        $settled = $total < 0 ? $paid <= $total : $paid >= $total;

        if ($settled) {
            return [
                'label' => $total < 0 ? trans('refunded', '', 'Rimborsata') : trans('paid', '', 'Pagata'),
                'class' => 'text-bg-success',
            ];
        }

        // Qualcosa è già stato pagato (o rimborsato) ma il documento non è
        // chiuso: è lo stato che prima si leggeva solo dalla colonna residuo.
        if (round($paid, 2) !== 0.0) {
            return ['label' => trans('partial', '', 'Parziale'), 'class' => 'text-bg-warning'];
        }

        return [
            'label' => $total < 0 ? trans('to_refund', '', 'Da rimborsare') : trans('unpaid', '', 'Da pagare'),
            'class' => 'text-bg-danger',
        ];
    }
}

if (!function_exists('payment_status_badge')) {
    /**
     * Stato di un pagamento, per la colonna "Stato" della lista.
     *
     * Il pagamento in sé non ha stati: è registrato o non esiste. Quello che
     * la colonna dice è se la fattura che lo contiene è chiusa o ancora
     * aperta, così la riga si legge come "questo incasso ha saldato" oppure
     * "resta da incassare" — la stessa semantica dei badge delle fatture.
     *
     * @param array<string, mixed> $payment Riga della lista, con gli importi
     *                                      della fattura arrivati dal join
     *
     * @return array{label: string, class: string} label e classe Bootstrap del badge
     */
    function payment_status_badge(array $payment): array
    {
        $total   = (float) ($payment['invoice_total'] ?? 0);
        $balance = (float) ($payment['invoice_balance'] ?? 0);
        $amount  = (float) ($payment['payment_amount'] ?? 0);

        if (round($balance, 2) === 0.0) {
            return [
                // Rimborso di una nota di credito: il documento non è
                // "saldata", è chiuso perché il rimborso è arrivato.
                'label' => ($total < 0 || $amount < 0)
                    ? trans('refunded', '', 'Rimborsata')
                    : trans('settled', '', 'Saldata'),
                'class' => 'text-bg-success',
            ];
        }

        return ['label' => trans('partial', '', 'Parziale'), 'class' => 'text-bg-warning'];
    }
}

if (!function_exists('get_country_list')) {
    /**
     * Elenco paesi (codice => nome) nella lingua richiesta
     * (equivalente ridotto di get_country_list() CI3).
     */
    function get_country_list(string $cldr): array
    {
        $countries = [
            'IT' => 'Italia',
            'DE' => 'Germania',
            'FR' => 'Francia',
            'ES' => 'Spagna',
            'GB' => 'Regno Unito',
            'US' => 'Stati Uniti',
            'CH' => 'Svizzera',
            'AT' => 'Austria',
            'BE' => 'Belgio',
            'NL' => 'Paesi Bassi',
            'PT' => 'Portogallo',
            'LU' => 'Lussemburgo',
            'IE' => 'Irlanda',
            'DK' => 'Danimarca',
            'SE' => 'Svezia',
            'NO' => 'Norvegia',
            'FI' => 'Finlandia',
            'IS' => 'Islanda',
            'GR' => 'Grecia',
            'MT' => 'Malta',
            'CY' => 'Cipro',
            'PL' => 'Polonia',
            'CZ' => 'Repubblica Ceca',
            'SK' => 'Slovacchia',
            'HU' => 'Ungheria',
            'SI' => 'Slovenia',
            'HR' => 'Croazia',
            'BA' => 'Bosnia ed Erzegovina',
            'RS' => 'Serbia',
            'AL' => 'Albania',
            'RO' => 'Romania',
            'BG' => 'Bulgaria',
            'UA' => 'Ucraina',
            'RU' => 'Russia',
            'TR' => 'Turchia',
            'CN' => 'Cina',
            'JP' => 'Giappone',
            'KR' => 'Corea del Sud',
            'IN' => 'India',
            'SG' => 'Singapore',
            'AE' => 'Emirati Arabi Uniti',
            'IL' => 'Israele',
            'BR' => 'Brasile',
            'AR' => 'Argentina',
            'CL' => 'Cile',
            'MX' => 'Messico',
            'CA' => 'Canada',
            'AU' => 'Australia',
            'NZ' => 'Nuova Zelanda',
            'ZA' => 'Sudafrica',
            'EG' => 'Egitto',
            'MA' => 'Marocco',
        ];

        $english = [
            'IT' => 'Italy',
            'DE' => 'Germany',
            'FR' => 'France',
            'ES' => 'Spain',
            'GB' => 'United Kingdom',
            'US' => 'United States',
            'CH' => 'Switzerland',
            'AT' => 'Austria',
            'BE' => 'Belgium',
            'NL' => 'Netherlands',
            'PT' => 'Portugal',
            'LU' => 'Luxembourg',
            'IE' => 'Ireland',
            'DK' => 'Denmark',
            'SE' => 'Sweden',
            'NO' => 'Norway',
            'FI' => 'Finland',
            'IS' => 'Iceland',
            'GR' => 'Greece',
            'MT' => 'Malta',
            'CY' => 'Cyprus',
            'PL' => 'Poland',
            'CZ' => 'Czech Republic',
            'SK' => 'Slovakia',
            'HU' => 'Hungary',
            'SI' => 'Slovenia',
            'HR' => 'Croatia',
            'BA' => 'Bosnia and Herzegovina',
            'RS' => 'Serbia',
            'AL' => 'Albania',
            'RO' => 'Romania',
            'BG' => 'Bulgaria',
            'UA' => 'Ukraine',
            'RU' => 'Russia',
            'TR' => 'Turkey',
            'CN' => 'China',
            'JP' => 'Japan',
            'KR' => 'South Korea',
            'IN' => 'India',
            'SG' => 'Singapore',
            'AE' => 'United Arab Emirates',
            'IL' => 'Israel',
            'BR' => 'Brazil',
            'AR' => 'Argentina',
            'CL' => 'Chile',
            'MX' => 'Mexico',
            'CA' => 'Canada',
            'AU' => 'Australia',
            'NZ' => 'New Zealand',
            'ZA' => 'South Africa',
            'EG' => 'Egypt',
            'MA' => 'Morocco',
        ];

        return str_starts_with($cldr, 'it') ? $countries : $english;
    }
}

if (!function_exists('get_country_name')) {
    /**
     * Nome del paese a partire dal codice, tradotto nella lingua $cldr
     * (equivalente di get_country_name() CI3).
     */
    function get_country_name(string $cldr, string $countryCode): string
    {
        $countries = get_country_list($cldr);

        return $countries[$countryCode] ?? $countryCode;
    }
}

if (!function_exists('active_company_id')) {
    /**
     * Azienda su cui si sta operando.
     *
     * Vive in sessione, non nella query string: cambiare azienda è un cambio
     * di contesto che deve valere su tutte le pagine, e un id nell'URL sarebbe
     * troppo facile da perdere passando da una sezione all'altra.
     *
     * Se in sessione non c'è nulla (primo accesso) si ricade sull'azienda
     * predefinita, così l'applicazione non si presenta mai senza contesto.
     */
    function active_company_id(): int
    {
        $fromSession = (int) (session('active_company_id') ?? 0);

        if ($fromSession > 0) {
            return $fromSession;
        }

        $default = (new \App\Models\CompanyModel())->getDefault();
        $id      = (int) ($default['company_id'] ?? 0);

        if ($id > 0) {
            session()->set('active_company_id', $id);
        }

        return $id;
    }
}

if (!function_exists('active_company')) {
    /** Dati dell'azienda attiva (ragione sociale, P.IVA, indirizzo, forma). */
    function active_company(): array
    {
        // Per id, non un valore solo: i cron girano senza sessione e cambiano
        // azienda attiva dentro un ciclo. Con una cache unica la prima azienda
        // letta restava "attiva" per tutto il giro, e company_setting() dava
        // alle successive le serie di numerazione della prima.
        static $cache = [];

        $id = active_company_id();

        return $cache[$id] ??= $id ? ((new \App\Models\CompanyModel())->find($id) ?: []) : [];
    }
}

if (!function_exists('available_companies')) {
    /** Aziende fra cui si può passare. */
    function available_companies(): array
    {
        return (new \App\Models\CompanyModel())->getAllActive();
    }
}

if (!function_exists('invoice_number_label')) {
    /**
     * Etichetta del numero di una fattura: il numero quando c'è, altrimenti la
     * bozza con il suo riferimento interno.
     *
     * Una bozza senza numero non ne ha uno, e prima al suo posto compariva
     * l'id: quattro cifre che sembrano un progressivo appena assegnato,
     * tanto da farlo citare al cliente come se fosse il numero. La parola
     * «Bozza» e il cancelletto tolgono l'equivoco, e il riferimento resta
     * perché serve a ritrovare il documento nell'elenco.
     *
     * @param array<string, mixed> $invoice riga di ip_invoices (o di un documento che la imita)
     */
    function invoice_number_label(array $invoice): string
    {
        $numero = trim((string) ($invoice['invoice_number'] ?? ''));

        return $numero !== ''
            ? $numero
            : trans('draft', '', 'Bozza') . ' #' . (int) ($invoice['invoice_id'] ?? 0);
    }
}

if (!function_exists('company_setting')) {
    /**
     * Impostazione che appartiene all'azienda, non all'installazione.
     *
     * Serve per le scelte che puntano a dati di una singola azienda — le serie
     * di numerazione su tutte — dove un valore globale finirebbe per indicare
     * un record che l'azienda attiva non possiede.
     *
     * Il ripiego sull'impostazione globale mantiene funzionanti le
     * installazioni con una sola azienda, dove il valore sta ancora li'.
     */
    function company_setting(string $key, $default = 0)
    {
        $company = active_company();

        if (isset($company[$key]) && $company[$key] !== null && $company[$key] !== '') {
            return $company[$key];
        }

        return get_setting($key, $default);
    }
}

if (!function_exists('system_email_sender')) {
    /**
     * Casella email di sistema: notifiche cron, reset password, SdI di ripiego.
     *
     * La scelta vive su ip_companies.company_system_sender_id (per-azienda, via
     * company_setting) così ogni società spedisce dalle proprie caselle; se non
     * è impostata si usa il mittente segnato sender_is_default. L'SMTP è solo
     * nei mittenti (ip_email_senders): non esistono più settings SMTP generali.
     */
    function system_email_sender(): ?array
    {
        $id = (int) company_setting('company_system_sender_id', 0);

        if ($id > 0) {
            $sender = (new \App\Models\EmailSenderModel())->findScoped($id);

            if ($sender) {
                return $sender;
            }
        }

        return (new \App\Models\EmailSenderModel())->getDefault();
    }
}

if (!function_exists('system_mail_from')) {
    /**
     * Indirizzo mittente per le email di sistema: la casella scelta, o vuoto
     * (i chiamanti hanno il proprio ripiego, es. system@dominio).
     */
    function system_mail_from(): string
    {
        $sender = system_email_sender();

        return $sender['sender_email'] ?? '';
    }
}

if (!function_exists('build_mail_config')) {
    /**
     * Configura il trasporto email per un mittente di ip_email_senders.
     *
     * Sostituisce i quattro blocchi duplicati che ricostruivano l'SMTP dai
     * vecchi settings generali: ora l'SMTP sta solo nei mittenti. Se il mittente
     * non ha un SMTP proprio si usa PHP mail() (protocol 'mail').
     */
    function build_mail_config(?array $sender = null): array
    {
        $config = ['protocol' => 'mail'];

        if (!empty($sender) && !empty($sender['sender_smtp_host'])) {
            $config['protocol']  = 'smtp';
            $config['SMTPHost']  = $sender['sender_smtp_host'];
            $config['SMTPPort']  = (int) ($sender['sender_smtp_port'] ?: 25);
            $config['SMTPUser']  = (string) ($sender['sender_smtp_user'] ?? '');
            $config['SMTPPass']  = decrypt_secret((string) ($sender['sender_smtp_password'] ?? ''));

            // Sempre esplicito, anche vuoto: "Nessuna" nel form e' la stringa
            // vuota, e lasciando la chiave fuori valeva il default 'tls' di
            // Config\Email, cosi' un server senza STARTTLS rifiutava ogni invio.
            $config['SMTPCrypto'] = (string) ($sender['sender_smtp_security'] ?? '');
        }

        return $config;
    }
}

if (!function_exists('company_scope_sql')) {
    /**
     * Frammento SQL che restringe una query grezza all'azienda attiva.
     *
     * Va concatenato a una WHERE gia' presente e restituisce
     * " AND alias.company_id = N", oppure stringa vuota quando non c'e'
     * un'azienda attiva.
     *
     * Esiste perche' i report e le viste di dettaglio usano SQL scritto a mano,
     * dove il scopeToCompany() dei model non arriva: senza questo, un report
     * sommava i documenti di tutte le aziende.
     *
     * Non c'e' piu' l'eccezione "OR company_id IS NULL": le righe anteriori al
     * multi-azienda sono state assegnate (BackfillOrphanCompanyData) e quelle
     * rimaste si vedono con `php spark companies:orphans`. Includerle qui
     * significava mostrarle a tutte le aziende, cioè il contrario del
     * perimetro.
     */
    function company_scope_sql(string $alias): string
    {
        $companyId = active_company_id();

        if (!$companyId) {
            return '';
        }

        return " AND {$alias}.company_id = " . (int) $companyId;
    }
}

if (!function_exists('scope_builder_to_company')) {
    /**
     * Equivalente di company_scope_sql() per un query builder.
     *
     * $alias e' il nome con cui la tabella compare nella query (di norma il
     * nome stesso). Non verifica l'esistenza della colonna: va usata solo sulle
     * tabelle che hanno company_id.
     */
    function scope_builder_to_company($builder, string $alias)
    {
        $companyId = active_company_id();

        if (!$companyId) {
            return $builder;
        }

        return $builder->where($alias . '.company_id', $companyId);
    }
}

if (!function_exists('client_type_badge')) {
    /**
     * Etichetta della tipologia cliente (azienda / persona fisica / PA).
     *
     * Serve in elenco e in scheda perche' la tipologia cambia il tracciato
     * della fattura elettronica: accorgersi solo al momento dello scarto che un
     * ente pubblico era registrato come azienda e' tardi.
     */
    function client_type_badge(?string $type): string
    {
        $types = [
            // Nomi FA6: le varianti "outline" con suffisso -o esistevano solo
            // grazie a v4-shims.css, che non viene piu' caricato (il sottoinsieme
            // di Font Awesome e' generato sugli usi reali, vedi
            // tools/subset_fontawesome.php). Il peso resta quello di fa-solid.
            'company' => ['fa-building',  trans('client_type_company', '', 'Azienda')],
            'person'  => ['fa-user',      trans('client_type_person', '', 'Persona fisica')],
            'pa'      => ['fa-institution', trans('client_type_pa', '', 'PA')],
        ];

        [$icon, $label] = $types[$type ?: 'company'] ?? $types['company'];

        return '<span class="ip-client-type" title="' . htmlsc($label) . '">'
             . '<i class="fa-solid ' . $icon . '" aria-hidden="true"></i> ' . htmlsc($label)
             . '</span>';
    }
}

if (!function_exists('country_list')) {
    /**
     * Paesi offerti dai select delle anagrafiche.
     *
     * Stava nel controller dei clienti, quindi il form fornitore non poteva
     * usarlo e ripiegava su un campo di testo libero: lo stesso dato — il
     * paese — si inseriva in due modi diversi, e sul fornitore bastava un
     * refuso ("ITA", "it") per avere un valore che nessun select avrebbe piu'
     * riconosciuto. Elenco ridotto ai paesi con cui si fattura davvero: un
     * elenco completo di 250 voci in un menu a tendina non aiuta a scegliere.
     */
    function country_list(): array
    {
        return [
            'IT' => 'Italia',
            'DE' => 'Germania',
            'FR' => 'Francia',
            'ES' => 'Spagna',
            'GB' => 'Regno Unito',
            'US' => 'Stati Uniti',
            'CH' => 'Svizzera',
            'AT' => 'Austria',
            'BE' => 'Belgio',
            'NL' => 'Paesi Bassi',
            'PT' => 'Portogallo',
            'LU' => 'Lussemburgo',
            'IE' => 'Irlanda',
            'DK' => 'Danimarca',
            'SE' => 'Svezia',
            'NO' => 'Norvegia',
            'FI' => 'Finlandia',
            'IS' => 'Islanda',
            'GR' => 'Grecia',
            'MT' => 'Malta',
            'CY' => 'Cipro',
            'PL' => 'Polonia',
            'CZ' => 'Repubblica Ceca',
            'SK' => 'Slovacchia',
            'HU' => 'Ungheria',
            'SI' => 'Slovenia',
            'HR' => 'Croazia',
            'BA' => 'Bosnia ed Erzegovina',
            'RS' => 'Serbia',
            'AL' => 'Albania',
            'RO' => 'Romania',
            'BG' => 'Bulgaria',
            'UA' => 'Ucraina',
            'RU' => 'Russia',
            'TR' => 'Turchia',
            'CN' => 'Cina',
            'JP' => 'Giappone',
            'KR' => 'Corea del Sud',
            'IN' => 'India',
            'SG' => 'Singapore',
            'AE' => 'Emirati Arabi Uniti',
            'IL' => 'Israele',
            'BR' => 'Brasile',
            'AR' => 'Argentina',
            'CL' => 'Cile',
            'MX' => 'Messico',
            'CA' => 'Canada',
            'AU' => 'Australia',
            'NZ' => 'Nuova Zelanda',
            'ZA' => 'Sudafrica',
            'EG' => 'Egitto',
            'MA' => 'Marocco',
        ];
    }
}

if (!function_exists('cron_key_valid')) {
    /**
     * Chiave cron valida per l'endpoint pianificato.
     *
     * hash_equals() e non !==: il confronto di un segreto va fatto a tempo
     * costante, come gia' fanno gli endpoint SdI e quello MCP. Qui i cinque
     * controlli del Cron usavano !==, cioe' un trattamento diverso per la
     * stessa classe di segreto dentro lo stesso albero.
     *
     * Fail-closed: se cron_key non e' configurata la risposta e' sempre no,
     * invece di lasciar passare un confronto fra due stringhe vuote.
     */
    function cron_key_valid(string $provided): bool
    {
        $expected = decrypt_secret((string) get_setting('cron_key', ''));

        if ($expected === '' || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }
}

if (!function_exists('invalidate_finance_cache')) {
    /**
     * Invalida la cache della pagina "Utile e imposte" per l'azienda attiva.
     *
     * Da chiamare quando si salvano fatture o spese, cosi' il report si
     * ricalcola alla prossima visita invece di mostrare dati vecchi.
     * Invalida tutti gli anni disponibili (corrente + vicini) perche' una
     * fattura retrodatata potrebbe cambiare un anno precedente.
     */
    function invalidate_finance_cache(): void
    {
        $companyId = active_company_id();
        $cache     = cache();

        // Invalida l'anno corrente e i 2 anni adiacenti (per retrodatazioni)
        $currentYear = (int) date('Y');
        for ($year = $currentYear - 1; $year <= $currentYear + 1; $year++) {
            $cache->delete("finance_{$companyId}_{$year}");
        }
    }
}

if (!function_exists('list_per_page')) {
    /**
     * Elementi per pagina nelle liste, dall'impostazione "Numero di elementi
     * nelle liste" (default_list_limit). Caduta a 15 se vuota/non valida.
     */
    function list_per_page(): int
    {
        $v = (int) get_setting('default_list_limit', 15);
        return $v > 0 ? $v : 15;
    }
}

if (!function_exists('list_page')) {
    /**
     * Pagina corrente per il gruppo di paginazione indicato.
     *
     * CodeIgniter\Pager\Pager genera (e si aspetta in lettura) un parametro
     * GET diverso per ogni gruppo: "page" solo per il gruppo 'default',
     * altrimenti "page_{group}" (vedi Pager::ensureGroup()). I link del
     * pager (layout/pager.php, via $pager->links($group, ...)) usano
     * sempre il gruppo esplicito (es. 'ip_clients'), quindi qui bisogna
     * leggere lo stesso nome di parametro o i click sui numeri di pagina
     * non hanno alcun effetto: l'URL cambia (?page_ip_clients=2) ma questa
     * funzione, letta con la chiave sbagliata, resta sempre a 1.
     */
    function list_page(string $group = 'default'): int
    {
        $selector = $group === 'default' ? 'page' : 'page_' . $group;
        $p = (int) (service('request')->getGet($selector) ?? 1);
        return $p > 0 ? $p : 1;
    }
}

if (!function_exists('pretty_print_xml')) {
    /**
     * Reindenta un XML per la lettura umana (usato in invoices/validate_xml,
     * sezione "Contenuto XML").
     *
     * FatturaXml costruisce il tracciato senza DOMDocument::formatOutput
     * (giusto per l'invio: SdI non legge indentazione, e' un byte in piu'
     * per ogni fattura trasmessa). Prima d'ora la vista mostrava quella
     * stessa stringa compatta dentro un <pre white-space:pre>: una fattura
     * vera e' su una riga sola di svariati KB, quindi "Contenuto XML" era
     * uno scroll orizzontale illeggibile invece di un elenco di tag.
     *
     * Tocca solo la copia mostrata in pagina — l'XML validato/scaricato/
     * inviato resta quello originale, compatto, invariato.
     *
     * Ritorna la stringa originale se il parsing fallisce (XML gia' rotto:
     * lo si mostra cosi' com'e', l'utente lo sta guardando proprio per
     * capire cosa non va e riformattarlo alla cieca rischierebbe di
     * nascondere l'errore).
     */
    function pretty_print_xml(string $xml): string
    {
        if (trim($xml) === '') {
            return $xml;
        }

        $previous = libxml_use_internal_errors(true);
        $dom      = new DOMDocument();
        // LIBXML_NOBLANKS: scarta gli a-capo/indentazioni gia' presenti prima
        // di re-indentare, altrimenti formatOutput si limita ad aggiungersi
        // a quelli vecchi invece di sostituirli.
        $ok = $dom->loadXML($xml, LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$ok) {
            return $xml;
        }

        $dom->formatOutput = true;
        $formatted = $dom->saveXML();

        return $formatted !== false ? $formatted : $xml;
    }
}

/**
 * Vero/falso da una colonna BOOLEAN letta dal driver PostgreSQL.
 *
 * pg_fetch_assoc() restituisce le booleane come testo ('t'/'f'), quindi
 * empty() e i cast a int sbagliano entrambi: 'f' e' una stringa non vuota
 * (sembrerebbe sempre vero) e (int)'t' vale 0 (sembrerebbe sempre falso).
 * Accetta anche i booleani/interi veri, perche' MySQL restituisce quelli.
 */
if (! function_exists('db_bool')) {
    function db_bool($value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}

if (!function_exists('sql_year_month')) {
    /**
     * Espressione SQL che riduce una data ad 'AAAA-MM' sul motore in uso.
     *
     * Le serie mensili (Bacheca, report, controllo di gestione) usavano
     * TO_CHAR, che esiste solo su PostgreSQL: su MySQL la Bacheca si apriva
     * con un errore 500 alla prima visita. I test unitari girano su SQLite,
     * da qui il terzo ramo.
     */
    function sql_year_month(\CodeIgniter\Database\BaseConnection $db, string $column): string
    {
        return match ($db->DBDriver) {
            'Postgre' => "TO_CHAR({$column}, 'YYYY-MM')",
            'SQLite3' => "strftime('%Y-%m', {$column})",
            default   => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }
}

if (!function_exists('chart_window_anchor')) {
    /**
     * Primo giorno del mese in cui finisce la finestra dei grafici mensili
     * (Bacheca, sparkline, serie IVA), come 'Y-m-01 00:00:00'.
     *
     * Era sempre "questo mese": con un archivio che arriva a dicembre dell'anno
     * scorso, o con un anno passato scelto in topbar, gli ultimi 6 mesi erano
     * vuoti e i grafici si aprivano piatti, come rotti. Ora:
     *  - con un anno scelto la finestra finisce a dicembre di quell'anno (o a
     *    questo mese, se l'anno è quello in corso);
     *  - con "tutti gli anni" finisce a questo mese, salvo che la finestra
     *    corrente sia vuota: allora segue l'ultimo mese con movimenti.
     *
     * Si calcola una volta per richiesta.
     */
    function chart_window_anchor(int $months = 6): string
    {
        static $cache = [];

        // Due sole lunghezze in uso: 6 mesi (finestre scorrevoli) e 12
        // (Indice di redditività). Una lunghezza diversa ricade sulla più
        // vicina, così le due finestre della stessa pagina restano confrontabili.
        $months = $months >= 12 ? 12 : 6;

        if (isset($cache[$months])) {
            return $cache[$months];
        }

        $current = date('Y-m-01 00:00:00');
        $year    = selected_year();

        if ($year > 0) {
            // Con un anno scelto la finestra è l'anno: i 12 mesi vanno da
            // gennaio a dicembre di quell'anno, anche quando è quello in
            // corso. Finché l'Indice finiva a questo mese, a novembre 2026
            // cominciava con il dicembre 2025: un mese di un altro anno dentro
            // un grafico che parla dell'anno scelto, e per giunta il primo
            // della serie, dove sembrava l'inizio dell'anno sbagliato.
            if ($months >= 12) {
                return $cache[$months] = $year . '-12-01 00:00:00';
            }

            return $cache[$months] = $year < (int) date('Y') ? $year . '-12-01 00:00:00' : $current;
        }

        $from = date('Y-m-01', strtotime('-5 month', strtotime($current)));
        $db   = \Config\Database::connect();

        $row = $db->query(
            "SELECT GREATEST(
                COALESCE((SELECT MAX(i.invoice_date_created) FROM ip_invoices i WHERE i.invoice_status_id != 1" . company_scope_sql('i') . "), '1970-01-01'),
                COALESCE((SELECT MAX(p.payment_date) FROM ip_payments p JOIN ip_invoices i ON i.invoice_id = p.invoice_id WHERE 1 = 1" . company_scope_sql('i') . "), '1970-01-01'),
                COALESCE((SELECT MAX(e.expense_date) FROM ip_expenses e WHERE 1 = 1" . company_scope_sql('e') . "), '1970-01-01')
            ) AS last_day"
        )->getRowArray();

        $last = substr((string) ($row['last_day'] ?? ''), 0, 10);

        // Nessun movimento mai, o già dentro la finestra corrente: non si sposta.
        if ($last === '' || $last <= '1970-01-01' || $last >= $from) {
            return $cache[$months] = $current;
        }

        return $cache[$months] = date('Y-m-01 00:00:00', strtotime($last));
    }
}

