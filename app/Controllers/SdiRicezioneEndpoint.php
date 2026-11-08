<?php

namespace App\Controllers;

use App\Libraries\FatturaXml;
use App\Libraries\FatturaXmlStore;
use App\Libraries\PurchaseInvoiceImporter;
use App\Libraries\Sdi\SdiSoapMessage;
use App\Models\CompanyModel;

/**
 * Webservice RicezioneFatture: la controparte del canale SDICoop per le
 * fatture di ACQUISTO in arrivo dallo SdI (lato opposto a
 * SdiCoopEndpoint, che gestisce invece le notifiche di esito in USCITA).
 *
 * Il SdI chiama QUESTO endpoint verso di noi per consegnare le fatture di
 * acquisto, a fronte dell'accreditamento "ricezione" distinto da quello di
 * trasmissione (vedi commento in settings/partial_sdi.php). Come
 * SdiCoopEndpoint, e' esposto pubblicamente e protetto da:
 *  1. TLS mutuo a livello di webserver (certificato SOGEI) — fuori da questa
 *     app, vedi docs/sdicoop.md;
 *  2. token segreto nell'URL (sdi_ricezione_inbound_token, impostazioni),
 *     stesso pattern del feed calendario: nessuna sessione, nessun CSRF.
 *
 * Ordine delle operazioni, voluto: prima il file si scrive su disco in
 * incoming/, POI si tenta l'import come spesa. Una volta risposto ER01 lo
 * SdI considera la fattura consegnata e non la rimanda: se l'import fallisse
 * (azienda non trovata, errore di database) senza la copia su disco, la
 * fattura sarebbe persa per noi e recuperabile solo dal cassetto fiscale.
 */
class SdiRicezioneEndpoint extends BaseController
{
    public function endpoint(string $token)
    {
        if (!hash_equals(decrypt_secret((string) get_setting('sdi_ricezione_inbound_token', '')), $token) || $token === '') {
            return $this->response->setStatusCode(403)->setBody('Token non valido');
        }

        $request = SdiSoapMessage::parse(
            (string) $this->request->getBody(),
            $this->request->getHeaderLine('Content-Type')
        );

        $element = $request !== null ? strtolower($request['element']) : '';

        // RiceviFattura ha come input fileSdIConMetadati; la decorrenza
        // termini un fileSdI semplice. Qualunque altra cosa e' un errore.
        if ($element === 'filesdiconmetadati') {
            $this->receiveInvoice($request['params']);

            return $this->soap(200, SdiSoapMessage::riceviFatturaResponse());
        }

        if ($element === 'filesdi') {
            // One-way: la decorrenza termini dice solo che il committente
            // (noi) non ha risposto entro 15 giorni. Si conserva il file.
            $this->store($request['params'], 'DT');

            return $this->response->setStatusCode(202);
        }

        log_message('error', 'SDIRicezione: richiesta non riconosciuta' . ($request !== null ? ' (' . $request['element'] . ')' : ''));

        return $this->soap(500, SdiSoapMessage::fault('Richiesta SOAP non riconosciuta'));
    }

