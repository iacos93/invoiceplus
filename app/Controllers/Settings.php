<?php

namespace App\Controllers;

use App\Libraries\Sdi\SdiSender;
use App\Models\CompanyModel;
use App\Models\InvoiceGroupModel;
use App\Models\PaymentMethodModel;
use App\Models\SettingModel;
use App\Models\TaxRateModel;

/**
 * Settings controller
 *
 * Porting CI4 del controller Settings (CI3, modulo settings).
 * Gestisce la pagina di configurazione di sistema (salvataggio POST,
 * upload logo, rimozione logo e cronologia versioni).
 */
class Settings extends BaseController
{
    /** Formati numerici disponibili: key => [decimal_point, thousands_separator] */
    private const NUMBER_FORMATS = [
        'en_US' => ['decimal_point' => '.', 'thousands_separator' => ','],
        'de_DE' => ['decimal_point' => ',', 'thousands_separator' => '.'],
        'fr_FR' => ['decimal_point' => ',', 'thousands_separator' => ' '],
        'it_IT' => ['decimal_point' => ',', 'thousands_separator' => '.'],
    ];

    /** Valute disponibili (lista semplice) */
    private const CURRENCIES = [
        'EUR', 'USD', 'GBP', 'CHF', 'JPY', 'CAD', 'AUD', 'NZD',
        'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'HUF', 'RON', 'BGN',
        'RUB', 'TRY', 'INR', 'CNY', 'BRL', 'MXN', 'ZAR', 'ILS',
        'SGD', 'HKD', 'KRW', 'THB', 'MYR', 'IDR', 'PHP', 'VND',
        'AED', 'SAR', 'UAH',
    ];

    /** Mappa codice valuta => simbolo, usata per precompilare il simbolo dalla valuta scelta */
    private const CURRENCY_SYMBOLS = [
        'EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF', 'JPY' => '¥',
        'CAD' => 'CA$', 'AUD' => 'A$', 'NZD' => 'NZ$', 'SEK' => 'kr', 'NOK' => 'kr',
        'DKK' => 'kr', 'PLN' => 'zł', 'CZK' => 'Kč', 'HUF' => 'Ft', 'RON' => 'lei',
        'BGN' => 'лв', 'RUB' => '₽', 'TRY' => '₺', 'INR' => '₹', 'CNY' => '¥',
        'BRL' => 'R$', 'MXN' => 'MX$', 'ZAR' => 'R', 'ILS' => '₪', 'SGD' => 'S$',
        'HKD' => 'HK$', 'KRW' => '₩', 'THB' => '฿', 'MYR' => 'RM', 'IDR' => 'Rp',
        'PHP' => '₱', 'VND' => '₫', 'AED' => 'د.إ', 'SAR' => '﷼', 'UAH' => '₴',
    ];

    /** Paesi disponibili per default_country (lista semplice) */
    private const COUNTRIES = [
        ''   => 'Nessuno',
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
        'IE' => 'Irlanda',
        'GR' => 'Grecia',
    ];

    /** Template disponibili (placeholder: in CI4 i template non sono stati portati) */
    private const TEMPLATES = [
        'default' => 'Default',
    ];

