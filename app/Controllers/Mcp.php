<?php

namespace App\Controllers;

use App\Libraries\Mcp\McpTools;

/**
 * Mcp controller
 *
 * Endpoint MCP (Model Context Protocol) dell'applicazione: permette a un
 * agente AI (es. opencode) di leggere e modificare i dati attraverso gli
 * stessi modelli dell'applicazione — mai query scritte a mano — così
 * scoping per azienda, normalizzazione importi e ricalcolo totali restano
 * quelli di sempre.
 *
 * Trasporto: Streamable HTTP (single POST /mcp). Ogni richiesta è un
 * messaggio JSON-RPC 2.0; la risposta è un singolo JSON. Non si usa il
 * flusso SSE perché nessuno dei metodi esposti richiede risposte multiple
 * o notifiche server→client: il server è stateless, le sessioni MCP non
 * vengono conservate (l'header Mcp-Session-Id viene accettato ed
 * echeggiato per compatibilità con i client che lo pretendono).
 *
 * Protezione: bearer token (Authorization: Bearer <chiave>) confrontato
 * con l'impostazione mcp_key, stessa filosofia della cron key. L'azienda su
 * cui opera l'integrazione è fissata dall'impostazione mcp_company_id:
 * viene iniettata in sessione prima di ogni elaborazione, così i helper
 * attivi (active_company_id, scopeToCompany, withCompany) si comportano
 * come se l'utente avesse selezionato quell'azienda in topbar.
 */
class Mcp extends BaseController
{
    /** Versioni del protocollo MCP che il server sa parlare. */
    private const SUPPORTED_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private const VERSION = '1.0.0';

    public function handle()
    {
        // CSRF è disabilitato sulla route (Filters.php) ma l'autenticazione
        // resta obbligatoria: senza chiave configurata o corretta, niente.
        if (!$this->authenticated()) {
            return $this->respondJson([
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => [
                    'code'    => -32001,
                    'message' => 'Non autorizzato: Authorization: Bearer <chiave MCP> richiesto',
                ],
            ], 401);
        }

        $this->bindCompany();

        $raw = (string) $this->request->getBody();

        if (trim($raw) === '') {
            return $this->respondJson([
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => ['code' => -32700, 'message' => 'Parse error: body JSON mancante'],
            ], 400);
        }

        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            return $this->respondJson([
                'jsonrpc' => '2.0',
                'id'      => null,
                'error'   => ['code' => -32700, 'message' => 'Parse error: JSON non valido'],
            ], 400);
        }

        // Un batch arriva come lista di messaggi; un messaggio singolo come
        // oggetto. La risposta rispecchia la forma della richiesta.
        $isBatch  = array_is_list($payload);
        $messages = $isBatch ? $payload : [$payload];
        $responses = [];

        foreach ($messages as $message) {
            $response = $this->dispatch($message);

            if ($response !== null) {
                $responses[] = $response;
            }
        }

        // Notifiche (nessun id): il protocollo vuole 202 senza corpo.
        if ($responses === []) {
            return $this->response->setStatusCode(202);
        }

