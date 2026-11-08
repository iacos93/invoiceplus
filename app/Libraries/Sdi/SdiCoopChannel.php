<?php

namespace App\Libraries\Sdi;

/**
 * Canale SDICoop: invio diretto al webservice SdIRiceviFile del Sistema
 * di Interscambio (canale di cooperazione applicativa, accreditamento
 * SOGEI), come da "Istruzioni per il servizio SDICoop - Trasmissione".
 *
 * Prerequisiti (fuori dal codice, a carico di chi gestisce il server):
 *  1. Accordo di servizio sottoscritto e superamento dei test di
 *     interoperabilita' con SOGEI: solo allora viene rilasciato il
 *     certificato client per l'autenticazione TLS mutua.
 *  2. Certificato + chiave privata del trasmittente (PEM), configurati
 *     in Impostazioni > SdI, usati per l'handshake TLS con il SdI.
 *  3. Firma digitale CAdES-BES del file XML con certificato di firma
 *     qualificato *prima* della chiamata: SDICoop non firma per conto
 *     del trasmittente non accreditato come fa la PEC. Questa classe
 *     manda il file cosi' com'e': il firmatario (smime -sign / servizio
 *     di firma remota) va integrato a monte, in FatturaPA o in un hook
 *     prima di SdiSender::send().
 *  4. Il WSDL ufficiale (SdIRiceviFile_v1.0.wsdl) non serve a runtime: la
 *     richiesta MTOM e' costruita da SdiSoapMessage::riceviFileRequest()
 *     sul suo schema. Va comunque confrontato con quello rilasciato in
 *     fase di accreditamento, perche' namespace ed elementi vengono da li'.
 *     Se la catena TLS del SdI non e' tra le CA di sistema, il suo
 *     certificato va indicato in sdi_coop_ca_path.
 *  5. L'endpoint di collaudo e produzione e' comunicato da SOGEI in fase
 *     di accreditamento (non e' un URL pubblico standard): va inserito
 *     in sdi_coop_endpoint.
 *
 * Le notifiche di esito (RC/NS/NE/EC/MC/DT) NON arrivano come risposta
 * di questa chiamata: il SdI le manda in un secondo momento richiamando
 * il webservice TrasmissioneFatture che DEVE essere esposto da questa
 * stessa applicazione — vedi SdiCoopEndpoint (Controller) per quella
 * parte, simmetrica e altrettanto necessaria all'accreditamento.
 */
class SdiCoopChannel implements SdiChannelInterface
{
    public function send(string $xml, string $filename, array $invoice): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'Estensione PHP curl non disponibile: richiesta per il canale SDICoop'];
        }

        $endpoint = (string) get_setting('sdi_coop_endpoint', '');
        $cert     = (string) get_setting('sdi_coop_cert_path', '');
        $key      = (string) get_setting('sdi_coop_key_path', '');

        if ($endpoint === '') {
            return ['ok' => false, 'message' => 'Endpoint SdIRiceviFile non configurato (Impostazioni > SdI > SDICoop)'];
        }

        if ($cert === '' || !is_file($cert) || $key === '' || !is_file($key)) {
            return ['ok' => false, 'message' => 'Certificato/chiave TLS del trasmittente non configurati (Impostazioni > SdI > SDICoop)'];
        }

        // MTOM costruito a mano invece di \SoapClient: quest'ultimo manda il
        // File in base64 inline, mentre le istruzioni SDICoop lo vogliono
        // come allegato xop. Il WSDL quindi non serve piu' a runtime
        // (sdi_coop_wsdl resta solo come riferimento documentale).
        $request = SdiSoapMessage::riceviFileRequest($filename, $xml);

        $options = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $request['body'],
            CURLOPT_HTTPHEADER     => [
                'Content-Type: ' . $request['content_type'],
                'SOAPAction: "http://www.fatturapa.it/SdIRiceviFile/RiceviFile"',
                'MIME-Version: 1.0',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSLCERT        => $cert,
            CURLOPT_SSLKEY         => $key,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        $passphrase = decrypt_setting_password((string) get_setting('sdi_coop_key_passphrase', ''));
        if ($passphrase !== '') {
            $options[CURLOPT_KEYPASSWD] = $passphrase;
        }

        // CA del server SdI, se non e' tra quelle di sistema (in collaudo
        // SOGEI usa una propria catena).
        $ca = (string) get_setting('sdi_coop_ca_path', '');
        if ($ca !== '' && is_file($ca)) {
            $options[CURLOPT_CAINFO] = $ca;
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, $options);
        $raw        = curl_exec($ch);
        $error      = curl_error($ch);
        $status     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        // curl_close() non serve più: la risorsa si libera da sola da PHP 8.0
        // e la funzione è deprecata in 8.5 (emetteva un warning per invio).

        if ($raw === false) {
            log_message('error', 'Invio SDICoop fallito: ' . $error);

            return ['ok' => false, 'message' => 'Errore di trasmissione SDICoop: ' . $error];
        }

        $headers     = substr($raw, 0, $headerSize);
        $contentType = preg_match('/^Content-Type:\s*(.+)$/mi', $headers, $m) ? trim($m[1]) : '';
        $result      = SdiSoapMessage::riceviFileResult(substr($raw, $headerSize), $contentType);

        if (!$result['ok']) {
            // Il rifiuto in accoglienza non arriva come SoapFault ma dentro
            // rispostaSdIRiceviFile: Errore EI01 file vuoto, EI02 servizio
            // non disponibile, EI03 utente non abilitato. Va riportato come
            // fallimento, altrimenti SdiSender brucerebbe il progressivo.
            $motivi = [
                'EI01' => 'file vuoto',
                'EI02' => 'servizio SdI non disponibile, riprovare',
                'EI03' => 'utente non abilitato alla trasmissione (accreditamento)',
            ];
            log_message('error', 'Invio SDICoop rifiutato (HTTP ' . $status . '): ' . $result['errore']);

            return ['ok' => false, 'message' => 'Rifiutata da SDICoop: ' . $result['errore']
                . (isset($motivi[$result['errore']]) ? ' (' . $motivi[$result['errore']] . ')' : '')];
        }

        return [
            'ok'      => true,
            'message' => 'Fattura consegnata a SDICoop (IdentificativoSdI: ' . $result['identificativo'] . ')',
        ];
    }
}