    /**
     * Chiavi che i form delle Impostazioni possono scrivere.
     *
     * Prima il salvataggio prendeva ogni chiave arrivata nella POST e la
     * scriveva in ip_settings: bastava costruire la richiesta a mano per
     * creare o sovrascrivere impostazioni che il form non mostra (credenziali
     * SdI, chiavi di servizio, flag che governano numerazione e invii). Qui
     * l'elenco è esplicito: è l'unione dei campi di settings/index.php e di
     * settings/partial_sdi.php, che condividono questo salvataggio. Una chiave
     * nuova va aggiunta anche qui, altrimenti viene ignorata in silenzio.
     */
    private const SETTING_KEYS = [
        // Scheda Generale
        'default_language', 'first_day_of_week', 'default_country', 'date_format',
        'default_list_limit', 'currency_symbol', 'currency_code', 'number_format',
        'currency_symbol_placement', 'custom_title', 'custom_subtitle',
        'monospace_amounts', 'read_only_toggle', 'cron_key',
        // Scheda Fatture
        'default_invoice_terms', 'pdf_invoice_footer', 'pdf_watermark',
        'invoices_due_after', 'default_invoice_tax_rate', 'default_item_tax_rate',
        'default_include_item_tax', 'invoice_default_payment_method',
        'invoice_pre_password', 'generate_invoice_number_for_draft',
        'enable_invoice_deletion', 'mark_invoices_sent_pdf', 'automatic_email_on_recur',
        // Scheda Preventivi
        'default_quote_notes', 'pdf_quote_footer', 'quotes_expire_after',
        'quote_pre_password', 'generate_quote_number_for_draft', 'mark_quotes_sent_pdf',
        // Scheda Progetti
        'projects_enabled', 'default_hourly_rate',
        // Pagina SdI (settings/sdi)
        'sdi_channel', 'sdi_pec_recipient', 'sdi_pec_recipient_full', 'sdi_pec_sender_id',
        'sdi_api_endpoint', 'sdi_api_username', 'sdi_api_password',
        'sdi_coop_endpoint', 'sdi_coop_cert_path', 'sdi_coop_key_path',
        'sdi_coop_key_passphrase', 'sdi_coop_ca_path', 'sdi_coop_inbound_token',
        'sdi_imap_host', 'sdi_imap_username', 'sdi_imap_password',
        'sdi_ricezione_default_expense_category_id', 'sdi_ricezione_inbound_token',
        'sdi_ricezione_notifica_endpoint', 'sdi_ricezione_notifica_wsdl',
        // Scelta della casella di sistema della company attiva: pur essendo una
        // colonna di ip_companies (vedi il ramo dedicato in saveSettings) arriva
        // nella stessa POST, quindi va riconosciuta come chiave legittima.
        'company_system_sender_id',
    ];

    /** Campi sì/no: il form manda 0 o 1, qualunque altro valore è da buttare. */
    private const SETTING_BOOLEANS = [
        'monospace_amounts', 'projects_enabled', 'automatic_email_on_recur',
        'enable_invoice_deletion', 'generate_invoice_number_for_draft',
        'generate_quote_number_for_draft', 'mark_invoices_sent_pdf', 'mark_quotes_sent_pdf',
    ];

    /** Contatori in giorni: interi, lo zero è ammesso (nessuna scadenza). */
    private const SETTING_COUNTS = ['invoices_due_after', 'quotes_expire_after'];

    /** Chiavi esterne scelte dalle tendine: vuoto (nessuno) oppure un id naturale. */
    private const SETTING_IDS = [
        'default_invoice_tax_rate', 'default_item_tax_rate',
        'invoice_default_payment_method', 'sdi_pec_sender_id',
        'sdi_ricezione_default_expense_category_id',
    ];

    /**
     * Lunghezza massima dei campi testuali dove un valore fuori misura ha un
     * effetto concreto: il simbolo valuta finisce dentro ogni importo stampato,
     * i testi predefiniti finiscono nei PDF. Gli altri campi liberi (titoli,
     * percorsi dei certificati) restano senza limite: setting_value è TEXT.
     */
    private const SETTING_TEXT_LIMITS = [
        'currency_symbol'      => 8,
        'default_invoice_terms' => 5000,
        'default_quote_notes'  => 5000,
        'pdf_invoice_footer'   => 5000,
        'pdf_quote_footer'     => 5000,
    ];

    /** Token dei webservice SdI in ingresso: cifrati con Cryptor, come cron_key. */
    private const ENCRYPTED_SETTING_KEYS = ['sdi_coop_inbound_token', 'sdi_ricezione_inbound_token'];

    /** Loghi caricabili dal form (campo file => impostazione omonima). */
    private const LOGO_FIELDS = ['invoice_logo', 'invoice_logo_footer', 'login_logo', 'login_logo_dark'];

    /** Estensioni ammesse per i loghi (le stesse che logo_data_uri sa servire). */
    private const LOGO_EXTENSIONS = ['gif', 'jpg', 'jpeg', 'png', 'svg'];

    /**
     * Dimensione massima di un logo. Il valore arriva nel data URI di ogni
     * pagina che lo mostra e dentro i PDF: senza limite un file da decine di MB
     * faceva crescere la memoria a ogni tag, e il browser restava fermo.
     */
    private const LOGO_MAX_BYTES = 2 * 1024 * 1024;

