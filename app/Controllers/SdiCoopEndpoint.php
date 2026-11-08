<?php

namespace App\Controllers;

use App\Libraries\Sdi\SdiSoapMessage;
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
        if (!hash_equals(decrypt_secret((string) get_setting('sdi_coop_inbound_token', '')), $token) || $token === '') {
            return $this->response->setStatusCode(403)->setBody('Token non valido');
        }

        $request = SdiSoapMessage::parse(
            (string) $this->request->getBody(),
            $this->request->getHeaderLine('Content-Type')
        );

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

        $transmission = $model->where('filename', $nomeFile)
            ->orderBy('sdi_transmission_id', 'DESC')
            ->first();

        if (!$transmission) {
            log_message('error', 'SDICoop: notifica ' . $esito . ' (' . $nomeFile . ') senza trasmissione corrispondente');

            return;
        }

        $model->setEsito(
            (int) $transmission['sdi_transmission_id'],
            $esito,
            self::message($esito, $fields + ['identificativo' => $params['IdentificativoSdI'] ?? ''])
        );
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
