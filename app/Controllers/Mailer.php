<?php

namespace App\Controllers;

use App\Models\ClientCustomModel;
use App\Models\EmailTemplateModel;
use App\Models\InvoiceCustomModel;
use App\Models\InvoiceModel;
use App\Models\QuoteCustomModel;
use App\Models\QuoteModel;
use App\Models\UploadModel;
use App\Models\UserCustomModel;
use App\Services\DocumentPdfBuilder;
use CodeIgniter\Email\Email;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Mailer controller
 *
 * Porting CI4 del controller Mailer (CI3): invio di fatture e preventivi
 * via email con allegato PDF. Usa \Config\Services::email (come
 * Sessions::sendEmail) al posto del vecchio helper phpmailer.
 */
class Mailer extends BaseController
{
    protected EmailTemplateModel $emailTemplateModel;

    public function __construct()
    {
        $this->emailTemplateModel = new EmailTemplateModel();
    }

    public function invoice(int $invoiceId)
    {
        if (!$this->mailerConfigured()) {
            return view('mailer/not_configured');
        }

        $invoice = (new InvoiceModel())->getByIdScoped($invoiceId);

        if (!$invoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        // I solleciti sono manuali: su una fattura scaduta la stessa schermata
        // di invio si apre gia' come sollecito (template e PDF "in ritardo"
        // delle Impostazioni, o un testo di sollecito predefinito), e chi la
        // invia vede e corregge il testo prima che parta. Nessun cron
        // sollecita da solo: la scelta di chi sollecitare, e quando, resta
        // di chi conosce il cliente (una scuola che paga a 90 giorni non va
        // trattata come un privato in ritardo).
        $isReminder = self::isOverdue($invoice);

        $emailTemplateId = (int) get_setting($isReminder ? 'email_invoice_template_overdue' : 'email_invoice_template', 0);
        $emailTemplate   = $emailTemplateId ? $this->emailTemplateModel->find($emailTemplateId) : null;

        if (!$emailTemplate && $isReminder) {
            $emailTemplate = self::defaultReminderTemplate();
        }

        $pdfTemplate = $isReminder ? (string) get_setting('pdf_invoice_template_overdue', '') : '';

        $data = [
            'invoice'                 => $invoice,
            'invoice_id'              => $invoiceId,
            'email_template'          => json_encode($emailTemplate ?: []),
            'selected_email_template' => $emailTemplateId,
            'email_templates'         => $this->emailTemplateModel->getAllOrdered(),
            'selected_pdf_template'   => $pdfTemplate !== '' ? $pdfTemplate : get_setting('pdf_invoice_template', 'default'),
            'pdf_templates'           => $this->pdfTemplatesList('invoice'),
            'senders'                 => (new \App\Models\EmailSenderModel())->getAllOrdered(),
            'is_reminder'             => $isReminder,
        ];

        return view('mailer/invoice', $data);
    }

    public function quote(int $quoteId)
    {
        if (!$this->mailerConfigured()) {
            return view('mailer/not_configured');
        }

        $quote = (new QuoteModel())->getByIdScoped($quoteId);

        if (!$quote) {
            throw PageNotFoundException::forPageNotFound();
        }

        $emailTemplateId = (int) get_setting('email_quote_template', 0);
        $emailTemplate   = $emailTemplateId ? $this->emailTemplateModel->find($emailTemplateId) : null;

        $data = [
            'quote'                   => $quote,
            'quote_id'                => $quoteId,
            'email_template'          => json_encode($emailTemplate ?: []),
            'selected_email_template' => $emailTemplateId,
            'email_templates'         => $this->emailTemplateModel->getAllOrdered(),
            'selected_pdf_template'   => get_setting('pdf_quote_template', 'InvoicePlane'),
            'pdf_templates'           => $this->pdfTemplatesList('quote'),
            'senders'                 => (new \App\Models\EmailSenderModel())->getAllOrdered(),
        ];

        return view('mailer/quote', $data);
    }

    public function sendInvoice(int $invoiceId)
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        if ($request->getPost('btn_cancel')) {
            return redirect()->to('/invoices/view/' . $invoiceId);
        }

        if (!$this->mailerConfigured()) {
            return view('mailer/not_configured');
        }

        $to = (string) $request->getPost('to_email');

        if (empty($to)) {
            session()->setFlashdata('alert_error', trans('email_to_address_missing'));

            return redirect()->to('/mailer/invoice/' . $invoiceId);
        }

        $invoiceModel = new InvoiceModel();
        $invoice      = $invoiceModel->getByIdScoped($invoiceId);

        if (!$invoice) {
            throw PageNotFoundException::forPageNotFound();
        }

        $data = $this->invoiceTemplateData($invoice);

        $fromName  = $this->parseTemplate((string) $request->getPost('from_name'), $data);
        $fromEmail = $this->parseTemplate((string) $request->getPost('from_email'), $data);
        $subject   = $this->parseTemplate((string) $request->getPost('subject'), $data);
        $body      = $this->parseTemplate($this->prepareBody((string) $request->getPost('body')), $data);
        $cc        = $this->parseTemplate((string) $request->getPost('cc'), $data);
        $bcc       = $this->parseTemplate((string) $request->getPost('bcc'), $data);

        $invoiceModel->generateInvoiceNumberIfApplicable($invoiceId);

        $sender = $this->selectedSender();

        $email = \Config\Services::email(null, false);
        $this->configureEmail($email, $sender);

        // Il mittente scelto ha la precedenza sui campi liberi del form: e'
        // la casella che ha autenticato l'invio, e un From diverso da quella
        // fa scartare il messaggio dai controlli antispam.
        if ($sender) {
            $fromEmail = $sender['sender_email'];
            $fromName  = $fromName !== '' ? $fromName : $sender['sender_name'];
        }

        if (empty($fromEmail)) {
            $fromEmail = system_mail_from();
            if (empty($fromEmail)) {
                $fromEmail = 'system@' . parse_url(base_url(), PHP_URL_HOST);
            }
        }

        $email->setFrom($fromEmail, $fromName);
        $email->setTo($to);

        if ($cc !== '') {
            $email->setCC($cc);
        }
        if ($bcc !== '') {
            $email->setBCC($bcc);
        }

        $email->setSubject($subject);
        $email->setMessage($body === '' ? ' ' : $body);

        // Allegato PDF della fattura (se richiesto dal setting o dal form)
        $attachPdf = (int) get_setting('email_pdf_attachment', 0) === 1
            || (bool) $request->getPost('invoice_pdf_attachment');

        if ($attachPdf) {
            $pdfTemplate = (string) $request->getPost('pdf_template');
            if ($pdfTemplate === '') {
                $pdfTemplate = (string) get_setting('pdf_invoice_template', 'default');
            }

            // Se l'allegato e' richiesto ma il PDF non si genera, l'email non
            // parte: un messaggio che annuncia la fattura senza averla e'
            // peggio di un errore visibile.
            if (!$this->attachInvoicePdf($email, $invoice, $pdfTemplate)) {
                session()->setFlashdata('alert_error', trans('email_pdf_attachment_failed', '', 'Impossibile generare il PDF da allegare: email non inviata'));

                return redirect()->to('/mailer/invoice/' . $invoiceId);
            }
        }

        // Allegati caricati tramite upload. I metodi getInvoiceUploads() e
        // getQuoteUploads() non esistevano nel modello (porting incompleto):
        // ogni invio di fattura o preventivo per email finiva in errore 500.
        // Anche la cartella era sbagliata: Upload salva in customer_files/.
        foreach ((new UploadModel())->byUrlKey((string) ($invoice['invoice_url_key'] ?? '')) as $upload) {
            $filePath = WRITEPATH . 'uploads/customer_files/' . basename((string) $upload['file_name_new']);

            if (is_file($filePath)) {
                $email->attach($filePath, 'attachment', $upload['file_name_original']);
            }
        }

        $sent = $email->send();


        if ($sent) {
            $invoiceModel->markSent($invoiceId);

            // Traccia del sollecito: la data compare sulla fattura e nello
            // scadenzario, cosi' si sa chi e' gia' stato sollecitato e quando.
            if ($request->getPost('is_reminder')) {
                $invoiceModel->update($invoiceId, ['invoice_last_reminder_sent' => date('Y-m-d')]);
                session()->setFlashdata('alert_success', trans('reminder_sent', '', 'Sollecito inviato'));

                return redirect()->to('/invoices/view/' . $invoiceId);
            }

            session()->setFlashdata('alert_success', trans('email_successfully_sent'));

            return redirect()->to('/invoices/view/' . $invoiceId);
        }

        session()->setFlashdata('alert_error', trans('email_send_failed', '', 'Invio email non riuscito'));

        return redirect()->to('/mailer/invoice/' . $invoiceId);
    }