        return $this->respondJson($isBatch ? $responses : $responses[0]);
    }

    /**
     * Convalida il bearer token contro l'impostazione mcp_key.
     * hash_equals: confronto a tempo costante, niente timing attack.
     *
     * mcp_key è salvata cifrata (encrypt_secret/Cryptor); decrypt_secret()
     * gestisce anche i valori legacy scritti in chiaro prima dell'introduzione
     * della cifratura, senza bisogno di una migrazione manuale sul DB.
     */
    private function authenticated(): bool
    {
        // Chiave bearer: priorità a .env (mcp.key) per chi vuole gestire il
        // segreto fuori dal DB (12-factor, reverse-decrypt senza query).
        // Se mcp.key è vuoto in .env si ripiega sul valore cifrato in ip_settings.
        $envKey   = trim((string) env('mcp.key', ''));
        $expected = $envKey !== '' ? $envKey : decrypt_secret((string) get_setting('mcp_key', ''));

        if ($expected === '') {
            return false;
        }

        $header = (string) $this->request->getHeaderLine('Authorization');

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return hash_equals($expected, trim($m[1]));
        }

        // Ripiego sulla query string solo fuori produzione: un segreto di
        // lunga durata come la chiave MCP finisce tipicamente nei log di
        // accesso di webserver/proxy, nella cronologia del browser e negli
        // header referrer se passato in URL -- un'esposizione ben piu' ampia
        // di un header, accettabile solo per i test locali via browser.
        if (ENVIRONMENT === 'production') {
            return false;
        }

        return hash_equals($expected, (string) $this->request->getGet('key'));
    }

    /**
     * Fissa l'azienda dell'integrazione nella sessione, così tutti i helper
     * di scoping (active_company_id, scopeToCompany, withCompany) lavorano
     * sull'azienda indicata da mcp_company_id invece che sul default.
     */
    private function bindCompany(): void
    {
        $envCompany = trim((string) env('mcp.company_id', ''));
        $companyId  = $envCompany !== '' ? (int) $envCompany : (int) get_setting('mcp_company_id', 0);

        if ($companyId > 0) {
            session()->set('active_company_id', $companyId);
        }
    }

    /**
     * Elabora un singolo messaggio JSON-RPC. Ritorna l'array della risposta,
     * o null per le notifiche (id assente) che non ammettono risposta.
     *
     * @return array<string, mixed>|null
     */
    private function dispatch(array $message): ?array
    {
        $isNotification = !array_key_exists('id', $message);
        $id             = $isNotification ? null : $message['id'];

        if (($message['jsonrpc'] ?? '') !== '2.0' || !is_string($message['method'] ?? null)) {
            return $this->error($id, -32600, 'Invalid Request');
        }

        $method = $message['method'];
        $params = $message['params'] ?? [];

        if (!is_array($params)) {
            return $this->error($id, -32602, 'Invalid params: atteso un oggetto');
        }

        switch ($method) {
            case 'initialize':
                return [
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'result'  => [
                        'protocolVersion' => $this->negotiateProtocol($params),
                        'capabilities'    => [
                            'tools' => ['listChanged' => false],
                        ],
                        'serverInfo' => [
                            'name'    => 'invoiceplane-mcp',
                            'version' => self::VERSION,
                        ],
                    ],
                ];

            case 'ping':
                return ['jsonrpc' => '2.0', 'id' => $id, 'result' => []];

            case 'tools/list':
                return [
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'result'  => ['tools' => McpTools::definitions()],
                ];

            case 'tools/call':
                $name = (string) ($params['name'] ?? '');

                if ($name === '') {
                    return $this->error($id, -32602, 'Invalid params: name richiesto');
                }

                $arguments = $params['arguments'] ?? [];

                if (!is_array($arguments)) {
                    return $this->error($id, -32602, 'Invalid params: arguments deve essere un oggetto');
                }

                return [
                    'jsonrpc' => '2.0',
                    'id'      => $id,
                    'result'  => McpTools::call($name, $arguments),
                ];

            // Notifiche: nessuna risposta attesa.
            case 'notifications/initialized':
            case 'notifications/cancelled':
                return null;

            default:
                return $this->error($id, -32601, 'Method not found: ' . $method);
        }
    }

    /**
     * Versione del protocollo: si ripiega sulla più recente supportata se il
     * client ne chiede una sconosciuta (il client è libero di continuare o
     * chiudere, ma la maggior parte prosegue con la nostra).
     */
    private function negotiateProtocol(array $params): string
    {
        $requested = (string) ($params['protocolVersion'] ?? '');

        if (in_array($requested, self::SUPPORTED_PROTOCOL_VERSIONS, true)) {
            return $requested;
        }

        return self::SUPPORTED_PROTOCOL_VERSIONS[0];
    }

    /** @return array<string, mixed> */
    private function error($id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ];
    }

    /**
     * Risposta JSON con header Mcp-Session-Id. Il server è stateless: l'id
     * è generato a ogni risposta e accettato quando il client lo rimanda
     * (richieste successive lo portano nel loro header).
     *
     * @param array<string, mixed> $body
     */
    private function respondJson(array $body, int $status = 200)
    {
        return $this->response
            ->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setHeader('Mcp-Session-Id', bin2hex(random_bytes(16)))
            ->setBody(json_encode($body, JSON_UNESCAPED_UNICODE));
    }
}