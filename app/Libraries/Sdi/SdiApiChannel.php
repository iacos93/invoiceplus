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
        $endpoint = $this->endpoint();

        if ($endpoint === '') {
            return ['ok' => false, 'message' => 'Endpoint API del trasmittente non configurato (Impostazioni > SdI)'];
        }

        // Solo HTTPS: l'endpoint porta credenziali Basic nel header e non
        // deve mai essere raggiunto in chiaro. Qualunque altro schema
        // (http, ftp, file) viene rifiutato prima di aprire la connessione.
        if (($problem = self::endpointProblem($endpoint)) !== null) {
            log_message('error', 'SdiApiChannel: ' . $problem);

            return ['ok' => false, 'message' => $problem];
        }

        try {
            $client = \Config\Services::curlrequest([
                'timeout'         => 60,
                // Nessun redirect: un 301/302 verso un altro host (o verso
                // http) porterebbe le credenziali Basic fuori dal dominio
                // configurato. Una risposta 3xx viene trattata come rifiuto.
                'allow_redirects' => false,
                'verify'          => true,
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
            // Il messaggio cURL puo' citare host o URL: prima di scriverlo
            // nel log si tolgono endpoint e credenziali.
            $dettaglio = SdiPayloadGuard::redact($e->getMessage());
            log_message('error', 'Invio SdI via API fallito: ' . $dettaglio);

            return [
                'ok'      => false,
                'message' => 'Errore di trasmissione verso il trasmittente: verificare endpoint e connettivita\' (dettaglio nel log)',
            ];
        }
    }

    /**
     * Endpoint configurato (Impostazioni > SdI). Protetto per i test: la
     * validazione dell'URL si prova senza database ne' rete.
     */
    protected function endpoint(): string
    {
        return (string) get_setting('sdi_api_endpoint', '');
    }

    /**
     * Problema dell'endpoint, o null se e' un URL https utilizzabile.
     *
     * Il messaggio non ripete l'URL: finisce nel log e nella risposta
     * all'utente, che non devono contenere la configurazione.
     */
    public static function endpointProblem(string $endpoint): ?string
    {
        $parts = parse_url($endpoint);

        if ($parts === false || !isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            return 'Endpoint API del trasmittente non valido: serve un URL https (Impostazioni > SdI)';
        }

        $scheme = strtolower((string) $parts['scheme']);

        if ($scheme !== 'https') {
            return 'Endpoint API del trasmittente non sicuro: serve https, non ' . $scheme;
        }

        return null;
    }

    private function parseResponse(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            $body = SdiPayloadGuard::redact(trim((string) $response->getBody()));

            return [
                'ok'      => true,
                'message' => 'Fattura consegnata al trasmittente (HTTP ' . $status . ')'
                    . ($body !== '' ? ': ' . mb_substr($body, 0, 180) : ''),
            ];
        }

        $body = SdiPayloadGuard::redact(trim((string) $response->getBody()));

        return [
            'ok'      => false,
            'message' => 'Rifiutata dal trasmittente (HTTP ' . $status . ')'
                . ($body !== '' ? ': ' . mb_substr($body, 0, 180) : ''),
        ];
    }
}