    public function sendQuote(int $quoteId)
    {
        $request = service('request');

        if ($request->getMethod() !== 'POST') {
            throw PageNotFoundException::forPageNotFound();
        }

        if ($request->getPost('btn_cancel')) {
            return redirect()->to('/quotes/view/' . $quoteId);
        }

        if (!$this->mailerConfigured()) {
            return view('mailer/not_configured');
        }

        $to = (string) $request->getPost('to_email');

        if (empty($to)) {
            session()->setFlashdata('alert_error', trans('email_to_address_missing'));

            return redirect()->to('/mailer/quote/' . $quoteId);
        }

        $quoteModel = new QuoteModel();
        $quote      = $quoteModel->getByIdScoped($quoteId);

        if (!$quote) {
            throw PageNotFoundException::forPageNotFound();
        }

        $data = $this->quoteTemplateData($quote);

        $fromName  = $this->parseTemplate((string) $request->getPost('from_name'), $data);
        $fromEmail = $this->parseTemplate((string) $request->getPost('from_email'), $data);
        $subject   = $this->parseTemplate((string) $request->getPost('subject'), $data);
        $body      = $this->parseTemplate($this->prepareBody((string) $request->getPost('body')), $data);
        $cc        = $this->parseTemplate((string) $request->getPost('cc'), $data);
        $bcc       = $this->parseTemplate((string) $request->getPost('bcc'), $data);

        $quoteModel->generateQuoteNumberIfApplicable($quoteId);

        $sender = $this->selectedSender();

        $email = \Config\Services::email(null, false);
        $this->configureEmail($email, $sender);

        // Il mittente scelto ha la precedenza sui campi liberi del form: e'
        // la casella che ha autenticato l'invio, e un From diverso da quella
        // fa scartare il messaggio dai controlli antispam.
        if ($sender) {
            $fromEmail = $sender['sender_email'];
            $fromName  = $fromName !== '' ? $fromName : $sender['sender_name'];
        }

        if (empty($fromEmail)) {
            $fromEmail = system_mail_from();
            if (empty($fromEmail)) {
                $fromEmail = 'system@' . parse_url(base_url(), PHP_URL_HOST);
            }
        }

        $email->setFrom($fromEmail, $fromName);
        $email->setTo($to);

        if ($cc !== '') {
            $email->setCC($cc);
        }
        if ($bcc !== '') {
            $email->setBCC($bcc);
        }

        $email->setSubject($subject);
        $email->setMessage($body === '' ? ' ' : $body);

        // Allegato PDF del preventivo (se richiesto dal setting o dal form)
        $attachPdf = (int) get_setting('email_pdf_attachment', 0) === 1
            || (bool) $request->getPost('quote_pdf_attachment');

        if ($attachPdf) {
            $pdfTemplate = (string) $request->getPost('pdf_template');
            if ($pdfTemplate === '') {
                $pdfTemplate = (string) get_setting('pdf_quote_template', 'InvoicePlane');
            }

            // Stessa regola della fattura: senza PDF l'email non parte.
            if (!$this->attachQuotePdf($email, $quote, $pdfTemplate)) {
                session()->setFlashdata('alert_error', trans('email_pdf_attachment_failed', '', 'Impossibile generare il PDF da allegare: email non inviata'));

                return redirect()->to('/mailer/quote/' . $quoteId);
            }
        }

        // Allegati caricati tramite upload
        foreach ((new UploadModel())->byUrlKey((string) ($quote['quote_url_key'] ?? '')) as $upload) {
            $filePath = WRITEPATH . 'uploads/customer_files/' . basename((string) $upload['file_name_new']);

            if (is_file($filePath)) {
                $email->attach($filePath, 'attachment', $upload['file_name_original']);
            }
        }

        $sent = $email->send();


        if ($sent) {
            $quoteModel->markSent($quoteId);
            session()->setFlashdata('alert_success', trans('email_successfully_sent'));

            return redirect()->to('/quotes/view/' . $quoteId);
        }

        session()->setFlashdata('alert_error', trans('email_send_failed', '', 'Invio email non riuscito'));

        return redirect()->to('/mailer/quote/' . $quoteId);
    }

