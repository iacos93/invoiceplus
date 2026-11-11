<?php

namespace App\Controllers;

use App\Models\CompanyModel;
use App\Models\InvoiceModel;
use App\Models\InvoiceRecurringModel;
use App\Models\UserModel;
use App\Services\CalendarService;
use App\Services\DocumentPdfBuilder;
use CodeIgniter\Email\Email;

/**
 * Cron controller
 *
 * Porting CI4 del controller Cron (CI3): generazione automatica delle
 * fatture ricorrenti (route pubblica /cron/recur/(:any) protetta dalla
 * cron key impostata nelle impostazioni) e polling delle ricevute SdI
 * sulla casella PEC (/cron/sdi_poll/(:any), stesso tipo di protezione).
 *
 * Si sono aggiunti i processi che la pagina "Scadenzario" promette ma che
 * nessuno eseguiva: riepilogo settimanale delle scadenze (/cron/digest) e
 * backup del database (/cron/backup). I solleciti delle fatture scadute NON
 * sono qui per scelta: si inviano a mano dalla fattura o dallo scadenzario
 * (Mailer::invoice si apre gia' come sollecito sulle fatture scadute). Tutti con la stessa protezione: URL segreto con cron key,
 * nessuna autenticazione di sessione — il cron gira senza utente.
 */
class Cron extends BaseController
{
    /**
     * Lock advisory Postgres per evitare due esecuzioni sovrapposte dello
     * stesso cron (retry dopo timeout, doppia voce nel crontab, un giro
     * ancora in corso quando parte il successivo). Senza questo, recur() segue
     * un pattern leggi-poi-agisci-poi-segna senza lock: due esecuzioni
     * concorrenti possono leggere la stessa ricorrente scaduta prima che una
     * delle due scriva lo stato, generando due fatture per lo stesso periodo.
     *
     * Lock di sessione (non di transazione): si rilascia da solo a fine
     * connessione anche se il processo muore a meta', quindi un cron
     * bloccato non lascia il lock preso per sempre.
     */
    private function withCronLock(string $name, callable $work): string
    {
        $db     = \Config\Database::connect();
        $lockId = "SELECT pg_try_advisory_lock(hashtext('{$name}')::bigint) AS locked";
        $locked = (bool) ($db->query($lockId)->getRow()->locked ?? false);

        if (!$locked) {
            return 'Un\'altra esecuzione di "' . $name . '" e\' gia\' in corso, questo giro viene saltato.';
        }

        try {
            return $work();
        } finally {
            $db->query("SELECT pg_advisory_unlock(hashtext('{$name}')::bigint)");
        }
    }

    public function recur(string $cronKey)
    {
        // Controllo della cron key (equivalente CI3)
        if (!cron_key_valid($cronKey)) {
            http_response_code(403);

            return 'Cron key non valida';
        }

        return $this->withCronLock('cron_recur', function () {
            return $this->doRecur();
        });
    }

