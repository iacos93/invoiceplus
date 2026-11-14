<?php

namespace App\Controllers;

use App\Libraries\PublicEndpointThrottle;
use App\Libraries\PurchaseInvoiceImporter;
use App\Libraries\Sdi\SdiPayloadGuard;
use App\Libraries\Sdi\SdiSoapMessage;
use App\Libraries\SdiAlert;
use App\Models\CompanyModel;
use App\Models\SdiTransmissionModel;

/**
 * Webservice TrasmissioneFatture: la META' del canale SDICoop che il SdI
 * chiama VERSO di noi per consegnare le notifiche di esito, a fronte di
 * ogni file inviato con SdiCoopChannel::send() (SdIRiceviFile).
 *
 * Senza questo endpoint pubblico, raggiungibile 24/7 e superata la
 * qualificazione SOGEI, l'accreditamento SDICoop non e' completo: il
 * webservice TrasmissioneFatture deve essere esposto dal trasmittente
 * secondo le "Istruzioni per il servizio SDICoop - Trasmissione". Le
 * operazioni (RicevutaConsegna, NotificaScarto, NotificaMancataConsegna,
 * NotificaEsito, NotificaDecorrenzaTermini, AttestazioneTrasmissioneFattura)
 * sono tutte one-way: lo SdI non attende un corpo di risposta, solo un
 * HTTP 2xx, e in mancanza ritenta.
 *
 * Sicurezza: il canale e' protetto da due livelli, entrambi necessari:
 *  1. TLS mutuo a livello di webserver (il certificato SOGEI e' l'unico
 *     accettato) — va configurato nel vhost, fuori da questa app
 *     (vedi docs/sdicoop.md);
 *  2. un token segreto nell'URL (sdi_coop_inbound_token, impostazioni),
 *     stesso pattern del feed calendario — perche' l'endpoint non ha
 *     sessione ne' CSRF e altrimenti sarebbe raggiungibile da chiunque
 *     conosca la rotta.
 *
 * La busta si legge con SdiSoapMessage e non con \SoapServer: il File
 * arriva in MTOM, che \SoapServer non ricompone (vedi la classe).
 */
class SdiCoopEndpoint extends BaseController
{
    public function endpoint(string $token)
    {
        // Tetto per IP PRIMA del token: anche i tentativi con token errato
        // consumano il contatore, altrimenti le chiavi si potrebbero provare
        // all'infinito. La risposta e' un SOAP Fault 429 come gli altri
        // rifiuti, con un messaggio generico: qui non si dice nulla sul token.
        $wait = PublicEndpointThrottle::guard(
            PublicEndpointThrottle::SCOPE_SDI,
            (string) $this->request->getIPAddress()
        );

        if ($wait !== null) {
            log_message('warning', 'SDICoop: richiesta bloccata dal tetto per IP (Retry-After ' . $wait . 's)');

            return $this->soap(429, SdiSoapMessage::fault('Troppe richieste: riprova più tardi'))
                ->setHeader('Retry-After', (string) $wait);
        }

        if (!hash_equals(decrypt_secret((string) get_setting('sdi_coop_inbound_token', '')), $token) || $token === '') {
            return $this->response->setStatusCode(403)->setBody('Token non valido');
        }

        // Limite sul body: se Content-Length dice gia' che e' troppo grande,
        // non si legge il corpo; il secondo controllo copre header assenti o
        // sbagliati. Rifiuto SOAP esplicito (413), non un 500.
        $declared = $this->request->getHeaderLine('Content-Length');

        if (($problem = SdiPayloadGuard::bodyProblem($declared)) !== null) {
            log_message('error', 'SDICoop: ' . $problem);

            return $this->soap(413, SdiSoapMessage::fault($problem));
        }

        $body = (string) $this->request->getBody();

        if (($problem = SdiPayloadGuard::bodyProblem($declared, strlen($body))) !== null) {
            log_message('error', 'SDICoop: ' . $problem);

            return $this->soap(413, SdiSoapMessage::fault($problem));
        }

        $request = SdiSoapMessage::parse(
            $body,
            $this->request->getHeaderLine('Content-Type')
        );

        // Anche il file di notifica e' un XML che arriva da fuori: stesso
        // tetto del singolo XML, prima di passarlo al parser.
        if (($problem = SdiPayloadGuard::xmlProblem(strlen($request['params']['File'] ?? ''))) !== null) {
            log_message('error', 'SDICoop: notifica rifiutata: ' . $problem);

            return $this->soap(413, SdiSoapMessage::fault($problem));
        }

        $esito = $request !== null ? (SdiSoapMessage::NOTIFICHE[strtolower($request['element'])] ?? null) : null;

        if ($esito === null) {
            log_message('error', 'SDICoop: richiesta non riconosciuta' . ($request !== null ? ' (' . $request['element'] . ')' : ''));

            return $this->soap(500, SdiSoapMessage::fault('Richiesta SOAP non riconosciuta'));
        }

        $this->importNotification($esito, $request['params']);

        // 202 senza corpo: e' la risposta WS-I a un'operazione one-way.
        // Anche una notifica che non aggancia nessuna trasmissione si
        // conferma: rifiutarla farebbe solo ritentare lo SdI all'infinito
        // per un problema che e' nostro, e resta comunque nel log.
        return $this->response->setStatusCode(202);
    }