    /**
     * Sostituisce i tag {{tag}} (o {{{tag}}}) nel testo con i valori
     * passati (equivalente di parse_template() CI3).
     */
    public function parseTemplate(string $body, array $data): string
    {
        if (!preg_match_all('/\{\{\{?([a-z0-9_]+)\}?\}\}/i', $body, $templateVars)) {
            return $body;
        }

        foreach ($templateVars[1] as $var) {
            if (!array_key_exists($var, $data)) {
                // Tag sconosciuto: resta com'è (visibile) invece di essere
                // sostituito col suo nome, che distrugge silenziosamente il
                // contenuto del template.
                continue;
            }

            $replace = $data[$var];
            $body    = str_replace('{{{' . $var . '}}}', (string) $replace, $body);
            $body    = str_replace('{{' . $var . '}}', (string) $replace, $body);
        }

        return $body;
    }

    /**
     * Verifica che l'invio email sia configurato: esiste almeno un mittente in
     * ip_email_senders (l'SMTP vive solo lì, dopo la migrazione dai vecchi
     * settings generali). Senza mittenti non c'è un From valido da usare.
     */
    private function mailerConfigured(): bool
    {
        return (bool) (new \App\Models\EmailSenderModel())->countAllResults();
    }

    /**
     * Configura il servizio email con i settings SMTP
     * (stessa logica di Sessions::sendEmail).
     */
    /**
     * Configura il trasporto. Se il mittente scelto ha un SMTP proprio si usa
     * quello: spedire da un dominio attraverso il server di un altro fa
     * fallire l'autenticazione o finire il messaggio nello spam. Senza
     * mittente esplicito (invio fattura senza casella selezionata) si usa la
     * casella di sistema, così l'SMTP resta coerente con il From.
     */
    private function configureEmail(Email $email, ?array $sender = null): void
    {
        if (empty($sender)) {
            $sender = system_email_sender();
        }

        $email->initialize(build_mail_config($sender));
    }