    /**
     * Tipo reale atteso per ogni estensione ammessa. L'SVG è un documento XML:
     * libmagic lo riconosce come image/svg+xml solo a volte, altrimenti lo
     * classifica come testo o XML generico (il contenuto lo controlla isSafeSvg).
     */
    private const LOGO_MIMES = [
        'gif'  => ['image/gif'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'svg'  => ['image/svg+xml', 'text/plain', 'text/html', 'application/xml', 'text/xml'],
    ];

    private SettingModel $settingModel;

    public function __construct()
    {
        $this->settingModel = new SettingModel();
    }

    public function index()
    {
        $request = service('request');

        if (strtolower((string) $request->getMethod()) === 'post') {
            return $this->saveSettings();
        }

        $data = [
            'invoice_groups'    => (new InvoiceGroupModel())->getAllOrdered(),
            'tax_rates'         => (new TaxRateModel())->getAllOrdered(),
            'payment_methods'   => (new PaymentMethodModel())->getAllOrdered(),
            'number_formats'    => self::NUMBER_FORMATS,
            'currencies'        => self::CURRENCIES,
            'currency_symbols'  => self::CURRENCY_SYMBOLS,
            'countries'         => self::COUNTRIES,
            'templates'         => self::TEMPLATES,
            'date_formats'      => date_formats(),
            'current_date'      => new \DateTime(),
            // Solo per la tendina della casella PEC nella scheda SdI: la
            // configurazione email vera sta in /email_senders.
            'email_senders'     => (new \App\Models\EmailSenderModel())->getAllOrdered(),
            'companies'         => (new \App\Models\CompanyModel())->getAllActive(),
            'calendar_feed_url' => (static function (): string {
                $token = decrypt_secret((string) get_setting('calendar_feed_token', ''));
                return $token !== '' ? site_url('calendar/feed/' . $token) : '';
            })(),
        ];

        return view('settings/index', $data);
    }

    /**
     * Pagina dedicata alla configurazione della fattura elettronica (SdI),
     * raggiungibile dal menu Fatture. Riutilizza la logica di salvataggio
     * generica delle impostazioni: il form posta lo stesso array "settings".
     */
    public function sdi()
    {
        $request = service('request');

        if (strtolower((string) $request->getMethod()) === 'post') {
            // Stesso salvataggio delle Impostazioni, ma il ritorno resta sulla
            // pagina SdI: prima il redirect era fisso su /settings, quindi un
            // errore su un campo SdI riappariva sulla scheda Generale.
            return $this->saveSettings('sdi/settings');
        }

        $data = [
            'email_senders' => (new \App\Models\EmailSenderModel())->getAllOrdered(),
        ];

        return view('settings/sdi', $data);
    }

    /**
     * Salva le impostazioni inviate dal form (POST).
     *
     * Il giro è: scartare le chiavi che il form non può scrivere, validare i
     * valori, controllare i loghi, poi scrivere tutto in blocco. Prima salvava
     * ogni chiave ricevuta e segnalava i problemi solo alla fine: un logo con
     * formato sbagliato lasciava comunque le impostazioni scritte a metà e
     * due messaggi contraddittori ("salvate correttamente" + l'errore).
     *
     * @param string $returnPath pagina a cui tornare (Impostazioni o SdI)
     */
    private function saveSettings(string $returnPath = 'settings')
    {
        $request  = service('request');
        $settings = $request->getPost('settings');

        if (!is_array($settings)) {
            session()->setFlashdata('alert_error', 'Nessuna impostazione ricevuta');

            return redirect()->to($returnPath);
        }

        $allowed = array_flip(self::SETTING_KEYS);

        foreach ($settings as $key => $value) {
            if (!isset($allowed[$key]) && !$this->isMetaKey($key)) {
                // Chiave non prevista dal form: via, senza far fallire il
                // salvataggio (i browser vecchi possono rimandare campi non
                // più presenti nella view).
                unset($settings[$key]);

                continue;
            }

            // settings[] è un array costruito dal client: un valore annidato
            // (settings[date_format][]=x) arriverebbe qui come array e farebbe
            // esplodere il cast a stringa più avanti.
            if (!is_scalar($value)) {
                unset($settings[$key]);
            }
        }

        $errors = $this->validateSettings($settings);
        $logos  = $this->incomingLogos($errors);

        if ($errors !== []) {
            session()->setFlashdata('alert_error', $this->errorsToHtml($errors));

            return redirect()->to($returnPath)->withInput();
        }

        $values = [];

        foreach ($settings as $key => $value) {
            // I marcatori *_field_is_password / *_field_is_amount dicono come
            // trattare il valore: non sono impostazioni da scrivere.
            if ($this->isMetaKey($key)) {
                continue;
            }

            // Casella email di sistema: è una colonna della company attiva, non
            // un'impostazione globale, così ogni società spedisce dalle proprie
            // caselle (company_setting la legge per prima cosa).
            if ($key === 'company_system_sender_id') {
                $companyId = active_company_id();

                if ($companyId) {
                    (new CompanyModel())->update($companyId, [
                        'company_system_sender_id' => (int) $value,
                    ]);
                }

                continue;
            }

            $value = is_string($value) ? trim($value) : (string) $value;

            if (isset($settings[$key . '_field_is_password'])) {
                // Campo password vuoto: non sovrascrivere la password esistente
                if ($value === '') {
                    continue;
                }

                // Crittografa la password e salva la stringa base64
                $encrypted = \Config\Services::encrypter()->encrypt($value);
                $values[$key] = base64_encode($encrypted);

                continue;
            }

            if (in_array($key, self::ENCRYPTED_SETTING_KEYS, true)) {
                // Token dei webservice SdI in ingresso (SDICoop, Ricezione):
                // stesso segreto di cron_key/mcp_key, cifrato con Cryptor
                // (encrypt_secret) invece che con l'encrypter nativo usato per
                // i campi "password" del form — qui sono testo semplice.
                $values[$key] = encrypt_secret($value);

                continue;
            }

            if (isset($settings[$key . '_field_is_amount'])) {
                // Normalizza gli importi con i separatori di sistema
                $values[$key] = standardize_amount($value);

                continue;
            }

            $values[$key] = $value;
        }

        if (isset(self::NUMBER_FORMATS[$settings['number_format'] ?? ''])) {
            // Aggiorna i separatori in base al formato numerico scelto
            $format = self::NUMBER_FORMATS[$settings['number_format']];
            $values['decimal_point']       = $format['decimal_point'];
            $values['thousands_separator'] = $format['thousands_separator'];
        }

        // I loghi si spostano prima di toccare il database: se un file non si
        // riesce a scrivere, l'utente torna al form con l'errore e nessuna
        // impostazione è stata cambiata.
        foreach ($logos as $field => $file) {
            $name = $this->storeLogo($file, $field);

            if ($name === '') {
                session()->setFlashdata('alert_error', \esc(trans($field, '', $field)) . ': caricamento non riuscito.');

                return redirect()->to($returnPath)->withInput();
            }

            $values[$field] = $name;
        }

        // Una sola passata: il salvataggio delle 50 e più chiavi era la parte
        // più lenta della pagina (vedi SettingModel::setSettings).
        $this->settingModel->setSettings($values);

        session()->setFlashdata('alert_success', 'Impostazioni salvate correttamente');

        return redirect()->to($returnPath);
    }

    /**
     * Vero per i marcatori che accompagnano un campo (password, importo).
     * Il browser li manda insieme al valore e servono solo al salvataggio.
     */
    private function isMetaKey(string $key): bool
    {
        return str_ends_with($key, '_field_is_password') || str_ends_with($key, '_field_is_amount');
    }

    /**
     * Valori ammessi per le tendine ad elenco chiuso. Le altre tendine (aliquote,
     * metodi di pagamento, caselle email, categorie di spesa) si popolano dal
     * database: per quelle basta controllare che l'id sia un intero.
     *
     * @return array<string, list<string>>
     */
    private function settingEnums(): array
    {
        return [
            'default_language'          => ['it', 'en'],
            'first_day_of_week'         => ['0', '1', '2', '3', '4', '5', '6'],
            'default_country'           => array_map('strval', array_keys(self::COUNTRIES)),
            'date_format'               => array_keys(date_formats()),
            'currency_code'             => self::CURRENCIES,
            'number_format'             => array_keys(self::NUMBER_FORMATS),
            'currency_symbol_placement' => ['before', 'beforespace', 'after', 'afterspace'],
            'read_only_toggle'          => ['0', '1', '2', '3'],
            'default_include_item_tax'  => ['0', '1'],
            'sdi_channel'               => array_keys(SdiSender::CHANNELS),
        ];
    }

    /**
     * Controlla i valori prima di scriverli.
     *
     * Copre i campi dove un valore fuori regola rompe qualcosa a valle: le
     * tendine (un formato data inventato non si riformatta), i contatori
     * (default_list_limit a 0 non carica nessuna lista), gli importi
     * (standardize_amount su "abc" restituisce "abc") e i testi dove la
     * lunghezza ha un effetto concreto. I campi liberi senza vincoli
     * applicativi restano liberi: rifiutarli bloccherebbe configurazioni
     * valide senza che nessuno le legga meglio.
     *
     * @return array<string, string> messaggi, indicizzati per campo
     */
    private function validateSettings(array $settings): array
    {
        $errors = [];
        $enums  = $this->settingEnums();

        foreach ($settings as $key => $value) {
            if ($this->isMetaKey($key) || $key === 'company_system_sender_id') {
                continue;
            }

            $value = is_string($value) ? trim($value) : (string) $value;

            // Password e token lasciati vuoti non si toccano: è il modo per non
            // dover riscrivere il segreto a ogni salvataggio.
            if (isset($settings[$key . '_field_is_password']) && $value === '') {
                continue;
            }

            $label = trans($key, '', $key);

            if (isset($enums[$key]) && !in_array($value, $enums[$key], true)) {
                $errors[$key] = sprintf('%s: valore non ammesso.', $label);

                continue;
            }

            if (in_array($key, self::SETTING_BOOLEANS, true) && !in_array($value, ['0', '1'], true)) {
                $errors[$key] = sprintf('%s: scegli una delle due opzioni.', $label);

                continue;
            }

            if ($key === 'default_list_limit' && (!ctype_digit($value) || (int) $value < 1)) {
                $errors[$key] = sprintf('%s: deve essere un numero intero maggiore di zero.', $label);

                continue;
            }

            if (in_array($key, self::SETTING_COUNTS, true) && $value !== '' && !ctype_digit($value)) {
                $errors[$key] = sprintf('%s: deve essere un numero intero maggiore o uguale a zero.', $label);

                continue;
            }

            if (in_array($key, self::SETTING_IDS, true) && $value !== '' && !ctype_digit($value)) {
                $errors[$key] = sprintf('%s: selezione non valida.', $label);

                continue;
            }

            if (isset($settings[$key . '_field_is_amount']) && $value !== '' && !is_numeric(standardize_amount($value))) {
                $errors[$key] = sprintf('%s: importo non valido.', $label);

                continue;
            }

            if (isset(self::SETTING_TEXT_LIMITS[$key]) && mb_strlen($value) > self::SETTING_TEXT_LIMITS[$key]) {
                $errors[$key] = sprintf('%s: troppo lungo (massimo %d caratteri).', $label, self::SETTING_TEXT_LIMITS[$key]);
            }
        }

        return $errors;
    }

    /**
     * Raccoglie i loghi caricati e ne controlla il formato prima che qualsiasi
     * cosa venga scritta. Prima l'estensione si verificava dentro il salvataggio
     * di ogni singolo file, quindi l'errore arrivava dopo aver già scritto le
     * impostazioni.
     *
     * @param array<string, string> $errors errori accumulati (per riferimento)
     *
     * @return array<string, \CodeIgniter\HTTP\Files\UploadedFile>
     */
    private function incomingLogos(array &$errors): array
    {
        $logos = [];

        foreach (self::LOGO_FIELDS as $field) {
            $file = service('request')->getFile($field);

            if (!$file || !$file->isValid() || $file->hasMoved()) {
                continue;
            }

            $label     = trans($field, '', $field);
            $extension = strtolower((string) $file->getClientExtension());

            if (!in_array($extension, self::LOGO_EXTENSIONS, true)) {
                $errors[$field] = $label . ': formato non consentito ('
                    . $extension . '). Formati ammessi: GIF, JPEG, PNG, SVG.';

                continue;
            }

            if ($file->getSize() > self::LOGO_MAX_BYTES) {
                $errors[$field] = $label . ': immagine troppo grande (massimo '
                    . (self::LOGO_MAX_BYTES / 1024 / 1024) . ' MB).';

                continue;
            }

            // L'estensione dice solo come si chiama il file: il tipo lo legge
            // fileinfo dal file temporaneo, non il browser (che può mentire).
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getTempName()) ?: '';

            if (!in_array($mime, self::LOGO_MIMES[$extension], true)) {
                $errors[$field] = $label . ': il file non contiene un\'immagine ' . strtoupper($extension) . '.';

                continue;
            }

            // Un SVG è un documento, non solo un'immagine: può portare script,
            // gestori di eventi o HTML incorporato. Nell'<img> non girano, ma il
            // file sta in una cartella scaricabile: qui si rifiuta il contenuto
            // attivo invece di sperare che nessuno lo apra da solo.
            if ($extension === 'svg' && !$this->isSafeSvg($file->getTempName())) {
                $errors[$field] = $label . ': il file SVG contiene codice o contenuti attivi.';

                continue;
            }

            $logos[$field] = $file;
        }

        return $logos;
    }