    private function doRecur(): string
    {
        $invoiceModel     = new InvoiceModel();
        $recurringModel   = new InvoiceRecurringModel();
        $recurringInvoices = $recurringModel->getActive();

        $created = 0;

        // Lettura una sola volta: è invariante rispetto al ciclo.
        $emailOnRecur = (int) get_setting('automatic_email_on_recur', 0) === 1;

        foreach ($recurringInvoices as $recurring) {
            $sourceInvoice = $invoiceModel->getById((int) $recurring['invoice_id']);

            if (!$sourceInvoice) {
                continue;
            }

            // Come per solleciti e digest: l'azienda della fattura modello
            // diventa quella attiva, cosi' numerazione, casella di invio
            // (system_email_sender) e query scoped lavorano sull'azienda giusta.
            session()->set('active_company_id', (int) $sourceInvoice['company_id']);

            // Nuova fattura inviata, con numero generato. company_id va
            // copiato esplicitamente dalla fattura sorgente: in cron non c'è
            // sessione, quindi active_company_id() cadrebbe sull'azienda
            // predefinita e la ricorrente finirebbe sotto un'altra azienda.
            $dbArray = [
                'company_id'          => $sourceInvoice['company_id'],
                'client_id'           => $sourceInvoice['client_id'],
                'invoice_group_id'    => $sourceInvoice['invoice_group_id'],
                'invoice_status_id'   => 2,
                // Niente invoice_date_due: la calcola create() con i giorni di
                // pagamento del cliente. Passandola già fatta con
                // getDateDue(oggi) valeva sempre il default globale, e la
                // ricorrente scadeva diversamente dalla stessa fattura creata
                // a mano per quel cliente.
                'invoice_date_created' => date('Y-m-d'),
                'invoice_terms'        => $sourceInvoice['invoice_terms'],
                'invoice_discount_amount'  => $sourceInvoice['invoice_discount_amount'],
                'invoice_discount_percent' => $sourceInvoice['invoice_discount_percent'],
                'payment_method'      => $sourceInvoice['payment_method'],
            ];

            // create() genera gia' il numero per uno stato != bozza (e avanza
            // il contatore della serie): rigenerarlo qui brucerebbe un secondo
            // numero, creando buchi nella numerazione sequenziale.
            $newInvoiceId = $invoiceModel->create($dbArray);

            if (!$newInvoiceId) {
                // Creazione fallita: la prossima data NON viene avanzata,
                // così il prossimo giro riprova senza saltare un periodo.
                log_message('error', 'Cron recur: creazione fattura fallita per la ricorrente #' . $recurring['invoice_id']);

                continue;
            }

            // La data di ricorrenza si avanza solo dopo che la fattura
            // esiste davvero: prima si rischiava di "bruciare" un periodo
            // se la creazione falliva a metà.
            $recurringModel->setNextRecurDate((int) $recurring['invoice_id']);

            // Copia TUTTE le righe, le imposte e i campi custom. In CI3 si
            // copiavano solo le righe "ricorrenti", ma ogni riga lo era per
            // default e la vista aveva l'icona per escluderla; il porting ha
            // perso l'icona e la colonna item_is_recurring e' nata a 0, cosi'
            // ogni fattura ricorrente veniva generata vuota (totale 0,00).
            // Finche' non torna un modo per escludere una riga, la ricorrente
            // e' la copia integrale del modello.
            $invoiceModel->copyInvoice((int) $recurring['invoice_id'], $newInvoiceId, false);

            $created++;

            // Email automatica se configurata
            if ($emailOnRecur) {
                $this->sendRecurringEmail($newInvoiceId);
            }
        }

        // return e non echo: doRecur() ora e' incapsulata in withCronLock(),
        // che deve propagare il messaggio come valore di ritorno (stesso
        // pattern degli altri metodi Cron, che gia' tornavano stringhe).
        return 'Fatture ricorrenti generate: ' . $created;
    }

    /**
     * Invia al cliente la fattura ricorrente appena generata
     * (pattern di Sessions::sendEmail con PDF allegato).
     */
    private function sendRecurringEmail(int $invoiceId): void
    {
        $invoiceModel = new InvoiceModel();
        $invoice      = $invoiceModel->getById($invoiceId);

        if (!$invoice || empty($invoice['client_email']) || !$this->hasSender()) {
            return;
        }

        // Genera il PDF (bytes) salvandolo in un file temporaneo
        $html = $this->renderInvoiceHtml($invoiceId);

        if ($html === '') {
            return;
        }

        $filename = (new DocumentPdfBuilder())->invoiceFilename($invoice);
        $tmpPath  = WRITEPATH . 'uploads/' . $filename;

        $pdfBytes = pdf_create($html, $filename, false);
        file_put_contents($tmpPath, $pdfBytes);

        $email = \Config\Services::email(null, false);
        $this->configureEmail($email);
        $email->setFrom(system_mail_from(), $this->companyName((int) $invoice['company_id']));
        $email->setTo($invoice['client_email']);
        $email->setSubject('Fattura ' . $invoice['invoice_number'] . ' - ' . $this->companyName((int) $invoice['company_id']));
        $email->setMessage('Gentile cliente, in allegato trova la fattura ' . $invoice['invoice_number'] . ".\n\n" .
            'Totale: ' . format_currency($invoice['invoice_total']) . "\n" .
            'Scadenza: ' . date_from_mysql($invoice['invoice_date_due'], true) . "\n\n" .
            'Cordiali saluti, ' . $this->companyName((int) $invoice['company_id']));
        $email->attach($tmpPath);

        $email->send();

        @unlink($tmpPath);
    }