    /**
     * Aggancia la trasmissione dal NomeFile della fattura ORIGINALE,
     * riportato dentro il file di notifica — stessa chiave usata da
     * FatturaPA::filename() e SdiSender. Il NomeFile del parametro SOAP
     * e' il nome della notifica e serve solo come ripiego.
     *
     * @param array<string, string> $params
     */
    private function importNotification(string $esito, array $params): void
    {
        $fields   = SdiSoapMessage::notificationFields($params['File'] ?? '');
        $nomeFile = $fields['nome_file'] ?: (string) ($params['NomeFile'] ?? '');

        if ($nomeFile === '') {
            log_message('error', 'SDICoop: notifica ' . $esito . ' senza NomeFile riconoscibile');

            return;
        }

        $model = new SdiTransmissionModel();

        // Il nome file e' IT<partitaIVA>_<progressivo>.xml: la P.IVA dice
        // quale azienda ha inviato. In un'installazione multi-azienda lo
        // stesso progressivo puo' esistere per due aziende, e senza questo
        // filtro la notifica poteva aggiornare la fattura sbagliata.
        $companyId    = $this->companyIdForFilename($nomeFile);
        $transmission = $model->findByFilenameForCompany($nomeFile, $companyId);

        if (!$transmission) {
            // Nessuna candidata o piu' di una: non si tocca nulla. Scrivere
            // l'esito sulla riga sbagliata e' peggio che non applicarlo,
            // perche' blocca/sblocca una fattura che lo SdI non ha guardato.
            $trovate = count($model->candidatesForFilename($nomeFile, $companyId));

            log_message('error', 'SDICoop: notifica ' . $esito . ' (' . $nomeFile . ') ignorata: '
                . ($trovate === 0
                    ? 'nessuna trasmissione corrispondente'
                    : $trovate . ' trasmissioni corrispondenti, impossibile scegliere')
                . ($companyId !== null ? ' per l\'azienda #' . $companyId : ' (azienda non riconosciuta dal nome file)'));

            return;
        }

        $messaggio = self::message($esito, $fields + ['identificativo' => $params['IdentificativoSdI'] ?? '']);
        $precedente = (string) ($transmission['esito'] ?? '');

        // Notifica ripetuta: lo SdI puo' rimandare la stessa notifica (o il
        // webserver/proxy puo' ritentare una chiamata gia' andata a buon
        // fine). Se esito e messaggio sono gia' quelli registrati non c'e'
        // nulla da applicare: si evita di riscrivere la riga, di rifare il
        // blocco/sblocco della fattura e di rimandare un avviso.
        if ($precedente === $esito && (string) ($transmission['messaggio'] ?? '') === $messaggio) {
            log_message('info', 'SDICoop: notifica ' . $esito . ' (' . $nomeFile . ') gia\' registrata, ignorata');

            return;
        }

        $model->setEsito((int) $transmission['sdi_transmission_id'], $esito, $messaggio);

        // Scarto, mancata consegna, impossibilita' di recapito: il titolare
        // lo deve sapere senza aprire l'app. Qualunque errore resta nel log:
        // allo SdI si risponde comunque come se niente fosse, perche' una
        // mail non partita non deve diventare una notifica che lo SdI crede
        // non consegnata.
        if (SdiAlert::shouldNotify($precedente, $esito)) {
            try {
                if (! (new SdiAlert())->notify($transmission, $esito, $messaggio)) {
                    log_message('error', 'SDICoop: avviso del titolare per ' . $esito . ' (' . $nomeFile . ') non inviato');
                }
            } catch (\Throwable $e) {
                log_message('error', 'SDICoop: avviso del titolare per ' . $esito . ' (' . $nomeFile . ') fallito: ' . $e->getMessage());
            }
        }
    }

