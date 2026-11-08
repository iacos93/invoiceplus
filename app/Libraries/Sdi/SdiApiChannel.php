<?php

namespace App\Libraries\Sdi;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Canale webservice: invio del file FatturaPA via HTTPS al servizio del
 * trasmittente accreditato (SDICoop, TeamSystem, Aruba, InfoCert...).
 *
 * Il provider gestisce tutto cio' che la PEC lascia all'utente: firma
 * digitale del file, esiti in automatico e (se previsto dal contratto)
 * la conservazione a norma. L'app si limita a consegnare il file:
 *
 *   POST {sdi_api_endpoint}
 *   Authorization: Basic base64(user:pass)
 *   Content-Type: application/xml
 *   X-Filename: IT{partitaiva}_{progressivo}.xml
 *   <corpo: XML FatturaPA>
 *
 * Ogni provider ha specifiche proprie di endpoint, autenticazione e
 * formato della risposta: la configurazione e' generica, e l'esito
 * immediato qui registrato e' "pending" finche' non arriva la risposta
 * definitiva (che il provider consegna via notifica o richiamando
 * Cron::sdiPoll sull'apposita risorsa).
 */
class SdiApiChannel implements SdiChannelInterface
{
    public function send(string $xml, string $filename, array $invoice): array
    {
        $endpoint = (string) get_setting('sdi_api_endpoint', '');

        if ($endpoint === '') {
            return ['ok' => false, 'message' => 'Endpoint API del trasmittente non configurato (Impostazioni > SdI)'];
        }

        try {
            $client = \Config\Services::curlrequest([
                'timeout' => 60,
            ]);

            $headers = [
                'Content-Type'   => 'application/xml',
                'X-Filename'     => $filename,
                'X-Document-Id'  => (string) ($invoice['invoice_number'] ?? ''),
                'Accept'         => 'application/json',
            ];

            $username = (string) get_setting('sdi_api_username', '');
            $password = decrypt_setting_password((string) get_setting('sdi_api_password', ''));

            if ($username !== '') {
                $headers['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);
            }

            $response = $client->request('POST', $endpoint, [
                'headers' => $headers,
                'body'    => $xml,
            ]);

            return $this->parseResponse($response);
        } catch (\Throwable $e) {
            log_message('error', 'Invio SdI via API fallito: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Errore di trasmissione: ' . $e->getMessage()];
        }
    }

    private function parseResponse(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            $body = trim((string) $response->getBody());

            return [
                'ok'      => true,
                'message' => 'Fattura consegnata al trasmittente (HTTP ' . $status . ')'
                    . ($body !== '' ? ': ' . mb_substr($body, 0, 180) : ''),
            ];
        }

        $body = trim((string) $response->getBody());

        return [
            'ok'      => false,
            'message' => 'Rifiutata dal trasmittente (HTTP ' . $status . ')'
                . ($body !== '' ? ': ' . mb_substr($body, 0, 180) : ''),
        ];
    }
}