    /**
     * HTML del PDF della fattura (stesso percorso di Invoices).
     *
     * @param string|null $templateName Template PDF esplicito (es. quello
     *                                  "in ritardo" dei solleciti); null usa
     *                                  il template predefinito delle impostazioni.
     */
    private function renderInvoiceHtml(int $invoiceId, ?string $templateName = null): string
    {
        $invoice = (new InvoiceModel())->getById($invoiceId);

        if (!$invoice) {
            return '';
        }

        // Stessa composizione del download e dell'allegato email: qui cambia
        // solo il template, che i solleciti possono forzare (es. "in ritardo").
        return (new DocumentPdfBuilder())->invoice($invoice, $templateName);
    }

    /**
     * Polling ricevute SdI dalla casella PEC di trasmissione.
     *
     * Lo SdI risponde agli invii via PEC con email di ricevuta
     * (RC/NS/NE/EC/MC/DT) aventi come allegato il file di esito
     * <CodiceEsito>_<nomefile>.xml: questo cron le importa nel registro
     * trasmissioni. Richiede l'estensione php-imap (casella IMAP
     * configurata in Impostazioni > SdI) e la cron key standard.
     *
     * Senza estensione IMAP il canale PEC resta utilizzabile, ma l'esito
     * va aggiornato a mano dalla pagina fattura (o con il webservice).
     */
    public function sdiPoll(string $cronKey)
    {
        if (!cron_key_valid($cronKey)) {
            http_response_code(403);

            return 'Cron key non valida';
        }

        if (!function_exists('imap_open')) {
            log_message('error', 'SdI: estensione php-imap non disponibile, polling ricevute saltato');

            return 'Estensione php-imap non disponibile: installala per il polling ricevute SdI';
        }

        $host     = (string) get_setting('sdi_imap_host', '');
        $username = (string) get_setting('sdi_imap_username', '');
        $password = decrypt_setting_password((string) get_setting('sdi_imap_password', ''));

        if ($host === '' || $username === '') {
            return 'Casella IMAP SdI non configurata (Impostazioni > SdI)';
        }

        $mailbox = '{' . $host . ':993/imap/ssl}INBOX';
        $conn    = @imap_open($mailbox, $username, $password);

        if (!$conn) {
            log_message('error', 'SdI: connessione IMAP fallita (' . $host . '): ' . implode('; ', imap_errors() ?: []));

            return 'Connessione IMAP fallita';
        }

        try {
            $messages = imap_search($conn, 'UNSEEN');

            if (!$messages) {
                return 'Nessuna ricevuta in attesa';
            }

            $model    = new \App\Models\SdiTransmissionModel();
            $imported = [];

            foreach ($messages as $msgNum) {
                $overview = imap_fetch_overview($conn, $msgNum, 0);

                if (!$overview) {
                    continue;
                }

                $subject = trim((string) $overview[0]->subject);
                $name    = $this->importReceipt($conn, $msgNum, $subject, $model);

                if ($name !== '') {
                    $imported[] = $name;

                    // Le ricevute si marcano come lette solo se elaborate:
                    // un esito non riconosciuto resta in coda (altrimenti
                    // imap_search('UNSEEN') non lo riprenderebbe mai piu').
                    imap_setflag_full($conn, $msgNum, '\\Seen');
                }
            }

            // L'esito SdI è l'evoluzione di un invio: chi spedisce vuole
            // saperlo anche senza aprire l'app, soprattutto quando l'esito
            // è negativo (NS/NE) e bisogna correggere e reinviare.
            if ($imported) {
                $notified = $this->sendNotificationEmail(
                    'Ricevute SdI importate (' . count($imported) . ')',
                    "Sono state importate le seguenti ricevute SdI:\n\n"
                    . implode("\n", array_map(static fn(string $f): string => ' - ' . $f, $imported))
                );

                return 'Ricevute SdI importate: ' . count($imported)
                    . ($notified ? '' : ' (notifica email non inviata)');
            }

            return 'Ricevute SdI importate: 0';
        } finally {
            imap_close($conn);
        }
    }

