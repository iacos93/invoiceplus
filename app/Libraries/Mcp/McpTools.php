<?php

namespace App\Libraries\Mcp;

use App\Models\BankAccountModel;
use App\Models\ClientModel;
use App\Models\CompanyModel;
use App\Models\CostingAccountModel;
use App\Models\InvoiceAmountModel;
use App\Models\InvoiceGroupModel;
use App\Models\InvoiceModel;
use App\Models\InvoiceTaxRateModel;
use App\Models\ItemModel;
use App\Models\PaymentMethodModel;
use App\Models\PaymentModel;
use App\Models\QuoteItemModel;
use App\Models\QuoteModel;
use App\Models\TaxRateModel;
use App\Models\UserModel;
use App\Services\InvoiceMath;

/**
 * McpTools
 *
 * Registro dei tool esposti dall'endpoint MCP (/mcp). Ogni tool è una
 * definizione JSON Schema (per il discovery) e un handler che riusa i
 * modelli dell'applicazione — mai query scritte a mano dove esiste già un
 * metodo del model, perché il model porta con sé lo scoping per azienda,
 * la normalizzazione degli importi e il ricalcolo dei totali.
 *
 * I nomi dei tool seguono la convenzione verb_nome. Gli handler non
 * toccano session/flash: gli errori tornano come testo nell'esito del
 * tool, non come redirect.
 *
 * Convenzioni per i parametri:
 * - date: "YYYY-MM-DD"
 * - importi: numero decimale con punto ("100.50"); standardize_amount()
 *   normalizza comunque i separatori configurati nel sistema
 * - campi facoltativi: ometterli invece di passare stringa vuota, così
 *   una stringa vuota non viene mai salvata al posto di un NULL
 */
class McpTools
{
    /** Utente amministratore di ripiego quando i parametri non lo indicano. */
    private static ?int $defaultUserId = null;