    /**
     * Mittente scelto nel form di invio (campo sender_id), se esiste.
     */
    private function selectedSender(): ?array
    {
        $senderId = (int) service('request')->getPost('sender_id');

        // findScoped(): la tendina che riempie questo campo e' gia' ristretta
        // all'azienda attiva (getAllOrdered), ma il valore arriva dal POST e
        // find() non lo verifica. Un sender_id di un'altra azienda faceva
        // partire l'email dal suo indirizzo e attraverso il suo account SMTP,
        // credenziali comprese: non solo un documento fuori perimetro, ma
        // l'identita' di un altro soggetto giuridico usata per spedire.
        return $senderId ? (new \App\Models\EmailSenderModel())->findScoped($senderId) : null;
    }

    /**
     * Corpo email: decodifica HTML (stesso comportamento di CI3).
     */
    private function prepareBody(string $body): string
    {
        if (strlen($body) !== strlen(strip_tags($body))) {
            return htmlspecialchars_decode($body);
        }

        return htmlspecialchars_decode(nl2br($body));
    }

    /**
     * Fattura da sollecitare: emessa (non bozza, non pagata), scaduta e con
     * saldo aperto. Stessi criteri dello scadenzario.
     */
    public static function isOverdue(array $invoice): bool
    {
        return !in_array((int) ($invoice['invoice_status_id'] ?? 0), [1, 4], true)
            && !empty($invoice['invoice_date_due'])
            && $invoice['invoice_date_due'] < date('Y-m-d')
            && (float) ($invoice['invoice_balance'] ?? 0) > 0;
    }

    /**
     * Testo di sollecito usato quando in Impostazioni > Fatture non c'e' un
     * template email "in ritardo". Usa i tag {{...}}, sostituiti all'invio
     * come per ogni altro template: nel form si vede il testo con i tag, e
     * si puo' modificare prima di inviare.
     *
     * @return array<string, string>
     */
    public static function defaultReminderTemplate(): array
    {
        return [
            'email_template_subject' => 'Sollecito di pagamento - Fattura {{invoice_number}}',
            'email_template_body'    => "Gentile cliente,\n\n"
                . "con la presente ricordiamo che la fattura {{invoice_number}} del {{invoice_date_created}}, "
                . "di importo residuo {{invoice_balance}}, risulta scaduta dal {{invoice_date_due}}.\n\n"
                . "In allegato trova il documento. Se il pagamento e' gia' stato effettuato, "
                . "consideri nulla la presente.\n\n"
                . "Cordiali saluti",
        ];
    }