    /**
     * Cerca l'allegato di esito (RC_*.xml, NS_*.xml...) e lo collega
     * alla trasmissione con lo stesso nome file. L'esito è già nel nome
     * dell'allegato, il corpo della email è solo una notifica.
     *
     * Ritorna il nome del file importato ('' se non riconosciuto).
     */
    private function importReceipt($conn, int $msgNum, string $subject, \App\Models\SdiTransmissionModel $model): string
    {
        $structure = imap_fetchstructure($conn, $msgNum);

        if (!$structure || empty($structure->parts)) {
            return '';
        }

        $parts = $structure->parts;

        for ($i = 1; $i <= count($parts); $i++) {
            $part = $parts[$i - 1];

            // Nome del file: parametro name della part o soggetto per le
            // ricevute del dominio SdI (subject = nome file).
            $filename = $this->partFilename($part) ?: $subject;

            if (!preg_match('/^(RC|NS|NE|EC|MC|DT)_(.+\.xml)$/i', $filename, $m)) {
                continue;
            }

            $base = $m[2];

            $transmission = $model->where('filename', $base)->orderBy('sdi_transmission_id', 'DESC')->first();

            if (!$transmission) {
                log_message('error', 'SdI: ricevuta ' . $filename . ' senza trasmissione corrispondente');

                continue;
            }

            $model->setEsito((int) $transmission['sdi_transmission_id'], strtoupper($m[1]),
                'Ricevuta ' . strtoupper($m[1]) . ' importata automaticamente');

            return $filename;
        }

        return '';
    }

    /** Nome file di una part MIME (filename o name, in header o parametri). */
    private function partFilename($part): string
    {
        $names = [];

        if (!empty($part->parameters)) {
            foreach ($part->parameters as $p) {
                if (strcasecmp($p->attribute, 'name') === 0) {
                    $names[] = $p->value;
                }
            }
        }

        if (!empty($part->dparameters)) {
            foreach ($part->dparameters as $p) {
                if (strcasecmp($p->attribute, 'filename') === 0) {
                    $names[] = $p->value;
                }
            }
        }

        // "encoded" filenames (RFC 2231): si leggono dalla part non
        // decodificata e si normalizzano con imap_utf8.
        foreach ($names as $name) {
            $decoded = imap_utf8($name);

            if (preg_match('/\.xml$/i', $decoded)) {
                return $decoded;
            }
        }

        return '';
    }

    /**
     * Riepilogo settimanale delle scadenze, per il titolare.
     *
     * Aggrega le stesse fonti della pagina Calendario (fatture, F24,
     * attività) tramite CalendarService — è l'unica definizione di
     * "scadenza" che esista, la vista e l'email non possono divergere —
     * e spedisce una email con le scadenze dei prossimi 7 giorni e quelle
     * già scadute. Il destinatario è la impostazione cron_notify_email,
     * con ripiego sull'email del primo utente amministratore.
     */
    public function deadlineDigest(string $cronKey)
    {
        if (!cron_key_valid($cronKey)) {
            http_response_code(403);

            return 'Cron key non valida';
        }

        // Un riepilogo per azienda, ciascuno con la casella di invio e le
        // scadenze di quell'azienda. Prima gli eventi di tutte venivano uniti
        // in un'unica email spedita con la casella dell'ULTIMA azienda del
        // ciclo: se quella non ne aveva una, l'invio ricadeva su mail() di
        // sistema, e un'azienda leggeva le scadenze delle altre.
        $results = [];

        foreach ((new CompanyModel())->getAllActive() as $company) {
            session()->set('active_company_id', (int) $company['company_id']);

            $result = $this->sendDigestForActiveCompany();
            if ($result !== null) {
                $results[] = $company['company_name'] . ': ' . $result;
            }
        }

        return $results === [] ? 'Nessuna scadenza nel prossimo periodo' : implode("\n", $results);
    }