    /**
     * Azienda a cui appartiene la trasmissione, ricavata dalla P.IVA nel
     * nome file (`IT<partitaIVA>_<progressivo>.xml`).
     *
     * Si confronta con company_vat_id e, come ripiego, con un codice fiscale
     * numerico (ditte individuali in cui IdTrasmittente e' il CF). La P.IVA
     * ha la precedenza: e' il dato che FatturaPA mette nell'IdTrasmittente.
     * Nessuna corrispondenza -> null, e la notifica si applica solo se il
     * nome file individua una trasmissione sola.
     */
    private function companyIdForFilename(string $filename): ?int
    {
        $vat = SdiTransmissionModel::vatFromFilename($filename);

        if ($vat === null) {
            return null;
        }

        $perCodiceFiscale = null;

        foreach ((new CompanyModel())->findAll() as $company) {
            $companyId = (int) $company['company_id'];

            if (PurchaseInvoiceImporter::normalizeId((string) ($company['company_vat_id'] ?? '')) === $vat) {
                return $companyId;
            }

            if ($perCodiceFiscale === null
                && PurchaseInvoiceImporter::normalizeId((string) ($company['company_tax_code'] ?? '')) === $vat) {
                $perCodiceFiscale = $companyId;
            }
        }

        return $perCodiceFiscale;
    }

    /**
     * Messaggio leggibile per la lista fatture: per uno scarto servono i
     * codici d'errore (sono l'unico modo di sapere cosa correggere), per
     * l'esito committente se la fattura e' stata accettata o rifiutata.
     *
     * @param array{identificativo: string, esito: string, errori: list<string>} $fields
     */
    public static function message(string $esito, array $fields): string
    {
        $text = 'Notifica ' . $esito . ' ricevuta via SDICoop';

        if ($fields['identificativo'] !== '') {
            $text .= ' (IdentificativoSdI ' . $fields['identificativo'] . ')';
        }

        if ($esito === 'NE' && $fields['esito'] !== '') {
            $text .= ': ' . match ($fields['esito']) {
                'EC01'  => 'fattura accettata dal committente',
                'EC02'  => 'fattura RIFIUTATA dal committente',
                default => $fields['esito'],
            };
        }

        if ($fields['errori'] !== []) {
            $text .= ': ' . implode('; ', $fields['errori']);
        }

        // ip_sdi_transmissions.messaggio e' VARCHAR(255).
        return mb_substr($text, 0, 255);
    }

    private function soap(int $status, string $xml)
    {
        return $this->response->setStatusCode($status)
            ->setContentType('text/xml', 'utf-8')
            ->setBody($xml);
    }
}