    /**
     * Vero se l'SVG è un disegno e non un documento con parti attive: script,
     * gestori di eventi (onload, onclick…), entità esterne o foreignObject con
     * HTML dentro. Tutti modi per far eseguire qualcosa a chi apre il file.
     */
    private function isSafeSvg(string $path): bool
    {
        // Basta l'inizio del file: un SVG con contenuti attivi li ha nelle
        // prime righe, e leggere 256 KB evita di caricare in memoria un file
        // enorme solo per controllarlo.
        $source = @file_get_contents($path, false, null, 0, 262144);

        if ($source === false || stripos($source, '<svg') === false) {
            return false;
        }

        return preg_match('/<\s*script|<\s*foreignObject|javascript:|<!ENTITY|\son[a-z]+\s*=/i', $source) !== 1;
    }

    /**
     * Sposta un logo in writable/uploads e restituisce il nome salvato ('' se
     * il file non si è potuto scrivere).
     *
     * Il nome del browser non è fidato: può contenere percorsi ("../../x.png")
     * e due campi diversi possono caricare lo stesso "logo.png", sovrascriven-
     * dosi a vicenda. Qui il nome si costruisce: campo + originale ripulito.
     */
    private function storeLogo(\CodeIgniter\HTTP\Files\UploadedFile $file, string $field): string
    {
        $uploadDir = WRITEPATH . 'uploads';

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            return '';
        }