    /**
     * Riepilogo dell'azienda attiva; null se non ha scadenze da segnalare.
     */
    private function sendDigestForActiveCompany(): ?string
    {
        $today    = date('Y-m-d');
        $horizon  = date('Y-m-d', strtotime('+7 days'));
        $overdue  = [];
        $upcoming = [];

        foreach ((new CalendarService())->events() as $event) {
            if ($event['overdue']) {
                $overdue[] = $event;
            } elseif ($event['date'] >= $today && $event['date'] <= $horizon) {
                $upcoming[] = $event;
            }
        }

        if (!$overdue && !$upcoming) {
            return null;
        }

        $lines = [];

        if ($overdue) {
            $lines[] = 'SCADUTE:';
            foreach ($overdue as $event) {
                $days = (int) ((strtotime($today) - strtotime($event['date'])) / 86400);
                $lines[] = $this->digestLine($event, ' (ritardo ' . $days . ' gg)');
            }
            $lines[] = '';
        }

        if ($upcoming) {
            $lines[] = 'IN SCADENZA NEI PROSSIMI 7 GIORNI:';
            foreach ($upcoming as $event) {
                $lines[] = $this->digestLine($event, '');
            }
        }

        $lines[] = '';
        $lines[] = 'Cordiali saluti, ' . $this->companyName();

        $sent = $this->sendNotificationEmail(
            'Riepilogo scadenze settimanali — ' . $this->companyName(),
            implode("\n", $lines)
        );

        if (!$sent) {
            return 'riepilogo pronto ma non inviato: nessuna casella di invio, nessun destinatario o invio fallito';
        }

        return 'riepilogo inviato (' . count($overdue) . ' scadute, ' . count($upcoming) . ' in arrivo)';
    }

    /**
     * Backup del database PostgreSQL su disco (writable/backups) con rotazione.
     *
     * Richiede il binario pg_dump sul server. Il dump è in formato custom
     * compresso (-Fc) e contiene i dati fiscali completi: la directory
     * writable/backups non deve essere servita dal web server, e il backup
     * va portato fuori dal server (questo processo è una copia di lavoro,
     * non un archivio). Il restore si fa con pg_restore.
     */
    public function backup(string $cronKey)
    {
        if (!cron_key_valid($cronKey)) {
            http_response_code(403);

            return 'Cron key non valida';
        }

        $cfg   = config('Database');
        $group = $cfg->defaultGroup;
        $db    = $cfg->{$group} ?? [];

        // pg_dump rifiuta un server di versione maggiore della propria, e il
        // primo nel PATH non e' quello giusto quando sono installate piu'
        // versioni (Homebrew con postgresql@16 e @17 affiancati, distribuzioni
        // con il client vecchio). La versione del server serve a scegliere il
        // binario; su MySQL/MariaDB non si usa e vale 0.
        $serverMajor = 0;

        try {
            $serverVersion = (string) \Config\Database::connect()->getVersion();
            $serverMajor   = (int) $serverVersion;
        } catch (\Throwable $e) {
            log_message('error', 'Backup: versione del server non leggibile: ' . $e->getMessage());
        }

        try {
            $result = (new \App\Libraries\DatabaseBackup($db, null, $serverMajor))->dump();
        } catch (\Throwable $e) {
            log_message('error', 'Backup DB fallito: ' . $e->getMessage());

            return $this->backupFailed('Backup fallito: ' . $e->getMessage());
        }

        return 'Backup salvato: ' . basename($result['file'])
            . ($result['removed'] ? ' (eliminati ' . $result['removed'] . ' vecchi)' : '');
    }