    /**
     * Dati per i tag {{...}} di una fattura (pubblica: la usa anche
     * tag_check.php per verificare i tag contro i dati reali).
     */
    public function invoiceTemplateData(array $invoice): array
    {
        $statuses = (new InvoiceModel())->statuses();

        $data = $this->clientUserTags($invoice) + [
            'invoice_number'         => (string) ($invoice['invoice_number'] ?? ''),
            'invoice_date_created'   => date_from_mysql($invoice['invoice_date_created'] ?? null, true),
            'invoice_date_due'       => date_from_mysql($invoice['invoice_date_due'] ?? null, true),
            'invoice_status'         => $statuses[(string) ($invoice['invoice_status_id'] ?? '')]['label'] ?? '',
            'invoice_item_subtotal'  => format_currency($invoice['invoice_item_subtotal'] ?? 0),
            'invoice_item_tax_total' => format_currency($invoice['invoice_item_tax_total'] ?? 0),
            'invoice_tax_total'      => format_currency($invoice['invoice_tax_total'] ?? 0),
            'invoice_total'          => format_currency($invoice['invoice_total'] ?? 0),
            'invoice_paid'           => format_currency($invoice['invoice_paid'] ?? 0),
            'invoice_balance'        => format_currency($invoice['invoice_balance'] ?? 0),
            'invoice_terms'          => (string) ($invoice['invoice_terms'] ?? ''),
            'invoice_guest_url'      => !empty($invoice['invoice_url_key'])
                ? site_url('guest/view/invoice/' . $invoice['invoice_url_key'])
                : '',
        ];

        foreach ($this->customTags(
            (new InvoiceCustomModel())->getByInvoice((int) ($invoice['invoice_id'] ?? 0)),
            'invoice_custom_fieldid',
            'invoice_custom_fieldvalue'
        ) as $tag => $value) {
            $data[$tag] = $value;
        }

        return $data;
    }

    /**
     * Dati per i tag {{...}} di un preventivo.
     */
    private function quoteTemplateData(array $quote): array
    {
        $data = $this->clientUserTags($quote) + [
            'quote_number'        => (string) ($quote['quote_number'] ?? ''),
            'quote_date_created'  => date_from_mysql($quote['quote_date_created'] ?? null, true),
            'quote_date_expires'  => date_from_mysql($quote['quote_date_expires'] ?? null, true),
            'quote_item_subtotal' => format_currency($quote['quote_item_subtotal'] ?? 0),
            'quote_item_tax_total' => format_currency($quote['quote_item_tax_total'] ?? 0),
            'quote_tax_total'     => format_currency($quote['quote_tax_total'] ?? 0),
            'quote_total'         => format_currency($quote['quote_total'] ?? 0),
            'quote_guest_url'     => !empty($quote['quote_url_key'])
                ? site_url('guest/view/quote/' . $quote['quote_url_key'])
                : '',
        ];

        foreach ($this->customTags(
            (new QuoteCustomModel())->getByQuote((int) ($quote['quote_id'] ?? 0)),
            'quote_custom_fieldid',
            'quote_custom_fieldvalue'
        ) as $tag => $value) {
            $data[$tag] = $value;
        }

        return $data;
    }