    /**
     * Salva la fattura (e i metadati) in incoming/, poi la importa come
     * spesa nell'azienda a cui e' intestata.
     *
     * @param array<string, string> $params
     */
    private function receiveInvoice(array $params): void
    {
        $path = $this->store($params, 'fattura');
        if ($path === null) {
            return;
        }

        if (($params['Metadati'] ?? '') !== '' && ($params['NomeFileMetadati'] ?? '') !== '') {
            $meta = dirname($path) . '/' . self::safeName($params['NomeFileMetadati']);
            @file_put_contents($meta, $params['Metadati']);
        }

        $name = self::safeName($params['NomeFile'] ?? basename($path));

        // L'endpoint non ha sessione: l'azienda si ricava dal committente
        // della fattura, cosi' in un'installazione multi-azienda il
        // documento finisce nei costi di chi lo ha ricevuto e non della
        // prima azienda trovata. Senza corrispondenza si lascia il file
        // in incoming/ e lo si importa a mano da Spese > Importa XML.
        $companyId = $this->companyFor($path, $name);
        if ($companyId === null) {
            log_message('error', 'SDIRicezione: ' . $name . ' intestata a un committente che non corrisponde a nessuna azienda, resta in ' . $path);

            return;
        }

        session()->set('active_company_id', $companyId);

        try {
            // importFromPath, non importDocument: gestisce anche il .p7m
            // firmato e il file lotto con piu' fatture sotto una testata.
            $result = (new PurchaseInvoiceImporter())->importFromPath($path, $name);
        } catch (\Throwable $e) {
            log_message('error', 'SDIRicezione: import di ' . $name . ' fallito: ' . $e->getMessage());

            return;
        }

        $problems = array_merge($result['failed'], $result['rejected']);
        log_message(
            $problems === [] ? 'info' : 'error',
            'SDIRicezione: ' . $name . ' importata=' . $result['imported'] . ' gia_presente=' . $result['skipped']
                . ($problems !== [] ? ' problemi: ' . implode('; ', $problems) : '')
        );

        if ($problems === []) {
            invalidate_finance_cache();
        }
    }

    /** Id dell'azienda la cui P.IVA o codice fiscale e' quello del committente. */
    private function companyFor(string $path, string $name): ?int
    {
        $reader    = new FatturaXml();
        $documents = $reader->extract($path, $name);
        $parsed    = $documents !== [] ? $reader->parse($documents[0]['xml']) : null;

        if ($parsed === null) {
            return null;
        }

        $customer = array_filter([
            PurchaseInvoiceImporter::normalizeId((string) $parsed['customer_vat']),
            PurchaseInvoiceImporter::normalizeId((string) $parsed['customer_tax_code']),
        ]);

        foreach ((new CompanyModel())->findAll() as $company) {
            $own = array_filter([
                PurchaseInvoiceImporter::normalizeId((string) ($company['company_vat_id'] ?? '')),
                PurchaseInvoiceImporter::normalizeId((string) ($company['company_tax_code'] ?? '')),
            ]);

            if (array_intersect($customer, $own) !== []) {
                return (int) $company['company_id'];
            }
        }

        return null;
    }

    /**
     * Scrive il File ricevuto in incoming/ con il suo nome originale
     * (IT..._xxxxx.xml o .xml.p7m: l'estensione dice al lettore se va
     * sbustato).
     *
     * @param array<string, string> $params
     */
    private function store(array $params, string $tipo): ?string
    {
        $content = $params['File'] ?? '';
        if ($content === '') {
            log_message('error', 'SDIRicezione: ' . $tipo . ' senza File');

            return null;
        }

        $dir = (new FatturaXmlStore())->dir() . 'incoming';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            log_message('error', 'SDIRicezione: cartella ' . $dir . ' non scrivibile');

            return null;
        }

        $name = self::safeName($params['NomeFile'] ?? '') ?: date('Ymd_His') . '_' . $tipo . '.xml';
        $path = $dir . '/' . $name;

        if (@file_put_contents($path, $content) === false) {
            log_message('error', 'SDIRicezione: impossibile scrivere ' . $path);

            return null;
        }

        log_message('info', 'SDIRicezione: ' . $tipo . ' ricevuto'
            . (($params['IdentificativoSdI'] ?? '') !== '' ? ' (IdentificativoSdI ' . $params['IdentificativoSdI'] . ')' : '')
            . ' -> ' . $path);

        return $path;
    }

    private static function safeName(string $name): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._-]/', '_', basename($name));
    }

    private function soap(int $status, string $xml)
    {
        return $this->response->setStatusCode($status)
            ->setContentType('text/xml', 'utf-8')
            ->setBody($xml);
    }
}