    /**
     * Un backup mancato deve arrivare al chiamante come errore HTTP, non come
     * corpo 200 con la parola "fallito": tools/cron-runner.sh usa `curl -f`,
     * quindi solo un 4xx/5xx gli fa registrare FALLITO e riprovare al giro
     * successivo. Con il 200 il log diceva "ok: Backup fallito: ..." e non
     * riprovava fino al giorno dopo.
     */
    private function backupFailed(string $message): string
    {
        $this->response->setStatusCode(500);

        return $message;
    }

    /* ------------------------------------------------------ email di sistema */

    /**
     * Destinatario delle notifiche automatiche (digest, esiti SdI): la casella
     * di sistema (ip_email_senders) se configurata, con ripiego sul primo
     * amministratore. Il vecchio campo libero cron_notify_email è stato rimosso
     * dalle Impostazioni di sistema: la casella di sistema è ora l'unica fonte.
     */
    private function notificationEmail(): string
    {
        $sender = system_email_sender();

        if (!empty($sender['sender_email'])) {
            return $sender['sender_email'];
        }

        $admin = (new UserModel())->where('user_type', 1)->first();

        return trim((string) ($admin['user_email'] ?? ''));
    }

    /** Invia una notifica interna (non al cliente) con il SMTP di sistema. */
    private function sendNotificationEmail(string $subject, string $body): bool
    {
        if (!$this->hasSender()) {
            return false;
        }

        $to = $this->notificationEmail();

        if ($to === '') {
            log_message('error', 'Notifica non inviata: nessun destinatario configurato (casella di sistema o primo amministratore)');

            return false;
        }

        $email = \Config\Services::email(null, false);
        $this->configureEmail($email);
        $email->setFrom(system_mail_from(), $this->companyName());
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($body);

        return $email->send();
    }

    /**
     * L'azienda attiva ha una casella di invio? Senza, build_mail_config()
     * ripiega su mail() di sistema con il mittente vuoto: dal cron significa
     * email che escono dal sendmail del server, senza firma SPF/DKIM della
     * casella aziendale, o restano in coda all'MTA locale. Meglio non inviare
     * e dirlo nel log.
     */
    private function hasSender(): bool
    {
        $sender = system_email_sender();

        if (empty($sender['sender_email'])) {
            log_message('error', 'Email automatica non inviata: nessuna casella di invio configurata per l\'azienda '
                . $this->companyName() . ' (Impostazioni > Email e mittenti)');

            return false;
        }

        return true;
    }

    /** Configura il trasporto email con la casella di sistema (ip_email_senders). */
    private function configureEmail(Email $email): void
    {
        $email->initialize(build_mail_config(system_email_sender()));
    }

    /**
     * Ragione sociale per mittente, oggetto e firma delle email automatiche.
     * Prima si leggeva get_setting('company_name'), un'impostazione che non
     * esiste piu' (i dati aziendali stanno in ip_companies): le email
     * partivano firmate "InvoicePlane" con l'oggetto troncato ("Fattura 12 - ").
     * Senza id si usa l'azienda attiva, che i cron impostano per ogni giro.
     */
    private function companyName(?int $companyId = null): string
    {
        $companyId ??= active_company_id();
        $company   = $companyId ? (new CompanyModel())->find($companyId) : null;

        return trim((string) ($company['company_name'] ?? '')) ?: 'Invoice+';
    }

    /** Riga di una scadenza nel digest (titolo, data, importo, link). */
    private function digestLine(array $event, string $suffix): string
    {
        $amount = $event['amount'] !== null ? ' — ' . format_currency($event['amount']) : '';

        return ' - ' . $event['title'] . ' (' . date_from_mysql($event['date'], true) . ')'
            . $suffix . $amount . ' — ' . $event['url'];
    }

}