    /**
     * Definizioni JSON Schema dei tool (risposta a tools/list).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(): array
    {
        $enumStatus = ['all', 'draft', 'sent', 'viewed', 'paid', 'overdue'];

        $clientFields = [
            'client_name'       => ['type' => 'string', 'description' => 'Ragione sociale o nome (obbligatorio)'],
            'client_surname'    => ['type' => 'string', 'description' => 'Cognome (persone fisiche)'],
            'client_email'      => ['type' => 'string', 'description' => 'Email'],
            'client_phone'      => ['type' => 'string', 'description' => 'Telefono'],
            'client_mobile'     => ['type' => 'string', 'description' => 'Cellulare'],
            'client_address_1'  => ['type' => 'string', 'description' => 'Indirizzo'],
            'client_address_2'  => ['type' => 'string', 'description' => 'Secondo indirizzo'],
            'client_city'       => ['type' => 'string', 'description' => 'Città'],
            'client_state'      => ['type' => 'string', 'description' => 'Provincia'],
            'client_zip'        => ['type' => 'string', 'description' => 'CAP'],
            'client_country'    => ['type' => 'string', 'description' => 'Paese (es. IT)'],
            'client_vat_id'     => ['type' => 'string', 'description' => 'Partita IVA'],
            'client_tax_code'   => ['type' => 'string', 'description' => 'Codice fiscale'],
            'client_sdi_code'   => ['type' => 'string', 'description' => 'Codice destinatario SdI (7 caratteri alfanumerici)'],
            'client_sdi_pec'    => ['type' => 'string', 'description' => 'PEC SdI'],
            'client_iban'       => ['type' => 'string', 'description' => 'IBAN'],
            'client_type'       => ['type' => 'string', 'enum' => ['company', 'person', 'pa'], 'description' => 'Tipo cliente: azienda, persona fisica, pubblica amministrazione'],
            'client_split_payment' => ['type' => 'string', 'enum' => ['auto', 'yes', 'no'], 'description' => 'Split payment (default: auto)'],
            'client_language'   => ['type' => 'string', 'description' => 'Lingua (es. it)'],
        ];

        $itemSchema = [
            'type'                 => 'object',
            'additionalProperties' => false,
            'properties'           => [
                'item_name'             => ['type' => 'string', 'description' => 'Descrizione / nome della riga'],
                'item_description'      => ['type' => 'string', 'description' => 'Note della riga (facoltative)'],
                'item_quantity'         => ['type' => 'number', 'description' => 'Quantità (default 1)'],
                'item_price'            => ['type' => 'number', 'description' => 'Prezzo unitario'],
                'item_tax_rate_id'      => ['type' => 'integer', 'description' => 'ID aliquota (da list_tax_rates); 0 = nessuna'],
                'item_discount_amount'  => ['type' => 'number', 'description' => 'Sconto in valore'],
                'item_discount_percent' => ['type' => 'number', 'description' => 'Sconto in percentuale'],
                // Il Bilancino legge i ricavi per voce di conto: un agente che
                // crea fatture senza questo campo le lascerebbe tutte in "non
                // assegnate", e il report perderebbe il dettaglio per ramo
                // d'attività. Facoltativo: senza, la riga resta non assegnata.
                'costing_account_id'    => ['type' => 'integer', 'description' => 'ID voce di ricavo del Bilancino (da list_costing_accounts); ometti per lasciare la riga non assegnata'],
            ],
        ];

        // I preventivi non hanno una voce di ricavo: il riconoscimento avviene
        // quando diventano fattura. Togliere il campo dal loro schema evita di
        // promettere all'agente un parametro che verrebbe poi scartato in
        // silenzio da quoteItemArray().
        $quoteItemSchema = $itemSchema;
        unset($quoteItemSchema['properties']['costing_account_id']);

        return [
            [
                'name'        => 'list_companies',
                'description' => 'Elenca le aziende (soggetti giuridici) configurate nell\'installazione. Utile per capire su quale azienda opera l\'integrazione.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            ],
            [
                'name'        => 'list_clients',
                'description' => 'Elenca i clienti (filtro per parola chiave su nome/email opzionale).',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'query'  => ['type' => 'string', 'description' => 'Parole chiave su nome, cognome, email'],
                        'active' => ['type' => 'boolean', 'description' => 'true = solo attivi, false = solo inattivi, omesso = tutti'],
                        'limit'  => ['type' => 'integer', 'description' => 'Numero massimo di risultati (default 100, max 500)'],
                    ],
                ],
            ],
            [
                'name'        => 'get_client',
                'description' => 'Dettaglio di un cliente con saldi totali e ultime fatture.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'client_id' => ['type' => 'integer', 'description' => 'ID del cliente'],
                    ],
                    'required' => ['client_id'],
                ],
            ],
            [
                'name'        => 'create_client',
                'description' => 'Crea un nuovo cliente. Rifiuta i duplicati con lo stesso nome e cognome (come la UI).',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => $clientFields + [
                        'client_active' => ['type' => 'boolean', 'description' => 'Cliente attivo (default true)'],
                    ],
                    'required' => ['client_name'],
                ],
            ],
            [
                'name'        => 'update_client',
                'description' => 'Aggiorna i dati di un cliente esistente. Passare solo i campi da modificare.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => $clientFields + [
                        'client_id'     => ['type' => 'integer', 'description' => 'ID del cliente'],
                        'client_active' => ['type' => 'boolean'],
                    ],
                    'required' => ['client_id'],
                ],
            ],
            [
                'name'        => 'list_invoices',
                'description' => 'Elenca le fatture con filtro per stato, anno, mese o parole chiave.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'status' => ['type' => 'string', 'enum' => $enumStatus, 'description' => 'Stato fattura (default: all)'],
                        'year'   => ['type' => 'integer', 'description' => 'Anno di emissione (0 = tutti)'],
                        'month'  => ['type' => 'integer', 'description' => 'Mese 1-12 (0 = tutto l\'anno)'],
                        'query'  => ['type' => 'string', 'description' => 'Parole chiave su numero, cliente, importi'],
                        'limit'  => ['type' => 'integer', 'description' => 'Numero massimo di risultati (default 100, max 500)'],
                    ],
                ],
            ],
            [
                'name'        => 'get_invoice',
                'description' => 'Dettaglio completo di una fattura: dati di testata, righe con importi, imposte e pagamenti registrati.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'invoice_id' => ['type' => 'integer', 'description' => 'ID della fattura'],
                    ],
                    'required' => ['invoice_id'],
                ],
            ],
            [
                'name'        => 'create_invoice',
                'description' => 'Crea una nuova fattura (bozza di default) con le righe indicate. Il numero viene assegnato dal gruppo di numerazione secondo le impostazioni; per uscire dalla bozza impostare invoice_status_id a 2 (inviata).',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'client_id'            => ['type' => 'integer', 'description' => 'ID del cliente (da list_clients)'],
                        'invoice_group_id'     => ['type' => 'integer', 'description' => 'Gruppo di numerazione (da list_invoice_groups)'],
                        'invoice_date_created' => ['type' => 'string', 'description' => 'Data fattura YYYY-MM-DD (default: oggi)'],
                        'invoice_status_id'    => ['type' => 'integer', 'enum' => [1, 2, 3, 4], 'description' => '1 bozza (default), 2 inviata, 3 vista, 4 pagata'],
                        'payment_method'       => ['type' => 'integer', 'description' => 'ID metodo di pagamento (da list_payment_methods)'],
                        'invoice_terms'        => ['type' => 'string', 'description' => 'Termini/condizioni (default: dall\'impostazione)'],
                        'invoice_discount_amount'  => ['type' => 'number', 'description' => 'Sconto in valore'],
                        'invoice_discount_percent' => ['type' => 'number', 'description' => 'Sconto in percentuale'],
                        'user_id'              => ['type' => 'integer', 'description' => 'ID utente intestatario (default: primo amministratore)'],
                        'items'                => ['type' => 'array', 'items' => $itemSchema, 'description' => 'Righe della fattura'],
                    ],
                    'required' => ['client_id', 'invoice_group_id'],
                ],
            ],
            [
                'name'        => 'update_invoice',
                'description' => 'Aggiorna i dati di testata di una fattura (stato, date, numero, termini, sconti, riferimenti fattura elettronica). Passare solo i campi da modificare; i campi vuoti azzerano il valore.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'invoice_id'                 => ['type' => 'integer'],
                        'invoice_status_id'          => ['type' => 'integer', 'enum' => [1, 2, 3, 4]],
                        'invoice_number'             => ['type' => 'string', 'description' => 'Numero scritto a mano (disattiva il provvisorio)'],
                        'invoice_date_created'       => ['type' => 'string', 'description' => 'Data fattura YYYY-MM-DD (ricalcola la scadenza)'],
                        'invoice_date_due'           => ['type' => 'string', 'description' => 'Scadenza YYYY-MM-DD'],
                        'payment_method'             => ['type' => 'integer'],
                        'invoice_terms'              => ['type' => 'string'],
                        'invoice_discount_amount'    => ['type' => 'number'],
                        'invoice_discount_percent'   => ['type' => 'number'],
                        'bank_account_id'            => ['type' => 'integer'],
                        'invoice_doc_type'           => ['type' => 'string', 'description' => 'Tipo documento FatturaPA (TD01, TD02, TD04...)'],
                        'invoice_cig'                => ['type' => 'string'],
                        'invoice_cup'                => ['type' => 'string'],
                        'invoice_order_number'       => ['type' => 'string'],
                        'invoice_order_date'         => ['type' => 'string'],
                        'invoice_contract_number'    => ['type' => 'string'],
                        'invoice_contract_date'      => ['type' => 'string'],
                        'invoice_convention_number'  => ['type' => 'string'],
                        'invoice_convention_date'    => ['type' => 'string'],
                        'invoice_receipt_number'     => ['type' => 'string'],
                        'invoice_receipt_date'       => ['type' => 'string'],
                        'invoice_admin_reference'    => ['type' => 'string'],
                        'invoice_vat_exigibility'    => ['type' => 'string', 'description' => 'Esigibilità IVA (I/D/S)'],
                        'invoice_stamp_mode'         => ['type' => 'string', 'enum' => ['si', 'no'], 'description' => 'Bollo virtuale'],
                        'invoice_stamp_amount'       => ['type' => 'number'],
                        'invoice_withholding_type'   => ['type' => 'string', 'description' => 'Tipo ritenuta (RT01...)'],
                        'invoice_withholding_rate'   => ['type' => 'number'],
                        'invoice_withholding_reason' => ['type' => 'string'],
                        'invoice_fund_type'          => ['type' => 'string', 'description' => 'Tipo cassa previdenziale'],
                        'invoice_fund_rate'          => ['type' => 'number'],
                    ],
                    'required' => ['invoice_id'],
                ],
            ],
            [
                'name'        => 'delete_invoice',
                'description' => 'Elimina una fattura. Consentito solo per le bozze (o se l\'impostazione di cancellazione è attiva), come nella UI.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'invoice_id' => ['type' => 'integer'],
                    ],
                    'required' => ['invoice_id'],
                ],
            ],
            [
                'name'        => 'list_quotes',
                'description' => 'Elenca i preventivi con filtro per stato, anno o mese.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'status' => ['type' => 'string', 'enum' => ['all', 'draft', 'sent', 'viewed', 'approved', 'rejected', 'canceled'], 'description' => 'Stato preventivo (default: all)'],
                        'year'   => ['type' => 'integer'],
                        'month'  => ['type' => 'integer'],
                        'limit'  => ['type' => 'integer', 'description' => 'Numero massimo di risultati (default 100, max 500)'],
                    ],
                ],
            ],
            [
                'name'        => 'get_quote',
                'description' => 'Dettaglio completo di un preventivo con righe e importi.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'quote_id' => ['type' => 'integer'],
                    ],
                    'required' => ['quote_id'],
                ],
            ],
            [
                'name'        => 'create_quote',
                'description' => 'Crea un nuovo preventivo (bozza di default) con le righe indicate.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'client_id'            => ['type' => 'integer'],
                        'invoice_group_id'     => ['type' => 'integer', 'description' => 'Gruppo di numerazione preventivi'],
                        'quote_date_created'   => ['type' => 'string', 'description' => 'Data preventivo YYYY-MM-DD (default: oggi)'],
                        'quote_status_id'      => ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5, 6], 'description' => '1 bozza (default), 2 inviato, 3 visto, 4 approvato, 5 rifiutato, 6 annullato'],
                        'notes'                => ['type' => 'string', 'description' => 'Note del preventivo'],
                        'quote_discount_amount' => ['type' => 'number'],
                        'quote_discount_percent' => ['type' => 'number'],
                        'user_id'              => ['type' => 'integer', 'description' => 'ID utente intestatario (default: primo amministratore)'],
                        'items'                => ['type' => 'array', 'items' => $quoteItemSchema, 'description' => 'Righe del preventivo'],
                    ],
                    'required' => ['client_id', 'invoice_group_id'],
                ],
            ],
            [
                'name'        => 'update_quote',
                'description' => 'Aggiorna i dati di testata di un preventivo (stato, date, numero, note, sconti). Passare solo i campi da modificare.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'quote_id'               => ['type' => 'integer'],
                        'quote_status_id'        => ['type' => 'integer', 'enum' => [1, 2, 3, 4, 5, 6]],
                        'quote_number'           => ['type' => 'string'],
                        'quote_date_created'     => ['type' => 'string', 'description' => 'Data preventivo YYYY-MM-DD'],
                        'quote_date_expires'     => ['type' => 'string', 'description' => 'Scadenza YYYY-MM-DD'],
                        'notes'                  => ['type' => 'string'],
                        'quote_discount_amount'  => ['type' => 'number'],
                        'quote_discount_percent' => ['type' => 'number'],
                    ],
                    'required' => ['quote_id'],
                ],
            ],
            [
                'name'        => 'list_payments',
                'description' => 'Elenca i pagamenti registrati, con filtro opzionale per fattura o cliente.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'invoice_id' => ['type' => 'integer'],
                        'client_id'  => ['type' => 'integer'],
                        'limit'      => ['type' => 'integer', 'description' => 'Numero massimo di risultati (default 100, max 500)'],
                    ],
                ],
            ],
            [
                'name'        => 'create_payment',
                'description' => 'Registra un pagamento su una fattura e ricalcola saldo e stato. L\'importo non può superare il saldo residuo.',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'invoice_id'        => ['type' => 'integer'],
                        'payment_date'      => ['type' => 'string', 'description' => 'Data pagamento YYYY-MM-DD (default: oggi)'],
                        'payment_amount'    => ['type' => 'number', 'description' => 'Importo pagato'],
                        'payment_method_id' => ['type' => 'integer', 'description' => 'ID metodo di pagamento (da list_payment_methods)'],
                        'payment_note'      => ['type' => 'string'],
                    ],
                    'required' => ['invoice_id', 'payment_amount'],
                ],
            ],
            [
                'name'        => 'invoice_summary',
                'description' => 'Riepilogo conteggi e totali delle fatture per stato (bozza, inviata, vista, pagata).',
                'inputSchema' => [
                    'type'                 => 'object',
                    'additionalProperties' => false,
                    'properties'           => [
                        'year' => ['type' => 'integer', 'description' => 'Anno di emissione (0 = tutti)'],
                    ],
                ],
            ],
            [
                'name'        => 'list_tax_rates',
                'description' => 'Elenca le aliquote IVA disponibili (per le righe dei documenti).',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            ],
            [
                'name'        => 'list_invoice_groups',
                'description' => 'Elenca i gruppi di numerazione disponibili per fatture e preventivi.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            ],
            [
                'name'        => 'list_payment_methods',
                'description' => 'Elenca i metodi di pagamento configurabili su fatture e pagamenti.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            ],
            [
                'name'        => 'list_costing_accounts',
                'description' => 'Elenca le voci del piano dei conti del controllo di gestione (Bilancino) dell\'azienda attiva, con tipo e gerarchia. Gli id servono per costing_account_id delle righe fattura.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false],
            ],
        ];
    }

    /**
     * Esegue un tool. Ritorna l'esito MCP: testo (JSON) e flag errore.
     *
     * @return array{content: array<int, array{type: string, text: string}>, isError: bool}
     */
    public static function call(string $name, array $arguments): array
    {
        $handlers = [
            'list_companies'      => [self::class, 'listCompanies'],
            'list_clients'        => [self::class, 'listClients'],
            'get_client'          => [self::class, 'getClient'],
            'create_client'       => [self::class, 'createClient'],
            'update_client'       => [self::class, 'updateClient'],
            'list_invoices'       => [self::class, 'listInvoices'],
            'get_invoice'         => [self::class, 'getInvoice'],
            'create_invoice'      => [self::class, 'createInvoice'],
            'update_invoice'      => [self::class, 'updateInvoice'],
            'delete_invoice'      => [self::class, 'deleteInvoice'],
            'list_quotes'         => [self::class, 'listQuotes'],
            'get_quote'           => [self::class, 'getQuote'],
            'create_quote'        => [self::class, 'createQuote'],
            'update_quote'        => [self::class, 'updateQuote'],
            'list_payments'       => [self::class, 'listPayments'],
            'create_payment'      => [self::class, 'createPayment'],
            'invoice_summary'     => [self::class, 'invoiceSummary'],
            'list_tax_rates'      => [self::class, 'listTaxRates'],
            'list_invoice_groups' => [self::class, 'listInvoiceGroups'],
            'list_payment_methods' => [self::class, 'listPaymentMethods'],
            'list_costing_accounts' => [self::class, 'listCostingAccounts'],
        ];

        if (!isset($handlers[$name])) {
            return self::error('Tool sconosciuto: ' . $name);
        }

        try {
            $result = $handlers[$name]($arguments);

            return [
                'content' => [['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]],
                'isError' => false,
            ];
        } catch (\Throwable $e) {
            log_message('error', 'MCP tool ' . $name . ' fallito: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');

            return self::error('Errore interno durante ' . $name . ': ' . $e->getMessage());
        }
    }

    // =====================================================================
    // Lettura
    // =====================================================================

    /** @return array<int, array<string, mixed>> */
    private static function listCompanies(array $p): array
    {
        // Il resto del server MCP e' vincolato a mcp_company_id (bindCompany
        // in Mcp.php), ma getAllActive() senza filtro tornava ragione
        // sociale, P.IVA, codice fiscale, indirizzo, telefono ed email di
        // TUTTE le aziende attive nell'installazione -- un'integrazione
        // vincolata a una sola azienda vedeva l'anagrafica di tutte le altre.
        $company = (new CompanyModel())->find(active_company_id());

        return $company ? [$company] : [];
    }

    /** @return array<string, mixed> */
    private static function listClients(array $p): array
    {
        $model = new ClientModel();

        $clients = !empty($p['query'])
            ? $model->filterByKeywords(preg_split('/\s+/', trim((string) $p['query'])))
            : $model->getAllOrdered();

        if (array_key_exists('active', $p) && $p['active'] !== null) {
            $clients = array_values(array_filter($clients, static fn($c) => (int) $c['client_active'] === (int) $p['active']));
        }

        return array_slice($clients, 0, self::limit($p));
    }

    /** @return array<string, mixed> */
    private static function getClient(array $p): array
    {
        // scopedFind() prima della lettura arricchita: getByIdWithTotals() non
        // filtra per azienda, quindi l'agente vincolato a mcp_company_id
        // leggeva la scheda di un cliente di un'altra azienda.
        $clientId = (int) $p['client_id'];
        self::scopedFind(new ClientModel(), $clientId, 'Cliente');

        $client = (new ClientModel())->getByIdWithTotals($clientId);

        if (!$client) {
            throw new \RuntimeException('Cliente non trovato: ' . $clientId);
        }

        $invoices = (new InvoiceModel())->byClient((int) $client['client_id']);

        $client['recent_invoices'] = array_map(
            static fn($i) => self::compactInvoice($i),
            array_slice($invoices, 0, 10)
        );

        return $client;
    }

    /** @return array<string, mixed> */
    private static function listInvoices(array $p): array
    {
        $model = new InvoiceModel();

        $invoices = !empty($p['query'])
            ? $model->filterByKeywords(preg_split('/\s+/', trim((string) $p['query'])))
            : $model->getAllWithStatus(
                (string) ($p['status'] ?? 'all'),
                (int) ($p['year'] ?? 0),
                (int) ($p['month'] ?? 0)
            );

        return array_map(static fn($i) => self::compactInvoice($i), array_slice($invoices, 0, self::limit($p)));
    }

    /** @return array<string, mixed> */
    private static function getInvoice(array $p): array
    {
        $invoiceId = (int) $p['invoice_id'];

        // getById() non applica il perimetro azienda (serve anche ai percorsi
        // guest e cron, dove l'azienda attiva non ha senso): qui il filtro va
        // messo prima, altrimenti l'agente legge le fatture di chiunque.
        self::scopedFind(new InvoiceModel(), $invoiceId, 'Fattura');

        $invoice = (new InvoiceModel())->getById($invoiceId);

        if (!$invoice) {
            throw new \RuntimeException('Fattura non trovata: ' . $invoiceId);
        }

        $invoice['items']    = (new ItemModel())->getByInvoice($invoiceId);
        $invoice['tax_rates'] = (new InvoiceTaxRateModel())->getByInvoice($invoiceId);
        $invoice['payments'] = (new PaymentModel())->getByInvoice($invoiceId);

        return $invoice;
    }

    /** @return array<string, mixed> */
    private static function listQuotes(array $p): array
    {
        $quotes = (new QuoteModel())->getAllWithStatus(
            (string) ($p['status'] ?? 'all'),
            (int) ($p['year'] ?? 0),
            (int) ($p['month'] ?? 0)
        );

        $compact = array_map(static function ($q) {
            return [
                'quote_id'           => (int) $q['quote_id'],
                'quote_number'       => $q['quote_number'],
                'quote_date_created' => $q['quote_date_created'],
                'quote_date_expires' => $q['quote_date_expires'],
                'quote_status_id'    => (int) $q['quote_status_id'],
                'client_id'          => (int) $q['client_id'],
                'client_name'        => trim(($q['client_name'] ?? '') . ' ' . ($q['client_surname'] ?? '')),
                'quote_total'        => (float) $q['quote_total'],
                'invoice_number'     => $q['invoice_number'] ?? null,
            ];
        }, $quotes);

        return array_slice($compact, 0, self::limit($p));
    }

    /** @return array<string, mixed> */
    private static function getQuote(array $p): array
    {
        $quoteId = (int) $p['quote_id'];

        // Stesso motivo di get_invoice: il perimetro azienda va applicato qui,
        // non dentro getByIdWithJoins().
        self::scopedFind(new QuoteModel(), $quoteId, 'Preventivo');

        $quote = (new QuoteModel())->getByIdWithJoins($quoteId);

        if (!$quote) {
            throw new \RuntimeException('Preventivo non trovato: ' . $quoteId);
        }

        $quote['items'] = (new QuoteItemModel())->getByQuote($quoteId);

        return $quote;
    }

    /** @return array<string, mixed> */
    private static function listPayments(array $p): array
    {
        $model = new PaymentModel();

        if (!empty($p['invoice_id'])) {
            $payments = $model->getByInvoice((int) $p['invoice_id']);
        } elseif (!empty($p['client_id'])) {
            $payments = $model->byClient((int) $p['client_id']);
        } else {
            $payments = $model->getAllOrdered();
        }

        return array_slice($payments, 0, self::limit($p));
    }

    /** @return array<string, mixed> */
    private static function invoiceSummary(array $p): array
    {
        $year  = (int) ($p['year'] ?? 0);
        $scope = company_scope_sql('i');

        $sql = "SELECT i.invoice_status_id AS s, COUNT(*) AS cnt, COALESCE(SUM(a.invoice_total), 0) AS total
                FROM ip_invoices i
                LEFT JOIN ip_invoice_amounts a ON a.invoice_id = i.invoice_id
                WHERE 1 = 1" . ($year > 0 ? ' AND EXTRACT(YEAR FROM i.invoice_date_created) = ' . $year : '') . $scope . '
                GROUP BY i.invoice_status_id';

        $rows = \Config\Database::connect()->query($sql)->getResultArray();

        $labels = [1 => 'draft', 2 => 'sent', 3 => 'viewed', 4 => 'paid'];
        $out    = ['year' => $year, 'statuses' => []];

        foreach ($rows as $row) {
            $status = (int) $row['s'];
            $out['statuses'][$labels[$status] ?? ('status_' . $status)] = [
                'count' => (int) $row['cnt'],
                'total' => round((float) $row['total'], 2),
            ];
        }

        $out['total_invoices'] = (int) array_sum(array_column($rows, 'cnt'));
        $out['total_amount']   = round((float) array_sum(array_column($rows, 'total')), 2);

        return $out;
    }

    /** @return array<string, mixed> */
    private static function listTaxRates(array $p): array
    {
        return (new TaxRateModel())->getAllOrdered();
    }

    /** @return array<string, mixed> */
    private static function listInvoiceGroups(array $p): array
    {
        return (new InvoiceGroupModel())->getAllOrdered();
    }

    /** @return array<string, mixed> */
    private static function listPaymentMethods(array $p): array
    {
        return (new PaymentMethodModel())->getAllOrdered();
    }

    /**
     * Piano dei conti del Bilancino per l'azienda attiva.
     *
     * Restituisce anche le voci disattivate (account_active = 0): restano
     * valide per i documenti storici e servono a capire i totali già emessi,
     * ma un agente che crea fatture nuove deve scegliere fra quelle attive.
     *
     * @return list<array<string, mixed>>
     */
    private static function listCostingAccounts(array $p): array
    {
        $rows = [];

        foreach ((new CostingAccountModel())->allForCompany() as $account) {
            $rows[] = [
                'account_id'     => (int) $account['account_id'],
                'parent_id'      => $account['parent_id'] !== null ? (int) $account['parent_id'] : null,
                'account_code'   => (string) $account['account_code'],
                'account_name'   => (string) $account['account_name'],
                'account_type'   => (string) $account['account_type'],
                'account_active' => (int) ($account['account_active'] ?? 1) === 1,
            ];
        }

        return $rows;
    }

    /**
     * Verifica gli id di voce passati sulle righe di una fattura nuova.
     *
     * Senza questo controllo un id di un'altra azienda (o di un conto di
     * costo) verrebbe scritto sulla riga e il Bilancino lo finirebbe nel
     * mucchio "non assegnati": l'agente riceverebbe "successo" mentre il
     * ricavo è sparito dal ramo giusto. Meglio un errore esplicito.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private static function assertCostingAccounts(array $items): void
    {
        $wanted = [];

        foreach ($items as $item) {
            $id = (int) ($item['costing_account_id'] ?? 0);

            if ($id > 0) {
                $wanted[$id] = true;
            }
        }

        if ($wanted === []) {
            return;
        }

        $valid = [];

        foreach ((new CostingAccountModel())->allForCompany() as $account) {
            if ((string) $account['account_type'] === CostingAccountModel::TYPE_REVENUE
                && (int) ($account['account_active'] ?? 1) === 1) {
                $valid[(int) $account['account_id']] = true;
            }
        }

        $unknown = array_diff_key($wanted, $valid);

        if ($unknown !== []) {
            throw new \RuntimeException(
                'Voce di ricavo inesistente, disattivata o di un\'altra azienda: '
                . implode(', ', array_keys($unknown))
                . '. Usa list_costing_accounts per gli id validi.'
            );
        }
    }

    // =====================================================================
    // Scrittura
    // =====================================================================

    /**
     * Esegue la scrittura di un documento (testata + righe) in transazione.
     *
     * Prima ogni insert era indipendente: se una riga falliva (validazione,
     * aliquota inesistente, connessione persa) la testata restava scritta e
     * la fattura nasceva vuota — con un numero consumato e un totale a zero
     * che nessuno collega all'errore. Nella stessa transazione stanno anche i
     * totali derivati, che altrimenti resterebbero calcolati su righe
     * inesistenti.
     *
     * I model condividono la connessione di db_connect() (CI4 la restituisce
     * condivisa per lo stesso gruppo), quindi le loro query cadono dentro
     * questa transazione senza doverla passare a mano.
     *
     * @param callable():int $work
     */
    private static function inTransaction(callable $work): int
    {
        $db = db_connect();
        $db->transBegin();

        try {
            $result = $work();

            if ($db->transStatus() === false) {
                throw new \RuntimeException('Scrittura annullata: il database ha rifiutato una delle operazioni');
            }

            $db->transCommit();

            return $result;
        } catch (\Throwable $e) {
            $db->transRollback();

            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private static function createClient(array $p): array
    {
        $model = new ClientModel();

        if (empty($p['client_name'])) {
            throw new \RuntimeException('client_name è obbligatorio');
        }

        $duplicate = $model->findDuplicate((string) $p['client_name'], (string) ($p['client_surname'] ?? ''));

        if ($duplicate) {
            throw new \RuntimeException('Cliente già esistente con questo nome e cognome (client_id '
                . (int) $duplicate['client_id'] . '): aggiorna quello invece di creare un duplicato');
        }

        if (!array_key_exists('client_active', $p)) {
            $p['client_active'] = 1;
        }

        if (!$model->validate($p)) {
            throw new \RuntimeException(implode(' ', $model->errors()));
        }

        $clientId = (int) $model->saveRecord(null, $model->dbArrayFromPost($p));

        return ['client_id' => $clientId, 'success' => true];
    }

    /** @return array<string, mixed> */
    private static function updateClient(array $p): array
    {
        $model = new ClientModel();
        $id    = (int) $p['client_id'];

        self::scopedFind($model, $id, 'Cliente');

        $dbArray = $model->dbArrayFromPost($p);

        unset($dbArray['client_id']);

        // ClientModel::dbArrayFromPost() porta client_active a 0 quando il
        // campo non c'e' (e' il comportamento giusto in creazione, dove la
        // casella non spuntata significa cliente inattivo). In modifica
        // disattiverebbe il cliente a ogni intervento che non parla di
        // client_active, quindi qui si toglie sempre: update_client non
        // cambia l'attivazione.
        unset($dbArray['client_active']);

        if (empty($dbArray)) {
            throw new \RuntimeException('Nessun campo da aggiornare');
        }

        $model->update($id, $dbArray);

        return ['client_id' => $id, 'success' => true];
    }

    /**
     * Imponibile delle righe come lo legge InvoiceMath, ricavato dai
     * parametri di create_invoice (righe non ancora salvate in tabella).
     *
     * L'aliquota non serve: la soglia dello sconto dipende solo dagli
     * imponibili di riga, non dall'IVA.
     *
     * @param array<int, array<string, mixed>> $items
     *
     * @return list<array{taxable: float}>
     */
    private static function discountBaseFromItems(array $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            $amounts = InvoiceMath::lineAmounts(
                (float) ($item['item_quantity'] ?? 1),
                (float) ($item['item_price'] ?? 0),
                (float) ($item['item_discount_amount'] ?? 0),
                (float) ($item['item_discount_percent'] ?? 0),
                0.0
            );

            $lines[] = ['taxable' => $amounts['taxable']];
        }

        return $lines;
    }

    /**
     * Creazione fattura: stesso percorso di InvoicesAjax::save() — validazione
     * del model, dbArrayFromPost() (data/scadenza/numero/url key) e righe via
     * ItemModel::saveItem(), che ricalcola gli importi dopo ogni riga.
     *
     * @return array<string, mixed>
     */
    private static function createInvoice(array $p): array
    {
        $model = new InvoiceModel();

        $post = $p;
        unset($post['items']);

        self::assertInvoiceGroup((int) ($post['invoice_group_id'] ?? 0));
        self::scopedFind(new ClientModel(), (int) ($post['client_id'] ?? 0), 'Cliente');
        self::assertCostingAccounts((array) ($p['items'] ?? []));

        if (!isset($post['invoice_date_created']) || $post['invoice_date_created'] === '') {
            $post['invoice_date_created'] = date('Y-m-d');
        }
        if (!isset($post['invoice_status_id']) || $post['invoice_status_id'] === '') {
            $post['invoice_status_id'] = 1;
        }
        if (empty($post['user_id'] ?? null)) {
            $post['user_id'] = self::defaultUserId();
        }

        // user_id non è nelle validation rules di InvoiceModel: dbArrayFromPost()
        // lo scarta e lo riprende da session('user_id'). Senza login la sessione
        // è vuota e la fattura nascerebbe con user_id 0 — invisibile in ogni
        // elenco (withJoins fa INNER JOIN su ip_users). Si inietta in sessione
        // come fa il login, e il model fa il resto.
        session()->set('user_id', (int) $post['user_id']);

        if (!$model->validate($post)) {
            throw new \RuntimeException(implode(' ', $model->errors()));
        }

        $dbArray = $model->dbArrayFromPost($post);

        // Come per i preventivi: dbArrayFromPost() tiene solo i campi con
        // regola di validazione, quindi condizioni di pagamento e sconti
        // passati a create_invoice venivano scartati in silenzio e sostituiti
        // dai default aziendali.
        foreach (['invoice_terms', 'invoice_discount_amount', 'invoice_discount_percent'] as $extra) {
            if (array_key_exists($extra, $post) && $post[$extra] !== '' && $post[$extra] !== null) {
                $dbArray[$extra] = $post[$extra];
            }
        }

        // Sconto di documento oltre l'imponibile: il totale andrebbe sotto
        // zero (InvoiceMath::discountExceedsBase()). Si valida sui dati
        // passati, prima di scrivere la testata, così un rifiuto non lascia
        // una fattura a metà né consuma un numero.
        if (InvoiceMath::discountExceedsBase(
            self::discountBaseFromItems((array) ($p['items'] ?? [])),
            (float) ($dbArray['invoice_discount_amount'] ?? 0),
            (float) ($dbArray['invoice_discount_percent'] ?? 0)
        )) {
            throw new \RuntimeException('Sconto non applicabile: supera l\'imponibile delle righe e porterebbe il totale sotto zero');
        }

        $invoiceId = self::inTransaction(static function () use ($model, $dbArray, $p): int {
            $id = $model->create($dbArray);

            if ($id <= 0) {
                throw new \RuntimeException('Fattura non salvata: ' . (implode(' ', $model->errors()) ?: 'dati non validi'));
            }

            foreach ((array) ($p['items'] ?? []) as $index => $item) {
                $item['invoice_id'] = $id;

                $itemModel = new ItemModel();
                $savedId   = $itemModel->saveItem(null, $itemModel->dbArrayFromItem($item));

                // saveItem() restituisce 0 quando la validazione del model
                // rifiuta la riga (es. descrizione vuota). Senza questo
                // controllo la fattura risultava creata con una riga in meno
                // e un totale più basso, senza dirlo a nessuno.
                if ($savedId <= 0) {
                    throw new \RuntimeException(
                        'Riga ' . ($index + 1) . ' non salvata: '
                        . (implode(' ', $itemModel->errors()) ?: 'dati non validi')
                    );
                }
            }

            return $id;
        });

        $invoice = $model->getById($invoiceId);

        return [
            'invoice_id'     => $invoiceId,
            'invoice_number' => $invoice['invoice_number'] ?? null,
            'success'        => true,
        ];
    }

    /**
     * Aggiornamento testata fattura: specchio di updateInvoiceFromPost()
     * in InvoicesAjax — i campi facoltativi vuoti tornano NULL (un campo
     * assente invece non viene toccato), l'uscita dalla bozza finalizza il
     * numero, e la presenza di sconti ricalcola gli importi.
     *
     * @return array<string, mixed>
     */
    private static function updateInvoice(array $p): array
    {
        $model = new InvoiceModel();
        $id    = (int) $p['invoice_id'];

        $invoice = self::scopedFind($model, $id, 'Fattura');

        $update = [];

        foreach ([
            'invoice_status_id', 'invoice_number', 'payment_method', 'invoice_terms',
            'invoice_password', 'invoice_discount_amount', 'invoice_discount_percent',
            'bank_account_id',
            'invoice_cig', 'invoice_cup', 'invoice_admin_reference',
            'invoice_order_number', 'invoice_order_date', 'invoice_order_line',
            'invoice_contract_number', 'invoice_contract_date',
            'invoice_convention_number', 'invoice_convention_date',
            'invoice_receipt_number', 'invoice_receipt_date',
            'invoice_doc_type', 'invoice_vat_exigibility',
            'invoice_stamp_mode', 'invoice_stamp_amount',
            'invoice_withholding_type', 'invoice_withholding_rate',
            'invoice_withholding_reason', 'invoice_fund_type', 'invoice_fund_rate',
        ] as $field) {
            if (array_key_exists($field, $p)) {
                $update[$field] = $p[$field] === '' || $p[$field] === null ? null : $p[$field];
            }
        }

        if (array_key_exists('invoice_date_created', $p) && $p['invoice_date_created'] !== '') {
            $update['invoice_date_created'] = self::toMysqlDate((string) $p['invoice_date_created']);
            $update['invoice_date_due']     = $model->getDateDue($update['invoice_date_created']);
        }

        if (array_key_exists('invoice_date_due', $p) && $p['invoice_date_due'] !== '') {
            $update['invoice_date_due'] = self::toMysqlDate((string) $p['invoice_date_due']);
        }

        // bank_account_id deve appartenere all'azienda attiva (mcp_company_id):
        // senza questo controllo un client MCP potrebbe attaccare il conto di
        // un'altra azienda a una fattura propria, leakando il suo IBAN nel
        // PDF/ZUGFeRD/FatturaPA. findScoped() risolve sullo scope corrente.
        if (array_key_exists('bank_account_id', $update)) {
            $value = $update['bank_account_id'];

            if ($value !== null && !(new BankAccountModel())->findScoped((int) $value)) {
                unset($update['bank_account_id']);
            }
        }

        // Stessa soglia del salvataggio da scheda (InvoicesAjax): lo sconto
        // non può superare l'imponibile delle righe già salvate, altrimenti
        // il totale va sotto zero.
        if (array_key_exists('invoice_discount_amount', $update)
            || array_key_exists('invoice_discount_percent', $update)) {
            $amount  = (float) ($update['invoice_discount_amount'] ?? $invoice['invoice_discount_amount'] ?? 0);
            $percent = (float) ($update['invoice_discount_percent'] ?? $invoice['invoice_discount_percent'] ?? 0);

            if (InvoiceMath::discountExceedsBase(
                InvoiceMath::linesFromItems((new ItemModel())->getByInvoice($id)),
                $amount,
                $percent
            )) {
                throw new \RuntimeException('Sconto non applicabile: supera l\'imponibile delle righe e porterebbe il totale sotto zero');
            }
        }

        // Numero riscritto a mano: smette di essere "provvisorio", altrimenti
        // l'uscita dalla bozza lo rimpiazzerebbe.
        if (array_key_exists('invoice_number', $update)) {
            $current = $invoice;

            if ($current && (string) $update['invoice_number'] !== (string) $current['invoice_number']) {
                $update['invoice_number_is_draft'] = false;
            }
        }

        if (!empty($update)) {
            $model->update($id, $update);
        }

        if (isset($update['invoice_status_id']) && (int) $update['invoice_status_id'] !== 1) {
            $model->finalizeNumber($id);
        }

        // Ricalcolo anche quando lo sconto viene azzerato o svuotato: con la
        // vecchia condizione (!empty) cancellare uno sconto lasciava i totali
        // derivati (invoice_total/invoice_balance) non ricalcolati.
        if (array_key_exists('invoice_discount_amount', $update)
            || array_key_exists('invoice_discount_percent', $update)) {
            (new InvoiceAmountModel())->calculate($id);
        }

        return ['invoice_id' => $id, 'success' => true];
    }

    /** @return array<string, mixed> */
    private static function deleteInvoice(array $p): array
    {
        $model = new InvoiceModel();
        $id    = (int) $p['invoice_id'];

        // Era l'unica mutazione che non passava da scopedFind(): con un id
        // qualsiasi l'agente cancellava la fattura di un'altra azienda.
        $invoice = self::scopedFind($model, $id, 'Fattura');

        $enableDeletion = get_setting('enable_invoice_deletion', 0);

        if ((int) $invoice['invoice_status_id'] !== 1 && (int) $enableDeletion !== 1) {
            throw new \RuntimeException('Cancellazione consentita solo per le bozze (o con l\'impostazione di cancellazione attiva)');
        }

        (new \App\Models\TaskModel())->updateOnInvoiceDelete($id);
        $model->delete($id);

        return ['invoice_id' => $id, 'deleted' => true, 'success' => true];
    }

    /**
     * Creazione preventivo: stesso percorso di QuotesAjax::create() —
     * validazione, dbArrayFromPost() (data/scadenza/numero/url key) e righe
     * via QuoteItemModel::saveItem().
     *
     * @return array<string, mixed>
     */
    private static function createQuote(array $p): array
    {
        $model = new QuoteModel();

        $post = $p;
        unset($post['items']);

        self::assertInvoiceGroup((int) ($post['invoice_group_id'] ?? 0));
        self::scopedFind(new ClientModel(), (int) ($post['client_id'] ?? 0), 'Cliente');

        if (!isset($post['quote_date_created']) || $post['quote_date_created'] === '') {
            $post['quote_date_created'] = date('Y-m-d');
        }
        if (!isset($post['quote_status_id']) || $post['quote_status_id'] === '') {
            $post['quote_status_id'] = 1;
        }
        if (empty($post['user_id'] ?? null)) {
            $post['user_id'] = self::defaultUserId();
        }

        // Come per le fatture: il model riprende user_id dalla sessione,
        // iniettato qui perché senza login sarebbe 0.
        session()->set('user_id', (int) $post['user_id']);

        if (!$model->validate($post)) {
            throw new \RuntimeException(implode(' ', $model->errors()));
        }

        $dbArray = $model->dbArrayFromPost($post);

        // dbArrayFromPost() tiene solo i campi con regola di validazione:
        // note, sconti e scadenza resterebbero fuori e andrebbero persi in
        // silenzio (le note venivano sostituite dal default aziendale).
        foreach (['notes', 'quote_discount_amount', 'quote_discount_percent', 'quote_date_expires'] as $extra) {
            if (array_key_exists($extra, $post) && $post[$extra] !== '' && $post[$extra] !== null) {
                $dbArray[$extra] = $post[$extra];
            }
        }
        if (isset($dbArray['quote_date_expires'])) {
            $dbArray['quote_date_expires'] = self::toMysqlDate((string) $dbArray['quote_date_expires']);
        }

        $quoteId = self::inTransaction(static function () use ($model, $dbArray, $p): int {
            $id = $model->create($dbArray);

            if ($id <= 0) {
                throw new \RuntimeException('Preventivo non salvato: ' . (implode(' ', $model->errors()) ?: 'dati non validi'));
            }

            foreach ((array) ($p['items'] ?? []) as $index => $item) {
                $item['quote_id'] = $id;

                $itemModel = new QuoteItemModel();
                $savedId   = $itemModel->saveItem(null, self::quoteItemArray($item));

                // Stessa ragione delle fatture: una riga rifiutata dalla
                // validazione spariva in silenzio, lasciando un preventivo
                // incompleto con esito "successo".
                if ($savedId <= 0) {
                    throw new \RuntimeException(
                        'Riga ' . ($index + 1) . ' non salvata: '
                        . (implode(' ', $itemModel->errors()) ?: 'dati non validi')
                    );
                }
            }

            (new \App\Models\QuoteAmountModel())->calculate($id);

            return $id;
        });

        $quote = $model->find($quoteId);

        return [
            'quote_id'     => $quoteId,
            'quote_number' => $quote['quote_number'] ?? null,
            'success'      => true,
        ];
    }

    /**
     * Aggiornamento testata preventivo: specchio del blocco dati di
     * QuotesAjax::save() (numero generato all'uscita dalla bozza, sconti
     * standardizzati, ricalcolo).
     *
     * @return array<string, mixed>
     */
    private static function updateQuote(array $p): array
    {
        $model = new QuoteModel();
        $id    = (int) $p['quote_id'];
        $quote = self::scopedFind($model, $id, 'Preventivo');

        $statusId = (int) ($p['quote_status_id'] ?? $quote['quote_status_id']);
        $number   = array_key_exists('quote_number', $p) ? (string) $p['quote_number'] : (string) ($quote['quote_number'] ?? '');

        if ($number === '' && $statusId !== 1) {
            $number = $model->generateQuoteNumber($model->getInvoiceGroupId($id));
        }

        $dbArray = [
            'quote_number'           => $number,
            'quote_status_id'        => $statusId,
            'quote_discount_amount'  => (float) ($p['quote_discount_amount'] ?? $quote['quote_discount_amount'] ?? 0),
            'quote_discount_percent' => (float) ($p['quote_discount_percent'] ?? $quote['quote_discount_percent'] ?? 0),
        ];

        if (array_key_exists('notes', $p)) {
            $dbArray['notes'] = $p['notes'];
        }

        if (!empty($p['quote_date_created'] ?? null)) {
            $dbArray['quote_date_created'] = self::toMysqlDate((string) $p['quote_date_created']);
        }
        if (!empty($p['quote_date_expires'] ?? null)) {
            $dbArray['quote_date_expires'] = self::toMysqlDate((string) $p['quote_date_expires']);
        }

        if ($number !== (string) $quote['quote_number']) {
            $dbArray['quote_number_is_draft'] = false;
        }

        $model->update($id, $dbArray);

        if ($statusId !== 1) {
            $model->finalizeNumber($id);
        }

        (new \App\Models\QuoteAmountModel())->calculate($id);

        return ['quote_id' => $id, 'success' => true];
    }

    /** @return array<string, mixed> */
    private static function createPayment(array $p): array
    {
        $model = new PaymentModel();

        $post = $p;

        if (!isset($post['payment_date']) || $post['payment_date'] === '') {
            $post['payment_date'] = date('Y-m-d');
        }

        // Il model PaymentModel non ha company_id: lo scoping passa dalla
        // fattura pagata, verificato qui per non registrare un pagamento
        // su un documento di un'altra azienda.
        self::scopedFind(new InvoiceModel(), (int) ($post['invoice_id'] ?? 0), 'Fattura');

        if (!$model->validate($post)) {
            throw new \RuntimeException(implode(' ', $model->errors()));
        }

        $paymentId = (int) $model->saveRecord(null, $model->dbArrayFromPost($post));

        return ['payment_id' => $paymentId, 'success' => true];
    }

    // =====================================================================
    // Utilità
    // =====================================================================

    /** @return array<string, mixed> */
    private static function compactInvoice(array $i): array
    {
        return [
            'invoice_id'           => (int) $i['invoice_id'],
            'invoice_number'       => $i['invoice_number'],
            'invoice_date_created' => $i['invoice_date_created'],
            'invoice_date_due'     => $i['invoice_date_due'],
            'invoice_status_id'    => (int) $i['invoice_status_id'],
            'client_id'            => (int) $i['client_id'],
            'client_name'          => trim(($i['client_name'] ?? '') . ' ' . ($i['client_surname'] ?? '')),
            'invoice_total'        => (float) ($i['invoice_total'] ?? 0),
            'invoice_paid'         => (float) ($i['invoice_paid'] ?? 0),
            'invoice_balance'      => (float) ($i['invoice_balance'] ?? 0),
            'is_overdue'           => (int) ($i['is_overdue'] ?? 0),
        ];
    }

    /**
     * Riga preventivo normalizzata come in QuotesAjax::saveItems() —
     * quantità e prezzo standardizzati, aliquota 0 se assente, id opzionali
     * a null (item_tax_rate_id NO: la colonna è NOT NULL). quote_id viene
     * dal chiamante e passa dentro l'array, altrimenti la validazione della
     * riga (required) lo scarterebbe in silenzio e il preventivo nascerebbe
     * senza righe.
     *
     * @return array<string, mixed>
     */
    private static function quoteItemArray(array $item): array
    {
        $quantity = !empty($item['item_quantity'] ?? null) ? standardize_amount($item['item_quantity']) : 0;
        $price    = !empty($item['item_price'] ?? null) ? standardize_amount($item['item_price']) : 0;

        return [
            'quote_id'             => (int) ($item['quote_id'] ?? 0),
            'item_tax_rate_id'     => !empty($item['item_tax_rate_id'] ?? null) ? $item['item_tax_rate_id'] : 0,
            'item_name'            => (string) ($item['item_name'] ?? ''),
            'item_description'     => (string) ($item['item_description'] ?? ''),
            'item_quantity'        => $quantity,
            'item_price'           => $price,
            'item_discount_amount' => !empty($item['item_discount_amount'] ?? null) ? standardize_amount($item['item_discount_amount']) : null,
        ];
    }

    private static function limit(array $p): int
    {
        $limit = (int) ($p['limit'] ?? 100);

        return min(max($limit, 1), 500);
    }

    /**
     * find() non applica lo scoping per azienda (quello vive nelle where
     * aggiunte da scopeToCompany()): usarlo nudo lascerebbe all'agente la
     * lettura dei documenti di un'altra azienda. Questo wrapper è l'unico
     * modo di raggiungere una riga per id dagli handler.
     *
     * @return array<string, mixed>
     */
    private static function scopedFind($model, int $id, string $label): array
    {
        $row = $model->scopeToCompany()->find($id);

        if (!$row) {
            throw new \RuntimeException($label . ' non trovato: ' . $id);
        }

        return $row;
    }

    /**
     * Verifica che il gruppo fatture esista e appartenga all'azienda attiva
     * (o all'archivio pre-multi-azienda). Un group_id inesistente passerebbe
     * la validazione ma produrrebbe una fattura senza numero definitivo:
     * meglio rifiutare subito.
     */
    private static function assertInvoiceGroup(int $invoiceGroupId): void
    {
        if ($invoiceGroupId <= 0) {
            throw new \RuntimeException('invoice_group_id è obbligatorio (vedi list_invoice_groups)');
        }

        self::scopedFind(new InvoiceGroupModel(), $invoiceGroupId, 'Gruppo fatture');
    }

    /**
     * Data in formato MySQL. Accetta già YYYY-MM-DD (lascia invariato),
     * altrimenti delega a date_to_mysql() che converte dal formato utente.
     */
    private static function toMysqlDate(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        return date_to_mysql($date) ?: $date;
    }

    /**
     * Utente di ripiego per i documenti creati senza user_id: il primo
     * utente con user_type 1 (amministratore), altrimenti il primo in
     * assoluto. Le fatture hanno un INNER JOIN su ip_users: con user_id 0
     * sparirebbero da ogni elenco.
     */
    private static function defaultUserId(): int
    {
        if (self::$defaultUserId !== null) {
            return self::$defaultUserId;
        }

        $user = (new UserModel())->where('user_type', 1)->orderBy('user_id', 'ASC')->first()
            ?? (new UserModel())->orderBy('user_id', 'ASC')->first();

        return self::$defaultUserId = (int) ($user['user_id'] ?? 0);
    }

    /** @return array{content: array<int, array{type: string, text: string}>, isError: bool} */
    private static function error(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }
}