    /**
     * Tag comuni a fatture e preventivi: anagrafica cliente, utente e i
     * relativi campi personalizzati (ip_cf_<id>), come parse_template() CI3.
     */
    private function clientUserTags(array $doc): array
    {
        $data = [];

        foreach ([
            'client_name', 'client_surname', 'client_address_1', 'client_address_2',
            'client_city', 'client_state', 'client_zip', 'client_country',
            'client_phone', 'client_fax', 'client_mobile', 'client_email',
            'client_web', 'client_vat_id', 'client_tax_code',
        ] as $field) {
            $data[$field] = (string) ($doc[$field] ?? '');
        }

        $data['client_fullname'] = format_client($doc);

        foreach ([
            'user_name', 'user_company', 'user_address_1', 'user_address_2',
            'user_city', 'user_state', 'user_zip', 'user_country',
            'user_phone', 'user_fax', 'user_mobile', 'user_email',
            'user_web', 'user_iban',
        ] as $field) {
            $data[$field] = (string) ($doc[$field] ?? '');
        }

        foreach ($this->customTags(
            (new ClientCustomModel())->getByClient((int) ($doc['client_id'] ?? 0)),
            'client_custom_fieldid',
            'client_custom_fieldvalue'
        ) as $tag => $value) {
            $data[$tag] = $value;
        }

        foreach ($this->customTags(
            (new UserCustomModel())->getByUser((int) ($doc['user_id'] ?? 0)),
            'user_custom_fieldid',
            'user_custom_fieldvalue'
        ) as $tag => $value) {
            $data[$tag] = $value;
        }

        return $data;
    }

    /**
     * Righe custom di una tabella (getByInvoice/getByQuote/getByClient/...)
     * trasformate in tag ip_cf_<id> => valore.
     */
    private function customTags(array $rows, string $fieldIdKey, string $valueKey): array
    {
        $tags = [];

        foreach ($rows as $row) {
            if (isset($row[$fieldIdKey])) {
                $tags['ip_cf_' . $row[$fieldIdKey]] = (string) ($row[$valueKey] ?? '');
            }
        }

        return $tags;
    }

    /**
     * Genera il PDF della fattura e lo allega all'email.
     *
     * La composizione dell'HTML non e' piu' duplicata qui: la fa
     * DocumentPdfBuilder, la stessa usata da download, anteprima, portale
     * cliente e cron. Restituisce false se il PDF non e' stato prodotto.
     */
    private function attachInvoicePdf(Email $email, array $invoice, string $pdfTemplate): bool
    {
        $filename = (new DocumentPdfBuilder())->invoiceFilename($invoice);

        return $this->attachPdf($email, $filename, (new DocumentPdfBuilder())->invoice($invoice, $pdfTemplate));
    }

    /**
     * Genera il PDF del preventivo e lo allega all'email.
     *
     * Composizione condivisa con download, anteprima e portale cliente
     * (DocumentPdfBuilder). Restituisce false se il PDF non e' stato prodotto.
     */
    private function attachQuotePdf(Email $email, array $quote, string $pdfTemplate): bool
    {
        $filename = 'Preventivo_' . str_replace(['\\', '/'], '_', (string) ($quote['quote_number'] ?: $quote['quote_id'])) . '.pdf';

        return $this->attachPdf($email, $filename, (new DocumentPdfBuilder())->quote($quote, $pdfTemplate));
    }

    /**
     * Genera il PDF e lo allega in memoria.
     *
     * Email::attach() con il tipo MIME esplicito tratta il primo argomento
     * come contenuto gia' pronto: si passano i byte, cosi' non servono file
     * temporanei in writable/uploads (niente nomi in collisione tra aziende
     * con lo stesso numero documento, niente file orfani se l'invio si
     * interrompe). Senza il tipo la libreria leggerebbe il primo argomento
     * come percorso e allegherebbe quello.
     */
    private function attachPdf(Email $email, string $filename, string $html): bool
    {
        $bytes = pdf_create($html, $filename, false);

        if (!is_string($bytes) || $bytes === '') {
            log_message('error', "PDF non generato per l'allegato email: " . $filename);

            return false;
        }

        return $email->attach($bytes, 'attachment', $filename, 'application/pdf') !== false;
    }
    /**
     * Elenco dei template PDF disponibili per il select del form.
     */
    private function pdfTemplatesList(string $type = 'invoice'): array
    {
        $templates = [];

        if ($type === 'quote') {
            foreach (glob(APPPATH . 'Views/quote_templates/pdf/*.php') ?: [] as $file) {
                $name                    = basename($file, '.php');
                $templates[$name]        = $name;
            }
        } else {
            foreach (glob(APPPATH . 'Views/invoice_templates/*.pdf.php') ?: [] as $file) {
                $name             = basename($file, '.pdf.php');
                $templates[$name] = $name;
            }
        }

        return $templates ?: ['default' => 'default'];
    }
}