        try {
            // move() lancia FileException se move_uploaded_file() fallisce
            // (permessi, disco pieno): qui diventa un errore da mostrare.
            $file->move($uploadDir, $this->logoFileName($file, $field), true);
        } catch (\Throwable) {
            return '';
        }

        return $file->getName();
    }

    /**
     * Nome da scrivere per un logo: prefisso del campo, nome originale ridotto
     * a lettere, numeri e trattini, estensione già verificata.
     */
    private function logoFileName(\CodeIgniter\HTTP\Files\UploadedFile $file, string $field): string
    {
        $extension = strtolower((string) $file->getClientExtension());
        $base      = strtolower(pathinfo((string) $file->getClientName(), PATHINFO_FILENAME));
        $base      = preg_replace('/[^a-z0-9_-]+/', '-', $base) ?? '';
        $base      = trim($base, '-_');

        if ($base === '') {
            $base = 'logo';
        }

        return $field . '-' . mb_substr($base, 0, 40) . '.' . $extension;
    }

    /**
     * Unisce i messaggi di validazione nel toast.
     *
     * alerts.php stampa il flashdata così com'è (per permettere il markup), non
     * lo scappa: i messaggi passano da esc() qui, perché contengono nomi di
     * campo tradotti.
     */
    private function errorsToHtml(array $errors): string
    {
        return implode('<br>', array_map(static fn (string $message): string => \esc($message), array_values($errors)));
    }

    /**
     * Rimuove un logo (invoice / login) dal filesystem e azzera l'impostazione.
     */
    public function removeLogo(string $type)
    {
        // Rimuovere un logo cancella un file e azzera un'impostazione: la
        // rotta e' POST e qui si ricontrolla il metodo, cosi' una futura
        // registrazione su GET non basta a farla funzionare di nuovo.
        if (service('request')->getMethod() !== 'POST') {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        // Elenco chiuso invece di comporre la chiave concatenando $type: quello
        // che arriva dall'URL non deve poter indicare un'impostazione qualsiasi.
        $keys = [
            'invoice'        => 'invoice_logo',
            'invoice_footer' => 'invoice_logo_footer',
            'login'          => 'login_logo',
            'login_dark'     => 'login_logo_dark',
        ];

        if (!isset($keys[$type])) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $settingKey = $keys[$type];
        $fileName   = $this->settingModel->setting($settingKey);

        if ($fileName) {
            $path = WRITEPATH . 'uploads/' . $fileName;

            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->settingModel->setSetting($settingKey, '');

        session()->setFlashdata('alert_success', 'Logo rimosso correttamente');

        return redirect()->to('/settings');
    }

}
